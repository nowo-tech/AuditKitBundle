<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuditKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfigurationRequiresUserClass(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[]]);
    }

    public function testProcessesConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => 'App\\Entity\\User',
        ]]);

        $this->assertTrue($config['enabled']);
        $this->assertSame('App\\Entity\\User', $config['user_class']);
        $this->assertSame('createdAt', $config['fields']['created_at']);
        $this->assertSame('datetime_immutable', $config['timestamp_type']);
    }
}
