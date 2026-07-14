<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

trait AuditableTrait
{
    use BlameableTrait;
    use TimestampableTrait;
}
