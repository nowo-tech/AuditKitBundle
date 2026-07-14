<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

/** Combines {@see TimestampableTrait} and {@see BlameableTrait} for full auditing. */
trait AuditableTrait
{
    use BlameableTrait;
    use TimestampableTrait;
}
