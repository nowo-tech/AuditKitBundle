<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\Doctrine\SqlLike;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelPublicItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/** Real kernel + DoctrineBundle + SQLite: PublicUuidListener wiring, trait mappings and SqlLike in DQL. */
final class AuditKitDoctrineHelpersTest extends TestCase
{
    private AuditKitTestKernel $kernel;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-test-kernel');

        $this->kernel = new AuditKitTestKernel();
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testTraitColumnsKeepHostCompatibleMapping(): void
    {
        $metadata = $this->em->getClassMetadata(KernelPublicItem::class);

        $uuid = $metadata->getFieldMapping('uuid');
        $this->assertSame('string', $uuid['type']);
        $this->assertSame(36, $uuid['length']);
        $this->assertFalse($uuid['nullable'] ?? false);

        $createdAt = $metadata->getFieldMapping('createdAt');
        $this->assertSame('datetime_immutable', $createdAt['type']);
        $this->assertFalse($createdAt['nullable'] ?? false);
    }

    public function testListenerAssignsUuidOnPersistAndKeepsAssignedOne(): void
    {
        $generated = new KernelPublicItem('generated');
        $assigned  = new KernelPublicItem('assigned');
        $assigned->assignUuid('0190a4b2-3c4d-7e5f-8a6b-7c8d9e0f1a2b');

        $this->em->persist($generated);
        $this->em->persist($assigned);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(KernelPublicItem::class)->findOneBy(['name' => 'generated']);
        self::assertInstanceOf(KernelPublicItem::class, $reloaded);
        $this->assertTrue(Uuid::isValid($reloaded->getUuid()));
        $this->assertSame($generated->getUuid(), $reloaded->getUuid());
        $this->assertLessThanOrEqual(new DateTimeImmutable(), $reloaded->getCreatedAt());

        $byUuid = $this->em->getRepository(KernelPublicItem::class)->findOneBy(['uuid' => '0190a4b2-3c4d-7e5f-8a6b-7c8d9e0f1a2b']);
        self::assertInstanceOf(KernelPublicItem::class, $byUuid);
        $this->assertSame('assigned', $byUuid->name);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function likeProvider(): iterable
    {
        yield 'percent is literal' => ['%', ['10% off']];
        yield 'underscore is literal' => ['_', ['snake_case']];
        yield 'backslash is literal' => ['\\', ['back\\slash']];
        yield 'plain substring' => ['off', ['10% off', '10x off']];
        yield 'combined' => ['0% o', ['10% off']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('likeProvider')]
    public function testSqlLikeMatchesWildcardsLiterallyOnSqlite(string $term, array $expected): void
    {
        $this->assertInstanceOf(SQLitePlatform::class, $this->em->getConnection()->getDatabasePlatform());

        foreach (['10% off', '10x off', 'snake_case', 'snakeXcase', 'back\\slash', 'plain'] as $name) {
            $this->em->persist(new KernelPublicItem($name));
        }
        $this->em->flush();

        $this->assertSame($expected, $this->search(SqlLike::contains($term), SqlLike::ESCAPE_CLAUSE));
    }

    public function testEscapeClauseRendersSingleBackslashInSql(): void
    {
        $sql = $this->em->createQuery('SELECT i.name FROM ' . KernelPublicItem::class . ' i WHERE i.name LIKE :q ' . SqlLike::ESCAPE_CLAUSE)->getSQL();

        $this->assertIsString($sql);
        $this->assertStringEndsWith("ESCAPE '\\'", $sql);
    }

    public function testUnescapedWildcardsMatchEverything(): void
    {
        foreach (['10% off', 'plain'] as $name) {
            $this->em->persist(new KernelPublicItem($name));
        }
        $this->em->flush();

        $this->assertSame(['10% off', 'plain'], $this->search('%_%', ''));
    }

    /**
     * @return list<string>
     */
    private function search(string $pattern, string $escapeClause): array
    {
        /** @var list<string> $names */
        $names = $this->em->createQueryBuilder()
            ->select('i.name')
            ->from(KernelPublicItem::class, 'i')
            ->where('i.name LIKE :q ' . $escapeClause)
            ->setParameter('q', $pattern)
            ->orderBy('i.id')
            ->getQuery()
            ->getSingleColumnResult();

        return $names;
    }
}
