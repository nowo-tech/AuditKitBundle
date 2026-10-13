<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Model;

use DateTimeImmutable;
use Nowo\AuditKitBundle\Model\CreatedAtImmutableTrait;
use PHPUnit\Framework\TestCase;

final class CreatedAtImmutableTraitTest extends TestCase
{
    public function testInitializeCreatedAtSetsCurrentTime(): void
    {
        $before = new DateTimeImmutable();
        $entity = new CreatedAtImmutableEntity();
        $after  = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $entity->getCreatedAt());
        $this->assertLessThanOrEqual($after, $entity->getCreatedAt());
    }
}

class CreatedAtImmutableEntity
{
    use CreatedAtImmutableTrait;

    public function __construct()
    {
        $this->initializeCreatedAt();
    }
}
