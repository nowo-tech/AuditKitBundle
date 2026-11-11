<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use DateTimeImmutable;

/**
 * Read model of an action log row, shared by both action log superclasses (since 1.14):
 * {@see AbstractActionLog} (actor / subject relations to the user entity, standard hosts) and
 * {@see AbstractIdActionLog} (actor / subject id columns, no foreign key, DDD hosts).
 *
 * @since 1.14.0
 */
interface ActionLogEntryInterface
{
    public function getId(): ?int;

    public function getAction(): string;

    public function isAction(string|BackedEnum $action): bool;

    /** Id of the user who performed the action, as stored or derived from the related user (`null`: system). */
    public function getActorId(): ?string;

    /** Id of the user the action is about, if any. */
    public function getSubjectId(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array;

    public function getScope(): ?string;

    public function getIpAddress(): ?string;

    public function getCreatedAt(): DateTimeImmutable;
}
