<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Model;

use DateTime;
use DateTimeImmutable;
use Nowo\AuditKitBundle\Model\BlameableInterface;
use Nowo\AuditKitBundle\Model\BlameableTrait;
use Nowo\AuditKitBundle\Model\TimestampableInterface;
use Nowo\AuditKitBundle\Model\TimestampableTrait;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ModelTraitTest extends TestCase
{
    public function testTimestampableTrait(): void
    {
        $entity = new TimestampableEntity();
        $now    = new DateTimeImmutable();
        $entity->setCreatedAt($now);
        $entity->setUpdatedAt(new DateTime());

        $this->assertSame($now, $entity->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $entity->getUpdatedAt());
    }

    public function testBlameableTrait(): void
    {
        $entity = new BlameableEntity();
        $user   = new stdClass();
        $entity->setCreatedBy($user);
        $entity->setUpdatedBy(null);

        $this->assertSame($user, $entity->getCreatedBy());
        $this->assertNull($entity->getUpdatedBy());
    }
}

class TimestampableEntity implements TimestampableInterface
{
    use TimestampableTrait;
}

class BlameableEntity implements BlameableInterface
{
    use BlameableTrait;
}
