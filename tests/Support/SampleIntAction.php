<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

/** Int-backed catalog: the kit stores the backing value as a string. */
enum SampleIntAction: int
{
    case Login = 7;
}
