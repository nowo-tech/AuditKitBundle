<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Profile\ProfileRegistry;
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
        $this->assertSame('default', $container->getParameter('nowo_audit_kit.default_profile'));
        $this->assertTrue($container->hasDefinition(AuditableEntityListener::class));
        $this->assertTrue($container->hasDefinition(ProfileRegistry::class));
    }

    public function testAlias(): void
    {
        $this->assertSame('nowo_audit_kit', (new NowoAuditKitExtension())->getAlias());
    }

    public function testUsesAuthKitUserClassBridge(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_auth_kit.user_class', 'App\\Entity\\AuthUser');

        (new NowoAuditKitExtension())->load([[]], $container);

        $this->assertSame('App\\Entity\\AuthUser', $container->getParameter('nowo_audit_kit.user_class'));
    }

    public function testMissingUserClassThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NowoAuditKitExtension())->load([[]], new ContainerBuilder());
    }

    public function testProfilesConfiguration(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([[
            'default_profile' => 'admin',
            'profiles'        => [
                'app_user' => [
                    'user_class'    => 'App\\Entity\\User',
                    'timestampable' => true,
                ],
                'admin' => [
                    'user_class'    => 'App\\Entity\\Admin',
                    'timestampable' => false,
                ],
            ],
        ]], $container);

        $this->assertSame('App\\Entity\\Admin', $container->getParameter('nowo_audit_kit.user_class'));
        $this->assertTrue($container->hasDefinition(AuditableEntityListener::class));
    }

    public function testDuplicateUserClassThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate user_class');

        (new NowoAuditKitExtension())->load([[
            'profiles' => [
                'one' => ['user_class' => 'App\\Entity\\User'],
                'two' => ['user_class' => 'App\\Entity\\User'],
            ],
        ]], new ContainerBuilder());
    }

    public function testMissingDefaultProfileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NowoAuditKitExtension())->load([[
            'default_profile' => 'missing',
            'profiles'        => [
                'default' => ['user_class' => 'App\\Entity\\User'],
            ],
        ]], new ContainerBuilder());
    }

    public function testMissingProfileUserClassThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NowoAuditKitExtension())->load([[
            'profiles' => [
                'default' => ['user_class' => 'App\\Entity\\User'],
                'admin'   => ['user_class' => ''],
            ],
        ]], new ContainerBuilder());
    }

    public function testRemovesListenerWhenAllProfilesDisabled(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([[
            'user_class' => 'App\\Entity\\User',
            'enabled'    => false,
        ]], $container);

        $this->assertFalse($container->hasDefinition(AuditableEntityListener::class));
    }
}
