# Configuration

All options live under the `nowo_audit_kit` root key.

A commented default template ships at `src/Resources/config/packages/nowo_audit_kit.yaml` in the bundle repository (and in the Symfony Flex recipe). Copy or adapt it as `config/packages/nowo_audit_kit.yaml` in your application.

## Table of contents

- [Profiles](#profiles)
  - [Field mapping (per profile)](#field-mapping-per-profile)
  - [Resolving profiles at runtime](#resolving-profiles-at-runtime)
- [Legacy flat configuration](#legacy-flat-configuration)
- [Timestamp-only or blame-only](#timestamp-only-or-blame-only)
- [Action log](#action-log)
  - [DDD host: ids instead of relations (since 1.14)](#ddd-host-ids-instead-of-relations-since-114)
- [Correlation and changes (since 2.2)](#correlation-and-changes-since-22)

## Profiles

Each **profile** maps a `user_class` to its own audit field mapping and feature flags. Use multiple profiles when the application has more than one authenticated user entity (for example `App\Entity\User` and `App\Entity\Admin`).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `default_profile` | string | first profile key (after all config files are merged) | Profile used for timestamps when no authenticated user is resolved (CLI, guest) or when the user class does not match any profile. |
| `profiles` | map | `default` | Named profile definitions (at least one required). |
| `auditable_entities` | list | `[]` | Since 1.13: classes audited without any AuditKit interface or trait (the class, a parent class or an interface of your own). Each must exist. Only the fields that exist and accept the value are written (others are skipped with a warning). `#[Auditable(enabled: false)]` opts out. |
| `attribute_opt_in` | bool | `true` | Since 1.13: `#[Auditable]` alone (no interface or trait) opts a class in. Default `true` since 2.0 (1.x: `false`); set `false` to keep ignoring the attribute alone. |
| `blame_mode` | `reference`\|`id` | `reference` | Since 1.14: what the blame fields receive. `reference`: a Doctrine reference to the profile `user_class` entity (`BlameableTrait` relations, unchanged). `id`: the current security user's `getId()` as a string (never the user identifier), for plain id columns; `user_class` becomes optional and may be an interface. Since 1.15 `id` runs on the framework-free ports `CurrentActorProviderInterface` / `BlameIdResolverInterface` (defaults keep the 1.14 values; see [Usage — DDD host](USAGE.md#ddd-host)). Since 2.0, in `id` mode, a host override of `CurrentUserResolverInterface` / `BlameValueResolverInterface` while its twin keeps the default fails the container build. |

Each profile supports:

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | bool | `true` | Enable auditing for this profile. When all profiles are disabled, the Doctrine listener is not registered. |
| `user_class` | string | — | FQCN used for `createdBy` / `updatedBy` references (must be unique across profiles). Required with `blame_mode: reference` and `blameable: true`. Since 1.14 optional with `blame_mode: id` or `blameable: false` (the profile then matches every security user: `Symfony\Component\Security\Core\User\UserInterface`); it may also be an interface. |
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
- **Blame fields:** only set when the authenticated user matches the active profile's `user_class`. Unmapped users (for example a third-party SSO user) do not populate blame fields even if timestamps are applied via the default profile. Since 1.12, alias `Nowo\AuditKitBundle\Blame\BlameValueResolverInterface` to `SecurityUserIdBlameValueResolver` to blame such a user through its `getId()`, or to your own resolver (see USAGE, "Using it from a DDD / hexagonal application").

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

## Action log

Since **1.5.0**, opt-in: nothing is registered (services, Doctrine mapping, command) until `entity_class` is set. See [Usage — Action log](USAGE.md#action-log).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `action_log.entity_class` | string\|null | `null` | Host entity extending `Nowo\AuditKitBundle\ActionLog\AbstractActionLog` (actor / subject relations to the user entity) or, since 1.14, `AbstractIdActionLog` (actor / subject id columns, no foreign key). Enables `ActionLogRecorder` and `nowo:audit-kit:action-log:purge`. Must extend one of them (checked at container build). |
| `action_log.register_mapping` | bool | `true` | Prepend the Doctrine ORM mapping `NowoAuditKitActionLog` (both superclasses) on the default entity manager. Set `false` with several entity managers and map it yourself: `vendor/nowo-tech/audit-kit-bundle/src/ActionLog` (type `attribute`) or `vendor/nowo-tech/audit-kit-bundle/src/Resources/config/doctrine/action_log` (type `xml`), prefix `Nowo\AuditKitBundle\ActionLog`. |
| `action_log.mapping` | `attribute`\|`xml` | `attribute` | Since 1.14: driver of the prepended superclass mapping. `xml` uses the shipped `*.orm.xml` files (same columns), for hosts that map everything in XML. |
| `action_log.scope_context_key` | string\|null | `null` | Context key copied into `scope` by the recorder when no scope is passed (e.g. `project_uuid`). |
| `action_log.capture_ip` | bool | `true` | Store the client IP of the current request. |
| `action_log.flush_mode` | `host`\|`isolated` | `isolated` | Since 1.15: what `recordAndFlush()` / `recordIdsAndFlush()` flush. `isolated` (default since 2.0, REQ-ARCH-001 rule 6): only the new row, through a short-lived entity manager of the bundle on the same connection; the application unit of work is neither flushed nor cleared, so your pending changes stay pending. Use DBAL savepoints (`use_savepoints: true`, always on with DBAL 4) so a failed isolated write inside your transaction does not mark it rollback-only. `host` (the 1.x default, an explicit choice since 2.0): the entity manager that maps the action log class, with every pending change of the application. See [Usage — Action log flush mode](USAGE.md#action-log-flush-mode-since-115). |
| `action_log.changes.secret_fields` | list of strings | `[]` | Since 2.2: field names always stored as secrets (`{changed: true}`), whatever the policy says (case-insensitive). See [Correlation and changes](#correlation-and-changes-since-22). |
| `action_log.changes.secret_pattern` | regex | `/password\|passwd\|secret\|token\|key\|dsn\|credential\|private/i` | Since 2.2: secret-looking field names: masked without a policy, refused as `plain`, masked as nested array keys. |
| `action_log.changes.max_string_length` | int ≥ 1 | `500` | Since 2.2: longer strings are stored as their lengths only. |
| `action_log.changes.max_bytes` | int ≥ 64 | `16384` | Since 2.2: largest JSON of one change set; further fields are cut field by field. |
| `action_log.purge.older_than_days` | int ≥ 1 | `90` | Default `--older-than-days`. |
| `action_log.purge.batch_size` | int ≥ 1 | `1000` | Default `--batch-size` (rows per keyset batch). |
| `action_log.purge.actions` | list of strings | `[]` | Actions purged when the command gets no `--action` (e.g. page views). Empty: `--action` or `--all-actions` is required. |

```yaml
nowo_audit_kit:
    profiles:
        default:
            user_class: App\Entity\User   # target of actor / subject
    action_log:
        entity_class: App\Entity\UserAction
        scope_context_key: project_uuid
        purge:
            older_than_days: 90
            actions: [project.opened, issue.opened]
```

Container parameters: `nowo_audit_kit.action_log.enabled`, and when enabled `nowo_audit_kit.action_log.entity_class`, `.scope_context_key`, `.capture_ip`, `.flush_mode` (since 1.15), `.purge.older_than_days`, `.purge.batch_size`, `.purge.actions`.

### DDD host: ids instead of relations (since 1.14)

Opt-in; the defaults above are unchanged. A host whose domain is mapped in XML, whose firewall user is not a Doctrine
entity, or whose users live in another bounded context:

```yaml
nowo_audit_kit:
    blame_mode: id                       # createdBy / updatedBy receive the user id string; no user_class needed
    auditable_entities:
        - App\Domain\Invoice\Invoice    # no bundle interface, trait or attribute in the domain
    action_log:
        entity_class: App\Infrastructure\Persistence\AuditEntry   # extends AbstractIdActionLog
        mapping: xml                     # superclass mapping from the shipped XML
```

See [Usage — Using it from a DDD / hexagonal application](USAGE.md#using-it-from-a-ddd--hexagonal-application).

## Correlation and changes (since 2.2)

See [Usage — Traceability](USAGE.md#traceability-since-22).

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `correlation.provider` | bool | `false` | Cannot be combined with your own binding of the interface (the container build fails). Register the bundle's `CorrelationIdHolder` as `Nowo\CorrelationContract\CorrelationIdProviderInterface` with its listeners (one id per main request, console command and received message). Leave `false` and alias the interface when your application has a provider; with neither, the contract's `NullCorrelationIdProvider` is used (no id). |
| `correlation.header_name` | HTTP token | `X-Correlation-Id` | Header of responses, outgoing requests and e-mails (e.g. `X-Request-Id`). Invalid names fail the configuration. |
| `correlation.inbound_headers` | list of strings | `[X-Correlation-Id, traceparent]` | With `provider: true`: request headers read for an incoming id, in order (`traceparent` gives its trace-id). `[]` reads none: every request gets a new id, so inbound ids never become the correlation id. |
| `correlation.inbound_trusted_proxies_only` | bool | `true` | With `provider: true`: read the inbound headers only from trusted proxies (`framework.trusted_proxies`). |
| `correlation.response_header_paths` | list of strings | `[]` | With `provider: true`: path prefixes whose main responses carry `header_name`. |
| `correlation.messenger_child_ids` | bool | `false` | With `provider: true`: a received message runs under a new id with `caused_by` = the dispatching id (default: under the dispatching id). |
| `correlation.http_client` | bool | `false` | Decorate `http_client` so outgoing requests carry `header_name` (needs symfony/http-client). |
| `correlation.mailer` | bool | `false` | Add `header_name` with the current id to outgoing e-mails, unless already set (needs symfony/mailer). |
| `correlation.monolog` | bool | `true` | Add `correlation_id` / `caused_by` to Monolog 3 records (`monolog.processor` tag; only when monolog/monolog is installed). |

```yaml
nowo_audit_kit:
    correlation:
        provider: true
        response_header_paths: [/api]
        http_client: true
    action_log:
        entity_class: App\Entity\UserAction   # implements TraceableActionLogInterface
        changes:
            secret_fields: [iban]
```

Services: `nowo_audit_kit.correlation.messenger_middleware` (when symfony/messenger is installed; add it to a bus),
`Nowo\AuditKitBundle\Change\ChangeSetBuilder`, `Nowo\AuditKitBundle\Timeline\TimelineMerger` (tag
`nowo_audit_kit.timeline_source`), and with the action log `Nowo\AuditKitBundle\Timeline\ActionLogTimelineSource`.
