<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use DateTimeImmutable;

use function base64_decode;
use function base64_encode;
use function explode;
use function preg_match;
use function rtrim;
use function strlen;
use function strtr;

/**
 * Keyset position in a newest-first action log listing (since 2.1): the `createdAt` and `id` of the last row a page
 * returned. The next page holds the rows strictly older than it (`createdAt < at`, or the same `createdAt` and a
 * smaller `id`), so rows recorded while a host pages through the log never shift, repeat or skip the rows already
 * listed (an offset would).
 *
 * {@see self::toToken()} / {@see self::fromToken()} give an opaque URL-safe string for an HTTP `cursor` parameter.
 * The token is not signed: it only says where to resume, and every page still applies the caller's criteria.
 *
 * @since 2.1.0
 */
final readonly class ActionLogCursor
{
    /** Longest token {@see self::fromToken()} accepts. */
    public const MAX_TOKEN_LENGTH = 128;

    private const FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public DateTimeImmutable $createdAt,
        public int $id,
    ) {
    }

    public function toToken(): string
    {
        return rtrim(strtr(base64_encode($this->createdAt->format(self::FORMAT) . '|' . $this->id), '+/', '-_'), '=');
    }

    /** The cursor a token encodes, `null` for anything {@see self::toToken()} cannot have produced. */
    public static function fromToken(string $token): ?self
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH || preg_match('/\A[A-Za-z0-9_-]+\z/', $token) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || preg_match('/\A[^|]+\|[0-9]+\z/', $decoded) !== 1) {
            return null;
        }

        [$at, $id] = explode('|', $decoded, 2);
        // Canonical positive int only: no leading zero, no overflow past PHP_INT_MAX (the cast would change it).
        if ($id !== (string) (int) $id || (int) $id < 1) {
            return null;
        }

        $createdAt = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $at);
        if (!$createdAt instanceof DateTimeImmutable || $createdAt->format(self::FORMAT) !== $at) {
            return null;
        }

        return new self($createdAt, (int) $id);
    }
}
