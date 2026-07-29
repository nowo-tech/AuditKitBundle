# Upgrading

This document describes how to upgrade between versions of Audit Kit Bundle.

## 1.x

### 1.1.11

From **1.1.10** — **no action required**. Maintainer / contributor only: more robust Makefile Compose binary detection when `docker` is missing from `PATH`.

### 1.1.10

From **1.1.9** — **no action required**. Maintainer / contributor only: Makefiles prefer `docker compose` (V2) over `docker-compose` (V1).

### 1.1.9

From **1.1.8** — **no action required**. Maintainer / CI only: more resilient `make demo-smoke` (classic FrankenPHP boot + readiness wait).

### 1.1.8

From **1.1.7** — **no action required**. Maintainer / CI only: demo Makefile uses Compose V2 (`docker compose`) when the classic `docker-compose` binary is absent.

### 1.1.7

From **1.1.6** — **no action required**. Maintainer / CI only: demo Makefiles optionally include monorepo `update-deps` helpers.

### 1.1.6

From **1.1.5** — **no action required**. Maintainer / CI only: Makefile include of monorepo `update-deps` is optional so standalone GitHub checkouts can run `make demo-smoke`.

### 1.1.5

From **1.1.4** (or any earlier **1.x**) — **no action required** for applications that load the listener via Symfony DI (autowire provides `LoggerInterface`).

- New runtime Composer requirement: `psr/log` (^1 || ^2 || ^3). Run `composer update nowo-tech/audit-kit-bundle`.
- If you **manually** instantiate `AuditableEntityListener`, optional sixth constructor argument: `Psr\Log\LoggerInterface` (defaults to `NullLogger`).
- Public config, traits, and audit field behavior are unchanged. Failed blame Doctrine references now emit a warning log (NullLogger by default).

### 1.1.4

From **1.1.3** (or any earlier **1.x**) — **no action required** for applications that load the listener via Symfony DI (autowire provides `ClockInterface`).

- New runtime Composer requirement: `psr/clock` (^1.0). Run `composer update nowo-tech/audit-kit-bundle`.
- If you **manually** instantiate `AuditableEntityListener`, pass a `Psr\Clock\ClockInterface` as the fifth constructor argument (e.g. `Symfony\Component\Clock\NativeClock`).
- Public config, traits, and audit field behavior are unchanged.

### 1.1.3

From **1.1.2** (or any earlier **1.x**) — **no action required** for Packagist consumers. Public API, configuration, and runtime behavior unchanged.

- Maintainer / contributor tooling: PHPStan FrankenPHP rulesets (REQ-CS-005), README worker-mode banner (REQ-DOCS-017), and Makefile targets `down-dev` / demo `update-bundle`.

### 1.1.2

From **1.1.1** (or any earlier **1.x**) — **no action required** for Packagist consumers. Public API and configuration unchanged.

- Demo / contributor only: FrankenPHP classic vs worker is selected with `FRANKENPHP_MODE` (default `worker`). If you previously relied on `APP_ENV=dev` alone to disable worker mode, set `FRANKENPHP_MODE=classic` in `demo/symfony8/.env` and recreate the container. See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

### 1.1.1

From **1.1.0** (or any **1.0.x**) — **no action required** for Packagist consumers. Public API and configuration unchanged.

- Repository / contributor tooling only: REQ-GIT-001 (no Cursor co-author trailers in git history), Code of Conduct, and CI matrix fix for Doctrine Bundle on PHP 8.2/8.3.
- **Contributors:** run `make setup-hooks` once per clone; `make check-no-cursor-coauthor` is part of `make release-check`. See [GITHUB_CI.md](GITHUB_CI.md).

### 1.1.0

From **1.0.5**, **1.0.4**, **1.0.3**, **1.0.2**, **1.0.1**, or **1.0.0** — backward compatible for single-entity setups.

```bash
composer update nowo-tech/audit-kit-bundle
```

**No migration required** if you keep the flat configuration (`user_class` at root). It is normalized internally to a single `default` profile.

**What is new:**

- Multiple user entities can each have their own audit field mapping and feature flags under `nowo_audit_kit.profiles`.
- Automatic profile resolution uses the authenticated entity class (cached O(1) lookup).
- Legacy DI parameters (`nowo_audit_kit.user_class`, `nowo_audit_kit.enabled`, etc.) still reflect the default profile.

**Optional migration to profiles layout:**

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
```

**Behavior notes:**

- **Timestamps:** resolved from the authenticated user's profile when possible; otherwise the `default_profile` is used (CLI, guest, or unmapped user classes).
- **Blame fields:** only populated when the authenticated user matches the active profile's `user_class`. Unmapped users (e.g. a third-party SSO user) do not set blame fields even if timestamps are applied via the default profile.
- When **all profiles** have `enabled: false`, the Doctrine listener is not registered (same as global `enabled: false` before 1.1.0).

### 1.0.5

- **No action required** for Packagist consumers. Demo-only release; public API unchanged since 1.0.0.
- **Contributors / demo users:** the Symfony 8 demo now persists `LegacyRecord` with `#[Auditable(enabled: false)]` on each page load. Run `make -C demo/symfony8 link-bundle` (or `make up`) so Doctrine creates the `legacy_records` table.

### 1.0.4

- **No action required** for Packagist consumers. Demo and maintainer-tooling release; public API unchanged since 1.0.0.
- **Contributors / demo users:** `make update-deps` from the bundle root now updates demo dependencies too. The Symfony 8 demo no longer starts a Postgres container (SQLite only). If you relied on `compose.override.yaml` for a local Postgres port, remove that expectation or restore a local override outside the repo.

### 1.0.3

- **No action required** for Packagist consumers. Documentation and PHPDoc-only release; public API unchanged since 1.0.0.

### 1.0.2

- **No action required** for Packagist consumers. Repository-only fix: removed stale demo folders that were accidentally included in the v1.0.1 tag.

### 1.0.1

- **No action required** for applications consuming the bundle from Packagist. Public API, configuration keys, and traits are unchanged since 1.0.0.
- **Contributors / clones:** stale Wallet QR demo folders were removed; use `demo/symfony8` only.

### 1.0.0

First stable release.

- **Requirements:** PHP **8.2+**, Symfony **^7.0 || ^8.0**, Doctrine ORM **^2.15 || ^3.0**.
- **Configuration:** set `nowo_audit_kit.user_class` to your user entity FQCN (required).
- **Entities:** add `AuditableTrait` (or `TimestampableTrait` / `BlameableTrait` separately) and ensure Doctrine mappings exist for the audit columns.
- **Migrations:** generate and run a migration for new timestamp and blame columns before deploying.
- **No prior versions:** there is no upgrade path from another package; install with `composer require nowo-tech/audit-kit-bundle`.
