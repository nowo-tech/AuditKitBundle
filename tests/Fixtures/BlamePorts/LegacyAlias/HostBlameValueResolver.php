<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\BlamePorts\LegacyAlias;

use Doctrine\Persistence\ObjectManager;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** A host override of the 1.14 blame port registered by autodiscovery (`#[AsAlias]`), read in id mode by 1.15–1.18. */
#[AsAlias(BlameValueResolverInterface::class)]
final class HostBlameValueResolver implements BlameValueResolverInterface
{
    public function resolve(ProfileSettings $profile, ObjectManager $objectManager): string
    {
        return 'legacy';
    }
}
