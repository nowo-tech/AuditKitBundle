<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver;
use Nowo\AuditKitBundle\Model\BlameableInterface;
use Nowo\AuditKitBundle\Model\BlameableTrait;
use Nowo\AuditKitBundle\Model\TimestampableInterface;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AuditablePropertyResolverExtendedTest extends TestCase
{
    public function testGetTimestampReturnsNullForNonDateValue(): void
    {
        $resolver = new AuditablePropertyResolver();
        $fields   = ProfileRegistryFactory::defaultFields();

        $entity            = new CustomFieldEntity();
        $entity->createdAt = 'not-a-date';

        $this->assertNull($resolver->getTimestamp($entity, 'created_at', $fields));
    }

    public function testDetectsCustomFieldNames(): void
    {
        $fields = [
            'created_at' => 'insertedAt',
            'updated_at' => 'modifiedAt',
            'created_by' => 'author',
            'updated_by' => 'editor',
        ];
        $resolver = new AuditablePropertyResolver();

        $entity = new CustomFieldEntity();
        $now    = new DateTimeImmutable();
        $resolver->setTimestamp($entity, 'created_at', $now, $fields);
        $user = new stdClass();
        $resolver->setBlame($entity, 'created_by', $user, $fields);

        $this->assertSame($now, $entity->insertedAt);
        $this->assertSame($user, $entity->author);
        $this->assertTrue($resolver->hasTimestampFields($entity, $fields));
        $this->assertTrue($resolver->hasBlameFields($entity, $fields));
    }

    public function testDetectsBlameableInterfaceOnly(): void
    {
        $resolver = new AuditablePropertyResolver();
        $fields   = ProfileRegistryFactory::defaultFields();

        $this->assertTrue($resolver->isAuditable(new BlameableOnlyEntity()));
        $this->assertTrue($resolver->hasBlameFields(new BlameableOnlyEntity(), $fields));
    }

    public function testDetectsTimestampableInterfaceOnly(): void
    {
        $resolver = new AuditablePropertyResolver();
        $fields   = ProfileRegistryFactory::defaultFields();

        $entity = new TimestampableOnlyEntity();
        $this->assertTrue($resolver->isAuditable($entity));
        $this->assertTrue($resolver->hasTimestampFields($entity, $fields));
    }
}

class CustomFieldEntity
{
    public ?DateTimeInterface $insertedAt = null;
    public ?DateTimeInterface $modifiedAt = null;
    public ?object $author                = null;
    public ?object $editor                = null;
    public mixed $createdAt               = null;
}

class BlameableOnlyEntity implements BlameableInterface
{
    use BlameableTrait;
}

class TimestampableOnlyEntity implements TimestampableInterface
{
    private ?DateTimeInterface $createdAt = null;
    private ?DateTimeInterface $updatedAt = null;

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
