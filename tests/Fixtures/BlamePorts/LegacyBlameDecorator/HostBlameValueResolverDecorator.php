<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\BlamePorts\LegacyBlameDecorator;

use Doctrine\Persistence\ObjectManager;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/** A host decorator of the 1.14 blame port (`#[AsDecorator]`), which 1.15–1.18 ignored silently in id mode. */
#[AsDecorator(BlameValueResolverInterface::class)]
final readonly class HostBlameValueResolverDecorator implements BlameValueResolverInterface
{
    public function __construct(#[AutowireDecorated] private BlameValueResolverInterface $inner)
    {
    }

    public function resolve(ProfileSettings $profile, ObjectManager $objectManager): mixed
    {
        return $this->inner->resolve($profile, $objectManager);
    }
}
