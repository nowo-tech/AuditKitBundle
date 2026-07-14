<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class NowoAuditKitExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $container->setParameter('nowo_audit_kit.enabled', $config['enabled']);
        $container->setParameter('nowo_audit_kit.user_class', $config['user_class']);
        $container->setParameter('nowo_audit_kit.fields', $config['fields']);
        $container->setParameter('nowo_audit_kit.timestamp_type', $config['timestamp_type']);
        $container->setParameter('nowo_audit_kit.blameable', $config['blameable']);
        $container->setParameter('nowo_audit_kit.timestampable', $config['timestampable']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}
