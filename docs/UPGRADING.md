# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.8.0](#to-180)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
- [To 1.1.19](#to-1119)
- [To 1.1.18](#to-1118)
- [To 1.1.17](#to-1117)
- [1.x](#1x)
  - [1.1.16](#1116)
  - [1.1.15](#1115)
  - [1.1.14](#1114)
  - [1.1.13](#1113)
  - [1.1.12](#1112)
  - [1.1.11](#1111)
  - [1.1.10](#1110)
  - [1.1.9](#119)
  - [1.1.8](#118)
  - [1.1.7](#117)
  - [1.1.6](#116)
  - [1.1.5](#115)
  - [1.1.4](#114)
  - [1.1.3](#113)
  - [1.1.2](#112)
  - [1.1.1](#111)
  - [1.1.0](#110)
  - [1.0.5](#105)
  - [1.0.4](#104)
  - [1.0.3](#103)
  - [1.0.2](#102)
  - [1.0.1](#101)
  - [1.0.0](#100)

## Unreleased

## To 1.8.0

From **1.7.0**:

```bash
composer update nowo-tech/audit-kit-bundle
```

## To 1.7.0

From **1.6.0** — new `SqlFulltext` helper; no configuration, dependency or schema change.

```bash
composer update nowo-tech/audit-kit-bundle
```

- **Application copies of a FULLTEXT / LIKE search helper** (e.g. Symfony Beacon `App\Issues\Repository\Query\PaletteTextMatch`) can be replaced with `SqlFulltext::clause()`, `::booleanQuery()` and `::snippet()` — same signatures and results with the defaults; the 4th argument of `clause()` also accepts the DBAL `Connection` or platform.
- The FULLTEXT form still needs a `MATCH` DQL function registered in the host (e.g. `beberlei/doctrineextensions` `MatchAgainst`). See [Usage — Full-text search](USAGE.md#full-text-search).

## To 1.6.0

From **1.5.0** — new `KeysetDelete` helper; no configuration, dependency or schema change.

```bash
composer update nowo-tech/audit-kit-bundle
```

- **Hand-written batched deletes** (`SELECT id … LIMIT n` + `DELETE … WHERE id IN (…)` loops) can be replaced with `KeysetDelete::run($connection, $table, $where, $params, $types, $batchSize)`. Drop the `id > ?` condition from the host clause (the helper adds the keyset bound) and express joins as `col IN (SELECT …)`. See [Usage — Batched deletes](USAGE.md#batched-deletes).
- `ActionLogRepository::purgeBefore()` keeps its signature and results. Subclasses that override it are unaffected.

## To 1.5.0

From **1.4.0** — new opt-in action log; nothing changes until `nowo_audit_kit.action_log.entity_class` is set.

```bash
composer update nowo-tech/audit-kit-bundle
```

- **New dependency:** `symfony/console` (already present in most Symfony applications).
- **Adopting the action log over an existing host table** (e.g. Beacon `UserAction`):
  1. Make the entity extend `Nowo\AuditKitBundle\ActionLog\AbstractActionLog`; keep `#[ORM\Entity]`, `#[ORM\Table]` and the `#[ORM\Index]` list; delete the host properties and accessors the superclass provides.
  2. Map renamed columns with `#[ORM\AttributeOverrides]` (e.g. `scope` → `project_uuid`, length 36) and `#[ORM\AssociationOverrides]` (e.g. `subject` → `subject_user_id`, repeat `nullable` / `onDelete: 'SET NULL'`).
  3. Make the repository extend `ActionLogRepository` (constructor `parent::__construct($registry, UserAction::class)`); replace the host keyset purge with `purgeBefore()`; DQL on `subjectUser` becomes `subject`, `projectUuid` becomes `scope`.
  4. Configure `action_log.entity_class`, `scope_context_key` (if the scope is copied from the context) and `purge.actions`; replace the host recorder with `ActionLogRecorder` (same argument order: action, actor, subject, context) and the host purge command / schedule entry with `nowo:audit-kit:action-log:purge`.
  5. `getAction()` now returns the stored string: add a host accessor for the enum (`UserActionType::from($this->getAction())`) and use `entry.action` instead of `entry.action.value` in Twig.
  6. Check: `php bin/console doctrine:schema:update --dump-sql` prints nothing.
- **Several entity managers:** set `action_log.register_mapping: false` and map `src/ActionLog` of the bundle on the manager that owns the log table.

## To 1.4.0

From **1.3.0** — new opt-in Doctrine helpers; no configuration or schema change for existing entities.

```bash
composer update nowo-tech/audit-kit-bundle
```

- **New dependency:** `symfony/uid` (installed automatically).
- **Public UUID:** implement `Nowo\AuditKitBundle\Model\PublicUuidInterface` and use `PublicUuidTrait`. The bundle registers `PublicUuidListener` (`prePersist`), so delete any host listener that called `ensureUuid()`. The column mapping (`uuid`, string 36, not null) matches the usual host copy, so switching needs no migration (check with `doctrine:schema:validate`). The listener only acts on entities implementing the interface: add `implements PublicUuidInterface` where a host listener relied on `method_exists()`.
- **`CreatedAtImmutableTrait`:** drop-in for a host trait with `#[ORM\Column] private DateTimeImmutable $createdAt` + `initializeCreatedAt()`. Do not combine it with `TimestampableTrait` on the same entity.
- **`SqlLike`:** replace hand-written LIKE escaping with `SqlLike::contains($term)` and append `SqlLike::ESCAPE_CLAUSE` to the DQL (`x LIKE :q ` . `SqlLike::ESCAPE_CLAUSE`).

## To 1.3.0

From **1.2.0** — default_profile resolved after config merge; Symfony >=7.4.

```bash
composer update nowo-tech/audit-kit-bundle
```

- **Requirements:** Symfony **^7.4 || ^8.0** (was `^7.0 || ^8.0`). Symfony 7.0–7.3 are no longer supported; upgrade to 7.4 LTS first.
- **`default_profile`:** when it is not set explicitly, it is now the first profile of the *merged* configuration. If you relied on a later config file (e.g. `when@prod`) implicitly switching the default to its first profile, set `default_profile` explicitly in that file.

## To 1.2.0

From **1.1.19** — audit fields are now written (global Doctrine listener) and BlameableTrait is mappable.

```bash
composer update nowo-tech/audit-kit-bundle
```

- Audit fields (createdAt/updatedAt/createdBy/updatedBy) are now written for every auditable entity, without needing `#[ORM\EntityListeners]` (entities that keep that mapping are audited once).
- Blame associations now target `AuditUserInterface`, resolved to the default profile's `user_class` via `resolve_target_entities`: run `make:migration` / review the schema diff.

## To 1.1.19

From **1.1.18** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/audit-kit-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.1.18

From **1.1.17** — dependency refresh only.

```bash
composer update nowo-tech/audit-kit-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.1.17

From **1.1.16** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/audit-kit-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

This document describes how to upgrade between versions of Audit Kit Bundle.

## 1.x

### 1.1.16

No configuration changes for typical Symfony DI apps (`RequestStack` and `@?security.firewall.map` are autowired).

**Behaviour (FrankenPHP worker / no kernel reset):** during an HTTP request, blame fields (`created_by` / `updated_by`) stay `null` when the request is not behind a firewall with `security: true` (public paths, `security: false` routes, or flushes before the firewall runs), even if `TokenStorage` still holds a previous request's token. With Symfony's default `services_resetter` the token is already cleared there, so classic PHP-FPM and reset-enabled worker setups are unchanged. CLI and Messenger (no main request) keep using the token set by the caller.

If you **manually** instantiate `CurrentUserResolver`, optional constructor arguments: `RequestStack` and the SecurityBundle firewall map (defaults keep the previous trust-all-tokens behaviour when omitted).

Blame Doctrine references prefer the event's `ObjectManager` (injected `EntityManagerInterface` remains as fallback for BC). New runtime Composer requirement: `symfony/http-foundation` (^7 || ^8) — normally already present via FrameworkBundle. Run `composer update nowo-tech/audit-kit-bundle`.

See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

### 1.1.15

From **1.1.14** — **no action required**.

### 1.1.14

From **1.1.13** — **no action required**.

### 1.1.13

From **1.1.12** — **no action required**.

### 1.1.12

From **1.1.11** — **no action required**. Demos only: Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

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
