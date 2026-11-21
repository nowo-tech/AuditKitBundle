<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Nowo\AuditKitBundle\Tests\Support\HostRelationsTestCase;

/** {@see HostRelationsTestCase} on in-memory SQLite. */
final class ActionLogHostRelationsTest extends HostRelationsTestCase
{
    protected function databaseUrl(): ?string
    {
        return null;
    }
}
