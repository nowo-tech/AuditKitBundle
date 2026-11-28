<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Change;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;
use UnitEnum;

use function array_key_exists;
use function array_keys;
use function array_map;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_float;
use function is_infinite;
use function is_nan;
use function is_scalar;
use function is_string;
use function json_encode;
use function mb_scrub;
use function mb_strlen;
use function mb_strtolower;
use function preg_match;
use function sprintf;
use function strlen;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 2.2: builds the {@see ChangeSet} of an action from a before and an after snapshot.
 *
 * - With a `$policy` (the host's allowlist, field => {@see ChangeSet::PLAIN} | {@see ChangeSet::PUBLIC} |
 *   {@see ChangeSet::SECRET} | {@see ChangeSet::LONG_TEXT}) only those fields are compared. A field whose name matches
 *   the secret pattern and is declared `plain` throws {@see UnsafeTraceableFieldException}: a host cannot store a secret
 *   by mistake (declare it `secret`, or `public` when the name only looks like one).
 * - Without a policy every top-level field of both snapshots is compared, and fields whose name matches the secret
 *   pattern are stored as secrets.
 * - Fields listed in `$secretFields` (configuration `action_log.changes.secret_fields`, case-insensitive) are always
 *   stored as secrets, whatever the policy says.
 * - In plain values, nested array keys that match the secret pattern or the secret fields become {@see ChangeSet::MASK}.
 *
 * Sizes are bounded: a string longer than `$maxStringLength` characters is stored as its lengths, and once the JSON of
 * the set would go over `$maxBytes` the remaining fields are cut field by field (see {@see ChangeSet}). The cap is
 * measured on the unescaped UTF-8 JSON; the text a database stores can be larger (escaped `\uXXXX`), except with a
 * binary JSON column type.
 *
 * Values are made storable: invalid UTF-8 in strings and keys is replaced (`mb_scrub()`), NaN / INF floats become the
 * strings `NaN`, `INF`, `-INF`, so a value never makes the JSON column fail at flush.
 *
 * The secret pattern is a heuristic and masks generously (`auth` also matches `author`, `key` matches `sort_key`): use
 * `public` for a field that only looks like a secret, and a policy plus `secret_fields` for the safe path.
 *
 * Stateless (configuration only); safe in a long-running worker.
 *
 * @since 2.2.0
 */
