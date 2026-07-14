<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class DemoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/', name: 'demo_home')]
    public function index(): Response
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'demo@audit-kit.test']);
        if (!$user instanceof User) {
            $user = (new User())->setEmail('demo@audit-kit.test');
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $article = (new Article())->setTitle('AuditKit demo · ' . date('Y-m-d H:i:s'));
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $articles = $this->entityManager->getRepository(Article::class)->findBy([], ['id' => 'DESC'], 5);

        return $this->render('demo/index.html.twig', [
            'articles' => $articles,
            'user' => $user,
        ]);
    }
}
