# Spec-driven development — AuditKitBundle

**Status:** Specification phase (2026-07-14)

This repository follows the Nowo bundle **spec-driven development** model in three layers:

1. **Baseline spec** — [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
2. **Integrator docs** — `docs/INSTALLATION.md`, `CONFIGURATION.md`, `USAGE.md` (to be added at implementation).
3. **Mechanical proof** — PHPUnit 100% coverage, PHPStan, CI (to be added at implementation).

---

## Bundle functional scope

**Goal:** Automatic Doctrine auditing with **four fields** on any opt-in entity:

- **`createdAt`** / **`updatedAt`** — timestamps (always set, including CLI)
- **`createdBy`** / **`updatedBy`** — blame references (nullable without authenticated user)

### In scope

- Optional traits (`AuditableTrait` = timestamps + blame; or `TimestampableTrait` / `BlameableTrait` alone).
- Doctrine entity listener on `prePersist` / `preUpdate`.
- Configurable property names and `user_class` for blame references.
- Safe behavior when no authenticated user (CLI, fixtures) — blame fields stay null.
- Per-entity opt-in (interface or trait); no global auto-audit of all entities.

### Explicit non-goals

- User enable/disable or login blocking → **UserKitBundle**.
- Auth flows → **AuthKitBundle**.
- Field-level revision history / audit log table.
- Envers integration in v1.
- Soft-delete columns (`deletedAt`) — future spec.

---

## User stories (backlog)

| ID | Story |
| --- | --- |
| US-01 | **As a** developer, **I want** created/updated timestamps set automatically **so that** I do not duplicate listener code in every entity. |
| US-02 | **As a** developer, **I want** createdBy/updatedBy from the logged-in user **so that** I know who changed records in admin apps. |
| US-03 | **As a** developer running CLI commands, **I want** blame fields to stay null without errors **so that** fixtures and migrations work. |
| US-04 | **As an** integrator, **I want** to use only timestamps or only blame **so that** legacy entities can adopt auditing incrementally. |

Full acceptance criteria: [`spec.md`](../specs/001-baseline/spec.md).

---

## Ecosystem placement

| Bundle | Responsibility |
| ------ | -------------- |
| **AuthKitBundle** | Login, register, password reset |
| **UserKitBundle** | `enabled`, `lastActivityAt`, online detection, login block |
| **AuditKitBundle** | `createdBy`, `updatedBy`, `createdAt`, `updatedAt` on any entity |

The `User` entity is often referenced by AuditKit blame fields and may also use UserKit traits — the bundles remain independent in `composer.json`.

---

## Validating the spec (when implemented)

- `make test-coverage-100` / `composer qa`
- Demo: persist/update `Article` (or similar) as authenticated user; verify DB columns
- Inventory: every file under `src/` mapped in `code-inventory.md`

---

## Version roadmap

See **Version roadmap** section in [`spec.md`](../specs/001-baseline/spec.md).

**MVP (v1.0.0):** Traits, listener, config, timestamp + blame, demo entity.

**v1.1.0:** `#[Auditable]` attribute, custom field maps.

**v1.2.0:** Migration guides (e.g. from Gedmo Blameable).
