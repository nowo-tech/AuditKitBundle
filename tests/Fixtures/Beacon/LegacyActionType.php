<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\Beacon;

/** Stand-in for Beacon's `App\Identity\UserActionType` (stays in the host). */
enum LegacyActionType: string
{
    case UserCreated   = 'user.created';
    case ProjectOpened = 'project.opened';
}
