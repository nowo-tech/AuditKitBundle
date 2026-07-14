# Feature Specification: AuditKitBundle baseline

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-14  
**Status**: Implemented (v1.0.0)

**Package**: `nowo-tech/audit-kit-bundle`  
**Configuration root**: `nowo_audit_kit`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

Symfony + Doctrine bundle providing **four automatic auditing fields** on persist/update:

| Field | When set | Source |
| ----- | -------- | ------ |
| `createdAt` | insert | system clock |
| `updatedAt` | insert + update | system clock |
| `createdBy` | insert | authenticated user (nullable) |
| `updatedBy` | insert + update | authenticated user (nullable) |

All four are **in scope for v1.0.0**. Property names are configurable under `nowo_audit_kit.fields` (defaults: `createdAt`, `updatedAt`, `createdBy`, `updatedBy`).

Implemented via optional **traits**, marker **interfaces**, and a **Doctrine entity listener** (`prePersist` / `preUpdate`). Timestamps are always written; blame fields use Symfony Security when a user is logged in. Works on **any** auditable entity, not only `User`.

Independent of AuthKitBundle and UserKitBundle; only requires a resolvable `user_class` and an authenticated token when blame fields should be populated.

---

## User Scenarios

### US-01 — Set created fields on insert (P1)

**Given** an entity uses `AuditableTrait` and an authenticated user is present,  
**When** the entity is persisted for the first time,  
**Then** `createdAt` and `updatedAt` are set to now, and `createdBy` / `updatedBy` reference the current user.

### US-02 — Set updated fields on update (P1)

**Given** an existing auditable entity and an authenticated user,  
**When** the entity is updated,  
**Then** `updatedAt` is refreshed and `updatedBy` references the current user; `createdAt` / `createdBy` remain unchanged.

### US-03 — Guest / CLI context (P1)

**Given** no authenticated user (console command, fixture, anonymous request),  
**When** an auditable entity is persisted,  
**Then** timestamp fields are still set; blame fields remain `null` (no exception).

### US-04 — Opt-out per entity (P2)

**Given** an entity implements `AuditableInterface` but uses attribute `#[Auditable(enabled: false)]` (or equivalent config),  
**When** persisted,  
**Then** the listener skips that entity.

### US-05 — Custom property names (P2)

**Given** global config maps `created_by` → `createdByUser` property,  
**When** the listener runs on an entity with that property,  
**Then** values are written to the mapped fields.

### US-06 — Blame-only or timestamp-only (P2)

**Given** an entity uses only `TimestampableTrait` (no blame columns),  
**When** persisted/updated,  
**Then** only timestamp fields are managed; no error for missing blame properties.

### US-07 — User reference type (P1)

**Given** `nowo_audit_kit.user_class: App\Entity\User`,  
**When** blame fields are set,  
**Then** the listener assigns a managed reference or identifier compatible with the configured Doctrine mapping (ManyToOne recommended in docs).

---

## Requirements

### Bundle & configuration

- **FR-BUNDLE-001**: `NowoAuditKitBundle` with extension alias `nowo_audit_kit`.
- **FR-CFG-001**: `Configuration` tree:
  - `enabled` (bool, default `true`).
  - `user_class` (string, required) — FQCN used for `createdBy` / `updatedBy` references.
  - `fields` — default property names:
    - `created_at` (default `createdAt`)
    - `updated_at` (default `updatedAt`)
    - `created_by` (default `createdBy`)
    - `updated_by` (default `updatedBy`)
  - `timestamp_type` — `datetime_immutable` (default) | `datetime`.
  - `blameable` (bool, default `true`) — master switch for blame fields.
  - `timestampable` (bool, default `true`) — master switch for timestamps.
- **FR-CFG-002**: Default YAML template under `Resources/config/packages/nowo_audit_kit.yaml`.

### Contracts & traits

- **FR-CONTRACT-001**: `TimestampableInterface` — getters/setters for created/updated timestamps.
- **FR-CONTRACT-002**: `BlameableInterface` — getters/setters for createdBy/updatedBy (typed to `user_class` or `object|null`).
- **FR-CONTRACT-003**: `AuditableInterface` — extends both (marker for listener discovery).
- **FR-TRAIT-001**: `TimestampableTrait` — ORM columns + accessors for `createdAt` and `updatedAt` (`DateTimeImmutable` by default).
- **FR-TRAIT-002**: `BlameableTrait` — ManyToOne to user (configurable join column name in docs) + accessors.
- **FR-TRAIT-003**: `AuditableTrait` — combines timestamp + blame traits.

### Doctrine integration

- **FR-ORM-001**: `AuditableEntityListener` registered for `prePersist` and `preUpdate`.
- **FR-ORM-002**: Listener discovers auditable entities via interface or trait (documented convention: implement `AuditableInterface` or use `AuditableTrait`).
- **FR-ORM-003**: `AuditablePropertyResolver` — resolves configured field names on entity metadata or PropertyAccessor.
- **FR-ORM-004**: Idempotent behavior — on update, listener refreshes `updatedAt` (and `updatedBy` when authenticated) but never overwrites `createdAt` or `createdBy`.
- **FR-ORM-005**: On insert, listener sets both timestamps to the same instant; on update, only `updatedAt` changes.

### Security integration

- **FR-SEC-001**: `CurrentUserResolver` — wraps `Security` service; returns `null` when anonymous or CLI.
- **FR-SEC-002**: No hard dependency on AuthKitBundle; only `symfony/security-core` (or equivalent).

### Documentation

- **FR-DOC-001**: Migration examples for adding audit columns to existing entities.
- **FR-DOC-002**: Recommendation for `onDelete: SET NULL` on blame ManyToOne relations.

---

## Success Criteria

- **SC-001**: All production files listed in `code-inventory.md` implemented and mapped.
- **SC-002**: Persist with authenticated user sets all four fields (functional test).
- **SC-003**: Update changes only `updatedAt` / `updatedBy` (functional test).
- **SC-004**: CLI persist leaves blame null, sets timestamps (functional test).
- **SC-005**: 100% PHPUnit line coverage on `src/`.
- **SC-006**: PHPStan max level passes.
- **SC-007**: Demo with sample `Article` entity using `AuditableTrait`.

---

## Explicit non-goals

- User enable/disable or login blocking (UserKitBundle).
- Login, registration, password reset (AuthKitBundle).
- Full audit log table / revision history (who changed what field).
- Envers or third-party versioning integration in v1.
- Automatic auditing of **all** entities without opt-in (must be explicit per entity).
- Soft-delete (`deletedAt`) — candidate for v2 or separate spec.

---

## Validation

When implemented:

- `composer qa`, `make test-coverage-100`, PHPStan.
- Demo: create/update entity as logged-in user; verify DB columns.
- Inventory row audit vs. `find src -type f`.

---

## Version roadmap (informative)

| Version | Scope |
| ------- | ----- |
| **v1.0.0** | Traits, listener, config, timestamp + blame, docs, demo |
| **v1.1.0** | Per-entity opt-out attribute, custom field name maps |
| **v1.2.0** | Gedmo migration guide / compatibility notes (if needed) |

---

## Relationship with UserKitBundle

| Concern | Bundle |
| ------- | ------ |
| Who created/updated a record | **AuditKitBundle** |
| Whether a user can log in | **UserKitBundle** |
| Whether a user appears online | **UserKitBundle** |
| Login form and routes | **AuthKitBundle** |

A typical `User` entity may use **UserKit** traits (`enabled`, `lastActivityAt`) and still be referenced as `createdBy` / `updatedBy` on other entities via **AuditKit**.
