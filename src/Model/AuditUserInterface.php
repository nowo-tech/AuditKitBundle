<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

/**
 * Doctrine target of the {@see BlameableTrait} `createdBy` / `updatedBy` associations.
 *
 * The bundle maps it to the default profile `user_class` via `doctrine.orm.resolve_target_entities`.
 * Implementing it on the user entity is optional (it documents the relation).
 */
interface AuditUserInterface
{
}
