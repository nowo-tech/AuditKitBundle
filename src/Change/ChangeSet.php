<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Change;

use Countable;
use JsonSerializable;

use function count;

/**
 * Since 2.2: what one action changed, as stored in the action log `changes` column: `{field: entry}` where an entry is.
 *
 * - `{from, to}`: the presented values of a plain field (scalars, null, arrays such as `{label, id}`; dates as
 *   ISO-8601, enums as their value; keys of nested arrays that look like secrets are masked as {@see MASK});
 * - `{changed: true}`: a secret field (the fixed mask: never the value, a hash or a length);
 * - `{changed: true, from_len, to_len}`: a long text, or any string longer than the length limit (500 characters by
 *   default), in characters;
 * - `{changed: true, truncated: true}`: a field cut because the whole set went over the size limit (16 KB of JSON by
 *   default).
 *
 * When even the cut entries do not fit, the remaining fields are left out and the reserved key {@see TRUNCATED_KEY}
 * is `true`.
 *
 * Build one with {@see ChangeSetBuilder} (the `nowo_audit_kit.action_log.changes` configuration: secret fields, limits)
 * or {@see self::between()} (default limits). An empty set means nothing allowlisted changed: skip the row.
 *
 * @since 2.2.0
 */
final readonly class ChangeSet implements Countable, JsonSerializable
{
    /** Policy: compare and store the presented values; refused for names that look like secrets. */
    public const PLAIN = 'plain';

    /** Policy: like {@see PLAIN}, for a field whose name only looks like a secret (`issue_key`, `sort_token`). */
    public const PUBLIC = 'public';

    /** Policy: store only that the value changed. */
    public const SECRET = 'secret';

    /** Policy: store only that the text changed and its lengths. */
    public const LONG_TEXT = 'long_text';

    /** Value that replaces a nested array key that looks like a secret. */
    public const MASK = '[masked]';

    /** Reserved key set when fields had to be left out. */
    public const TRUNCATED_KEY = '_truncated';

    /**
     * @param array<string, array<string, mixed>|true> $entries
     */
    public function __construct(private array $entries = [])
    {
    }

    /**
     * Changes between two snapshots, with the default limits and secret pattern (see {@see ChangeSetBuilder::between()}).
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, string>|null $policy field => {@see PLAIN} | {@see PUBLIC} | {@see SECRET} | {@see LONG_TEXT};
     *                                           only these fields are compared (null: every field, secrets masked)
     *
     * @throws UnsafeTraceableFieldException
     */
    public static function between(array $before, array $after, ?array $policy = null): self
    {
        return (new ChangeSetBuilder())->between($before, $after, $policy);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** Whether fields were cut or left out to fit the size limit. */
    public function isTruncated(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry === true || ($entry['truncated'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>|true>
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * @return array<string, array<string, mixed>|true>
     */
    public function jsonSerialize(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
