<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use DateTimeInterface;
use Nowo\AuditKitBundle\Attribute\Auditable as AuditableAttribute;
use Nowo\AuditKitBundle\Model\AuditableInterface;
use Nowo\AuditKitBundle\Model\AuditableTrait;
use Nowo\AuditKitBundle\Model\BlameableInterface;
use Nowo\AuditKitBundle\Model\BlameableTrait;
use Nowo\AuditKitBundle\Model\TimestampableInterface;
use Nowo\AuditKitBundle\Model\TimestampableTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\PropertyAccess\Exception\ExceptionInterface as PropertyAccessException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

use function array_key_exists;
use function in_array;

/**
 * Maps configuration field names to entity properties and auditing traits.
 *
 * A class is audited when it implements one of the bundle interfaces or uses one of its traits (the "contract" path,
 * unchanged), or, since 1.13, without any bundle interface or trait (the "opt-in" path) when it is listed in
 * `nowo_audit_kit.auditable_entities` (the class, a parent class or an interface it implements) or, with
 * `nowo_audit_kit.attribute_opt_in: true`, when it carries `#[Auditable]`. `#[Auditable(enabled: false)]`, on the class or
 * on a parent class (so also on a Doctrine proxy's entity), always opts out. On the opt-in path each field is written
 * only when it exists and accepts the value; otherwise it is skipped and a warning is logged once per class and field.
 * The decision is memoised per class.
 */
final class AuditablePropertyResolver
{
    private const CONTRACT = 'contract';

    private const OPT_IN = 'opt_in';

    private readonly PropertyAccessorInterface $propertyAccessor;

    private readonly LoggerInterface $logger;

    /** @var array<class-string, self::CONTRACT|self::OPT_IN|null> */
    private array $decisions = [];

    /** @var array<string, true> */
    private array $warned = [];

    /**
     * @param list<class-string> $auditableClasses Since 1.13: classes / interfaces opted in by configuration (`auditable_entities`)
     * @param bool $attributeOptIn Since 1.13: `#[Auditable]` alone opts a class in (`attribute_opt_in`, default false)
     */
    public function __construct(
        ?PropertyAccessorInterface $propertyAccessor = null,
        private readonly array $auditableClasses = [],
        private readonly bool $attributeOptIn = false,
        ?LoggerInterface $logger = null,
    ) {
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
        $this->logger           = $logger ?? new NullLogger();
    }

    public function isAuditable(object $entity): bool
    {
        return $this->decision($entity) !== null;
    }

    /**
     * @return self::CONTRACT|self::OPT_IN|null
     */
    private function decision(object $entity): ?string
    {
        $class = $entity::class;
        if (array_key_exists($class, $this->decisions)) {
            return $this->decisions[$class];
        }

        $attribute = $this->attributeOf($entity);
        if ($attribute instanceof AuditableAttribute && !$attribute->enabled) {
            $decision = null;
        } elseif ($this->implementsAuditingContracts($entity)) {
            $decision = self::CONTRACT;
        } elseif ($this->isConfiguredAuditable($entity) || ($this->attributeOptIn && $attribute instanceof AuditableAttribute)) {
            $decision = self::OPT_IN;
        } else {
            $decision = null;
        }

        // @igor-ignore - Deterministic per class (configuration and class attributes only), safe to keep across requests.
        return $this->decisions[$class] = $decision;
    }

    /** The `#[Auditable]` of the class or of its nearest parent that has one (a Doctrine proxy extends the entity). */
    private function attributeOf(object $entity): ?AuditableAttribute
    {
        for ($reflection = new ReflectionClass($entity); $reflection instanceof ReflectionClass; $reflection = $reflection->getParentClass()) {
            $attributes = $reflection->getAttributes(AuditableAttribute::class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }

    private function isConfiguredAuditable(object $entity): bool
    {
        foreach ($this->auditableClasses as $class) {
            if ($entity instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{created_at: string, updated_at: string, created_by: string, updated_by: string} $fields
     */
    public function hasTimestampFields(object $entity, array $fields): bool
    {
        return $entity instanceof TimestampableInterface
            || $this->hasProperty($entity, $fields['created_at'])
            || $this->hasProperty($entity, $fields['updated_at']);
    }

    /**
     * @param array{created_at: string, updated_at: string, created_by: string, updated_by: string} $fields
     */
    public function hasBlameFields(object $entity, array $fields): bool
    {
        return $entity instanceof BlameableInterface
            || $this->hasProperty($entity, $fields['created_by'])
            || $this->hasProperty($entity, $fields['updated_by']);
    }

    /**
     * @param array{created_at: string, updated_at: string, created_by: string, updated_by: string} $fields
     */
    public function setTimestamp(object $entity, string $configKey, DateTimeInterface $value, array $fields): void
    {
        $this->write($entity, $fields[$configKey], $value);
    }

    /**
     * @param array{created_at: string, updated_at: string, created_by: string, updated_by: string} $fields
     */
    public function setBlame(object $entity, string $configKey, mixed $user, array $fields): void
    {
        $this->write($entity, $fields[$configKey], $user);
    }

    /**
     * Contract classes are written as before. Since 1.13 an opt-in class (no bundle interface or trait) gets only the
     * fields it has and that accept the value; any other field is skipped with a warning, once per class and field.
     */
    private function write(object $entity, string $property, mixed $value): void
    {
        if ($this->decision($entity) !== self::OPT_IN) {
            // @igor-ignore - Not shared worker service state.
            $this->propertyAccessor->setValue($entity, $property, $value);

            return;
        }
        if (!$this->propertyAccessor->isWritable($entity, $property)) {
            $this->warnOnce($entity, $property, 'is not writable');

            return;
        }
        try {
            // @igor-ignore - Not shared worker service state.
            $this->propertyAccessor->setValue($entity, $property, $value);
        } catch (PropertyAccessException) {
            $this->warnOnce($entity, $property, 'does not accept the value');
        }
    }

    private function warnOnce(object $entity, string $property, string $reason): void
    {
        $key = $entity::class . '::' . $property;
        if (isset($this->warned[$key])) {
            return;
        }
        // @igor-ignore - Deterministic per class and field: only avoids repeating the same warning.
        $this->warned[$key] = true;
        $this->logger->warning('AuditKit: an opted-in entity field {property} {reason}; it is not audited.', ['bundle' => 'nowo_audit_kit', 'class' => $entity::class, 'property' => $property, 'reason' => $reason]);
    }

    /**
     * @param array{created_at: string, updated_at: string, created_by: string, updated_by: string} $fields
     */
    public function getTimestamp(object $entity, string $configKey, array $fields): ?DateTimeInterface
    {
        $value = $this->propertyAccessor->getValue($entity, $fields[$configKey]);

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

        return in_array(AuditableTrait::class, $traits, true)
            || in_array(TimestampableTrait::class, $traits, true)
            || in_array(BlameableTrait::class, $traits, true);
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
