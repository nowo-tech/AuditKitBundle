<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\Beacon\Legacy;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Tests\Fixtures\Beacon\LegacyActionType;

/** Copy of the Beacon `App\Identity\Entity\UserAction` mapping before adoption (spec 136 state). */
#[ORM\Entity]
#[ORM\Table(name: 'user_action')]
#[ORM\Index(name: 'idx_user_action_subject_created', columns: ['subject_user_id', 'created_at'])]
#[ORM\Index(name: 'idx_user_action_actor_created', columns: ['actor_id', 'created_at'])]
#[ORM\Index(name: 'idx_user_action_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_user_action_project_created', columns: ['project_uuid', 'created_at'])]
class UserAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 48, enumType: LegacyActionType::class)]
    public LegacyActionType $action = LegacyActionType::UserCreated;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $actor = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $subjectUser = null;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: 'json')]
    public array $context = [];

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $projectUuid = null;

    #[ORM\Column(length: 45, nullable: true)]
    public ?string $ipAddress = null;

    #[ORM\Column]
    public DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }
}
