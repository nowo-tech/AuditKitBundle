<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelAuditedRecord;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelPlainRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Since 1.13: `auditable_entities` (opt-in without a bundle interface or trait). */
final class AuditableEntitiesConfigTest extends TestCase
{
    public function testDefaultsToNoClass(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([['user_class' => 'App\\Entity\\User']], $container);

        $this->assertSame([], $container->getParameter('nowo_audit_kit.auditable_entities'));
    }

    public function testKeepsExistingClassesAndInterfacesOnce(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([[
            'user_class'         => 'App\\Entity\\User',
            'auditable_entities' => [KernelPlainRecord::class, '\\' . KernelAuditedRecord::class, KernelPlainRecord::class],
        ]], $container);

        $this->assertSame([KernelPlainRecord::class, KernelAuditedRecord::class], $container->getParameter('nowo_audit_kit.auditable_entities'));
    }

    public function testWorksWithProfiles(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([[
            'profiles'           => ['app' => ['user_class' => 'App\\Entity\\User']],
            'auditable_entities' => [KernelPlainRecord::class],
        ]], $container);

        $this->assertSame([KernelPlainRecord::class], $container->getParameter('nowo_audit_kit.auditable_entities'));
    }

    public function testAnUnknownClassIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"App\\Missing\\Record" is not an existing class or interface');

        (new NowoAuditKitExtension())->load([[
            'user_class'         => 'App\\Entity\\User',
            'auditable_entities' => ['App\\Missing\\Record'],
        ]], new ContainerBuilder());
    }
}
