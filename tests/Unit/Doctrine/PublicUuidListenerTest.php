<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Nowo\AuditKitBundle\Doctrine\PublicUuidListener;
use Nowo\AuditKitBundle\Model\PublicUuidInterface;
use Nowo\AuditKitBundle\Model\PublicUuidTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PublicUuidListenerTest extends TestCase
{
    public function testAssignsUuidToPublicUuidEntities(): void
    {
        $entity = new ListenerPublicUuidEntity();

        (new PublicUuidListener())->prePersist(new PrePersistEventArgs($entity, $this->createStub(EntityManagerInterface::class)));

        $this->assertTrue(Uuid::isValid($entity->getUuid()));
    }

    public function testKeepsAnAssignedUuid(): void
    {
        $entity = new ListenerPublicUuidEntity();
        $entity->assignUuid('0190a4b2-3c4d-7e5f-8a6b-7c8d9e0f1a2b');

        (new PublicUuidListener())->prePersist(new PrePersistEventArgs($entity, $this->createStub(EntityManagerInterface::class)));

        $this->assertSame('0190a4b2-3c4d-7e5f-8a6b-7c8d9e0f1a2b', $entity->getUuid());
    }

    public function testIgnoresEntitiesWithoutTheInterface(): void
    {
        $entity = new DuckTypedUuidEntity();

        (new PublicUuidListener())->prePersist(new PrePersistEventArgs($entity, $this->createStub(EntityManagerInterface::class)));

        $this->assertFalse($entity->called);
    }
}

class ListenerPublicUuidEntity implements PublicUuidInterface
{
    use PublicUuidTrait;
}

/** Has an ensureUuid() method but does not implement the interface: must be ignored (no duck typing). */
class DuckTypedUuidEntity
{
    public bool $called = false;

    public function ensureUuid(): void
    {
        $this->called = true;
    }
}
