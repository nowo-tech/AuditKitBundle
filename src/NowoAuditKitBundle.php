<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle;

use Nowo\AuditKitBundle\DependencyInjection\Compiler\BlamePortsPass;
use Nowo\AuditKitBundle\DependencyInjection\Compiler\HostFlushProbePass;
use Nowo\AuditKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\Deprecation\DefaultFlipNotices;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

use function is_array;

/** Symfony bundle entry point for automatic Doctrine audit fields. */
final class NowoAuditKitBundle extends Bundle
{
    /** Registers {@see TwigPathsPass} (`@NowoAuditKitBundle` Twig namespace, application overrides first), since 1.15 {@see BlamePortsPass} and since 1.18 {@see HostFlushProbePass}. */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
        $container->addCompilerPass(new TwigPathsPass());
        // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
        $container->addCompilerPass(new BlamePortsPass());
        // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
        $container->addCompilerPass(new HostFlushProbePass());
    }

    /**
     * Since 1.18: one deprecation notice per kernel boot for each default that changes in 2.0 and that the host left
     * unset ({@see DefaultFlipNotices}). Stateless: it reads a container parameter.
     */
    public function boot(): void
    {
        if ($this->container?->hasParameter(DefaultFlipNotices::PARAMETER) === true) {
            $notices = $this->container->getParameter(DefaultFlipNotices::PARAMETER);
            if (is_array($notices)) {
                DefaultFlipNotices::trigger($notices);
            }
        }
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
