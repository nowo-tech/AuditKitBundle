<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\ORM\Mapping as ORM;

/** A host entity whose identifier a pre-insert generator assigns during persist() (as a sequence or UUID would). */
#[ORM\Entity]
#[ORM\Table(name: 'hr_keyed_tenant')]
class HostKeyedTenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: RandomKeyGenerator::class)]
    #[ORM\Column(length: 32)]
    public ?string $id = null;

    public function __construct(
        #[ORM\Column(length: 64)]
        public string $name = '',
    ) {
    }
}
