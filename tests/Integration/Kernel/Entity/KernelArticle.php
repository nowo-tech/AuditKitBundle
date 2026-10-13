<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\AuditableInterface;
use Nowo\AuditKitBundle\Model\AuditableTrait;

/** Auditable entity with no #[ORM\EntityListeners] mapping (the documented setup). */
#[ORM\Entity]
#[ORM\Table(name: 'kernel_article')]
class KernelArticle implements AuditableInterface
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 255)]
        public string $title,
    ) {
    }
}
