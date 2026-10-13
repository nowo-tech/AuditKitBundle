<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuditKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        $this->assertNull($config['default_profile'], 'Resolved by the extension after all files are merged.');
        $this->assertNull($config['profiles']['default']['user_class']);
        $this->assertTrue($config['profiles']['default']['enabled']);
        $this->assertSame('createdAt', $config['profiles']['default']['fields']['created_at']);
        $this->assertSame('datetime_immutable', $config['profiles']['default']['timestamp_type']);
    }

    public function testActionLogDefaultsAreDisabled(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        $this->assertSame([
            'entity_class'      => null,
            'register_mapping'  => true,
            'scope_context_key' => null,
            'capture_ip'        => true,
            'purge'             => ['older_than_days' => 90, 'batch_size' => 1000, 'actions' => []],
        ], $config['action_log']);
    }

    public function testActionLogConfigurationIsProcessed(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => 'App\\Entity\\User',
            'action_log' => [
                'entity_class'      => 'App\\Entity\\UserAction',
                'scope_context_key' => 'project_uuid',
                'capture_ip'        => false,
                'purge'             => ['older_than_days' => 7, 'batch_size' => 50, 'actions' => ['project.opened']],
            ],
        ]]);

        $this->assertSame('App\\Entity\\UserAction', $config['action_log']['entity_class']);
        $this->assertSame('project_uuid', $config['action_log']['scope_context_key']);
        $this->assertFalse($config['action_log']['capture_ip']);
        $this->assertSame(['older_than_days' => 7, 'batch_size' => 50, 'actions' => ['project.opened']], $config['action_log']['purge']);
        $this->assertSame('App\\Entity\\User', $config['profiles']['default']['user_class']);
    }

    public function testLegacyFlatConfigurationIsNormalizedToProfiles(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => 'App\\Entity\\User',
            'blameable'  => false,
        ]]);

        $this->assertSame('App\\Entity\\User', $config['profiles']['default']['user_class']);
        $this->assertFalse($config['profiles']['default']['blameable']);
    }

    public function testProcessesProfilesConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'default_profile' => 'admin',
            'profiles'        => [
                'app_user' => [
                    'user_class' => 'App\\Entity\\User',
                ],
                'admin' => [
                    'user_class'    => 'App\\Entity\\Admin',
                    'timestampable' => false,
                ],
            ],
        ]]);

        $this->assertSame('admin', $config['default_profile']);
        $this->assertSame('App\\Entity\\Admin', $config['profiles']['admin']['user_class']);
        $this->assertFalse($config['profiles']['admin']['timestampable']);
    }

    public function testLaterFileWithoutDefaultProfileKeepsExplicitChoice(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [
            [
                'default_profile' => 'admin',
                'profiles'        => [
                    'app_user' => ['user_class' => 'App\\Entity\\User'],
                    'admin'    => ['user_class' => 'App\\Entity\\Admin'],
                ],
            ],
            // e.g. a when@prod block that only tweaks a profile
            [
                'profiles' => [
                    'app_user' => ['timestampable' => false],
                ],
            ],
        ]);

        $this->assertSame('admin', $config['default_profile']);
        $this->assertFalse($config['profiles']['app_user']['timestampable']);
    }
}
