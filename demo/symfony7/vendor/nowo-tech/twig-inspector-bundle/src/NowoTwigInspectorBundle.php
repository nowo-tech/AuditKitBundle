<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle;

use Nowo\TwigInspectorBundle\DependencyInjection\NowoTwigInspectorExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle for Twig Inspector functionality.
 * Adds the possibility to find Twig templates and blocks used for rendering HTML pages faster during development.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
class NowoTwigInspectorBundle extends Bundle
{
    /**
     * Overridden to allow for the custom extension alias.
     * Creates and returns the container extension instance if not already created.
     *
     * @return ExtensionInterface|null The container extension instance, or null if not available
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new NowoTwigInspectorExtension();
        }

        return $this->extension;
    }
}
