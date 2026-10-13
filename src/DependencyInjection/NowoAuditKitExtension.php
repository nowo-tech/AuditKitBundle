<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuditKitBundle\Doctrine\AuditableDoctrineListener;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Model\AuditUserInterface;
use Nowo\AuditKitBundle\Profile\ProfileRegistry;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function is_array;
use function is_string;
use function sprintf;

/** Loads bundle parameters and services from processed configuration. */
final class NowoAuditKitExtension extends Extension implements PrependExtensionInterface
{
    /** Maps {@see AuditUserInterface} (BlameableTrait association target) to the default profile user class. */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $config    = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $userClass = $config['profiles'][$config['default_profile']]['user_class'] ?? null;
        if (!is_string($userClass) || $userClass === '') {
            $userClass = $this->findAuthKitUserClass($container);
        }

        if ($userClass === null) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'resolve_target_entities' => [
                    AuditUserInterface::class => $userClass,
                ],
            ],
        ]);
    }

    /** Registers parameters and loads service definitions from YAML. */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $defaultProfileName = $config['default_profile'];
        if (!isset($config['profiles'][$defaultProfileName])) {
            throw new InvalidArgumentException(sprintf('The "nowo_audit_kit.default_profile" value "%s" does not match any configured profile.', $defaultProfileName));
        }

        $profiles = $config['profiles'];
        $this->resolveMissingUserClasses($profiles, $defaultProfileName, $container);
        $this->assertUniqueUserClasses($profiles);

        $defaultProfile = $profiles[$defaultProfileName];

        $container->setParameter('nowo_audit_kit.default_profile', $defaultProfileName);
        $container->setParameter('nowo_audit_kit.profiles', $profiles);
        $container->setParameter('nowo_audit_kit.enabled', $defaultProfile['enabled']);
        $container->setParameter('nowo_audit_kit.user_class', $defaultProfile['user_class']);
        $container->setParameter('nowo_audit_kit.fields', $defaultProfile['fields']);
        $container->setParameter('nowo_audit_kit.timestamp_type', $defaultProfile['timestamp_type']);
        $container->setParameter('nowo_audit_kit.blameable', $defaultProfile['blameable']);
        $container->setParameter('nowo_audit_kit.timestampable', $defaultProfile['timestampable']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $registry = new ProfileRegistry($profiles, $defaultProfileName);

        if (!$registry->hasEnabledProfile()) {
            $container->removeDefinition(AuditableEntityListener::class);
            $container->removeDefinition(AuditableDoctrineListener::class);
        }
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /** Same fallback as {@see resolveMissingUserClasses()}, read from the raw AuthKit configuration at prepend time. */
    private function findAuthKitUserClass(ContainerBuilder $container): ?string
    {
        foreach (array_reverse($container->getExtensionConfig('nowo_auth_kit')) as $authConfig) {
            $candidate = $authConfig['user_class'] ?? null;
            if (!is_string($candidate) && is_array($authConfig['profiles'] ?? null)) {
                $profileName = $authConfig['default_profile'] ?? array_key_first($authConfig['profiles']);
                $candidate   = $authConfig['profiles'][$profileName]['user_class'] ?? null;
            }

            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function resolveMissingUserClasses(array &$profiles, string $defaultProfileName, ContainerBuilder $container): void
    {
        if ('' !== ($profiles[$defaultProfileName]['user_class'] ?? '')) {
            return;
        }

        if ($container->hasParameter('nowo_auth_kit.user_class')) {
            $profiles[$defaultProfileName]['user_class'] = $container->getParameter('nowo_auth_kit.user_class');

            return;
        }

        throw new InvalidArgumentException(sprintf('The "nowo_audit_kit.profiles.%s.user_class" configuration value is required.', $defaultProfileName));
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function assertUniqueUserClasses(array $profiles): void
    {
        $seen = [];

        foreach ($profiles as $name => $profile) {
            if ('' === ($profile['user_class'] ?? '')) {
                throw new InvalidArgumentException(sprintf('The "nowo_audit_kit.profiles.%s.user_class" configuration value is required.', $name));
            }

            $userClass = $profile['user_class'];
            if (isset($seen[$userClass])) {
                throw new InvalidArgumentException(sprintf('Duplicate user_class "%s" in profiles "%s" and "%s".', $userClass, $seen[$userClass], $name));
            }

            $seen[$userClass] = $name;
        }
    }
}
