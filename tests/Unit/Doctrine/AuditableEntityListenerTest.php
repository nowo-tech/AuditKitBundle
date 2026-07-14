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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class AuditableEntityListenerTest extends TestCase
{
    public function testPrePersistSetsTimestampsAndBlame(): void
    {
        $entity       = new TestArticle();
        $user         = new TestUser(42);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $em = $this->createEntityManagerMock($user);

        $listener = $this->createListener($tokenStorage, $em);
        $listener->prePersist($entity, new PrePersistEventArgs($entity, $em));

        $this->assertNotNull($entity->getCreatedAt());
        $this->assertNotNull($entity->getUpdatedAt());
        $this->assertSame($user, $entity->getCreatedBy());
    }

    public function testPrePersistWithoutUserLeavesBlameNull(): void
    {
        $entity   = new TestArticle();
        $em       = $this->createMock(EntityManagerInterface::class);
        $listener = $this->createListener(new TokenStorage(), $em);
        $listener->prePersist($entity, new PrePersistEventArgs($entity, $em));

        $this->assertNotNull($entity->getCreatedAt());
        $this->assertNull($entity->getCreatedBy());
    }

    public function testPreUpdateRefreshesUpdatedFieldsOnly(): void
    {
        $entity  = new TestArticle();
        $created = new DateTimeImmutable('-1 day');
        $entity->setCreatedAt($created);
        $entity->setUpdatedAt($created);
        $entity->setCreatedBy(new TestUser(1));

        $user         = new TestUser(99);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $em = $this->createEntityManagerMock($user);

        $listener  = $this->createListener($tokenStorage, $em);
        $changeSet = [];
        $listener->preUpdate($entity, new PreUpdateEventArgs($entity, $em, $changeSet));

        $this->assertSame($created, $entity->getCreatedAt());
        $createdBy = $entity->getCreatedBy();
        $this->assertInstanceOf(TestUser::class, $createdBy);
        $this->assertSame(1, $createdBy->id);
        $this->assertNotSame($created, $entity->getUpdatedAt());
        $updatedBy = $entity->getUpdatedBy();
        $this->assertInstanceOf(TestUser::class, $updatedBy);
        $this->assertSame(99, $updatedBy->id);
    }

    private function createListener(
        TokenStorage $tokenStorage,
        EntityManagerInterface $em,
        ?ProfileRegistry $registry = null,
    ): AuditableEntityListener {
        $registry ??= ProfileRegistryFactory::single(TestUser::class);

        return new AuditableEntityListener(
            registry: $registry,
            propertyResolver: new AuditablePropertyResolver(),
            currentUserResolver: new CurrentUserResolver($tokenStorage),
            entityManager: $em,
        );
    }

    private function createEntityManagerMock(TestUser $user): EntityManagerInterface
    {
        $metadata = new ClassMetadata(TestUser::class);
        $metadata->setIdentifier(['id']);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('getReference')->willReturn($user);

        return $em;
    }
}

class TestArticle
{
    use AuditableTrait;
}

class TestUser implements UserInterface
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
