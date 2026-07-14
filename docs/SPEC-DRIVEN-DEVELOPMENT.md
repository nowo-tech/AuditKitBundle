# Spec-driven development

## Table of contents

- [Layers](#layers)
- [Public API (Packagist contract)](#public-api-packagist-contract)
- [User stories](#user-stories)
- [Functional scope](#functional-scope)
- [Validation](#validation)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)
- [Engram relationship](#engram-relationship)
- [See also](#see-also)

This repository uses **spec-driven development** with three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)). **Operator manual:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — automatic audit fields on opt-in Doctrine entities ([`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md)).
3. **Traceability anchors** — `REQ-*` in Makefiles and alignment with org-wide checklist docs ([`ENGRAM.md`](ENGRAM.md)).

PHPUnit and PHPStan enforce contracts in CI. There is no separate executable spec language; Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## Public API (Packagist contract)

| Artifact | Responsibility |
| -------- | -------------- |
| `Nowo\AuditKitBundle\Model\AuditableTrait` | Timestamps + blame accessors and default Doctrine mappings |
| `Nowo\AuditKitBundle\Model\TimestampableTrait` | `createdAt` / `updatedAt` only |
| `Nowo\AuditKitBundle\Model\BlameableTrait` | `createdBy` / `updatedBy` only |
| `Nowo\AuditKitBundle\Model\*Interface` | Marker contracts for traits |
| `Nowo\AuditKitBundle\Attribute\Auditable` | Per-entity opt-out (`enabled: false`) |
| Extension `nowo_audit_kit` | DI configuration (`user_class`, `fields`, flags) |
| `AuditableEntityListener` | Doctrine `prePersist` / `preUpdate` wiring (internal service) |

**Out of package API:** `demo/symfony8`, FrankenPHP demos — illustrative only.

---

## User stories

| ID | Story |
| --- | --- |
| US-01 | **As a** developer, **I want** automatic timestamps on persist/update **so that** I avoid duplicate listener code. |
| US-02 | **As a** developer, **I want** blame fields from the logged-in user **so that** I know who changed records. |
| US-03 | **As a** developer running CLI, **I want** blame fields to stay null without errors **so that** fixtures work. |
| US-04 | **As an** integrator, **I want** timestamp-only or blame-only entities **so that** I can adopt auditing incrementally. |
| US-05 | **As an** integrator, **I want** configurable property names **so that** I match legacy column names. |
| US-06 | **As an** integrator, **I want** per-entity opt-out **so that** legacy entities are excluded. |

Full acceptance criteria: [`spec.md`](../specs/001-baseline/spec.md).

---

## Functional scope

**In scope:** optional traits, Doctrine entity listener, configurable `user_class` and field names, guest/CLI-safe blame handling, `#[Auditable(enabled: false)]`.

**Out of scope:** auth flows (AuthKitBundle), user account state (UserKitBundle), revision history tables, Envers, soft-delete columns.

---

## Validation

```bash
make test-coverage-100
make release-check
```

- Demo: `make -C demo/symfony8 up` — persist `Article` and verify audit columns.
- Inventory: every file under `src/` mapped in [`code-inventory.md`](../specs/001-baseline/code-inventory.md).

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| REQ-MAKE-006 | Root `Makefile` | `setup-hooks` installs `.githooks/pre-commit` |
| REQ-MAKE-008 | Root `Makefile` | `update-deps` syncs bundle + demo dependencies |
| REQ-TEST-006 | Root `Makefile` | `test-coverage-100` enforces 100% on `src/` |
| REQ-DEMO-005 | `demo/symfony8/Makefile` | `up` prints `Demo started at: http://localhost:<PORT>` |
| REQ-DEMO-007 | `demo/symfony8/Makefile` | `link-bundle` / `update-bundle` sync mounted bundle code |

---

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR (update [`spec.md`](../specs/001-baseline/spec.md) when scope changes).
2. **Implement** with tests under `tests/` and PHPStan clean.
3. **Anchor scripts and demos** when dev UX changes (`REQ-*` comments in Makefiles).
4. **Ship integrator docs** when behavior or configuration changes ([`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md)).
5. **Keep Spec Kit artifacts in sync** when `src/` changes — update [`code-inventory.md`](../specs/001-baseline/code-inventory.md); see [`SPEC-KIT.md`](SPEC-KIT.md).

---

## GitHub Spec Kit (summary)

| Artifact | Path |
| --- | --- |
| Operator manual | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) |

---

## Engram relationship

Cross-repo documentation hygiene and MCP setup: [`ENGRAM.md`](ENGRAM.md). This file defines **what the bundle guarantees** and **how it is proven**.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md)
- [`INSTALLATION.md`](INSTALLATION.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`USAGE.md`](USAGE.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
- [`RELEASE.md`](RELEASE.md)
- [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md)
