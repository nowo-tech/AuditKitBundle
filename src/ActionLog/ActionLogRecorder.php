<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Proxy;
use InvalidArgumentException;
use LogicException;
use Nowo\AuditKitBundle\Model\ReferenceId;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_keys;
use function get_parent_class;
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
 *
 * Concurrency (since 1.17, REQ-RUNTIME-003, docs/CONCURRENCY.md): rows are append-only inserts. A failed write is never
 * swallowed. In host mode the row belongs to the host's unit of work, so it commits or fails with the host's changes;
 * a host entity manager that is already closed is replaced through the registry before the row is persisted (an open
 * one is never cleared or reset). In isolated mode the row is written through nowo-tech/transaction-doctrine's runner
 * (savepoint-aware repair of the connection); outside a transaction a deadlock or serialization failure is replayed at
 * most {@see MAX_ISOLATED_REPLAYS} times on a new entity manager, and a lock conflict that remains is thrown as
 * {@see ConcurrencyConflict} (`committed` false: the row itself was not written).
 *
 * Since 1.18 (REQ-ARCH-001 rule 6 deprecation layer), when `flush_mode` is left unset the two `*AndFlush()` methods run
 * transaction-doctrine's {@see HostFlushProbe} on the host's entity manager right before they persist the row and
 * flush: each entity class outside this bundle with pending changes (an action log row recorded earlier with record()
 * included) triggers one deprecation per unit of work, because the 2.0 default (`isolated`) no longer flushes them.
 * The probe only reads the unit of work.
 */
