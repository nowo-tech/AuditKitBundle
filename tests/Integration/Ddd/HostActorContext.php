<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Ddd;

use Nowo\AuditKitBundle\Security\AuditActor;
use Nowo\AuditKitBundle\Security\CurrentActorProviderInterface;

/**
 * Since 1.15: the DDD host's only audit adapter. It maps the host's own "who is acting" (here set by the test, in an
 * application it would come from the host's session or command context) to an {@see AuditActor}. No Symfony
 * Security, no Doctrine type.
 */
final class HostActorContext implements CurrentActorProviderInterface
{
    private ?AuditActor $actor = null;

    public function actAs(?string $userId, ?string $profile = null): void
    {
        $this->actor = $userId === null && $profile === null ? null : new AuditActor($userId, $profile);
    }

    public function currentActor(): ?AuditActor
    {
        return $this->actor;
    }
}
