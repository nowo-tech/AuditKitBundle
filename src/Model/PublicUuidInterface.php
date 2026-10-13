<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

use InvalidArgumentException;
use Nowo\AuditKitBundle\Doctrine\PublicUuidListener;

/**
 * Entity exposing a public opaque UUID (integer primary keys stay internal).
 *
 * Implemented together with {@see PublicUuidTrait}; {@see PublicUuidListener}
 * calls {@see self::ensureUuid()} on `prePersist` for every entity implementing this interface.
 */
interface PublicUuidInterface
{
    public function getUuid(): string;

    /**
     * Forces a stable UUID (e.g. preloaded from configuration) before flush; an empty value is a no-op.
     *
     * @throws InvalidArgumentException when the trimmed value is non-empty but not a valid RFC 4122 UUID
     */
    public function assignUuid(string $uuid): void;

    /** Assigns a new UUID v7 when none is set; idempotent. */
    public function ensureUuid(): void;
}