final readonly class ChangeSetBuilder
{
    /** Default secret-looking names (case-insensitive substring match). */
    public const DEFAULT_SECRET_PATTERN = '/password|passwd|passphrase|pwd|secret|token|key|dsn|credential|private|auth|cookie|session|bearer|otp|salt|signature|cvv/i';

    public const DEFAULT_MAX_STRING_LENGTH = 500;

    public const DEFAULT_MAX_BYTES = 16384;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var list<string> lower-cased */
    private array $secretFields;

    /**
     * @param list<string> $secretFields field names always stored as secrets (case-insensitive)
     */
    public function __construct(
        array $secretFields = [],
        private string $secretPattern = self::DEFAULT_SECRET_PATTERN,
        private int $maxStringLength = self::DEFAULT_MAX_STRING_LENGTH,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        if (@preg_match($secretPattern, '') === false) {
            throw new InvalidArgumentException(sprintf('The change secret pattern "%s" is not a valid regular expression.', $secretPattern));
        }
        if ($maxStringLength < 1 || $maxBytes < 64) {
            throw new InvalidArgumentException('The change limits must be at least 1 character per string and 64 bytes per set.');
        }

        $this->secretFields = array_map(mb_strtolower(...), $secretFields);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, string>|null $policy
     *
     * @throws UnsafeTraceableFieldException on a secret-looking `plain` field or an unknown policy
     */
    public function between(array $before, array $after, ?array $policy = null): ChangeSet
    {
        $entries = [];
        foreach ($this->fields($before, $after, $policy) as $field => $mode) {
            $from = array_key_exists($field, $before) ? $this->present($before[$field]) : null;
            $to   = array_key_exists($field, $after) ? $this->present($after[$field]) : null;
            if ($from === $to) {
                continue;
            }

            $entries[mb_scrub((string) $field, 'UTF-8')] = $this->entry($mode, $from, $to);
        }

        return new ChangeSet($this->bounded($entries));
    }

    /** Whether `$field` is stored as a secret without a policy (configured field or secret-looking name). */
    public function isSecret(string $field): bool
    {
        return in_array(mb_strtolower($field), $this->secretFields, true) || preg_match($this->secretPattern, $field) === 1;
    }

    /**
     * The fields to compare, each with its mode.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, string>|null $policy
     *
     * @return array<string, string>
     */
    private function fields(array $before, array $after, ?array $policy): array
    {
        $fields = [];
        if ($policy === null) {
            foreach ([...array_keys($before), ...array_keys($after)] as $field) {
                $field          = (string) $field;
                $fields[$field] = $this->isSecret($field) ? ChangeSet::SECRET : ChangeSet::PLAIN;
            }

            return $fields;
        }

        foreach ($policy as $field => $mode) {
            $field = (string) $field;
            if (!in_array($mode, [ChangeSet::PLAIN, ChangeSet::PUBLIC, ChangeSet::SECRET, ChangeSet::LONG_TEXT], true)) {
                throw UnsafeTraceableFieldException::unknownPolicy($field, (string) $mode);
            }
            $looksSecret = preg_match($this->secretPattern, $field) === 1;
            if ($mode === ChangeSet::PLAIN && $looksSecret) {
                throw UnsafeTraceableFieldException::forField($field);
            }

            // A secret never stores lengths either: `long_text` on a secret-looking or configured field is a secret.
            $secret         = in_array(mb_strtolower($field), $this->secretFields, true) || ($mode === ChangeSet::LONG_TEXT && $looksSecret);
            $fields[$field] = $secret ? ChangeSet::SECRET : $mode;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $mode, mixed $from, mixed $to): array
    {
        if ($mode === ChangeSet::SECRET) {
            return ['changed' => true];
        }

        if ($mode === ChangeSet::LONG_TEXT || $this->isLong($from) || $this->isLong($to)) {
            return ['changed' => true, 'from_len' => $this->length($from), 'to_len' => $this->length($to)];
        }

        return ['from' => $from, 'to' => $to];
    }

    private function isLong(mixed $value): bool
    {
        return is_string($value) && mb_strlen($value, 'UTF-8') > $this->maxStringLength;
    }

    private function length(mixed $value): ?int
    {
        return is_string($value) ? mb_strlen($value, 'UTF-8') : null;
    }

    /**
     * A value as stored: scalars and null as they are, dates as ISO-8601, enums as their value (or name), Stringable
     * objects as their string, arrays recursively (secret-looking keys masked), other objects as their type.
     */
    private function present(mixed $value): mixed
    {
        return match (true) {
            is_string($value)                   => mb_scrub($value, 'UTF-8'),
            is_float($value)                    => $this->presentFloat($value),
            $value === null, is_scalar($value)  => $value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339_EXTENDED),
            $value instanceof BackedEnum        => $value->value,
            $value instanceof UnitEnum          => $value->name,
            $value instanceof Stringable        => (string) $value,
            is_array($value)                    => $this->presentArray($value),
            default                             => '[' . get_debug_type($value) . ']',
        };
    }

    /** A finite float as it is; NaN and the infinities as the markers `NaN`, `INF`, `-INF` (JSON has no such values). */
    private function presentFloat(float $value): float|string
    {
        return match (true) {
            is_nan($value)      => 'NaN',
            is_infinite($value) => $value > 0 ? 'INF' : '-INF',
            default             => $value,
        };
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private function presentArray(array $values): array
    {
        $presented = [];
        foreach ($values as $key => $value) {
            $presented[is_string($key) ? mb_scrub($key, 'UTF-8') : $key] = is_string($key) && $this->isSecret($key) ? ChangeSet::MASK : $this->present($value);
        }

        return $presented;
    }

    /**
     * Keeps entries while the JSON of the set fits `$maxBytes`; then cuts each remaining one to
     * `{changed, truncated}`, and leaves out what does not fit even so (reserved key {@see ChangeSet::TRUNCATED_KEY}).
     *
     * @param array<string, array<string, mixed>> $entries
     *
     * @return array<string, array<string, mixed>|true>
     */
    private function bounded(array $entries): array
    {
        // `{}` plus room for `,"_truncated":true`.
        $budget = $this->maxBytes - 2 - strlen(',"' . ChangeSet::TRUNCATED_KEY . '":true');
        $used   = 0;
        $kept   = [];
        $cut    = false;
        $left   = false;
        foreach ($entries as $field => $entry) {
            $name = (string) json_encode((string) $field, self::JSON_FLAGS);
            if (!$cut) {
                $size = strlen($name) + 1 + strlen((string) json_encode($entry, self::JSON_FLAGS)) + ($kept === [] ? 0 : 1);
                if ($used + $size <= $budget) {
                    $kept[$field] = $entry;
                    $used += $size;

                    continue;
                }
                $cut = true;
            }

            $stub = ['changed' => true, 'truncated' => true];
            $size = strlen($name) + 1 + strlen((string) json_encode($stub, self::JSON_FLAGS)) + ($kept === [] ? 0 : 1);
            if ($used + $size > $budget) {
                $left = true;

                continue;
            }
            $kept[$field] = $stub;
            $used += $size;
        }

        if ($left) {
            $kept[ChangeSet::TRUNCATED_KEY] = true;
        }

        return $kept;
    }
}
