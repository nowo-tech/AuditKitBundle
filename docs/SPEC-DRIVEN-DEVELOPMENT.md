# Spec-driven development — AuditKitBundle

**Status:** Implemented (v1.0.0+)

This repository follows the Nowo bundle **spec-driven development** model in three layers:

1. **Baseline spec** — [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
2. **Integrator docs** — [`INSTALLATION.md`](INSTALLATION.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`USAGE.md`](USAGE.md).
3. **Mechanical proof** — PHPUnit 100% coverage on `src/`, PHPStan level 8, CI matrix, `make release-check`.

See also [`SPEC-KIT.md`](SPEC-KIT.md) for Specify CLI and Cursor Agent skills.

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
- Per-entity opt-in via traits/interfaces; `#[Auditable(enabled: false)]` opt-out.

### Explicit non-goals

- User enable/disable or login blocking → **UserKitBundle**.
- Auth flows → **AuthKitBundle**.
- Field-level revision history / audit log table.
- Envers integration in v1.
- Soft-delete columns (`deletedAt`) — future spec.

---

## User stories

| ID | Story | Status |
| --- | --- | --- |
| US-01 | Automatic created/updated timestamps | ✅ v1.0.0 |
| US-02 | createdBy/updatedBy from logged-in user | ✅ v1.0.0 |
| US-03 | Blame null in CLI without errors | ✅ v1.0.0 |
| US-04 | Timestamp-only or blame-only entities | ✅ v1.0.0 |
| US-05 | Custom property names via config | ✅ v1.0.0 |
| US-06 | Per-entity opt-out attribute | ✅ v1.0.0 |

Full acceptance criteria: [`spec.md`](../specs/001-baseline/spec.md).

---

## Ecosystem placement

| Bundle | Responsibility |
| ------ | -------------- |
| **AuthKitBundle** | Login, register, password reset |
| **UserKitBundle** | `enabled`, `lastActivityAt`, online detection, login block |
| **AuditKitBundle** | `createdBy`, `updatedBy`, `createdAt`, `updatedAt` on any entity |

---

## Validating the spec

```bash
make test-coverage-100
make release-check
```

- Demo: `make -C demo/symfony8 up` — persist `Article` and verify audit columns.
- Inventory: every file under `src/` mapped in [`code-inventory.md`](../specs/001-baseline/code-inventory.md).

---

## Engram relationship

Cross-repo documentation hygiene and MCP setup: [`ENGRAM.md`](ENGRAM.md). This file defines **what the bundle guarantees** and **how it is proven**.
