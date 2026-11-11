<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog;

use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\AuditEntry;
use Nowo\AuditKitBundle\Tests\Support\SampleAction;
use Nowo\AuditKitBundle\Tests\Support\SampleIntAction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AbstractIdActionLogTest extends TestCase
{
    public function testAccessors(): void
    {
        $uuid  = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f60';
        $entry = (new AuditEntry())
            ->setAction(SampleIntAction::Login)
            ->setActorId(Uuid::fromString($uuid))
            ->setSubjectId(12)
            ->setContext(['k' => 'v'])
            ->setScope('')
            ->setIpAddress('203.0.113.7');

        $this->assertNull($entry->getId());
        $this->assertSame('7', $entry->getAction());
        $this->assertTrue($entry->isAction(SampleIntAction::Login));
        $this->assertFalse($entry->isAction(SampleAction::Viewed));
        $this->assertSame($uuid, $entry->getActorId());
        $this->assertSame('12', $entry->getSubjectId());
        $this->assertSame(['k' => 'v'], $entry->getContext());
        $this->assertNull($entry->getScope());
        $this->assertSame('203.0.113.7', $entry->getIpAddress());
        $this->assertEqualsWithDelta(time(), $entry->getCreatedAt()->getTimestamp(), 5);

        $entry->setActorId(null)->setSubjectId('0')->setScope('tenant');
        $this->assertNull($entry->getActorId());
        $this->assertNull($entry->getSubjectId());
        $this->assertSame('tenant', $entry->getScope());
    }
}
