<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use DateTimeInterface;
use Nowo\AuditKitBundle\Attribute\Auditable as AuditableAttribute;
use Nowo\AuditKitBundle\Model\AuditableInterface;
use Nowo\AuditKitBundle\Model\BlameableInterface;
use Nowo\AuditKitBundle\Model\TimestampableInterface;
use ReflectionClass;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

use function in_array;

final class AuditablePropertyResolver
{
    private readonly PropertyAccessorInterface $propertyAccessor;

    /**
     * @param array{created_at: string, updated_at: string, created_by: string, updated_by: string} $fields
     */
    public function __construct(
        private readonly array $fields,
        ?PropertyAccessorInterface $propertyAccessor = null,
    ) {
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
    }

    public function isAuditable(object $entity): bool
    {
        if (!$this->implementsAuditingContracts($entity)) {
            return false;
        }

        $reflection = new ReflectionClass($entity);
        $attributes = $reflection->getAttributes(AuditableAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->enabled;
        }

        return true;
    }

    public function hasTimestampFields(object $entity): bool
    {
        return $entity instanceof TimestampableInterface
            || $this->hasProperty($entity, $this->fields['created_at'])
            || $this->hasProperty($entity, $this->fields['updated_at']);
    }

    public function hasBlameFields(object $entity): bool
    {
        return $entity instanceof BlameableInterface
            || $this->hasProperty($entity, $this->fields['created_by'])
            || $this->hasProperty($entity, $this->fields['updated_by']);
    }

    public function setTimestamp(object $entity, string $configKey, DateTimeInterface $value): void
    {
        $this->propertyAccessor->setValue($entity, $this->fields[$configKey], $value);
    }

    public function setBlame(object $entity, string $configKey, ?object $user): void
    {
        $this->propertyAccessor->setValue($entity, $this->fields[$configKey], $user);
    }

    public function getTimestamp(object $entity, string $configKey): ?DateTimeInterface
    {
        $value = $this->propertyAccessor->getValue($entity, $this->fields[$configKey]);

        return $value instanceof DateTimeInterface ? $value : null;
    }

    private function implementsAuditingContracts(object $entity): bool
    {
        return $entity instanceof AuditableInterface
            || $entity instanceof TimestampableInterface
            || $entity instanceof BlameableInterface
            || $this->usesAuditingTraits($entity);
    }

    private function usesAuditingTraits(object $entity): bool
    {
        $traits = $this->collectTraits($entity);

        return in_array(\Nowo\AuditKitBundle\Model\AuditableTrait::class, $traits, true)
            || in_array(\Nowo\AuditKitBundle\Model\TimestampableTrait::class, $traits, true)
            || in_array(\Nowo\AuditKitBundle\Model\BlameableTrait::class, $traits, true);
    }

    /**
     * @return list<string>
     */
    private function collectTraits(object $entity): array
    {
        $traits = [];
        foreach (class_uses($entity) as $trait) {
            $traits[] = $trait;
            $traits   = array_merge($traits, class_uses($trait) ?: []);
        }

        return array_values(array_unique($traits));
    }

    private function hasProperty(object $entity, string $property): bool
    {
        return $this->propertyAccessor->isWritable($entity, $property)
            && $this->propertyAccessor->isReadable($entity, $property);
    }
}
