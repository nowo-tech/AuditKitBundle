<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle;

use Nowo\AuditKitBundle\DependencyInjection\Compiler\BlamePortsPass;
use Nowo\AuditKitBundle\DependencyInjection\Compiler\CorrelationMiddlewareOrderPass;
use Nowo\AuditKitBundle\DependencyInjection\Compiler\CorrelationProviderPass;
use Nowo\AuditKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/** Symfony bundle entry point for automatic Doctrine audit fields. */
final class NowoAuditKitBundle extends Bundle
{
    /** Registers {@see TwigPathsPass} (`@NowoAuditKitBundle` Twig namespace, application overrides first) since 1.15, {@see BlamePortsPass} and, since 2.2, {@see CorrelationProviderPass} and {@see CorrelationMiddlewareOrderPass}. */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
        $container->addCompilerPass(new TwigPathsPass());
        // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
        $container->addCompilerPass(new BlamePortsPass());
        // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
        $container->addCompilerPass(new CorrelationProviderPass());
        // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
        $container->addCompilerPass(new CorrelationMiddlewareOrderPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
    }

    /** Returns the DI extension that loads {@see NowoAuditKitExtension}. */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoAuditKitExtension();
        }

        return $this->extension;
    }
}
