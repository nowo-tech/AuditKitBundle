<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Blame;

use Doctrine\Persistence\ObjectManager;
use Nowo\AuditKitBundle\Profile\ProfileSettings;

/**
 * Port: the value written into the `created_by` / `updated_by` fields of the active profile.
 *
 * The default {@see EntityReferenceBlameValueResolver} keeps the historical behaviour (a Doctrine reference to the
 * security user when it is an instance of the profile `user_class`, null otherwise).
 * {@see SecurityUserIdBlameValueResolver} also blames a security user that is not the entity (a wrapper / DTO with a
 * `getId()`), through `getReference($userClass, $id)`. Alias this interface to either, or to your own
 * implementation; a value other than an object needs blame fields (`fields.*`) whose setters accept it.
 *
 * Doctrine-typed port of the reference path (`blame_mode: reference`). With `blame_mode: id`, since 1.15 the listener
 * reads the framework-free {@see BlameIdResolverInterface}; aliasing this interface there is deprecated (still read in
 * 1.x, removed in 2.0).
 *
 * @since 1.12.0
 */
interface BlameValueResolverInterface
{
    /**
     * @param ObjectManager $objectManager the entity manager of the Doctrine event (multi-manager applications)
     *
     * @return mixed the blame value, or null to leave the fields null
     */
    public function resolve(ProfileSettings $profile, ObjectManager $objectManager): mixed;
}
