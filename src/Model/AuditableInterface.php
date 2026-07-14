<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

interface AuditableInterface extends TimestampableInterface, BlameableInterface
{
}
