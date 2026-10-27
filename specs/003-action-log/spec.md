# Feature Specification: Action log (who did what, to whom)

**Feature Branch**: `003-action-log`  
**Created**: 2026-10-13  
**Status**: Implemented (v1.5.0)

**Package**: `nowo-tech/audit-kit-bundle`  
**Configuration root**: `nowo_audit_kit.action_log` (new, opt-in)  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md) · [`../002-doctrine-helpers/spec.md`](../002-doctrine-helpers/spec.md)  
**Origin**: Symfony Beacon spec `138-kit-medium-extractions` FR-040 (KIT-ACTION-LOG) — Beacon's `UserAction` entity, `UserActionRecorder`, `UserActionRepository` (filters, keyset purge) and `app:user-actions:purge-views` move into the kit that already owns the entity-level audit fields.

---

## Summary

An opt-in, append-only action log next to the entity-level audit fields (`createdAt` / `createdBy` …, unchanged):

| Unit | Purpose |
| ---- | ------- |
| `ActionLog\AbstractActionLog` | Doctrine mapped superclass: `action`, `actor`, `subject`, `context`, `scope`, `ipAddress`, `createdAt`. The host declares the entity (table, indexes, repository) and may rename columns. |
| `ActionLog\ActionLogRecorder` | `record()` / `recordAndFlush()` for the configured entity class; `string\|BackedEnum` actions; client IP from `RequestStack`; optional scope copied from a context key. |
| `ActionLog\ActionLogRepository` | Base repository: `findByCriteria()`, `countByCriteria()`, `findLatest()`, `findInvolving()`, keyset `purgeBefore()`. |
| `ActionLog\ActionLogCriteria` | Filter value object (actor, subject, actor-or-subject, scope(s), action(s), date range, limit / offset). |
| `ActionLog\Command\PurgeActionLogCommand` | `nowo:audit-kit:action-log:purge --older-than-days=N [--action=…] [--all-actions] [--batch-size=N]`. |
| `@NowoAuditKitBundle/action_log/_timeline.html.twig` | Optional timeline partial (UiKit `nowo-ui-*` classes + `nowo-audit-timeline__*` hooks), overridable translation keys. |

Nothing changes when `action_log.entity_class` is not set: no service, no Doctrine mapping, no table.

---

## User Scenarios

### US-01 — Record an action (P1)

**Given** `action_log.entity_class: App\Entity\UserAction` (a subclass of `AbstractActionLog`),  
**When** a service calls `$recorder->record(UserActionType::GroupCreated, $actor, $subject, ['group_uuid' => …])`,  
**Then** a row is persisted (not flushed) with the enum backing value as `action`, the actor / subject, the context and the client IP of the current request (`null` in CLI). `recordAndFlush()` also flushes.

### US-02 — Scope from context (P2)

**Given** `scope_context_key: project_uuid`,  
**When** a row is recorded without an explicit scope and `context.project_uuid` is a non-empty string that fits the `scope` column,  
**Then** it is copied into `scope` (indexed). An explicit `$scope` argument wins; an over-long or non-string value leaves `scope` null.

### US-03 — Timelines (P1)

**Given** rows by several actors, about several subjects, in several scopes,  
**When** the host queries `findByCriteria(new ActionLogCriteria(involving: $user, actions: $allowed, from: …, to: …, limit: 100))`,  
**Then** matching rows come newest first (`createdAt DESC, id DESC`) with actor and subject fetch-joined. An empty allow-list (`actions: []` or `scope: []`) returns no rows (never "all").

### US-04 — Retention purge (P1)

**Given** page-view rows older than N days and audit rows of other actions,  
**When** `nowo:audit-kit:action-log:purge` runs without `--action`,  
**Then** only the configured `purge.actions` older than `--older-than-days` (default `purge.older_than_days`) are deleted, in keyset batches over the primary key; other actions are kept. With no configured list and no `--action`, the command refuses (`--all-actions` purges everything).

### US-05 — Existing table, no migration (P1)

**Given** a host table such as Beacon's `user_action` (`subject_user_id`, `project_uuid` VARCHAR(36), four named indexes, FKs `ON DELETE SET NULL`),  
**When** the host entity extends `AbstractActionLog` with `#[ORM\AttributeOverrides]` (`scope` → `project_uuid`, length 36) and `#[ORM\AssociationOverrides]` (`subject` → `subject_user_id`) and re-declares its indexes,  
**Then** `doctrine:schema:update --dump-sql` is empty.

---

## Requirements

