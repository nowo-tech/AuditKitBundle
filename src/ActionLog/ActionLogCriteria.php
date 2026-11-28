<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use DateTimeImmutable;
use Stringable;

use function is_array;
use function is_int;

/**
 * Filters for {@see ActionLogRepository::findByCriteria()} / {@see ActionLogRepository::countByCriteria()}.
 *
 * `null` means "no filter"; an empty list for `actions` / `scope` means "match nothing" (an allow-list that
 * allows nothing), so callers passing a computed allow-list never widen the query by accident.
 *
 * Since 2.1 scopes are compared byte for byte (audit YC1-M1), so they are normalised to strings here: an int scope
 * (`5`) or a `Stringable` one (a Uuid) becomes its string form and still matches the stored `'5'` / UUID string, as SQL
 * `IN` matched it before.
 */
final readonly class ActionLogCriteria
{
    /** @var list<string>|null */
    public ?array $actions;

    /** @var list<string>|null */
    public ?array $scopes;

    /**
     * @param int|object|string|null $actor rows performed by this user (entity or id)
     * @param int|object|string|null $subject rows about this user (entity or id)
     * @param int|object|string|null $involving rows where this user is the actor OR the subject
     * @param array<int|string|Stringable>|int|string|Stringable|null $scope one scope or a list of scopes (ints and
     *                                                                       `Stringable` values are cast to string, since 2.1)
     * @param array<BackedEnum|string>|BackedEnum|string|null $actions one action or an allow-list
     * @param DateTimeImmutable|null $from inclusive lower bound on `createdAt`
     * @param DateTimeImmutable|null $to inclusive upper bound on `createdAt`
     * @param string|null $correlationId since 2.2: rows of this unit of work (byte-for-byte match; an entity that does
     *                                   not implement {@see TraceableActionLogInterface} has none)
     */
    public function __construct(
        public object|int|string|null $actor = null,
        public object|int|string|null $subject = null,
        public object|int|string|null $involving = null,
        string|int|Stringable|array|null $scope = null,
        string|BackedEnum|array|null $actions = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public int $limit = 100,
        public int $offset = 0,
        public ?string $correlationId = null,
    ) {
        /** @var list<string>|null $scopes ints and Stringables cast; other values (outside the documented types) as given */
        $scopes        = $scope === null ? null : array_values(array_map($this->scopeValue(...), is_array($scope) ? $scope : [$scope]));
        $this->scopes  = $scopes;
        $this->actions = $actions === null ? null : array_values(array_map(
            AbstractActionLog::actionValue(...),
            is_array($actions) ? $actions : [$actions],
        ));
    }

    /**
     * Since 2.1: the string form of a scope (an int or a `Stringable` is cast), so the exact re-check of the repository
     * compares strings. Any other value is kept as given (outside the documented types; it matches no stored scope).
     */
    private function scopeValue(mixed $scope): mixed
    {
        return is_int($scope) || $scope instanceof Stringable ? (string) $scope : $scope;
    }

    /** True when an allow-list is empty: the query can short-circuit to no rows. */
    public function matchesNothing(): bool
    {
        return $this->actions === [] || $this->scopes === [];
    }
}
