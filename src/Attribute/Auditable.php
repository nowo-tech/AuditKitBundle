<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Attribute;

use Attribute;

/** Opt-in/out marker for the Doctrine auditing listener on a class. */
#[Attribute(Attribute::TARGET_CLASS)]
final class Auditable
{
    public function __construct(
        public readonly bool $enabled = true,
    ) {
    }
}
