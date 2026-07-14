<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Profile;

use InvalidArgumentException;

use function sprintf;

final class UnknownProfileException extends InvalidArgumentException
{
    public function __construct(string $profileName)
    {
        parent::__construct(sprintf('Unknown Audit Kit profile "%s".', $profileName));
    }
}
