<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

/** Contract for entities with both timestamp and blame audit fields. */
interface AuditableInterface extends TimestampableInterface, BlameableInterface
{
}
