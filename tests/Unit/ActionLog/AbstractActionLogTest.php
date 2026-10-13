<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog;

use DateTimeImmutable;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Support\SampleAction;
use Nowo\AuditKitBundle\Tests\Support\SampleIntAction;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AbstractActionLogTest extends TestCase
{
    public function testDefaultsAndFluentSetters(): void
    {
        $entry = new KernelActionLog();
        $this->assertNull($entry->getId());
        $this->assertSame('', $entry->getAction());
        $this->assertSame([], $entry->getContext());
        $this->assertNull($entry->getScope());
        $this->assertNull($entry->getIpAddress());
        $this->assertNull($entry->getActor());
        $this->assertNull($entry->getSubject());
        $this->assertLessThanOrEqual(new DateTimeImmutable(), $entry->getCreatedAt());

        $actor   = new stdClass();
        $subject = new stdClass();
        $result  = $entry->setAction(SampleAction::Updated)
            ->setActor($actor)
            ->setSubject($subject)
            ->setContext(['name' => 'Demo'])
            ->setScope('p-1')
            ->setIpAddress('::1');

        $this->assertSame($entry, $result);
        $this->assertSame('item.updated', $entry->getAction());
        $this->assertSame($actor, $entry->getActor());
        $this->assertSame($subject, $entry->getSubject());
        $this->assertSame(['name' => 'Demo'], $entry->getContext());
        $this->assertSame('p-1', $entry->getScope());
        $this->assertSame('::1', $entry->getIpAddress());

        $this->assertNull($entry->setScope('')->getScope());
    }

    public function testActionValueNormalisesEnumsAndStrings(): void
    {
        $this->assertSame('item.viewed', AbstractActionLog::actionValue(SampleAction::Viewed));
        $this->assertSame('7', AbstractActionLog::actionValue(SampleIntAction::Login));
        $this->assertSame('custom.key', AbstractActionLog::actionValue('custom.key'));

        $entry = (new KernelActionLog())->setAction(SampleIntAction::Login);
        $this->assertSame('7', $entry->getAction());
        $this->assertTrue($entry->isAction('7'));
        $this->assertTrue($entry->isAction(SampleIntAction::Login));
    }
}
