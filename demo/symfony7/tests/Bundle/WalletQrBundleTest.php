<?php

declare(strict_types=1);

namespace App\Tests\Bundle;

use Nowo\WalletQrBundle\NowoWalletQrBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class WalletQrBundleTest extends TestCase
{
    public function testBundleExtendsSymfonyBundle(): void
    {
        $this->assertInstanceOf(Bundle::class, new NowoWalletQrBundle());
    }

    public function testBundleHasContainerExtension(): void
    {
        $bundle = new NowoWalletQrBundle();
        $this->assertNotNull($bundle->getContainerExtension());
        $this->assertSame('nowo_wallet_qr', $bundle->getContainerExtension()?->getAlias());
    }
}
