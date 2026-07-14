<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle;

use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/** Symfony bundle entry point for automatic Doctrine audit fields. */
final class NowoAuditKitBundle extends Bundle
{
    /** Returns the DI extension that loads {@see NowoAuditKitExtension}. */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new NowoAuditKitExtension();
        }

        return $this->extension;
    }
}
