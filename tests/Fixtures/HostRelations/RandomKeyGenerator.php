<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;

use function bin2hex;
use function random_bytes;

/** A pre-insert identifier generator: a new random key on every persist(). `mixed $entity`: ORM 2 and ORM 3 signatures. */
final class RandomKeyGenerator extends AbstractIdGenerator
{
    public function generateId(EntityManagerInterface $em, mixed $entity): string
    {
        return bin2hex(random_bytes(16));
    }
}
