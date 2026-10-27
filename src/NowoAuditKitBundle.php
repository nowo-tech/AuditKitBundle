<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle;

use Nowo\AuditKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/** Symfony bundle entry point for automatic Doctrine audit fields. */
final class NowoAuditKitBundle extends Bundle
{
    /** Registers {@see TwigPathsPass} (`@NowoAuditKitBundle` Twig namespace, application overrides first). */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
        $container->addCompilerPass(new TwigPathsPass());
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
