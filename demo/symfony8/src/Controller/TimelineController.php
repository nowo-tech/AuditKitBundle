<?php

declare(strict_types=1);

namespace App\Controller;

use App\Demo\DemoActionLog;
use App\Demo\DemoAvatarResolver;
use App\Demo\DemoPerson;
use Nowo\AuditKitBundle\Avatar\ActionLogAvatarResolverInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * AuditKit 1.11 demo: the action log timeline with actor and subject avatars from the demo resolver (a picture, a
 * broken URL, an anonymised account, a rejected http: URL and a deleted actor). In-memory rows, no database.
 */
final class TimelineController extends AbstractController
{
    #[Route('/timeline', name: 'demo_timeline')]
    public function __invoke(ActionLogAvatarResolverInterface $resolver): Response
    {
        $ada = new DemoPerson(1, 'Ada Lovelace');
        $grace = new DemoPerson(2, 'Grace Hopper');
        $anonymised = new DemoPerson(3, 'Anonymous user');
        $alan = new DemoPerson(4, 'Alan Turing');

        $entries = [
            (new DemoActionLog())->setAction('project.member_added')->setActor($ada)->setSubject($grace)->setContext(['project' => 'Apollo']),
            (new DemoActionLog())->setAction('task.assigned')->setActor($grace)->setSubject($alan),
            (new DemoActionLog())->setAction('comment.deleted')->setActor($anonymised),
            (new DemoActionLog())->setAction('user.created')->setSubject($ada),
        ];

        return $this->render('demo/timeline.html.twig', [
            'entries' => $entries,
            'resolver' => $resolver instanceof DemoAvatarResolver ? $resolver : null,
            'calls_before' => $resolver instanceof DemoAvatarResolver ? $resolver->calls : 0,
        ]);
    }
}
