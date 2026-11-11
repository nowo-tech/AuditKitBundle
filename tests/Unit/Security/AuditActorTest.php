<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Security;

use Nowo\AuditKitBundle\Security\AuditActor;
use PHPUnit\Framework\TestCase;

final class AuditActorTest extends TestCase
{
    public function testKeepsIdAndProfile(): void
    {
        $actor = new AuditActor('u-42', 'staff');

        $this->assertSame('u-42', $actor->id);
        $this->assertSame('staff', $actor->profile);
    }

    public function testEmptyAndZeroIdsAreNotBlamed(): void
    {
        $this->assertNull((new AuditActor(''))->id);
        $this->assertNull((new AuditActor('0'))->id);
        $this->assertNull((new AuditActor())->id);
        $this->assertNull((new AuditActor())->profile);
    }
}
