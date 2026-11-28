<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Change\ChangeSetBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Records one row per request, with a change set built from the query string. */
final readonly class TraceController
{
    public function __construct(
        private ActionLogRecorder $recorder,
        private ChangeSetBuilder $changes,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $entry = $this->recorder->recordAndFlush('item.viewed', null, null, ['path' => $request->getPathInfo()], 'trace', $this->changes->between(
            ['title' => 'before', 'password' => 'old-secret'],
            ['title' => $request->query->getString('title', 'after'), 'password' => 'new-secret'],
        ));

        return new JsonResponse(['id' => $entry->getId()]);
    }
}
