<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Neither listed nor marked: never audited, even with the default field names (unchanged behaviour). */
#[ORM\Entity]
#[ORM\Table(name: 'kernel_unlisted_record')]
class KernelUnlistedRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $updatedAt = null;
}
