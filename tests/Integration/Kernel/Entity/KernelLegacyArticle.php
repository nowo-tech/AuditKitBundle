<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Model\TimestampableTrait;

/** Pre-fix setup: the listener mapped explicitly as an entity listener must still run (exactly once). */
#[ORM\Entity]
#[ORM\Table(name: 'kernel_legacy_article')]
#[ORM\EntityListeners([AuditableEntityListener::class])]
class KernelLegacyArticle
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $title = 'legacy';
}
