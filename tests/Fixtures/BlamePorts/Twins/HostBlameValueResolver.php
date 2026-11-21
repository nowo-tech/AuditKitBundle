<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\BlamePorts\Twins;

use Doctrine\Persistence\ObjectManager;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** A 1.14 port override next to its overridden twin: allowed, and not read in id mode. */
#[AsAlias(BlameValueResolverInterface::class)]
final class HostBlameValueResolver implements BlameValueResolverInterface
{
    public function resolve(ProfileSettings $profile, ObjectManager $objectManager): string
    {
        return 'legacy';
    }
}
