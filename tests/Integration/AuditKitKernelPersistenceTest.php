<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\Model\AuditUserInterface;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\CountingClock;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelArticle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelLegacyArticle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * End-to-end: real kernel + DoctrineBundle + SQLite. Entities use the traits only (no #[ORM\EntityListeners]),
 * exactly as documented; the audit fields must be written to the database.
 */
final class AuditKitKernelPersistenceTest extends TestCase
{
    private AuditKitTestKernel $kernel;

    private EntityManagerInterface $em;

    private CountingClock $clock;

    protected function setUp(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-test-kernel');

        $this->kernel = new AuditKitTestKernel();
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $clock = $container->get('clock');
        self::assertInstanceOf(CountingClock::class, $clock);
        $this->clock = $clock;

        // BlameableTrait's AuditUserInterface target must resolve to the configured user_class.
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testBlameableTraitTargetsConfiguredUserClass(): void
    {
        $mapping = $this->em->getClassMetadata(KernelArticle::class)->getAssociationMapping('createdBy');

        self::assertSame(KernelUser::class, $mapping->targetEntity);
        self::assertNotSame(AuditUserInterface::class, $mapping->targetEntity);
    }

    public function testPersistAndUpdateWriteTimestampsAndBlameWithoutEntityListenerMapping(): void
    {
        $author = new KernelUser('author@example.com');
        $this->em->persist($author);
        $this->em->flush();
        $this->authenticate($author);

        $article = new KernelArticle('Hello');
        $this->em->persist($article);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->find(KernelArticle::class, $article->id);
        self::assertInstanceOf(KernelArticle::class, $stored);
        self::assertEquals(new DateTimeImmutable('2026-01-01 10:00:00'), $stored->getCreatedAt());
        self::assertEquals(new DateTimeImmutable('2026-01-01 10:00:00'), $stored->getUpdatedAt());
        self::assertSame($author->id, $this->userId($stored->getCreatedBy()));
        self::assertSame($author->id, $this->userId($stored->getUpdatedBy()));

        $editor = new KernelUser('editor@example.com');
        $this->em->persist($editor);
        $this->em->flush();
        $this->authenticate($editor);
        $this->clock->modify('+1 hour');

        $stored->title = 'Hello again';
        $this->em->flush();
        $this->em->clear();

        $updated = $this->em->find(KernelArticle::class, $article->id);
        self::assertInstanceOf(KernelArticle::class, $updated);
        self::assertEquals(new DateTimeImmutable('2026-01-01 10:00:00'), $updated->getCreatedAt());
        self::assertEquals(new DateTimeImmutable('2026-01-01 11:00:00'), $updated->getUpdatedAt());
        self::assertSame($author->id, $this->userId($updated->getCreatedBy()));
        self::assertSame($editor->id, $this->userId($updated->getUpdatedBy()));
    }

    public function testExplicitEntityListenerMappingStillAuditsExactlyOnce(): void
    {
        $legacy = new KernelLegacyArticle();
        $this->em->persist($legacy);
        $this->em->flush();

        self::assertSame(1, $this->clock->calls);
        self::assertEquals(new DateTimeImmutable('2026-01-01 10:00:00'), $legacy->getCreatedAt());

        $this->clock->modify('+1 hour');
        $legacy->title = 'changed';
        $this->em->flush();
        $this->em->clear();

        self::assertSame(2, $this->clock->calls);
        $stored = $this->em->find(KernelLegacyArticle::class, $legacy->id);
        self::assertInstanceOf(KernelLegacyArticle::class, $stored);
        self::assertEquals(new DateTimeImmutable('2026-01-01 11:00:00'), $stored->getUpdatedAt());
    }

    private function authenticate(KernelUser $user): void
    {
        $tokenStorage = $this->kernel->getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorage::class, $tokenStorage);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function userId(?object $user): ?int
    {
        self::assertInstanceOf(KernelUser::class, $user);

        return $user->id;
    }
}
