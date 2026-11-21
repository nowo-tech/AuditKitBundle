<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\ORM\Mapping as ORM;

/** The root of a single-table hierarchy: a reference to it is looked up (the subclass is not known from the id). */
#[ORM\Entity]
#[ORM\Table(name: 'hr_party')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['party' => HostParty::class, 'person' => HostPerson::class])]
class HostParty
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 64)]
        public string $name = '',
    ) {
    }
}
