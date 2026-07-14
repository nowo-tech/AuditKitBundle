<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle;

use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NowoAuditKitBundle extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new NowoAuditKitExtension();
        }

        return $this->extension;
    }
}
