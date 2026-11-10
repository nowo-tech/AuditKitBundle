<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Attribute\Auditable;

/** Since 1.13: opted in by `#[Auditable]` alone (`attribute_opt_in: true` in the test kernel), with its own timestamp and blame properties. */
#[Auditable]
#[ORM\Entity]
#[ORM\Table(name: 'kernel_attribute_record')]
class KernelAttributeRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $title = 'attribute';

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: KernelUser::class)]
    public ?KernelUser $createdBy = null;

    #[ORM\ManyToOne(targetEntity: KernelUser::class)]
    public ?KernelUser $updatedBy = null;
}
