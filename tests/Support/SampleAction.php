<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

/** Host-like action catalog (the kit stores the backing value). */
enum SampleAction: string
{
    case Viewed  = 'item.viewed';
    case Updated = 'item.updated';
    case Deleted = 'item.deleted';
}
