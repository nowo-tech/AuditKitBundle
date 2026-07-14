<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit;

use Nowo\AuditKitBundle\NowoAuditKitBundle;
use PHPUnit\Framework\TestCase;

final class NowoAuditKitBundleTest extends TestCase
{
    public function testExtensionAlias(): void
    {
        $bundle = new NowoAuditKitBundle();
        $this->assertSame('nowo_audit_kit', $bundle->getContainerExtension()->getAlias());
    }
}