- **FR-AL-001 (entity)**: `AbstractActionLog` is an `#[ORM\MappedSuperclass]` with explicit column names (independent of the naming strategy): `id` (int, generated), `action` (string 48, not null), `actor` / `subject` (`ManyToOne` to `AuditUserInterface`, join columns `actor_id` / `subject_id`, nullable, `onDelete: SET NULL`), `context` (json, not null, default `[]`), `scope` (string 64, nullable), `ip_address` (string 45, nullable), `created_at` (`datetime_immutable`, not null, from `CreatedAtImmutableTrait`, set in the constructor). No table, index or repository is declared on the superclass. Fluent setters return `static`; `setAction(string|BackedEnum)`, `isAction()`, static `actionValue()`; `setScope('')` stores `null`.
- **FR-AL-002 (user target)**: `AuditUserInterface` is resolved to the default profile `user_class` by the existing `resolve_target_entities` prepend (implementing the interface stays optional).
- **FR-AL-003 (mapping registration)**: when `action_log.entity_class` is set and `register_mapping` is true (default), the extension prepends the ORM mapping `NowoAuditKitActionLog` (attribute, `src/ActionLog`, prefix `Nowo\AuditKitBundle\ActionLog`). With several entity managers, set `register_mapping: false` and map that directory on the right manager.
- **FR-AL-004 (recorder)**: `ActionLogRecorder` (final, readonly, stateless) is registered only when the action log is enabled; it resolves the manager with `ManagerRegistry::getManagerForClass()` and throws `LogicException` when no ORM manager handles the class. `capture_ip: false` disables IP capture.
- **FR-AL-005 (repository)**: `ActionLogRepository<T>` (extends `ServiceEntityRepository`, constructor `(ManagerRegistry, class-string<T>)`). Queries use DQL on mapped fields; `purgeBefore(DateTimeImmutable $before, ?array $actions, int $batchSize = 1000)` runs on DBAL with table / column names from the metadata (quote strategy), `SELECT id … WHERE id > :afterId AND created_at < :before [AND action IN (…)] ORDER BY id LIMIT n` then `DELETE … WHERE id IN (…)`; `null` actions = every action, `[]` = nothing.
- **FR-AL-006 (command)**: `nowo:audit-kit:action-log:purge` — `--older-than-days` (≥ 1), repeatable `--action`, `--all-actions`, `--batch-size` (≥ 1); invalid values return `INVALID`. Uses the entity repository when it extends `ActionLogRepository`, otherwise a base `ActionLogRepository`.
- **FR-AL-007 (config)**: `nowo_audit_kit.action_log.{entity_class, register_mapping, scope_context_key, capture_ip, purge.{older_than_days, batch_size, actions}}`; `entity_class` must extend `AbstractActionLog` (else `InvalidArgumentException` at container build). Parameters `nowo_audit_kit.action_log.*`.
- **FR-AL-008 (timeline)**: `@NowoAuditKitBundle/action_log/_timeline.html.twig` renders `entries`; keys / domains (`translation_domain`, `empty_key`, `by_key`, `by_system_key`, `subject_key`, `action_prefix`, `action_domain`, `context_prefix`), `show_ip`, `show_context`, `date_format`, `timeline_id`, `entry_testid` are overridable; user label = `displayName` ?? `userIdentifier` ?? `id`. Translations `NowoAuditKitBundle` (en, es). Twig and UiKit are suggestions, not requirements.
- **FR-AL-009 (dependencies)**: `symfony/console` becomes a runtime requirement (`^7.4 || ^8.0`); `twig/twig` is a dev requirement (template test).

---

## Success Criteria

- **SC-001**: Integration tests (real kernel, DoctrineBundle, SQLite): mapping (columns, lengths, nullability, join columns, SET NULL, resolved target), recorder (enum, IP, scope from context, explicit / invalid scope, no request), every filter and their combinations, ordering, limit / offset, count, empty allow-lists, keyset purge in batches (listed actions only, `null` = all, `[]` = none), command (configured actions, `--action`, `--all-actions`, invalid options, refusal, base-repository fallback).
- **SC-002**: Beacon compatibility test — schema generated from a copy of Beacon's `UserAction` and from the adopted subclass produce no ALTER statement on SQLite and MySQL 8 (both directions).
- **SC-003**: Unit tests — configuration defaults / processing, extension opt-in, invalid entity class, mapping prepend on / off, criteria normalisation, entity setters and enum values (string- and int-backed), recorder without manager, Twig timeline (empty state, overrides, actor / system / subject / IP / context, hidden IP / context).
- **SC-004**: 100% PHP line coverage, PHPStan, CS, Rector, Igor green (`make release-check`).

## Explicit non-goals

- No catalog of actions: hosts keep their enum (Beacon keeps `UserActionType`).
- No JSON-path filters on `context` (platform-specific; Beacon keeps its `group_uuid` query) and no denormalised columns other than `scope`.
- No Doctrine migration shipped: hosts generate theirs (or none, for an existing table).
- The entity-level audit features (`TimestampableTrait`, `BlameableTrait`, listeners, profiles) are unchanged.
