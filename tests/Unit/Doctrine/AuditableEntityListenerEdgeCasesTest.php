<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver;
use Nowo\AuditKitBundle\Model\AuditableTrait;
use Nowo\AuditKitBundle\Security\CurrentUserResolver;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class AuditableEntityListenerEdgeCasesTest extends TestCase
{
    public function testSkipsWhenDisabled(): void
    {
        $entity   = new EdgeArticle();
        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'enabled'        => false,
            'timestamp_type' => 'datetime',
        ]);

        $listener = new AuditableEntityListener(
            registry: $registry,
            propertyResolver: new AuditablePropertyResolver(),
            currentUserResolver: new CurrentUserResolver(new TokenStorage()),
            entityManager: $this->createMock(EntityManagerInterface::class),
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $listener->prePersist($entity, new PrePersistEventArgs($entity, $em));

        $this->assertNull($entity->getCreatedAt());
    }

    public function testTimestampOnlyMode(): void
    {
        $entity   = new EdgeArticle();
        $registry = ProfileRegistryFactory::single(TestUser::class, [
            'blameable'      => false,
            'timestamp_type' => 'datetime',
        ]);

        $listener = new AuditableEntityListener(
            registry: $registry,
            propertyResolver: new AuditablePropertyResolver(),
            currentUserResolver: new CurrentUserResolver(new TokenStorage()),
            entityManager: $this->createMock(EntityManagerInterface::class),
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $listener->prePersist($entity, new PrePersistEventArgs($entity, $em));

        $this->assertInstanceOf(DateTimeInterface::class, $entity->getCreatedAt());
        $this->assertNull($entity->getCreatedBy());
    }
}

class EdgeArticle
{
    use AuditableTrait;
}
