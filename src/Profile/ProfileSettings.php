<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Profile;

final readonly class ProfileSettings
{
    /**
     * @param class-string $userClass
     * @param array{created_at: string, updated_at: string, created_by: string, updated_by: string} $fields
     */
    public function __construct(
        public string $name,
        public string $userClass,
        public bool $enabled,
        public array $fields,
        public string $timestampType,
        public bool $blameable,
        public bool $timestampable,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        /** @var class-string $userClass */
        $userClass = $config['user_class'];

        return new self(
            name: $name,
            userClass: $userClass,
            enabled: $config['enabled'],
            fields: $config['fields'],
            timestampType: $config['timestamp_type'],
            blameable: $config['blameable'],
            timestampable: $config['timestampable'],
        );
    }
}
