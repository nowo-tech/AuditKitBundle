<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver;
use Nowo\AuditKitBundle\Model\AuditableTrait;
use Nowo\AuditKitBundle\Profile\ProfileRegistry;
use Nowo\AuditKitBundle\Security\CurrentUserResolver;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class AuditableEntityListenerExtendedTest extends TestCase
{
    public function testSkipsNonAuditableEntity(): void
    {
        $entity   = new stdClass();
        $em       = $this->createMock(EntityManagerInterface::class);
        $listener = $this->createListener(new TokenStorage(), $em);

        $listener->prePersist($entity, new PrePersistEventArgs($entity, $em));
        $changeSet = [];
        $listener->preUpdate($entity, new PreUpdateEventArgs($entity, $em, $changeSet));

        $this->addToAssertionCount(1);
    }

    public function testSkipsWhenPreUpdateDisabled(): void
    {
        $entity = new ExtendedTestArticle();
        $entity->setCreatedAt(new DateTimeImmutable());
        $em = $this->createMock(EntityManagerInterface::class);

        $listener = $this->createListener(
            new TokenStorage(),
            $em,
            ProfileRegistryFactory::single(ExtendedTestUser::class, ['enabled' => false]),
        );

        $before    = $entity->getUpdatedAt();
        $changeSet = [];
        $listener->preUpdate($entity, new PreUpdateEventArgs($entity, $em, $changeSet));
        $this->assertSame($before, $entity->getUpdatedAt());
    }

    public function testResolveBlameUserWithReference(): void
    {
        $entity    = new ExtendedTestArticle();
        $user      = new ExtendedTestUser(7);
        $reference = new ExtendedTestUser(7);

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getSingleIdentifierFieldName')->willReturn('id');
        $metadata->method('getIdentifierValues')->with($user)->willReturn(['id' => 7]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('getReference')->with(ExtendedTestUser::class, 7)->willReturn($reference);

        $listener = $this->createListener($tokenStorage, $em);
        $listener->prePersist($entity, new PrePersistEventArgs($entity, $em));

        $this->assertSame($reference, $entity->getCreatedBy());
    }

    public function testResolveBlameUserIgnoresNullUser(): void
    {
        $entity   = new ExtendedTestArticle();
        $em       = $this->createMock(EntityManagerInterface::class);
        $listener = $this->createListener(new TokenStorage(), $em);

        $listener->prePersist($entity, new PrePersistEventArgs($entity, $em));

        $this->assertNull($entity->getCreatedBy());
    }

    public function testResolveBlameUserWrongClass(): void
    {
        $entity       = new ExtendedTestArticle();
        $otherUser    = new OtherUser('other');
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($otherUser, 'main', $otherUser->getRoles()));

        $em       = $this->createMock(EntityManagerInterface::class);
        $listener = $this->createListener($tokenStorage, $em);
        $listener->prePersist($entity, new PrePersistEventArgs($entity, $em));

        $this->assertNull($entity->getCreatedBy());
    }

    public function testResolveBlameUserFallbackWhenMetadataFails(): void
    {
        $entity       = new ExtendedTestArticle();
        $user         = new ExtendedTestUser(3);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willThrowException(new RuntimeException('metadata error'));

        $listener = $this->createListener($tokenStorage, $em);
        $listener->prePersist($entity, new PrePersistEventArgs($entity, $em));

        $this->assertSame($user, $entity->getCreatedBy());
    }

    public function testBlameableDisabledOnUpdate(): void
    {
        $entity = new ExtendedTestArticle();
        $entity->setCreatedAt(new DateTimeImmutable('-1 hour'));
        $entity->setUpdatedAt(new DateTimeImmutable('-1 hour'));

        $user         = new ExtendedTestUser(1);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $em = $this->createMock(EntityManagerInterface::class);

        $listener = $this->createListener(
            $tokenStorage,
            $em,
            ProfileRegistryFactory::single(ExtendedTestUser::class, [
                'timestampable' => false,
                'blameable'     => false,
            ]),
        );

        $changeSet = [];
        $listener->preUpdate($entity, new PreUpdateEventArgs($entity, $em, $changeSet));
        $this->assertNull($entity->getUpdatedBy());
    }

    private function createListener(
        TokenStorage $tokenStorage,
        EntityManagerInterface $em,
        ?ProfileRegistry $registry = null,
    ): AuditableEntityListener {
        $registry ??= ProfileRegistryFactory::single(ExtendedTestUser::class);

        return new AuditableEntityListener(
            registry: $registry,
            propertyResolver: new AuditablePropertyResolver(),
            currentUserResolver: new CurrentUserResolver($tokenStorage),
            entityManager: $em,
        );
    }
}

class ExtendedTestArticle
{
    use AuditableTrait;
}

class ExtendedTestUser implements UserInterface
{
    public function __construct(public int $id)
    {
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->id;
    }
}

class OtherUser implements UserInterface
{
    public function __construct(private readonly string $id)
    {
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->id !== '' ? $this->id : 'other-user';
    }
}
