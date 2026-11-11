<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Blame;

use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\AuditActor;
use Nowo\AuditKitBundle\Security\SecurityCurrentActorProvider;

/**
 * Default {@see BlameIdResolverInterface} (since 1.15, `blame_mode: id`): the current actor's id, null without an
 * actor. With the default {@see SecurityCurrentActorProvider} it writes exactly what
 * {@see UserIdBlameValueResolver} wrote in 1.14.
 *
 * @since 1.15.0
 */
final readonly class ActorIdBlameIdResolver implements BlameIdResolverInterface
{
    public function resolveBlameId(ProfileSettings $profile, ?AuditActor $actor): ?string
    {
        return $actor?->id;
    }
}
