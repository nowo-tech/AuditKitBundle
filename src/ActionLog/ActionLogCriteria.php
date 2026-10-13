<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use DateTimeImmutable;

use function is_array;
use function is_string;

/**
 * Filters for {@see ActionLogRepository::findByCriteria()} / {@see ActionLogRepository::countByCriteria()}.
 *
 * `null` means "no filter"; an empty list for `actions` / `scope` means "match nothing" (an allow-list that
 * allows nothing), so callers passing a computed allow-list never widen the query by accident.
 */
final class ActionLogCriteria
{
    /** @var list<string>|null */
    public readonly ?array $actions;

    /** @var list<string>|null */
    public readonly ?array $scopes;

    /**
     * @param int|object|string|null $actor rows performed by this user (entity or id)
     * @param int|object|string|null $subject rows about this user (entity or id)
     * @param int|object|string|null $involving rows where this user is the actor OR the subject
     * @param array<string>|string|null $scope one scope or a list of scopes
     * @param array<BackedEnum|string>|BackedEnum|string|null $actions one action or an allow-list
     * @param DateTimeImmutable|null $from inclusive lower bound on `createdAt`
     * @param DateTimeImmutable|null $to inclusive upper bound on `createdAt`
     */
    public function __construct(
        public readonly object|int|string|null $actor = null,
        public readonly object|int|string|null $subject = null,
        public readonly object|int|string|null $involving = null,
        string|array|null $scope = null,
        string|BackedEnum|array|null $actions = null,
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $to = null,
        public readonly int $limit = 100,
        public readonly int $offset = 0,
    ) {
        $this->scopes  = $scope === null ? null : array_values(is_string($scope) ? [$scope] : $scope);
        $this->actions = $actions === null ? null : array_values(array_map(
            AbstractActionLog::actionValue(...),
            is_array($actions) ? $actions : [$actions],
        ));
    }

    /** True when an allow-list is empty: the query can short-circuit to no rows. */
    public function matchesNothing(): bool
    {
        return $this->actions === [] || $this->scopes === [];
    }
}
