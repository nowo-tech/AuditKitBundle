<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Blame;

use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\AuditActor;
use Nowo\AuditKitBundle\Security\CurrentActorProviderInterface;

/**
 * Port: the id written into the `created_by` / `updated_by` id columns with `blame_mode: id` (since 1.15).
 * Framework-free twin of {@see BlameValueResolverInterface} (no Doctrine `ObjectManager`, no security user).
 *
 * The default {@see ActorIdBlameIdResolver} returns the actor id. Alias this interface to your own service to write
 * another id (a tenant-scoped id, an impersonator), or null to leave the fields null.
 *
 * @since 1.15.0
 */
interface BlameIdResolverInterface
{
    /**
     * @param ProfileSettings $profile the profile in effect for the entity being written
     * @param AuditActor|null $actor the current actor ({@see CurrentActorProviderInterface}), null for a guest / CLI context
     */
    public function resolveBlameId(ProfileSettings $profile, ?AuditActor $actor): ?string;
}
