<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Model;

use InvalidArgumentException;
use Nowo\AuditKitBundle\Model\PublicUuidInterface;
use Nowo\AuditKitBundle\Model\PublicUuidTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

use function strlen;

final class PublicUuidTraitTest extends TestCase
{
    public function testUuidIsEmptyByDefault(): void
    {
        $this->assertSame('', (new PublicUuidEntity())->getUuid());
    }

    public function testEnsureUuidAssignsRfc4122UuidV7(): void
    {
        $entity = new PublicUuidEntity();
        $entity->ensureUuid();

        $uuid = $entity->getUuid();
        $this->assertSame(36, strlen($uuid));
        $this->assertTrue(Uuid::isValid($uuid));
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($uuid));
        $this->assertSame(Uuid::fromString($uuid)->toRfc4122(), $uuid);
    }

    public function testEnsureUuidIsIdempotent(): void
    {
        $entity = new PublicUuidEntity();
        $entity->ensureUuid();
        $first = $entity->getUuid();

        $entity->ensureUuid();

        $this->assertSame($first, $entity->getUuid());
    }

    public function testAssignUuidTrimsAndReplacesGeneratedValue(): void
    {
        $entity = new PublicUuidEntity();
        $entity->ensureUuid();

        $entity->assignUuid('  0190a4b2-3c4d-7e5f-8a6b-7c8d9e0f1a2b ');

        $this->assertSame('0190a4b2-3c4d-7e5f-8a6b-7c8d9e0f1a2b', $entity->getUuid());

        $entity->ensureUuid();
        $this->assertSame('0190a4b2-3c4d-7e5f-8a6b-7c8d9e0f1a2b', $entity->getUuid());
    }

    public function testAssignEmptyUuidIsNoOp(): void
    {
        $entity = new PublicUuidEntity();
        $entity->assignUuid('   ');
        $this->assertSame('', $entity->getUuid());

        $entity->ensureUuid();
        $generated = $entity->getUuid();
        $entity->assignUuid('');
        $this->assertSame($generated, $entity->getUuid());
    }

    public function testAssignInvalidUuidThrows(): void
    {
        $entity = new PublicUuidEntity();

        try {
            $entity->assignUuid(' not-a-uuid ');
            $this->fail('An invalid UUID must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('invalid_uuid:not-a-uuid', $exception->getMessage());
        }

        $this->assertSame('', $entity->getUuid());
    }
}

class PublicUuidEntity implements PublicUuidInterface
{
    use PublicUuidTrait;
}
