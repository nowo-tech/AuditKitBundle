<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\AuditKitBundle\ActionLog\ActionLogCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function rtrim;
use function str_repeat;
use function strtr;

final class ActionLogCursorTest extends TestCase
{
    public function testTheTokenRoundTripsTheWallClockTimeAndId(): void
    {
        $cursor = new ActionLogCursor(new DateTimeImmutable('2026-03-04 05:06:07.123456', new DateTimeZone('Europe/Madrid')), 42);
        $token  = $cursor->toToken();

        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $token);
        $back = ActionLogCursor::fromToken($token);
        $this->assertInstanceOf(ActionLogCursor::class, $back);
        $this->assertSame(42, $back->id);
        $this->assertSame('2026-03-04 05:06:07.123456', $back->createdAt->format('Y-m-d H:i:s.u'));
        $this->assertSame($cursor->createdAt->getTimestamp(), $back->createdAt->getTimestamp());
        $this->assertSame($token, $back->toToken());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('A', ActionLogCursor::MAX_TOKEN_LENGTH + 1)];
        yield 'not url-safe base64' => ['abc+/='];
        yield 'not base64' => ['A'];
        yield 'no separator' => [self::token('2026-03-04T05:06:07.000000+00:00')];
        yield 'id zero' => [self::token('2026-03-04T05:06:07.000000+00:00|0')];
        yield 'id leading zero' => [self::token('2026-03-04T05:06:07.000000+00:00|07')];
        yield 'id overflow' => [self::token('2026-03-04T05:06:07.000000+00:00|99999999999999999999')];
        yield 'negative id' => [self::token('2026-03-04T05:06:07.000000+00:00|-1')];
        yield 'bad date' => [self::token('yesterday|1')];
        yield 'out-of-range date' => [self::token('2026-02-31T05:06:07.000000+00:00|1')];
        yield 'two separators' => [self::token('2026-03-04T05:06:07.000000+00:00|1|2')];
    }

    #[DataProvider('invalidTokens')]
    public function testAnyOtherTokenIsRefused(string $token): void
    {
        $this->assertNull(ActionLogCursor::fromToken($token));
    }

    private static function token(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
