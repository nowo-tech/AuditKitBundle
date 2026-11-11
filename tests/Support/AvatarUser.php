<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

/**
 * Plain (unmapped) user for avatar memo tests; a Doctrine-like proxy subclass is declared at runtime by the test.
 */
class AvatarUser
{
    public function __construct(public ?int $id = null)
    {
    }
}
