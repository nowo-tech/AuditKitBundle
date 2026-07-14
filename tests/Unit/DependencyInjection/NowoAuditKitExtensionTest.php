<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoAuditKitExtensionTest extends TestCase
{
    public function testLoadSetsParameters(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([[
            'user_class' => 'App\\Entity\\User',
            'fields'     => [
                'created_at' => 'createdAt',
                'updated_at' => 'updatedAt',
                'created_by' => 'createdBy',
                'updated_by' => 'updatedBy',
            ],
        ]], $container);

        $this->assertTrue($container->getParameter('nowo_audit_kit.enabled'));
        $this->assertSame('App\\Entity\\User', $container->getParameter('nowo_audit_kit.user_class'));
        $this->assertTrue($container->hasDefinition(\Nowo\AuditKitBundle\Doctrine\AuditableEntityListener::class));
    }

    public function testAlias(): void
    {
        $this->assertSame('nowo_audit_kit', (new NowoAuditKitExtension())->getAlias());
    }
}