final readonly class ActionLogRecorder implements ActionLogRecorderInterface, ActionLogIdRecorderInterface
{
    /** `*AndFlush()` flush the entity manager that maps the action log class (every pending change). */
    public const FLUSH_HOST = 'host';

    /** Since 1.15: `*AndFlush()` write only the new row through a bundle-owned entity manager. */
    public const FLUSH_ISOLATED = 'isolated';

    /** Since 1.17: replays of an isolated write after a deadlock or a serialization failure, outside a transaction. */
    public const MAX_ISOLATED_REPLAYS = 2;

    /** Since 1.18: the bundle name the host-flush probe shows. */
    public const BUNDLE_NAME = 'NowoAuditKitBundle';

    /**
     * Since 1.18: the namespace whose classes the host-flush probe never reports (the action log mapped superclasses).
     * The bundle maps no entity of its own: the action log class is the host's, so its pending rows are reported.
     */
    public const BUNDLE_NAMESPACE = 'Nowo\\AuditKitBundle\\ActionLog';

    /** Since 1.17: `Retry-After` hint (seconds) carried by the {@see ConcurrencyConflict} of an isolated write. */
    public const RETRY_AFTER_SECONDS = 1;

    /**
     * @param class-string<AbstractActionLog>|class-string<AbstractIdActionLog> $entityClass
     * @param string|null $scopeContextKey context key copied into `scope` when no scope is given
     * @param string $flushMode since 1.15: {@see FLUSH_HOST} or {@see FLUSH_ISOLATED}
     * @param LoggerInterface|null $logger since 1.15.1: warned when an unusable user id is stored as `null`
     * @param HostFlushProbe|null $hostFlushProbe since 1.18: set by the bundle only when `flush_mode` is left unset
     */
    public function __construct(
        private ManagerRegistry $registry,
        private RequestStack $requestStack,
        private string $entityClass,
        private ?string $scopeContextKey = null,
        private bool $captureIp = true,
        private string $flushMode = self::FLUSH_HOST,
        private ?LoggerInterface $logger = null,
        private ?HostFlushProbe $hostFlushProbe = null,
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
        $closed  = !$this->entityManager()->isOpen();
        $manager = $this->writableEntityManager();
        if ($closed && $manager->isOpen()) {
            // Since 1.17: the closed manager was replaced; users it loaded are unknown to the new one.
            $actor   = $this->reattached($manager, $actor);
            $subject = $this->reattached($manager, $subject);
        }

        return $this->persist($manager, $this->newObjectEntry($actor, $subject), $action, $context, $scope);
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

        $this->probeHostFlush();
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
        return $this->persistIds($this->writableEntityManager(), $action, $actorId, $subjectId, $context, $scope);
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

        $this->probeHostFlush();
        $entry = $this->recordIds($action, $actorId, $subjectId, $context, $scope);
        $this->entityManager()->flush();

        return $entry;
    }

    /**
     * Since 1.18: reports, through the host-flush probe, the host's pending changes the coming host-mode flush writes.
     *
     * It runs before the new row is persisted, so the row itself is never reported, and only on an open manager: a
     * closed one is replaced with an empty unit of work before the row is written (see writableEntityManager()), so
     * nothing of the host's is flushed then. Read-only: the probe never flushes, clears, closes or resets the manager.
     */
    private function probeHostFlush(): void
    {
        if (!$this->hostFlushProbe instanceof HostFlushProbe) {
            return;
        }

        $manager = $this->entityManager();
        if ($manager->isOpen()) {
            $this->hostFlushProbe->beforeHostFlush($manager, self::BUNDLE_NAME, [self::BUNDLE_NAMESPACE]);
        }
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
     * Since 1.17 the write goes through transaction-doctrine's runner, and lock conflicts are replayed or thrown as
     * {@see ConcurrencyConflict} (see the class description). Other failures are rethrown unchanged.
     *
     * @template E of ActionLogEntryInterface
     *
     * @param callable(EntityManagerInterface, EntityManagerInterface): E $write
     *
     * @throws ConcurrencyConflict on a lock conflict that remains
     *
     * @return E
     */
    private function isolated(callable $write): ActionLogEntryInterface
    {
        $host       = $this->entityManager();
        $connection = $host->getConnection();
        // Outside a transaction a failed attempt rolled back alone, so it may be replayed; inside one it belongs to the host.
        $replayable = !$connection->isTransactionActive();
        $classifier = new TransientFailureClassifier();

        for ($attempt = 1;; ++$attempt) {
            $events = $host->getEventManager();
            $own    = new EntityManager($connection, $host->getConfiguration(), $events instanceof EventManager ? $events : null);

            try {
                // The runner flushes $own and commits; after a failure it rolls the connection back to its entry level.
                return DoctrineTransactionRunner::forEntityManager($own, null, null, self::RETRY_AFTER_SECONDS, $this->logger)
                    ->run(static fn (): ActionLogEntryInterface => $write($own, $host));
            } catch (TransientStorageFailure $failure) {
                // Already decided by an inner unit (it may carry committed = true, CONFLICT_002): never re-wrapped or replayed.
                if ($failure instanceof ConcurrencyConflict) {
                    throw $failure;
                }

                $kind = $classifier->classify($failure) ?? TransientFailureKind::Retryable;
                if (!$replayable || $attempt > self::MAX_ISOLATED_REPLAYS || !in_array($kind, [TransientFailureKind::Deadlock, TransientFailureKind::SerializationFailure], true)) {
                    throw new ConcurrencyConflict($attempt, $kind, self::RETRY_AFTER_SECONDS, $failure->getPrevious() ?? $failure);
                }
            } finally {
                // Never keep managed entities across calls (worker mode, REQ-RUNTIME-002 rule 4).
                $own->clear();
                $own->close();
            }
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

    /**
     * Since 1.17 (REQ-RUNTIME-003 rule 2): the manager that maps the action log class, replaced through the registry
     * when it is already closed (a failed flush of the host closes it; persisting into it would throw). An open
     * manager is returned as it is, never cleared or reset. A closed manager the registry does not list is returned
     * unchanged (persisting then throws as before).
     */
    /**
     * Since 1.17: a reference of $manager to a user entity loaded by the manager it replaced, so the new unit of work
     * does not see it as a new entity through the actor / subject association. A user that is not a mapped entity, or
     * has no identifier yet, is returned as it is (the ORM decides at flush, as before).
     */
    private function reattached(EntityManagerInterface $manager, ?object $user): ?object
    {
        if ($user === null) {
            return null;
        }

        $parent = get_parent_class($user);
        $class  = $user instanceof Proxy && $parent !== false ? $parent : $user::class;
        if ($manager->getMetadataFactory()->isTransient($class)) {
            return $user;
        }

        $metadata = $manager->getClassMetadata($class);
        $id       = $metadata->getIdentifierValues($user);
        if ($id === [] || in_array(null, $id, true)) {
            return $user;
        }

        return $manager->getReference($metadata->getName(), $id);
    }

    private function writableEntityManager(): EntityManagerInterface
    {
        $manager = $this->entityManager();
        if ($manager->isOpen()) {
            return $manager;
        }

        foreach (array_keys($this->registry->getManagerNames()) as $name) {
            if ($this->registry->getManager($name) === $manager) {
                $this->logger?->notice('Action log: replacing the closed entity manager before writing.', ['bundle' => 'NowoAuditKitBundle', 'manager' => $name]);
                $this->registry->resetManager($name);

                return $this->entityManager();
            }
        }

        return $manager;
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
