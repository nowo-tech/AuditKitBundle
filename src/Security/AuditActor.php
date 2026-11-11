<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Security;

use Nowo\AuditKitBundle\Model\ReferenceId;

/**
 * Value object: who is acting right now, by id (since 1.15). Returned by {@see CurrentActorProviderInterface}.
 *
 * - `id`: the actor's user id as stored in id-mode blame columns (`blame_mode: id`). `''` and `'0'` become null
 *   (same rule as {@see ReferenceId}). Null means "acting, but not blamed".
 * - `profile`: the `nowo_audit_kit.profiles` name that applies to this actor; null selects the default profile.
 *
 * No framework type is involved, so a DDD host builds it from its own domain user id.
 *
 * @since 1.15.0
 */
final readonly class AuditActor
{
    public ?string $id;

    public function __construct(?string $id = null, public ?string $profile = null)
    {
        $this->id = ReferenceId::of($id);
    }
}
