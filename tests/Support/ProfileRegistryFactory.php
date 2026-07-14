<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use Nowo\AuditKitBundle\Profile\ProfileRegistry;

final class ProfileRegistryFactory
{
    /**
     * @return array{created_at: string, updated_at: string, created_by: string, updated_by: string}
     */
    public static function defaultFields(): array
    {
        return [
            'created_at' => 'createdAt',
            'updated_at' => 'updatedAt',
            'created_by' => 'createdBy',
            'updated_by' => 'updatedBy',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function single(string $userClass, array $overrides = [], string $profileName = 'default'): ProfileRegistry
    {
        return self::fromProfiles([
            $profileName => array_replace_recursive([
                'enabled'        => true,
                'user_class'     => $userClass,
                'fields'         => self::defaultFields(),
                'timestamp_type' => 'datetime_immutable',
                'blameable'      => true,
                'timestampable'  => true,
            ], $overrides),
        ], $profileName);
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    public static function fromProfiles(array $profiles, string $defaultProfileName = 'default'): ProfileRegistry
    {
        return new ProfileRegistry($profiles, $defaultProfileName);
    }
}
