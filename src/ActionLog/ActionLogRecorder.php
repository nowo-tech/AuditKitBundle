<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function is_int;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Creates and persists {@see AbstractActionLog} rows of the configured `nowo_audit_kit.action_log.entity_class`.
 *
 * {@see record()} only persists (the caller flushes with its own writes); {@see recordAndFlush()} flushes at once
 * (navigation / view events with no other writes). The client IP comes from the current request (`RequestStack`).
 */
final readonly class ActionLogRecorder
{
    /**
     * @param class-string<AbstractActionLog> $entityClass
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
        $entry->setAction($action);
        $entry->setActor($actor);
        $entry->setSubject($subject);
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

    /** @return class-string<AbstractActionLog> */
    public function getEntityClass(): string
    {
        return $this->entityClass;
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
