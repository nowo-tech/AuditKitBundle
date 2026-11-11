<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuditKitBundle\Model\ReferenceId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function is_int;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Creates and persists action log rows of the configured `nowo_audit_kit.action_log.entity_class`.
 *
 * {@see record()} only persists (the caller flushes with its own writes); {@see recordAndFlush()} flushes at once
 * (navigation / view events with no other writes), only the entity manager that maps the action log class. The
 * client IP comes from the current request (`RequestStack`).
 *
 * Since 1.14 {@see recordIds()} records by user id, with an {@see AbstractIdActionLog} (ids stored as they are) or an
 * {@see AbstractActionLog} (ids turned into references). {@see record()} needs an {@see AbstractActionLog} class.
 */
final readonly class ActionLogRecorder implements ActionLogRecorderInterface, ActionLogIdRecorderInterface
{
    /**
     * @param class-string<AbstractActionLog>|class-string<AbstractIdActionLog> $entityClass
     * @param string|null $scopeContextKey context key copied into `scope` when no scope is given
     */
    public function __construct(
        private ManagerRegistry $registry,
        private RequestStack $requestStack,
        private string $entityClass,
        private ?string $scopeContextKey = null,
        private bool $captureIp = true,
    ) {
    }

    /**
     * Creates and persists a row (not flushed).
     *
     * @param array<string, mixed> $context UI-facing metadata (names, roles, UUIDs, …)
     */
    public function record(
        string|BackedEnum $action,
        ?object $actor = null,
        ?object $subject = null,
        array $context = [],
        ?string $scope = null,
    ): AbstractActionLog {
        $entry = new ($this->entityClass)();
        if (!$entry instanceof AbstractActionLog) {
            throw new LogicException(sprintf('The action log class "%s" references users by id: record rows with %s::recordIds().', $this->entityClass, ActionLogIdRecorderInterface::class));
        }

        $entry->setActor($actor);
        $entry->setSubject($subject);

        return $this->persist($entry, $action, $context, $scope);
    }

    /**
     * Creates, persists and flushes a row.
     *
     * @param array<string, mixed> $context
     */
    public function recordAndFlush(
        string|BackedEnum $action,
        ?object $actor = null,
        ?object $subject = null,
        array $context = [],
        ?string $scope = null,
    ): AbstractActionLog {
        $entry = $this->record($action, $actor, $subject, $context, $scope);
        $this->entityManager()->flush();

        return $entry;
    }

    /**
     * Since 1.14: creates and persists a row from user ids (not flushed).
     *
     * @param array<string, mixed> $context
     */
    public function recordIds(
        string|BackedEnum $action,
        mixed $actorId = null,
        mixed $subjectId = null,
        array $context = [],
        ?string $scope = null,
    ): ActionLogEntryInterface {
        $entry = new ($this->entityClass)();
        if ($entry instanceof AbstractIdActionLog) {
            $entry->setActorId($actorId);
            $entry->setSubjectId($subjectId);
        } else {
            $entry->setActor($this->userReference('actor', $actorId));
            $entry->setSubject($this->userReference('subject', $subjectId));
        }

        return $this->persist($entry, $action, $context, $scope);
    }

    /**
     * Since 1.14: creates, persists and flushes a row from user ids.
     *
     * @param array<string, mixed> $context
     */
    public function recordIdsAndFlush(
        string|BackedEnum $action,
        mixed $actorId = null,
        mixed $subjectId = null,
        array $context = [],
        ?string $scope = null,
    ): ActionLogEntryInterface {
        $entry = $this->recordIds($action, $actorId, $subjectId, $context, $scope);
        $this->entityManager()->flush();

        return $entry;
    }

    /** @return class-string<AbstractActionLog>|class-string<AbstractIdActionLog> */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /**
     * @template E of AbstractActionLog|AbstractIdActionLog
     *
     * @param E $entry
     * @param array<string, mixed> $context
     *
     * @return E
     */
    private function persist(AbstractActionLog|AbstractIdActionLog $entry, string|BackedEnum $action, array $context, ?string $scope): AbstractActionLog|AbstractIdActionLog
    {
        $entry->setAction($action);
        $entry->setContext($context);
        $entry->setScope($scope ?? $this->scopeFromContext($context));

        if ($this->captureIp) {
            $request = $this->requestStack->getCurrentRequest();
            if ($request instanceof Request) {
                $entry->setIpAddress($request->getClientIp());
            }
        }

        $this->entityManager()->persist($entry);

        return $entry;
    }

    /** Doctrine reference to the target entity of the `actor` / `subject` association, or null for "no user". */
    private function userReference(string $association, mixed $id): ?object
    {
        $id = ReferenceId::of($id);
        if ($id === null) {
            return null;
        }

        $manager = $this->entityManager();

        return $manager->getReference($manager->getClassMetadata($this->entityClass)->getAssociationTargetClass($association), $id);
    }

    /**
     * Copies `context[scope_context_key]` into `scope` when it is a non-empty string that fits the column.
     *
     * @param array<string, mixed> $context
     */
    private function scopeFromContext(array $context): ?string
    {
        if ($this->scopeContextKey === null) {
            return null;
        }

        $value = $context[$this->scopeContextKey] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        $length = $this->entityManager()->getClassMetadata($this->entityClass)->getFieldMapping('scope')['length'] ?? null;

        return is_int($length) && strlen($value) > $length ? null : $value;
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass($this->entityClass);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine ORM entity manager manages the action log class "%s".', $this->entityClass));
        }

        return $manager;
    }
}
