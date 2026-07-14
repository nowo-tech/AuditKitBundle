# Configuration

All options live under the `nowo_audit_kit` root key.

A commented default template ships at `src/Resources/config/packages/nowo_audit_kit.yaml` in the bundle repository (and in the Symfony Flex recipe). Copy or adapt it as `config/packages/nowo_audit_kit.yaml` in your application.

## Profiles

Each **profile** maps a `user_class` to its own audit field mapping and feature flags. Use multiple profiles when the application has more than one authenticated user entity (for example `App\Entity\User` and `App\Entity\Admin`).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `default_profile` | string | first profile key | Profile used for timestamps when no authenticated user is resolved (CLI, guest) or when the user class does not match any profile. |
| `profiles` | map | `default` | Named profile definitions (at least one required). |

Each profile supports:

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | bool | `true` | Enable auditing for this profile. When all profiles are disabled, the Doctrine listener is not registered. |
| `user_class` | string | — | FQCN used for `createdBy` / `updatedBy` references (required; must be unique across profiles). |
| `timestamp_type` | string | `datetime_immutable` | `datetime_immutable` or `datetime` |
| `timestampable` | bool | `true` | When `false`, timestamp fields are not managed for this profile. |
| `blameable` | bool | `true` | When `false`, blame fields are not managed for this profile. |

### Field mapping (per profile)

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `fields.created_at` | string | `createdAt` | Property name for creation timestamp |
| `fields.updated_at` | string | `updatedAt` | Property name for update timestamp |
| `fields.created_by` | string | `createdBy` | Property name for creation user reference |
| `fields.updated_by` | string | `updatedBy` | Property name for update user reference |

Property names must match getters/setters on the entity (e.g. `createdAt` → `getCreatedAt()` / `setCreatedAt()`).

Example with two profiles:

```yaml
nowo_audit_kit:
    default_profile: app_user
    profiles:
        app_user:
            user_class: App\Entity\User
            enabled: true
            fields:
                created_at: createdAt
                updated_at: updatedAt
                created_by: createdBy
                updated_by: updatedBy
            timestamp_type: datetime_immutable
            blameable: true
            timestampable: true
        admin:
            user_class: App\Entity\Admin
            enabled: true
            fields:
                created_at: insertedAt
                updated_at: modifiedAt
                created_by: author
                updated_by: editor
            timestamp_type: datetime_immutable
            blameable: true
            timestampable: true
```

### Resolving profiles at runtime

- **Timestamps:** the active profile is resolved from the authenticated user when possible; otherwise the `default_profile` is used (CLI, guest, or unmapped user classes).
- **Blame fields:** only set when the authenticated user matches the active profile's `user_class`. Unmapped users (for example a third-party SSO user) do not populate blame fields even if timestamps are applied via the default profile.

## Legacy flat configuration

The previous flat layout remains supported and is normalized internally to a single `default` profile:

```yaml
nowo_audit_kit:
    user_class: App\Entity\User
    fields:
        created_at: createdAt
        updated_at: updatedAt
        created_by: createdBy
        updated_by: updatedBy
    timestamp_type: datetime_immutable
    timestampable: true
    blameable: true
```

Backward-compatible parameters (`nowo_audit_kit.user_class`, `nowo_audit_kit.enabled`, etc.) mirror the **default profile** values for existing integrations.

## Timestamp-only or blame-only

Disable one category per profile:

```yaml
nowo_audit_kit:
    profiles:
        default:
            user_class: App\Entity\User
            timestampable: true
            blameable: false
```

Or use only `TimestampableTrait` / `BlameableTrait` on entities instead of `AuditableTrait`.
