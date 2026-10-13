<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\AuditUserInterface;
use Nowo\AuditKitBundle\Model\CreatedAtImmutableTrait;

/**
 * Append-only action log row (who did what, to whom, in which scope, from which IP).
 *
 * Mapped superclass: the host declares the concrete `#[ORM\Entity]` (table name, indexes, repository) and may rename
 * columns with `#[ORM\AttributeOverrides]` / `#[ORM\AssociationOverrides]` so an existing table maps without a
 * migration. `actor` and `subject` target {@see AuditUserInterface}, resolved to the default profile `user_class`
 * through `doctrine.orm.resolve_target_entities`.
 *
 * Column names are explicit (independent of the naming strategy):
 * `id`, `action` (string 48), `actor_id`, `subject_id` (FK SET NULL), `context` (json), `scope` (string 64, nullable),
 * `ip_address` (string 45, nullable), `created_at` (datetime_immutable).
 *
 * Treat the actor IP and the context as personal data.
 */
#[ORM\MappedSuperclass]
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'createdAt', column: new ORM\Column(name: 'created_at', type: 'datetime_immutable')),
])]
abstract class AbstractActionLog
{
    use CreatedAtImmutableTrait;

    /** Maximum length of the `action` column. */
    public const ACTION_LENGTH = 48;

    /** Default maximum length of the `scope` column (override it per host). */
    public const SCOPE_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id')]
    protected ?int $id = null;

    #[ORM\Column(name: 'action', length: self::ACTION_LENGTH)]
    protected string $action = '';

    /** Authenticated user who performed the action, if any. */
    #[ORM\ManyToOne(targetEntity: AuditUserInterface::class)]
    #[ORM\JoinColumn(name: 'actor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?object $actor = null;

    /** User the action is primarily about, if any. */
    #[ORM\ManyToOne(targetEntity: AuditUserInterface::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?object $subject = null;

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
        $this->action = self::actionValue($action);

        return $this;
    }

    /** True when the stored action equals the given key / enum backing value. */
    public function isAction(string|BackedEnum $action): bool
    {
        return $this->action === self::actionValue($action);
    }

    public function getActor(): ?object
    {
        return $this->actor;
    }

    public function setActor(?object $actor): static
    {
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->actor = $actor;

        return $this;
    }

    public function getSubject(): ?object
    {
        return $this->subject;
    }

    public function setSubject(?object $subject): static
    {
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->subject = $subject;

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

    /** Normalises an action key: enum backing value (cast to string) or the string itself. */
    public static function actionValue(string|BackedEnum $action): string
    {
        return $action instanceof BackedEnum ? (string) $action->value : $action;
    }
}
