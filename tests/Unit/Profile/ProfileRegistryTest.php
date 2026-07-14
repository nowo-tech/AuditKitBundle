<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Profile;

use Nowo\AuditKitBundle\Profile\UnknownProfileException;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;

final class ProfileRegistryTest extends TestCase
{
    public function testResolveUsesExactClassMap(): void
    {
        $registry = ProfileRegistryFactory::fromProfiles([
            'app_user' => [
                'user_class'     => ExactMatchUser::class,
                'enabled'        => true,
                'fields'         => ProfileRegistryFactory::defaultFields(),
                'timestamp_type' => 'datetime_immutable',
                'blameable'      => true,
                'timestampable'  => true,
            ],
        ], 'app_user');

        $profile = $registry->resolveForObject(new ExactMatchUser());
        $this->assertNotNull($profile);
        $this->assertSame('app_user', $profile->name);
    }

    public function testResolveCachesParentClassMatch(): void
    {
        $registry = ProfileRegistryFactory::fromProfiles([
            'app_user' => [
                'user_class'     => BaseProfileUser::class,
                'enabled'        => true,
                'fields'         => ProfileRegistryFactory::defaultFields(),
                'timestamp_type' => 'datetime_immutable',
                'blameable'      => true,
                'timestampable'  => true,
            ],
        ]);

        $child    = new ChildProfileUser();
        $resolved = $registry->resolveForObject($child);
        $this->assertNotNull($resolved);
        $this->assertSame('app_user', $resolved->name);
        $this->assertSame('app_user', $registry->resolveForObject($child)?->name);
    }

    public function testUnknownProfileThrows(): void
    {
        $registry = ProfileRegistryFactory::single(ExactMatchUser::class);

        $this->expectException(UnknownProfileException::class);
        $registry->getByName('missing');
    }

    public function testResolveReturnsNullForUnknownClass(): void
    {
        $registry = ProfileRegistryFactory::single(ExactMatchUser::class);

        $this->assertNull($registry->resolveForObject(new OtherProfileUser()));
    }

    public function testHasEnabledProfile(): void
    {
        $registry = ProfileRegistryFactory::fromProfiles([
            'enabled_profile' => [
                'user_class'     => ExactMatchUser::class,
                'enabled'        => true,
                'fields'         => ProfileRegistryFactory::defaultFields(),
                'timestamp_type' => 'datetime_immutable',
                'blameable'      => true,
                'timestampable'  => true,
            ],
            'disabled_profile' => [
                'user_class'     => OtherProfileUser::class,
                'enabled'        => false,
                'fields'         => ProfileRegistryFactory::defaultFields(),
                'timestamp_type' => 'datetime_immutable',
                'blameable'      => true,
                'timestampable'  => true,
            ],
        ], 'enabled_profile');

        $this->assertTrue($registry->hasEnabledProfile());
        $this->assertSame('enabled_profile', $registry->getDefault()->name);
    }

    public function testHasEnabledProfileFalseWhenAllDisabled(): void
    {
        $registry = ProfileRegistryFactory::fromProfiles([
            'one' => [
                'user_class'     => ExactMatchUser::class,
                'enabled'        => false,
                'fields'         => ProfileRegistryFactory::defaultFields(),
                'timestamp_type' => 'datetime_immutable',
                'blameable'      => true,
                'timestampable'  => true,
            ],
        ]);

        $this->assertFalse($registry->hasEnabledProfile());
    }
}

class ExactMatchUser
{
}

class BaseProfileUser
{
}

class ChildProfileUser extends BaseProfileUser
{
}

class OtherProfileUser
{
}
