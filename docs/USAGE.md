# Usage

## Table of contents

- [Configuration profiles](#configuration-profiles)
- [Quick start with AuditableTrait](#quick-start-with-auditabletrait)
- [Traits and interfaces](#traits-and-interfaces)
- [Custom property names](#custom-property-names)
- [Opt-out per entity](#opt-out-per-entity)
- [Blame fields without the trait](#blame-fields-without-the-trait)
- [Public UUID](#public-uuid)
- [Append-only created-at](#append-only-created-at)
- [Escaping LIKE searches](#escaping-like-searches)
- [Doctrine migrations](#doctrine-migrations)
- [Behaviour summary](#behaviour-summary)
- [Related bundles](#related-bundles)

## Configuration profiles

Since **1.1.0**, settings can be grouped under named **profiles** when the application has more than one user entity. Each profile maps a `user_class` to its own field names, `timestamp_type`, and `blameable` / `timestampable` flags.

The listener resolves the profile automatically from the authenticated entity class at runtime. Timestamps fall back to `default_profile` when no user is present (CLI/guest) or when the user class is not mapped to any profile.

See [Configuration — Profiles](CONFIGURATION.md#profiles) for the YAML structure.

## Quick start with AuditableTrait

```php
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\AuditableTrait;

#[ORM\Entity]
class Article
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
}
```

Generate and run a Doctrine migration for the new columns. On persist/update, the listener sets:

- `createdAt` / `updatedAt` — always (when `timestampable: true`)
- `createdBy` / `updatedBy` — when an authenticated user matches `user_class` (nullable in CLI or guest context)

## Traits and interfaces

| Trait | Fields | Interface |
| ----- | ------ | --------- |
| `TimestampableTrait` | `createdAt`, `updatedAt` | `TimestampableInterface` |
| `BlameableTrait` | `createdBy`, `updatedBy` | `BlameableInterface` |
| `AuditableTrait` | all four | `AuditableInterface` |

`BlameableTrait` maps `createdBy` / `updatedBy` as `ManyToOne` to `Nowo\AuditKitBundle\Model\AuditUserInterface`. The bundle resolves that interface to the **default profile** `user_class` through `doctrine.orm.resolve_target_entities` (implementing the interface on your user entity is optional). If another profile's user class must be the target, declare the blame properties yourself (see [Blame fields without the trait](#blame-fields-without-the-trait)), or override the mapping with your own `resolve_target_entities` entry.

No `#[ORM\EntityListeners]` attribute is needed: the bundle registers a global Doctrine `prePersist` / `preUpdate` listener and only touches auditable entities.

## Custom property names

Map config keys to your entity properties:

```yaml
nowo_audit_kit:
    user_class: App\Entity\User
    fields:
        created_by: createdByUser
        updated_by: updatedByUser
```

## Opt-out per entity

```php
use Nowo\AuditKitBundle\Attribute\Auditable;

#[Auditable(enabled: false)]
#[ORM\Entity]
class LegacyRecord
{
    use AuditableTrait;
}
```

## Blame fields without the trait

When you need a custom `ManyToOne` mapping (e.g. typed to your `User` entity), implement the interface and declare columns manually — see `demo/symfony8/src/Entity/Article.php`.

## Public UUID

Since **1.4.0**. Expose an opaque identifier in URLs while integer primary keys stay internal:

```php
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\PublicUuidInterface;
use Nowo\AuditKitBundle\Model\PublicUuidTrait;

#[ORM\Entity]
class Project implements PublicUuidInterface
{
    use PublicUuidTrait; // uuid: string(36), not null
}
```

- `PublicUuidListener` (registered by the bundle, `prePersist`) calls `ensureUuid()` on every entity implementing `PublicUuidInterface`; other entities are ignored. It runs whatever the audit profile configuration is.
- `ensureUuid()` assigns a UUID v7 (RFC 4122) only when the value is empty; call it from the constructor if the UUID is needed before flush.
- `assignUuid($uuid)` forces a stable value (fixtures, preloaded configuration): the value is trimmed, an empty value is ignored and a non-RFC value throws `InvalidArgumentException` with message `invalid_uuid:<value>`.

## Append-only created-at

Since **1.4.0**. For rows that are written once (logs, events, history), `CreatedAtImmutableTrait` maps a **non-null** `createdAt` (`datetime_immutable`) set by the entity itself:

```php
use Nowo\AuditKitBundle\Model\CreatedAtImmutableTrait;

#[ORM\Entity]
class LoginEvent
{
    use CreatedAtImmutableTrait;

    public function __construct()
    {
        $this->initializeCreatedAt();
    }
}
```

| | `TimestampableTrait` | `CreatedAtImmutableTrait` |
| --- | --- | --- |
| Columns | `createdAt`, `updatedAt` (nullable) | `createdAt` (not null) |
| Written by | the audit listener (`prePersist` / `preUpdate`) | the entity constructor (`initializeCreatedAt()`) |
| Use for | editable entities | append-only rows |

Use one or the other on a given entity, not both (same `createdAt` property).

## Escaping LIKE searches

Since **1.4.0**. `Nowo\AuditKitBundle\Doctrine\SqlLike` escapes `\`, `%` and `_` in user input. Always append `SqlLike::ESCAPE_CLAUSE` (`ESCAPE '\'`): MySQL uses `\` as default escape character but SQLite has none, so the explicit clause gives the same result on both.

```php
use Nowo\AuditKitBundle\Doctrine\SqlLike;

$qb->andWhere('p.name LIKE :q ' . SqlLike::ESCAPE_CLAUSE)
    ->setParameter('q', SqlLike::contains($term)); // '%' . SqlLike::escape($term) . '%'
```

## Doctrine migrations

After adding traits or columns:

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

Recommended column types:

- Timestamps: `datetime_immutable` (or match `timestamp_type` in config)
- Blame: `ManyToOne` to your user entity, nullable, `onDelete: SET NULL`

## Behaviour summary

| Event | Timestamps | Blame |
| ----- | ---------- | ----- |
| `prePersist` | sets `createdAt` and `updatedAt` | sets `createdBy` and `updatedBy` |
| `preUpdate` | refreshes `updatedAt` only | refreshes `updatedBy` only |
| No authenticated user | timestamps use `default_profile` | blame fields remain `null` |
| Authenticated user not in any profile | timestamps use `default_profile` | blame fields remain `null` |

## Related bundles

- [`nowo-tech/user-kit-bundle`](https://github.com/nowo-tech/UserKitBundle) — account state / presence
- [`nowo-tech/auth-kit-bundle`](https://github.com/nowo-tech/AuthKitBundle) — authentication flows

Audit Kit Bundle is independent; it only needs a resolvable `user_class` and Symfony Security when blame fields should be populated.
