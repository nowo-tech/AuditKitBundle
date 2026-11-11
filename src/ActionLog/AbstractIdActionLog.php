<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\CreatedAtImmutableTrait;
use Nowo\AuditKitBundle\Model\ReferenceId;

/**
 * Append-only action log row that references users **by id** (since 1.14): `actor_id` / `subject_id` are plain string
 * columns with no foreign key and no relation to a user entity, so a DDD / hexagonal host (UUID or int ids, users in
 * another bounded context or service) can use the action log without `user_class` or `resolve_target_entities`.
 *
 * Mapped superclass, like {@see AbstractActionLog}: the host declares the concrete entity (attributes or its own
 * XML mapping) and may override columns. The bundle ships the mapping as attributes and as XML
 * (`Resources/config/doctrine/action_log/AbstractIdActionLog.orm.xml`, `action_log.mapping: xml`).
 *
 * Column names are explicit: `id`, `action` (string 48), `actor_id`, `subject_id` (string 64, nullable),
 * `context` (json), `scope` (string 64, nullable), `ip_address` (string 45, nullable), `created_at`
 * (datetime_immutable). Record rows with {@see ActionLogIdRecorderInterface::recordIds()}.
 *
 * Treat the actor IP and the context as personal data.
 *
 * @since 1.14.0
 */
#[ORM\MappedSuperclass]
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'createdAt', column: new ORM\Column(name: 'created_at', type: 'datetime_immutable')),
])]
abstract class AbstractIdActionLog implements ActionLogEntryInterface
{
    use CreatedAtImmutableTrait;

    /** Maximum length of the `action` column. */
    public const ACTION_LENGTH = AbstractActionLog::ACTION_LENGTH;

    /** Default maximum length of the `scope` column (override it per host). */
    public const SCOPE_LENGTH = AbstractActionLog::SCOPE_LENGTH;

    /** Default maximum length of the `actor_id` / `subject_id` columns (a UUID is 36). */
    public const REFERENCE_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id')]
    protected ?int $id = null;

    #[ORM\Column(name: 'action', length: self::ACTION_LENGTH)]
    protected string $action = '';

    /** Id of the user who performed the action, if any. */
    #[ORM\Column(name: 'actor_id', length: self::REFERENCE_LENGTH, nullable: true)]
    protected ?string $actorId = null;

    /** Id of the user the action is primarily about, if any. */
    #[ORM\Column(name: 'subject_id', length: self::REFERENCE_LENGTH, nullable: true)]
    protected ?string $subjectId = null;

    /**
     * Structured details for the UI (names, roles, UUIDs, …).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'context', type: 'json')]
    protected array $context = [];

    /** Optional indexed partition key (project / tenant / group UUID, …). */
    #[ORM\Column(name: 'scope', length: self::SCOPE_LENGTH, nullable: true)]
    protected ?string $scope = null;

    /** Client IP of the request that triggered the action, when available. */
    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    protected ?string $ipAddress = null;

    public function __construct()
    {
        $this->initializeCreatedAt();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /** Stores the enum backing value (or the string) as the action key. */
    public function setAction(string|BackedEnum $action): static
    {
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->action = AbstractActionLog::actionValue($action);

        return $this;
    }

    public function isAction(string|BackedEnum $action): bool
    {
        return $this->action === AbstractActionLog::actionValue($action);
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /** Accepts an id (int, string, `Stringable` id object) or an object with `getId()`; see {@see ReferenceId}. */
    public function setActorId(mixed $actorId): static
    {
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->actorId = ReferenceId::of($actorId);

        return $this;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    /** Accepts an id (int, string, `Stringable` id object) or an object with `getId()`; see {@see ReferenceId}. */
    public function setSubjectId(mixed $subjectId): static
    {
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->subjectId = ReferenceId::of($subjectId);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): static
    {
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->context = $context;

        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): static
    {
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->scope = $scope === '' ? null : $scope;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->ipAddress = $ipAddress;

        return $this;
    }
}
