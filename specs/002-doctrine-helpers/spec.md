# Feature Specification: Doctrine helpers (public UUID, immutable created-at, SQL LIKE escaping)

**Feature Branch**: `002-doctrine-helpers`  
**Created**: 2026-10-13  
**Status**: Implemented (v1.4.0)

**Package**: `nowo-tech/audit-kit-bundle`  
**Configuration root**: `nowo_audit_kit` (no new keys)  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Origin**: Symfony Beacon spec `137-kit-small-extractions` FR-001 (KIT-DOCTRINE-HELPERS) — generic entity helpers copied into most workspace apps move into the kit that already ships `TimestampableTrait`.

---

## Summary

Three opt-in Doctrine helpers next to the existing audit traits:

| Unit | Purpose |
| ---- | ------- |
| `Model\PublicUuidInterface` + `Model\PublicUuidTrait` | Public opaque `uuid` column for URLs (integer PKs stay internal). |
| `Doctrine\PublicUuidListener` | Global `prePersist` listener: calls `ensureUuid()` on `PublicUuidInterface` entities only. |
| `Model\CreatedAtImmutableTrait` | Non-null, append-only `createdAt` set once by `initializeCreatedAt()`. |
| `Doctrine\SqlLike` | Escape user input for `LIKE` and the `ESCAPE '\'` clause that works on MySQL and SQLite. |

Nothing changes for applications that do not use them: no new configuration, no schema change for existing entities.

---

## User Scenarios

### US-01 — Public UUID on insert (P1)

**Given** an entity implements `PublicUuidInterface` and uses `PublicUuidTrait`,  
**When** it is persisted without a UUID,  
**Then** the listener assigns a UUID v7 (RFC 4122 string, 36 chars) before insert.

### US-02 — Stable UUID chosen by the caller (P1)

**Given** an entity on which `assignUuid('<valid uuid>')` was called,  
**When** it is persisted,  
**Then** the assigned value is kept (`ensureUuid()` is idempotent).

### US-03 — Invalid UUID rejected (P1)

**Given** `assignUuid()` receives a non-empty value that is not an RFC UUID,  
**Then** `InvalidArgumentException('invalid_uuid:<trimmed value>')` is thrown and the current value is unchanged. An empty / blank value is a no-op.

### US-04 — Non-opted entities untouched (P1)

**Given** an entity that does not implement `PublicUuidInterface` (even if it declares an `ensureUuid()` method),  
**When** it is persisted,  
**Then** the listener does nothing (no duck typing).

### US-05 — Append-only rows (P2)

**Given** an entity uses `CreatedAtImmutableTrait` and calls `initializeCreatedAt()` in its constructor,  
**Then** `getCreatedAt()` returns a non-null `DateTimeImmutable` mapped as a non-null `datetime_immutable` column.

### US-06 — Literal `%` / `_` in search (P1)

**Given** a DQL query `x LIKE :q ` . `SqlLike::ESCAPE_CLAUSE` with `:q = SqlLike::contains($term)`,  
**When** `$term` contains `%`, `_` or `\`,  
**Then** they match literally on both MySQL and SQLite.

---

## Requirements

- **FR-UUID-001**: `PublicUuidInterface` — `getUuid(): string`, `assignUuid(string $uuid): void`, `ensureUuid(): void`.
- **FR-UUID-002**: `PublicUuidTrait` — `#[ORM\Column(length: 36)] private string $uuid = ''` (string, not null; same mapping as the host copies so adopting apps need no migration); `assignUuid()` trims, ignores empty, validates with `Symfony\Component\Uid\Uuid::isValid()`; `ensureUuid()` assigns `Uuid::v7()->toRfc4122()` only when empty.
- **FR-UUID-003**: `PublicUuidListener` — registered by the bundle as `doctrine.event_listener` (`prePersist`, lazy); acts only on `PublicUuidInterface`; independent of audit profiles (kept when every profile is disabled).
- **FR-CREATED-001**: `CreatedAtImmutableTrait` — `#[ORM\Column] private DateTimeImmutable $createdAt`, `protected initializeCreatedAt(): void`, `getCreatedAt(): DateTimeImmutable`. Documented as the append-only alternative to `TimestampableTrait` (nullable, updated by the listener); the two must not be combined on one entity.
- **FR-LIKE-001**: `SqlLike` (final, static) — `escape(string): string` escapes `\`, `%`, `_` with `\`; `contains(string): string` returns `%` + escaped + `%`; `ESCAPE_CLAUSE = "ESCAPE '\\'"` (renders `ESCAPE '\'`). Not registered as a service.
- **FR-DEP-001**: `symfony/uid` (`^7.4 || ^8.0`) becomes a runtime requirement.

---

## Success Criteria

- **SC-001**: Unit tests — trait (default empty, v7 generation, idempotency, assign valid / blank / invalid), listener with and without the interface (including a duck-typed `ensureUuid()`), `SqlLike` escaping table and clause.
- **SC-002**: Integration test (real kernel, DoctrineBundle, SQLite): listener wired, UUID persisted, assigned UUID kept, column mappings (`uuid` string 36 not null; `createdAt` `datetime_immutable` not null), DQL `LIKE … ESCAPE '\'` matches `%`, `_`, `\` literally.
- **SC-003**: 100% PHP line coverage, PHPStan, CS, Rector, Igor green (`make release-check`).

## Explicit non-goals

- No MySQL service in the bundle test setup: MySQL already uses `\` as default LIKE escape, so the explicit clause is a no-op there; the SQLite test covers the platform that needs it.
- No `uuid` Doctrine type / binary storage: string column kept for host compatibility.
- Other kits (DashboardMenuBundle `MenuRepository`, BreadcrumbKitBundle `BreadcrumbCollectionRepository`) may switch to `SqlLike` in their own releases.
