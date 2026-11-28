<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Timeline;

use DateTimeImmutable;
use Nowo\AuditKitBundle\Timeline\TimelineCriteria;
use Nowo\AuditKitBundle\Timeline\TimelineCursor;
use Nowo\AuditKitBundle\Timeline\TimelineEvent;
use Nowo\AuditKitBundle\Timeline\TimelinePage;
use Nowo\AuditKitBundle\Timeline\TimelineSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function rtrim;
use function str_repeat;
use function strtr;

final class TimelineCursorTest extends TestCase
{
    public function testTokenRoundTrip(): void
    {
        $cursor = new TimelineCursor(new DateTimeImmutable('2026-01-02 03:04:05.123456+02:00'), 'tasks|x', 'id/é');
        $back   = TimelineCursor::fromToken($cursor->toToken());

        $this->assertNotNull($back);
        $this->assertEquals($cursor, $back);
    }

    public function testBoundForEachSource(): void
    {
        $cursor = new TimelineCursor(new DateTimeImmutable(), 'm', '1');

        $this->assertSame(TimelineCursor::OLDER, $cursor->boundFor('a'));
        $this->assertSame(TimelineCursor::AFTER_ID, $cursor->boundFor('m'));
        $this->assertSame(TimelineCursor::SAME_INSTANT, $cursor->boundFor('z'));
    }

    #[DataProvider('badTokens')]
    public function testMalformedTokensGiveNull(string $token): void
    {
        $this->assertNull(TimelineCursor::fromToken($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', TimelineCursor::MAX_TOKEN_LENGTH + 1)];
        yield 'bad characters' => ['ab+/'];
        yield 'not json' => [self::encode('nope')];
        yield 'missing parts' => [self::encode('["2026-01-01T00:00:00.000000+00:00","a"]')];
        yield 'not strings' => [self::encode('["2026-01-01T00:00:00.000000+00:00","a",1]')];
        yield 'bad date' => [self::encode('["yesterday","a","1"]')];
    }

    public function testValueObjects(): void
    {
        $at    = new DateTimeImmutable('2026-01-01');
        $event = new TimelineEvent('audit', '7', $at, 'item.viewed', 'u1', 'api', 'CI token', 'corr', ['a' => ['from' => 1, 'to' => 2]], ['x' => 1]);
        $page  = new TimelinePage([$event], $event->cursor());

        $this->assertEquals(new TimelineCursor($at, 'audit', '7'), $page->next);
        $this->assertSame('CI token', $event->tokenLabel);
        $this->assertSame('task', (new TimelineSubject('task', '9'))->type);
        $this->assertSame(['k'], (new TimelineCriteria(kinds: ['k']))->kinds);
    }

    private static function encode(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
