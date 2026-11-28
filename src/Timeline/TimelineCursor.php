<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Timeline;

use DateTimeImmutable;

use function base64_decode;
use function base64_encode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function rtrim;
use function strcmp;
use function strlen;
use function strtr;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 2.2: keyset position in a merged, newest-first timeline: the `occurredAt`, `source` and `id` of the last entry a
 * page returned. A timeline is ordered by `occurredAt` (newest first), then by source name (ascending), then by each
 * source's own order, so the next page holds, for a source:
 *
 * - {@see self::OLDER} (its name sorts before the cursor's source): only entries strictly older than `occurredAt`;
 * - {@see self::AFTER_ID} (it is the cursor's source): entries at `occurredAt` after `id` in its own order, then older;
 * - {@see self::SAME_INSTANT} (its name sorts after the cursor's source): entries at `occurredAt` or older.
 *
 * {@see self::toToken()} / {@see self::fromToken()} give an opaque URL-safe string (not signed: it only says where to
 * resume; every page still applies the caller's criteria and permissions).
 *
 * @since 2.2.0
 */
final readonly class TimelineCursor
{
    public const OLDER = 'older';

    public const AFTER_ID = 'after_id';

    public const SAME_INSTANT = 'same_instant';

    /** Longest token {@see self::fromToken()} accepts. */
    public const MAX_TOKEN_LENGTH = 512;

    private const FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public DateTimeImmutable $occurredAt,
        public string $source,
        public string $id,
    ) {
    }

    /** Which entries of `$source` come after this cursor (see the class description). */
    public function boundFor(string $source): string
    {
        $order = strcmp($source, $this->source);

        return match (true) {
            $order < 0 => self::OLDER,
            $order > 0 => self::SAME_INSTANT,
            default    => self::AFTER_ID,
        };
    }

    public function toToken(): string
    {
        $json = (string) json_encode([$this->occurredAt->format(self::FORMAT), $this->source, $this->id], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** The cursor a token encodes, `null` for anything {@see self::toToken()} cannot have produced. */
    public static function fromToken(string $token): ?self
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH || preg_match('/\A[A-Za-z0-9_-]+\z/', $token) !== 1) {
            return null;
        }

        $decoded = json_decode((string) base64_decode(strtr($token, '-_', '+/'), true), true);
        if (!is_array($decoded) || !isset($decoded[0], $decoded[1], $decoded[2]) || !is_string($decoded[0]) || !is_string($decoded[1]) || !is_string($decoded[2])) {
            return null;
        }

        $at = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $decoded[0]);
        if (!$at instanceof DateTimeImmutable || $at->format(self::FORMAT) !== $decoded[0]) {
            return null;
        }

        return new self($at, $decoded[1], $decoded[2]);
    }
}
