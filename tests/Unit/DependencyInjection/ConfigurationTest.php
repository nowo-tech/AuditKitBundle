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

        $this->assertSame('default', $config['default_profile']);
        $this->assertNull($config['profiles']['default']['user_class']);
        $this->assertTrue($config['profiles']['default']['enabled']);
        $this->assertSame('createdAt', $config['profiles']['default']['fields']['created_at']);
        $this->assertSame('datetime_immutable', $config['profiles']['default']['timestamp_type']);
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
}
