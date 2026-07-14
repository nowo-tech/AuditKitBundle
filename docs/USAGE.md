# Usage

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

`BlameableTrait` ships a default `ManyToOne` mapping. Override `targetEntity` on your entity when the user FQCN differs from the default mapping.

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
| No authenticated user | timestamps still set | blame fields remain `null` |

## Related bundles

- [`nowo-tech/user-kit-bundle`](https://github.com/nowo-tech/UserKitBundle) — account state / presence
- [`nowo-tech/auth-kit-bundle`](https://github.com/nowo-tech/AuthKitBundle) — authentication flows

Audit Kit Bundle is independent; it only needs a resolvable `user_class` and Symfony Security when blame fields should be populated.
