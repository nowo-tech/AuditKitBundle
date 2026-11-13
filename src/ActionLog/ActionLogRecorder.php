<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\AuditKitBundle\Model\ReferenceId;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function in_array;
use function is_int;
use function is_string;
use function mb_strlen;
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
 *
 * Since 1.15, with `action_log.flush_mode: isolated` ({@see FLUSH_ISOLATED}), the two `*AndFlush()` methods write
 * only the new row, through a short-lived entity manager of the bundle on the same connection, configuration and
 * event manager: the application's unit of work is neither flushed nor cleared, and the returned row is detached.
 * Actor and subject entities must then already be stored (they are referenced by id). The default
 * ({@see FLUSH_HOST}) keeps the behaviour of earlier versions.
 */
final readonly class ActionLogRecorder implements ActionLogRecorderInterface, ActionLogIdRecorderInterface
{
    /** `*AndFlush()` flush the entity manager that maps the action log class (every pending change). */
    public const FLUSH_HOST = 'host';

    /** Since 1.15: `*AndFlush()` write only the new row through a bundle-owned entity manager. */
    public const FLUSH_ISOLATED = 'isolated';

    /**
     * @param class-string<AbstractActionLog>|class-string<AbstractIdActionLog> $entityClass
     * @param string|null $scopeContextKey context key copied into `scope` when no scope is given
     * @param string $flushMode since 1.15: {@see FLUSH_HOST} or {@see FLUSH_ISOLATED}
     * @param LoggerInterface|null $logger since 1.15.1: warned when an unusable user id is stored as `null`
     */
    public function __construct(
        private ManagerRegistry $registry,
        private RequestStack $requestStack,
        private string $entityClass,
        private ?string $scopeContextKey = null,
        private bool $captureIp = true,
        private string $flushMode = self::FLUSH_HOST,
        private ?LoggerInterface $logger = null,
    ) {
        if ($flushMode !== self::FLUSH_HOST && $flushMode !== self::FLUSH_ISOLATED) {
            throw new InvalidArgumentException(sprintf('Unknown action log flush mode "%s" (expected "%s" or "%s").', $flushMode, self::FLUSH_HOST, self::FLUSH_ISOLATED));
        }
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
        return $this->persist($this->entityManager(), $this->newObjectEntry($actor, $subject), $action, $context, $scope);
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
        if ($this->flushMode === self::FLUSH_ISOLATED) {
            return $this->isolated(function (EntityManagerInterface $own, EntityManagerInterface $host) use ($action, $actor, $subject, $context, $scope): AbstractActionLog {
                $entry = $this->newObjectEntry($this->isolatedReference($own, $host, 'actor', $actor), $this->isolatedReference($own, $host, 'subject', $subject));

                return $this->persist($own, $entry, $action, $context, $scope);
            });
        }

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
        return $this->persistIds($this->entityManager(), $action, $actorId, $subjectId, $context, $scope);
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
        if ($this->flushMode === self::FLUSH_ISOLATED) {
            return $this->isolated(fn (EntityManagerInterface $own): ActionLogEntryInterface => $this->persistIds($own, $action, $actorId, $subjectId, $context, $scope));
        }

        $entry = $this->recordIds($action, $actorId, $subjectId, $context, $scope);
        $this->entityManager()->flush();

        return $entry;
    }

    /** @return class-string<AbstractActionLog>|class-string<AbstractIdActionLog> */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    private function newObjectEntry(?object $actor, ?object $subject): AbstractActionLog
    {
        $entry = new ($this->entityClass)();
        if (!$entry instanceof AbstractActionLog) {
            throw new LogicException(sprintf('The action log class "%s" references users by id: record rows with %s::recordIds().', $this->entityClass, ActionLogIdRecorderInterface::class));
        }

        $entry->setActor($actor);
        $entry->setSubject($subject);

        return $entry;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function persistIds(EntityManagerInterface $manager, string|BackedEnum $action, mixed $actorId, mixed $subjectId, array $context, ?string $scope): ActionLogEntryInterface
    {
        $entry = new ($this->entityClass)();
        if ($entry instanceof AbstractIdActionLog) {
            $entry->setActorId($this->storableId($manager, 'actorId', $actorId));
            $entry->setSubjectId($this->storableId($manager, 'subjectId', $subjectId));
        } else {
            $entry->setActor($this->userReference($manager, 'actor', $actorId));
            $entry->setSubject($this->userReference($manager, 'subject', $subjectId));
        }

        return $this->persist($manager, $entry, $action, $context, $scope);
    }

    /**
     * Since 1.15 ({@see FLUSH_ISOLATED}): runs $write on a new entity manager sharing the connection, configuration and
     * event manager of the one that maps the action log class, flushes it, then clears and closes it. The application
     * entity manager is never flushed nor cleared. Inside an open transaction of the connection the row commits or
     * rolls back with it.
     *
     * @template E of ActionLogEntryInterface
     *
     * @param callable(EntityManagerInterface, EntityManagerInterface): E $write
     *
     * @return E
     */
    private function isolated(callable $write): ActionLogEntryInterface
    {
        $host   = $this->entityManager();
        $events = $host->getEventManager();
        $own    = new EntityManager($host->getConnection(), $host->getConfiguration(), $events instanceof EventManager ? $events : null);

        try {
            $entry = $write($own, $host);
            $own->flush();

            return $entry;
        } finally {
            // Never keep managed entities across calls (worker mode, REQ-RUNTIME-002 rule 4).
            $own->clear();
            $own->close();
        }
    }

    /**
     * Since 1.15 ({@see FLUSH_ISOLATED}): a reference of the bundle entity manager to the stored actor / subject entity.
     *
     * Only an entity the host entity manager manages and has already written (not scheduled for insertion) counts as
     * stored: a persisted entity with an assigned id (UUID) may not exist in the database yet, and a detached or new
     * one is not known to exist.
     *
     * @throws LogicException when the entity is not an instance of the association target or is not stored yet
     */
    private function isolatedReference(EntityManagerInterface $own, EntityManagerInterface $host, string $association, ?object $user): ?object
    {
        if ($user === null) {
            return null;
        }

        $target = $own->getClassMetadata($this->entityClass)->getAssociationTargetClass($association);
        if (!$user instanceof $target) {
            throw new LogicException(sprintf('The action log %s must be an instance of "%s", "%s" given.', $association, $target, $user::class));
        }

        $uow = $host->getUnitOfWork();
        $id  = $host->getClassMetadata($target)->getIdentifierValues($user);
        if ($id === [] || in_array(null, $id, true)
            || $uow->getEntityState($user, UnitOfWork::STATE_NEW) !== UnitOfWork::STATE_MANAGED
            || $uow->isScheduledForInsert($user)) {
            throw new LogicException(sprintf('With "nowo_audit_kit.action_log.flush_mode: isolated" the action log %s must already be stored and managed by the entity manager (it is new, detached or not flushed yet): flush it first, or call record() and flush yourself.', $association));
        }

        return $own->getReference($target, $id);
    }

    /**
     * @template E of AbstractActionLog|AbstractIdActionLog
     *
     * @param E $entry
     * @param array<string, mixed> $context
     *
     * @return E
     */
    private function persist(EntityManagerInterface $manager, AbstractActionLog|AbstractIdActionLog $entry, string|BackedEnum $action, array $context, ?string $scope): AbstractActionLog|AbstractIdActionLog
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

        $manager->persist($entry);

        return $entry;
    }

    /**
     * Since 1.15.1 (REQ-SEC-006): the id to store in an id-mode column, or null (with a warning) when the given id is
     * refused by {@see ReferenceId} (edge whitespace, control or format characters, invalid UTF-8) or longer than the
     * column. Never trimmed or truncated: a look-alike of another user's id must not be stored as theirs.
     */
    private function storableId(EntityManagerInterface $manager, string $field, mixed $reference): ?string
    {
        $id     = ReferenceId::of($reference);
        $length = $manager->getClassMetadata($this->entityClass)->getFieldMapping($field)['length'] ?? null;
        if ($id !== null && is_int($length) && mb_strlen($id, 'UTF-8') > $length) {
            $id = null;
        }
        if ($id === null && is_string($reference) && $reference !== '' && $reference !== '0') {
            $this->logger?->warning('Action log {field} not stored: the id is not usable (whitespace at an edge, control or format characters, invalid UTF-8 or longer than the column).', ['bundle' => 'NowoAuditKitBundle', 'field' => $field]);
        }

        return $id;
    }

    /** Doctrine reference to the target entity of the `actor` / `subject` association, or null for "no user". */
    private function userReference(EntityManagerInterface $manager, string $association, mixed $id): ?object
    {
        $id = ReferenceId::of($id);
        if ($id === null) {
            return null;
        }

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
