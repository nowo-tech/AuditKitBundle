<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\CreatedAtImmutableTrait;
use Nowo\AuditKitBundle\Model\PublicUuidInterface;
use Nowo\AuditKitBundle\Model\PublicUuidTrait;

/** Append-only row with a public UUID (PublicUuidListener + CreatedAtImmutableTrait + SqlLike tests). */
#[ORM\Entity]
#[ORM\Table(name: 'kernel_public_item')]
class KernelPublicItem implements PublicUuidInterface
{
    use CreatedAtImmutableTrait;
    use PublicUuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 255)]
        public string $name,
    ) {
        $this->initializeCreatedAt();
    }
}
