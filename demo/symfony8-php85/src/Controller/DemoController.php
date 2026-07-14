<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\WalletQrBundle\Enum\WalletPlatform;
use Nowo\WalletQrBundle\Service\WalletQrService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo controller for Wallet QR Bundle.
 */
class DemoController extends AbstractController
{
    public function __construct(
        private readonly WalletQrService $walletQrService,
    ) {
    }

    #[Route('/', name: 'demo_home')]
    public function index(): Response
    {
        $androidDemoUrl = 'https://pay.google.com/gp/v/save/demo-jwt-token';
        $iosDemoUrl     = 'https://demo.example.com/wallet/demo-ticket.pkpass';

        $androidQr = $this->walletQrService->createQrForUrl(WalletPlatform::Android, $androidDemoUrl);
        $iosQr     = $this->walletQrService->createQrForUrl(WalletPlatform::Ios, $iosDemoUrl);

        return $this->render('demo/index.html.twig', [
            'androidQr' => $androidQr,
            'iosQr'     => $iosQr,
        ]);
    }
}
