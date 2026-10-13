<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\Doctrine\AuditableDoctrineListener;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Model\AuditUserInterface;
use Nowo\AuditKitBundle\Profile\ProfileRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

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

    public function testExplicitDefaultProfileSurvivesLaterFileWithoutIt(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([
            [
                'default_profile' => 'admin',
                'profiles'        => [
                    'app_user' => ['user_class' => 'App\\Entity\\User'],
                    'admin'    => ['user_class' => 'App\\Entity\\Admin'],
                ],
            ],
            [
                'profiles' => [
                    'app_user' => ['timestampable' => false],
                ],
            ],
        ], $container);

        $this->assertSame('admin', $container->getParameter('nowo_audit_kit.default_profile'));
        $this->assertSame('App\\Entity\\Admin', $container->getParameter('nowo_audit_kit.user_class'));
    }

    public function testDefaultProfileFallsBackToFirstMergedProfile(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([
            ['profiles' => ['app_user' => ['user_class' => 'App\\Entity\\User']]],
            ['profiles' => ['admin' => ['user_class' => 'App\\Entity\\Admin']]],
        ], $container);

        $this->assertSame('app_user', $container->getParameter('nowo_audit_kit.default_profile'));
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
        $this->assertFalse($container->hasDefinition(AuditableDoctrineListener::class));
    }

    public function testRegistersGlobalDoctrineListenerInsteadOfUnattachedEntityListenerTags(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([['user_class' => 'App\\Entity\\User']], $container);

        $events = array_column($container->getDefinition(AuditableDoctrineListener::class)->getTag('doctrine.event_listener'), 'event');
        $this->assertSame(['prePersist', 'preUpdate'], $events);

        foreach ($container->getDefinition(AuditableEntityListener::class)->getTag('doctrine.orm.entity_listener') as $attributes) {
            $this->assertArrayNotHasKey('event', $attributes);
        }
    }

    public function testPrependSkipsWithoutDoctrineExtension(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('nowo_audit_kit', ['user_class' => 'App\\Entity\\User']);

        (new NowoAuditKitExtension())->prepend($container);

        $this->assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testPrependResolvesAuditUserInterfaceToDefaultProfileUserClass(): void
    {
        $container = $this->createContainerWithDoctrineExtension();
        $container->prependExtensionConfig('nowo_audit_kit', [
            'default_profile' => 'admin',
            'profiles'        => [
                'app_user' => ['user_class' => 'App\\Entity\\User'],
                'admin'    => ['user_class' => 'App\\Entity\\Admin'],
            ],
        ]);

        (new NowoAuditKitExtension())->prepend($container);

        $this->assertSame(
            [['orm' => ['resolve_target_entities' => [AuditUserInterface::class => 'App\\Entity\\Admin']]]],
            $container->getExtensionConfig('doctrine'),
        );
    }

    public function testPrependKeepsExplicitDefaultProfileAcrossFiles(): void
    {
        $container = $this->createContainerWithDoctrineExtension();
        // prependExtensionConfig() inserts at the front: the second file is added first.
        $container->prependExtensionConfig('nowo_audit_kit', [
            'profiles' => ['app_user' => ['timestampable' => false]],
        ]);
        $container->prependExtensionConfig('nowo_audit_kit', [
            'default_profile' => 'admin',
            'profiles'        => [
                'app_user' => ['user_class' => 'App\\Entity\\User'],
                'admin'    => ['user_class' => 'App\\Entity\\Admin'],
            ],
        ]);

        (new NowoAuditKitExtension())->prepend($container);

        $this->assertSame(
            [['orm' => ['resolve_target_entities' => [AuditUserInterface::class => 'App\\Entity\\Admin']]]],
            $container->getExtensionConfig('doctrine'),
        );
    }

    /**
     * @param array<string, mixed> $authKitConfig
     */
    #[DataProvider('authKitConfigProvider')]
    public function testPrependFallsBackToAuthKitUserClass(array $authKitConfig): void
    {
        $container = $this->createContainerWithDoctrineExtension();
        $container->prependExtensionConfig('nowo_auth_kit', $authKitConfig);

        (new NowoAuditKitExtension())->prepend($container);

        $this->assertSame(
            [['orm' => ['resolve_target_entities' => [AuditUserInterface::class => 'App\\Entity\\AuthUser']]]],
            $container->getExtensionConfig('doctrine'),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function authKitConfigProvider(): iterable
    {
        yield 'flat' => [['user_class' => 'App\\Entity\\AuthUser']];
        yield 'first profile' => [['profiles' => ['main' => ['user_class' => 'App\\Entity\\AuthUser']]]];
        yield 'default profile' => [[
            'default_profile' => 'admin',
            'profiles'        => [
                'main'  => ['user_class' => 'App\\Entity\\Other'],
                'admin' => ['user_class' => 'App\\Entity\\AuthUser'],
            ],
        ]];
    }

    public function testPrependSkipsWhenNoUserClassIsKnown(): void
    {
        $container = $this->createContainerWithDoctrineExtension();
        $container->prependExtensionConfig('nowo_auth_kit', ['profiles' => ['main' => []]]);

        (new NowoAuditKitExtension())->prepend($container);

        $this->assertSame([], $container->getExtensionConfig('doctrine'));
    }

    private function createContainerWithDoctrineExtension(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $doctrine  = $this->createStub(ExtensionInterface::class);
        $doctrine->method('getAlias')->willReturn('doctrine');
        $container->registerExtension($doctrine);

        return $container;
    }
}
