<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_home')]
    public function home(): Response
    {
        return $this->render('demo/home.html.twig', [
            'title' => 'Twig Inspector Bundle - Demo',
            'message' => 'This is a demo page to showcase the Twig Inspector Bundle functionality with Symfony 7.0.',
            'items' => [
                'Enable Twig Inspector in the Web Profiler toolbar',
                'Hover over HTML elements to see which templates rendered them',
                'Click on elements to open templates in your IDE',
                'Running on Symfony 7.0',
            ],
        ]);
    }
}

