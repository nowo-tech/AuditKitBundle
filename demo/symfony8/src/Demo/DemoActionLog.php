<?php

declare(strict_types=1);

namespace App\Demo;

use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;

/** In-memory action log row for the timeline demo (not mapped: the demo does not enable the action log table). */
final class DemoActionLog extends AbstractActionLog
{
}
