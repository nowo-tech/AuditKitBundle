<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog;

use DateTimeImmutable;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\Tests\Support\SampleAction;
use PHPUnit\Framework\TestCase;

final class ActionLogCriteriaTest extends TestCase
{
    public function testDefaultsMeanNoFilter(): void
    {
        $criteria = new ActionLogCriteria();

        $this->assertNull($criteria->actor);
        $this->assertNull($criteria->subject);
        $this->assertNull($criteria->involving);
        $this->assertNull($criteria->scopes);
        $this->assertNull($criteria->actions);
        $this->assertNull($criteria->from);
        $this->assertNull($criteria->to);
        $this->assertSame(100, $criteria->limit);
        $this->assertSame(0, $criteria->offset);
        $this->assertFalse($criteria->matchesNothing());
    }

    public function testNormalisesScopesAndActions(): void
    {
        $from     = new DateTimeImmutable('-1 day');
        $criteria = new ActionLogCriteria(actor: 1, subject: 'u-2', involving: 3, scope: 'p-1', actions: SampleAction::Viewed, from: $from, limit: 5, offset: 10);

        $this->assertSame(['p-1'], $criteria->scopes);
        $this->assertSame(['item.viewed'], $criteria->actions);
        $this->assertSame(1, $criteria->actor);
        $this->assertSame('u-2', $criteria->subject);
        $this->assertSame(3, $criteria->involving);
        $this->assertSame($from, $criteria->from);
        $this->assertSame(5, $criteria->limit);
        $this->assertSame(10, $criteria->offset);

        $list = new ActionLogCriteria(scope: [2 => 'a', 5 => 'b'], actions: ['x' => SampleAction::Updated, 'y' => 'raw']);
        $this->assertSame(['a', 'b'], $list->scopes);
        $this->assertSame(['item.updated', 'raw'], $list->actions);
        $this->assertSame('raw', (new ActionLogCriteria(actions: 'raw'))->actions[0] ?? null);
    }

    public function testEmptyAllowListsMatchNothing(): void
    {
        $this->assertTrue((new ActionLogCriteria(actions: []))->matchesNothing());
        $this->assertTrue((new ActionLogCriteria(scope: []))->matchesNothing());
    }
}
