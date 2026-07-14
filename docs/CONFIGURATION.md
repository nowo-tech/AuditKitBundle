# Configuration

All options live under the `nowo_audit_kit` root key.

## Global options

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | bool | `true` | Master switch for the auditing listener |
| `user_class` | string | — | **Required.** FQCN used for `createdBy` / `updatedBy` references |
| `timestamp_type` | string | `datetime_immutable` | `datetime_immutable` or `datetime` |
| `timestampable` | bool | `true` | When `false`, timestamp fields are not managed |
| `blameable` | bool | `true` | When `false`, blame fields are not managed |

## Field mapping

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `fields.created_at` | string | `createdAt` | Property name for creation timestamp |
| `fields.updated_at` | string | `updatedAt` | Property name for update timestamp |
| `fields.created_by` | string | `createdBy` | Property name for creation user reference |
| `fields.updated_by` | string | `updatedBy` | Property name for update user reference |

Property names must match getters/setters on the entity (e.g. `createdAt` → `getCreatedAt()` / `setCreatedAt()`).

## Example

```yaml
nowo_audit_kit:
    enabled: true
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

## Timestamp-only or blame-only

Disable one category globally:

```yaml
nowo_audit_kit:
    user_class: App\Entity\User
    timestampable: true
    blameable: false
```

Or use only `TimestampableTrait` / `BlameableTrait` on entities instead of `AuditableTrait`.
