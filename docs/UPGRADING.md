# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 2.0.0](#to-200)
  - [Before you upgrade](#before-you-upgrade)
  - [The action log no longer flushes your changes](#the-action-log-no-longer-flushes-your-changes)
  - [`#[Auditable]` alone opts a class in](#auditable-alone-opts-a-class-in)
  - [Blame id mode reads only the framework-free ports](#blame-id-mode-reads-only-the-framework-free-ports)
  - [Removed](#removed)
  - [Composer](#composer)
  - [1.x support](#1x-support)
- [To 1.18.1](#to-1181)
- [To 1.18.0](#to-1180)
- [Preparing for 2.0](#preparing-for-20)
- [To 1.17.0](#to-1170)
- [To 1.16.1](#to-1161)
- [To 1.16.0](#to-1160)
- [To 1.15.1](#to-1151)
- [To 1.15.0](#to-1150)
- [To 1.14.1](#to-1141)
- [To 1.14.0](#to-1140)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.0](#to-1110)
- [To 1.10.0](#to-1100)
- [To 1.9.0](#to-190)
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

From **2.0.0**: `composer require nowo-tech/audit-kit-bundle:^2.1`. Nothing to change; 2.1 only adds API.

- **Read port (optional).** Type-hint `Nowo\AuditKitBundle\ActionLog\ActionLogReaderInterface` to list or count
  action log rows instead of extending `ActionLogRepository` or writing DQL over your log entity. It returns
  `ActionLogView` snapshots (ids, scalars) and pages with a keyset cursor (`ActionLogPage::$next`, `toToken()` for an
  HTTP parameter, `ActionLogCursor::fromToken()` to read it back; a malformed token gives `null`). Offsets are not used
  by the port: `ActionLogCriteria::$offset` is ignored there (`findByCriteria()` still honours it).
- **Repository subclasses.** `ActionLogRepository` now implements the port and gains `pageByCriteria()`,
  `latestEntries()` and `pageInvolving()`. A host subclass that already declares a method with one of these names and an
  incompatible signature must rename it (no other change).
- The reader service exists only when `action_log.entity_class` is set, like the recorder.

## To 2.0.0

From **1.18.1**:

```bash
composer update nowo-tech/audit-kit-bundle
```

```bash
composer require nowo-tech/audit-kit-bundle:^2.0 -W
```

2.0 contains only changes that 1.x announced through deprecation notices: an application that runs the last 1.x
minor without deprecation notices from this bundle upgrades without code changes. After 2.0.0 is released, the 1.x
line receives security fixes for 6 months: fixes rated Moderate and above are backported to the last 1.x minor;
Low ones are fixed in 2.x only.

**Packages that require this bundle.** Update them with it (`-W` does): LegalKitBundle 1.15.3 or later and
BlogKitBundle 1.12.0 or later accept `^2.0`. Older versions of them keep Composer on 1.x.

### Before you upgrade

On 1.18, run your test suite (or one deploy cycle) with the deprecation log on and clear every notice of this bundle.
Build-time notices show in `bin/console debug:container --deprecations`; the probe lines show per request, message or
command (profiler "Deprecations" panel, the `php` log channel, your test suite's deprecation report).

| 1.18 notice | What to do |
| --- | --- |
| `Since nowo-tech/audit-kit-bundle 1.18: Leaving "nowo_audit_kit.action_log.flush_mode" unset is deprecated: recordAndFlush() and recordIdsAndFlush() then flush the entity manager of the action log class with all your pending changes ("host"). The default becomes "isolated" in 2.0 (only the new row is written; your pending changes stay pending). Set "nowo_audit_kit.action_log.flush_mode: isolated" to opt in now, or "flush_mode: host" to keep the current behaviour explicitly.` | Fix every probe line below, then set `flush_mode: isolated` (or `host`, see [The action log no longer flushes your changes](#the-action-log-no-longer-flushes-your-changes)) |
| `Your pending changes to App\Entity\X were flushed by NowoAuditKitBundle; 2.0 no longer does.` (host-flush probe) | A change of yours that relied on the action log flush: see [A change that relied on the action log flush](#a-change-that-relied-on-the-action-log-flush) |
| `Since nowo-tech/audit-kit-bundle 1.18: Leaving "nowo_audit_kit.attribute_opt_in" unset is deprecated: #[Auditable] alone (without a bundle interface or trait) is ignored today. The default becomes true in 2.0 (the attribute alone opts a class in). Set "nowo_audit_kit.attribute_opt_in: true" to opt in now, or "attribute_opt_in: false" to keep the current behaviour explicitly.` | See [`#[Auditable]` alone opts a class in](#auditable-alone-opts-a-class-in) |
| `Since nowo-tech/audit-kit-bundle 1.15: With "nowo_audit_kit.blame_mode: id", overriding "Nowo\AuditKitBundle\Security\CurrentUserResolverInterface" is deprecated: alias "Nowo\AuditKitBundle\Security\CurrentActorProviderInterface" to your service instead. 2.0 no longer reads the former in id mode.` | Alias `CurrentActorProviderInterface` (see [Blame id mode reads only the framework-free ports](#blame-id-mode-reads-only-the-framework-free-ports)) |
| `Since nowo-tech/audit-kit-bundle 1.15: With "nowo_audit_kit.blame_mode: id", overriding "Nowo\AuditKitBundle\Blame\BlameValueResolverInterface" is deprecated: alias "Nowo\AuditKitBundle\Blame\BlameIdResolverInterface" to your service instead. 2.0 no longer reads the former in id mode.` | Alias `BlameIdResolverInterface` |

### The action log no longer flushes your changes

`action_log.flush_mode` defaults to `isolated` (REQ-ARCH-001 rule 6).

| `flush_mode` | 1.x | 2.0 |
| --- | --- | --- |
| unset | `host`, plus the 1.18 notice and probe | `isolated`: `recordAndFlush()` / `recordIdsAndFlush()` write only the new row, through a short-lived entity manager of the bundle on the same connection; your pending changes stay pending |
| `host` | flushes the entity manager of the action log class, with every pending change | the same, with no notice and no probe (a supported choice) |
| `isolated` | only the new row | the same |

**Data-loss warning.** Nothing fails when code relied on the old default: a change made before `*AndFlush()` and never
flushed by your own code is simply not written. Migrate in this order (the 1.18 list):

1. On 1.18, collect the probe lines (one per class of yours that the action log flush wrote).
2. For each class, find the code that changes it and then records an action with `*AndFlush()`; add your own `flush()`
   (or record with `record()` and flush once yourself).
3. A line naming your action log class means a row recorded with `record()` was left for `*AndFlush()` to write: flush
   it yourself.
4. Set `flush_mode: isolated` on 1.18 and check that no line and no notice remain. Then upgrade.

To keep the 1.x behaviour, set it explicitly:

```yaml
nowo_audit_kit:
    action_log:
        flush_mode: host
```

#### A change that relied on the action log flush

The pattern the probe reports. A removal (or any change) made just before an action is recorded, with no `flush()` of
your own, for example dropping a sync schedule when a board is unlinked:

```php
// 1.x (unset flush_mode): the removal was written by recordAndFlush(). 2.0: never written, no error.
$em->remove($schedule);
$actionLog->recordAndFlush('board.unlinked', $user, null, ['board' => $board->getUuid()]);
```

```php
// 2.0: flush your own change first, then record (or keep flush_mode: host).
$em->remove($schedule);
$em->flush();
$actionLog->recordAndFlush('board.unlinked', $user, null, ['board' => $board->getUuid()]);
```

#### Code patterns that change with the isolated default

- **Your pending changes are no longer flushed by the bundle.** Flush them yourself (above).
- **Actor and subject must be stored.** With `AbstractActionLog`, the actor / subject passed to `recordAndFlush()` must
  be managed by your entity manager and already flushed: a new, persisted-but-not-flushed or detached user throws a
  `LogicException`.
- **The returned row is detached.** It belongs to the bundle's short-lived entity manager, which is closed after the
  call. To point one of your entities at it, use a reference of your own entity manager:
  `$em->getReference(UserAction::class, $entry->getId())`, never the returned instance (the flush of your entity
  manager would fail with "A new entity was found through the relationship").
- **Your own associations on the action log.** A to-one association of your action log class (set by your subclass or
  by one of your Doctrine listeners) that holds one of your entities is written as a reference by id; your instance
  stays managed by your entity manager and is not written. The entity must already be stored: one persisted but not
  flushed throws a `LogicException`. Do not cascade-persist from the action log to your entities.
- **Remove `cascade: ['persist']` from the action log's associations to your entities.** In 1.15–1.18 isolated mode,
  the ORM's "A new entity was found through the relationship … configure cascade persist" hint led some hosts to add
  it. With the isolated default it would make the bundle's entity manager insert your entity a second time (and give
  your instance a new id), so 2.0 refuses it with a `LogicException` before anything is written, naming the
  association. The row only needs the reference; host mode is unaffected.
- **Associations set in your `preFlush` / `onFlush` listeners** are not converted: the ORM refuses them ("A new entity
  was found through the relationship") before anything is written. Set them in `prePersist` or before recording.
- **Inside your transaction** (same connection), the row commits or rolls back with it. On DBAL 3 enable
  `use_savepoints: true` so a failed isolated write does not mark your transaction rollback-only.
- **Lock conflicts** of the isolated write are thrown as `ConcurrencyConflict` (since 1.17), not as the DBAL exception.

### `#[Auditable]` alone opts a class in

`attribute_opt_in` defaults to `true` (announced in 1.13).

| `attribute_opt_in` | 1.x | 2.0 |
| --- | --- | --- |
| unset | `false` (+ the 1.18 notice): `#[Auditable]` alone is ignored | `true`: `#[Auditable]` alone opts a class in |
| `true` / `false` | as set | as set |

Before upgrading, search for `#[Auditable]` on classes that use none of the bundle interfaces or traits
(`AuditableInterface`, `TimestampableInterface`, `BlameableInterface` or their traits) and are not listed in
`auditable_entities`: they start being audited (and blamed when blame is on). To keep ignoring them, set
`attribute_opt_in: false`.

### Blame id mode reads only the framework-free ports

With `blame_mode: id`, the audit listener reads only `CurrentActorProviderInterface` and `BlameIdResolverInterface`.
While its twin keeps the bundle default, these host overrides now **fail the container build**, naming the service
and the twin to alias (`#[AsAlias]` / `#[AsDecorator]` on an autodiscovered class included):

| Port | Fails the build | Notes |
| --- | --- | --- |
| `BlameValueResolverInterface` | an alias, a service registered under the interface id, a decorator | 1.15–1.18 warned about the alias and the service id. **They ignored a decorator silently, with no notice**: it had no effect in id mode, and now it is reported |
| `CurrentUserResolverInterface` | an alias, a service registered under the interface id (both announced by the 1.15 notice) | A decorator stays allowed, as in 1.x: the default actor provider (`SecurityCurrentActorProvider`) still reads it |

```text
With "nowo_audit_kit.blame_mode: id", the service(s) "App\Audit\MyBlame" override "Nowo\AuditKitBundle\Blame\BlameValueResolverInterface", which the id mode no longer supports since nowo-tech/audit-kit-bundle 2.0 (deprecated since 1.15). Alias "Nowo\AuditKitBundle\Blame\BlameIdResolverInterface" to your service instead (see UPGRADING.md, "To 2.0.0").
```

Move the override to the twin (the migration table and example of [To 1.15.0](#to-1150)):

```yaml
# config/services.yaml
services:
    Nowo\AuditKitBundle\Security\CurrentActorProviderInterface: '@App\Infrastructure\Audit\AuditActorAdapter'
    Nowo\AuditKitBundle\Blame\BlameIdResolverInterface: '@App\Infrastructure\Audit\AuditBlameIdAdapter'
```

Reference mode (`blame_mode: reference`, the default) is unchanged: it still uses both 1.14 ports, and overriding them
there stays supported.

### Removed

| Removed | Deprecated since | Replacement |
| --- | --- | --- |
| The `action_log.flush_mode` default `host` | 1.18 (notice), announced in 1.15 | `isolated`; `flush_mode: host` explicitly |
| The `attribute_opt_in` default `false` | 1.18 (notice), announced in 1.13 | `true`; `attribute_opt_in: false` explicitly |
| Reading host overrides of `CurrentUserResolverInterface` / `BlameValueResolverInterface` in `blame_mode: id` (now a build error) | 1.15 | `CurrentActorProviderInterface` / `BlameIdResolverInterface` |
| The host-flush probe: service `nowo_audit_kit.host_flush_probe`, `NowoAuditKitExtension::HOST_FLUSH_PROBE`, `ActionLogHostFlushProbeResetSubscriber`, `HostFlushProbePass` (internal) | 1.18 (existed only for the unset `flush_mode`) | none needed |
| The default-flip notices: `Deprecation\DefaultFlipNotices` (internal), the `nowo_audit_kit.default_flip_notices` parameter, `NowoAuditKitBundle::boot()` | 1.18 | none needed |
| `ReferenceCollationListener::postGenerateSchemaTable()` (internal no-op) | 1.18.1 | `postGenerateSchema` (unchanged, keeps the foreign keys) |

**Kept, ignored:** the trailing `?HostFlushProbe $hostFlushProbe` argument of `ActionLogRecorder::__construct()`
(direct instantiation only) and the constants `ActionLogRecorder::BUNDLE_NAME` / `BUNDLE_NAMESPACE` are deprecated
since 2.0 and removed in 3.0. `ActionLogRecorder::__construct()` still defaults `$flushMode` to `host` for direct
instantiation; the bundle always passes the configured value.

### Composer

No package moves. Same PHP, Symfony, Doctrine and transaction-doctrine (`^1.9`) ranges.

### 1.x support

1.x receives security fixes for 6 months after 2.0.0: fixes rated Moderate and above are backported to the last 1.x
minor; Low ones are fixed in 2.x only.

## To 1.18.1

From **1.18.0**:

```bash
composer update nowo-tech/audit-kit-bundle
```

**Missing foreign keys on an id-mode action log (audit XI-6, MySQL / MariaDB).** Only if your `AbstractIdActionLog`
subclass adds a `ManyToOne` / `OneToOne` association and its table was created or migrated with 1.15.1–1.18.0 on
DBAL 4.5+ with ORM 3.7–3.8: the foreign key of that association (and its index) may be missing. After updating:

1. Run `php bin/console doctrine:schema:validate` (or `doctrine:migrations:diff`): a missing foreign key shows up as a
   pending `ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY` (with its `CREATE INDEX`).
2. Before applying it, remove or fix the rows that point to deleted targets, or the `ALTER` fails, e.g.
   `SELECT l.id FROM app_action_log l LEFT JOIN app_user u ON u.id = l.owner_id WHERE l.owner_id IS NOT NULL AND u.id IS NULL;`
   then delete those rows or set the column to `NULL`, as the association's `onDelete` would have done.
3. Generate and run the migration (`doctrine:migrations:diff`, then `doctrine:migrations:migrate`).

Nothing else changes: the id columns keep their collation, and tables without such associations need nothing.

## To 1.18.0

From **1.17.x**:

```bash
composer update nowo-tech/audit-kit-bundle
```

Nothing breaks. This minor announces the 2.0 defaults at runtime and requires `nowo-tech/transaction-doctrine` ^1.9.

**Deprecation notices (once per kernel boot).** You see them when an option whose default changes in 2.0 is unset:

```text
Since nowo-tech/audit-kit-bundle 1.18: Leaving "nowo_audit_kit.action_log.flush_mode" unset is deprecated: ...
Since nowo-tech/audit-kit-bundle 1.18: Leaving "nowo_audit_kit.attribute_opt_in" unset is deprecated: ...
```

- `action_log.flush_mode` (only with `action_log.entity_class` set): `host` today, `isolated` in 2.0.
- `attribute_opt_in` (only with an enabled profile): `false` today, `true` in 2.0.

To opt in now:

```yaml
nowo_audit_kit:
    attribute_opt_in: true      # or false to keep ignoring #[Auditable] alone
    action_log:
        flush_mode: isolated    # or host to keep flushing your pending changes
```

**Host-flush probe: your migration list.** While `flush_mode` is unset, `recordAndFlush()` / `recordIdsAndFlush()`
report each of your entity classes whose pending changes their flush writes, once per request, message or command:

```text
Your pending changes to App\Entity\Invoice were flushed by NowoAuditKitBundle; 2.0 no longer does.
```

1. Run your test suite, or one deploy cycle, with the deprecation log on and collect these lines.
2. For each class, find the code that changes it and then records an action with `*AndFlush()`; add your own
   `flush()` (or record with `record()` and flush once yourself).
3. A line naming your action log class means a row recorded with `record()` was left for `*AndFlush()` to write: flush
   it yourself.
4. Set `flush_mode: isolated` and check that no line and no notice remain.

The probe only reads Doctrine's unit of work. It never flushes, clears, closes or resets your entity manager, and the
flush that follows is exactly the one of 1.17. It resets at every request, message and command, also in FrankenPHP
worker mode with the kernel reset off. With transaction-doctrine's Symfony bundle registered, its shared probe is used.

**`attribute_opt_in`.** Before setting it to `true`, search for `#[Auditable]` on classes that use none of the bundle
interfaces or traits (`AuditableInterface`, `TimestampableInterface`, `BlameableInterface` or their traits) and are not
listed in `auditable_entities`: they start being audited (and blamed when blame is on).

**Constructor (direct instantiation only).** `ActionLogRecorder::__construct()` takes an optional trailing
`?HostFlushProbe $hostFlushProbe` (default `null`: no probe).

## Preparing for 2.0

Done in 2.0: see [Unreleased](#unreleased).

2.0 contains only changes announced by deprecations in 1.x: if your application runs 1.18 without deprecation notices
from this bundle, the upgrade needs no code change. After 2.0 is released, the 1.x line gets security fixes for 6
months: Moderate and higher advisories are backported to the last 1.x minor; Low ones are fixed in 2.x only.

What changes in 2.0, and how to opt in on 1.18:

| 2.0 change | 1.18 notice | Opt in now |
| --- | --- | --- |
| `action_log.flush_mode` defaults to `isolated`: `recordAndFlush()` / `recordIdsAndFlush()` write only the new row, your pending changes stay pending | "Leaving \"nowo_audit_kit.action_log.flush_mode\" unset is deprecated", plus one host-flush probe line per class of yours that the flush writes | `nowo_audit_kit.action_log.flush_mode: isolated`, after adding your own `flush()` for each class the probe lists |
| `attribute_opt_in` defaults to `true`: `#[Auditable]` alone opts a class in | "Leaving \"nowo_audit_kit.attribute_opt_in\" unset is deprecated" | `nowo_audit_kit.attribute_opt_in: true`, after checking which classes carry `#[Auditable]` without a bundle interface or trait |
| With `blame_mode: id`, host overrides of `CurrentUserResolverInterface` / `BlameValueResolverInterface` are no longer read | "With \"nowo_audit_kit.blame_mode: id\", overriding … is deprecated" (since 1.15) | alias `CurrentActorProviderInterface` / `BlameIdResolverInterface` to your services |

Setting an option explicitly to its current value (`flush_mode: host`, `attribute_opt_in: false`) silences its notice
and keeps the 1.x behaviour in 2.0.

## To 1.17.0

From **1.16.1**:

```bash
composer update nowo-tech/audit-kit-bundle
```

Concurrency (REQ-RUNTIME-003, see [CONCURRENCY.md](CONCURRENCY.md)). No configuration change is needed.

- **New requirements.** `nowo-tech/transaction-contract` ^1.0 and `nowo-tech/transaction-doctrine` ^1.6 are installed
  with the bundle. Registering transaction-doctrine's Symfony bundle is optional. Do it, with `commit_detection: true`,
  when you want lock conflicts answered as 409 with `CONFLICT_001` / `CONFLICT_002` across the request.
- **Exception type (BC note).** A lock conflict (deadlock, lock wait timeout, serialization failure) of:
  - `recordAndFlush()` / `recordIdsAndFlush()` with `flush_mode: isolated`, and
  - `KeysetDelete::run()` / `ActionLogRepository::purgeBefore()` outside a transaction of the connection

  is now thrown as `Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict`, after the replays. Before, it was the DBAL
  exception (`DeadlockException`, `LockWaitTimeoutException`, …). The DBAL exception is now its previous exception.
  Code that catches the DBAL class there should catch `ConcurrencyConflict` (or
  `Nowo\TransactionContract\TransientStorageFailure`), or walk `getPrevious()`. Host mode, `record()` and
  `KeysetDelete` inside your transaction still throw the DBAL exception unchanged. Unique violations and lost
  connections are never wrapped.
- **Replays.** Outside a transaction, a deadlock or serialization failure of one isolated write or one purge batch is
  replayed at most twice. A replay runs only after the attempt rolled back, so no row is written or deleted twice.
  Your Doctrine listeners on the shared event manager run once per attempt: keep their side effects idempotent or
  after the commit.
- **Closed entity manager.** `record()`, `recordIds()` and the host-mode `*AndFlush()` methods replace the action log's
  entity manager through the registry when it is already closed, before they persist. Actor and subject entities
  loaded by the closed manager are then referenced through the new one by their identifier. Before, they failed with
  "EntityManager is closed". An open manager is never cleared or reset.
- **Purge command exit code.** `nowo:audit-kit:action-log:purge` exits with 75 (`EX_TEMPFAIL`) and prints
  `CONFLICT_001` or `CONFLICT_002` on a lock conflict. Before, the exception ended the command with exit code 1. Rows
  of batches already deleted stay deleted; running the command again deletes the rest.
- **A failed audit write.** It is never swallowed. Read
  [A failed audit write and the host action](CONCURRENCY.md#a-failed-audit-write-and-the-host-action) to choose
  between atomic rows (`host` mode, or `isolated` inside your transaction) and a row written after your commit.
- **Worker mode with the kernel reset off.** Only the recorder's own write path replaces a closed manager. Other
  services that hold the closed instance are repaired only by a kernel reset, or by transaction-doctrine's
  `WorkerConnectionHygiene`.

## To 1.16.1

From **1.16.0**:

```bash
composer update nowo-tech/audit-kit-bundle
```

Patch fix (1.16.1), no action required. Hosts that worked around the phantom `abstract_id_action_log` table with
`key_collation_check.ignore_columns` can drop that entry: the provider now declares only the host's concrete action log tables.

## To 1.16.0

From **1.15.1**:

```bash
composer update nowo-tech/audit-kit-bundle
```

## To 1.15.1

From **1.15.0**:

```bash
composer update nowo-tech/audit-kit-bundle
```

Target **1.15.1** (patch), from **1.15.0**. Only id-mode action logs (`AbstractIdActionLog`) on MySQL / MariaDB need
an action; relation-mode logs (`AbstractActionLog`), PostgreSQL and SQLite need none.

```bash
composer update nowo-tech/audit-kit-bundle
```

**What changes (REQ-SEC-006, look-alike ids)**

- The `actor` / `subject` / `involving` filters of `ActionLogRepository` return and count only rows whose id is
  byte-identical to the filter id. With a folding collation, rows of `Bob`, `bób` or `bob ` no longer show up for
  `bob`.
- `ReferenceId::of()` returns `null` for ids with edge whitespace, control or format characters, invalid UTF-8 or
  more than 255 characters. Such ids were stored as given before; now they are stored as `null` (with a warning) and
  match nothing in filters. If your user ids can hold such characters, fix them at the source.
- New schemas get a binary NO PAD collation on `actor_id` / `subject_id`. Configure the full server version
  (`server_version: '8.4.3'`, not `'8.0'`), or the listener falls back to the PAD SPACE `utf8mb4_bin` and logs a
  warning.

**Existing tables (MySQL / MariaDB).** Recommended: the repository is exact without it, but the collation lets SQL
pre-select exact rows only, so look-alike rows no longer cost extra reads. Replace `user_action` with your table
and column names.

1. Detection, before the `ALTER`. Ids that collide under the current collation but are different bytes (these are
   the rows the re-check now separates):

   ```sql
   SELECT LOWER(actor_id) AS folded, COUNT(DISTINCT HEX(actor_id)) AS variants
   FROM user_action WHERE actor_id IS NOT NULL
   GROUP BY actor_id HAVING COUNT(DISTINCT HEX(actor_id)) > 1;
   -- repeat with subject_id
   ```

   Stored ids that the bundle now refuses (they stay in the table but no filter reaches them):

   ```sql
   SELECT id, HEX(actor_id), HEX(subject_id) FROM user_action
   WHERE actor_id   REGEXP '^[[:space:]\\x{00A0}]|[[:space:]\\x{00A0}]$|[[:cntrl:]]|[\\x{200B}-\\x{200F}\\x{2028}-\\x{202E}\\x{2060}\\x{FEFF}]'
      OR subject_id REGEXP '^[[:space:]\\x{00A0}]|[[:space:]\\x{00A0}]$|[[:cntrl:]]|[\\x{200B}-\\x{200F}\\x{2028}-\\x{202E}\\x{2060}\\x{FEFF}]';
   ```

   Do not use `TRIM`, `DISTINCT`, `LOWER` alone or `COLLATE …_ai_ci` to compare ids: they fold too.

2. The `ALTER`. `MODIFY` redefines the column: restate every existing attribute (length, nullability, `DEFAULT`,
   `COMMENT` ...):

   ```sql
   -- MySQL 8.0.17+
   ALTER TABLE user_action
     MODIFY actor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
     MODIFY subject_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL;
   -- MariaDB: COLLATE utf8mb4_nopad_bin; MySQL 5.7: COLLATE utf8mb4_bin
   ```

   `doctrine:migrations:diff` generates the same `ALTER` once the bundle is updated.

## To 1.15.0

From **1.14.1**:

```bash
composer update nowo-tech/audit-kit-bundle
```

Target **1.15.0**, from **1.14.x**:

```bash
composer update nowo-tech/audit-kit-bundle
```

**Standard host (`blame_mode: reference`, the default):** nothing to change. The profile is now selected through
`CurrentActorProviderInterface`, whose default gives the same result as before.

**DDD host (`blame_mode: id`):** nothing to change. The id path now runs on the framework-free ports, and the
defaults write the same ids as 1.14. To drop the Symfony Security dependency of the audit, alias the new port:

```yaml
# config/services.yaml
services:
    Nowo\AuditKitBundle\Security\CurrentActorProviderInterface: '@App\Infrastructure\Audit\AuditActorAdapter'
```

**Deprecations (removal in 2.0), `blame_mode: id` only**

| Deprecated (in id mode) | Replacement |
| --- | --- |
| alias of `Security\CurrentUserResolverInterface` (`resolve(): ?UserInterface`) | alias of `Security\CurrentActorProviderInterface` (`currentActor(): ?AuditActor`) |
| alias of `Blame\BlameValueResolverInterface` (`resolve(ProfileSettings, ObjectManager): mixed`) | alias of `Blame\BlameIdResolverInterface` (`resolveBlameId(ProfileSettings, ?AuditActor): ?string`) |

- Your override still works in 1.x; the container build triggers a deprecation.
- 2.0 reads only the new ports in id mode.
- Migration:

```php
// Before (1.14)
final class MyBlame implements BlameValueResolverInterface
{
    public function resolve(ProfileSettings $profile, ObjectManager $objectManager): ?string { /* ... */ }
}

// After (1.15)
final class MyBlame implements BlameIdResolverInterface
{
    public function resolveBlameId(ProfileSettings $profile, ?AuditActor $actor): ?string { /* ... */ }
}
```

**Action log flush (optional):** `recordAndFlush()` / `recordIdsAndFlush()` still flush the entity manager of the
action log class by default, with every pending change of your unit of work. Set
`nowo_audit_kit.action_log.flush_mode: isolated` to write only the row.

- Before switching, check your callers: code that changes entities and relies on `recordAndFlush()` to save them must
  call `flush()` itself.
- Actor / subject entities must already be stored.
- The returned row is detached.
- Actor / subject must be managed by your entity manager and already flushed (a persisted user with an assigned id
  that is not flushed yet, or a detached one, throws).
- On DBAL 3, enable `use_savepoints: true` on the connection: otherwise a failed isolated write inside your own
  transaction marks it rollback-only.
- REQ-ARCH-001 rule 6 is opt-in in 1.x. The default becomes `isolated` in 2.0 (see
  [Preparing for 2.0](#preparing-for-20)).

**Reference mode with your own actor port:** if you alias only `CurrentActorProviderInterface` while keeping
`blame_mode: reference`, it selects the profile but blame still comes from `CurrentUserResolverInterface`. Keep the
two in agreement, or alias the blame ports too.

**Constructor change (direct instantiation only):** `AuditableEntityListener::__construct()` now takes
`?CurrentUserResolverInterface` and two new optional trailing arguments, `$currentActorProvider` and `$blameIdResolver`.
Existing calls keep working.

## To 1.14.1

From **1.14.0**:

```bash
composer update nowo-tech/audit-kit-bundle
```

Next patch: 1.14.1. No API or configuration change. The timeline avatar memo is now emptied at every unit-of-work
boundary (main request start and finish, each Messenger message, `console.terminate`), so the resolver is called again
on the next request. Keep `ActionLogAvatarResolverInterface::urlsFor()` one query per call, as before.

## To 1.14.0

Target **1.14.0**, from **1.13.x**:

```bash
composer update nowo-tech/audit-kit-bundle
```

Nothing to change: `blame_mode` defaults to `reference` and `action_log.mapping` to `attribute`, so standard hosts keep
the 1.13 behaviour (blame relations to `user_class`, `AbstractActionLog` with relations, attribute mapping).

- `ActionLogRepository`'s template bound is now `ActionLogEntryInterface` (`AbstractActionLog` implements it), and
  `AbstractActionLog` gains `getActorId()` / `getSubjectId()`. A subclass that already declares methods with these
  names must keep compatible signatures (`?string`).
- A profile with `blameable: false` may now omit `user_class`.
- Optional, for DDD / hexagonal hosts (opt-in): `blame_mode: id`, an `AbstractIdActionLog` subclass recorded through
  `ActionLogIdRecorderInterface::recordIds()`, and `action_log.mapping: xml`. See USAGE, "Using it from a DDD /
  hexagonal application". Moving an existing action log table from relations to ids needs your own migration (drop
  the foreign keys and widen `actor_id` / `subject_id` to strings).

## To 1.13.0

Target **1.13.0**, from **1.12.x**:

```bash
composer update nowo-tech/audit-kit-bundle
```

Nothing to change: the defaults keep the 1.12 behaviour (`auditable_entities: []`, `attribute_opt_in: false`).

- **One fix in the safe direction:** `#[Auditable(enabled: false)]` is now also read on parent classes, so an
  opted-out entity loaded as a Doctrine proxy (or a subclass of it) is no longer audited on update.
- **Optional:** `attribute_opt_in: true` makes `#[Auditable]` alone opt a class in. Before you turn it on, search for
  `#[Auditable]` on classes that use none of the bundle interfaces or traits: they start being audited (and blamed when
  blame is on). **The default becomes `true` in 2.0.**
- Optional, for DDD / hexagonal hosts: list classes, or a marker interface of your own, under
  `nowo_audit_kit.auditable_entities` to audit them without any bundle type (see USAGE, "Using it from a DDD /
  hexagonal application"). An unknown class makes the container build fail.

## To 1.12.0

From **1.11.0**:

```bash
composer update nowo-tech/audit-kit-bundle
```

Target **1.12.0**, from **1.11.x**:

```bash
composer update nowo-tech/audit-kit-bundle
```

No action needed: the new ports (`CurrentUserResolverInterface`, `BlameValueResolverInterface`,
`ActionLogRecorderInterface`) are aliased to implementations that behave as before, and `BlameableTrait` /
`BlameableInterface` are unchanged.

- Firewall user that is not the `user_class` entity (blame used to stay `null`): alias
  `Nowo\AuditKitBundle\Blame\BlameValueResolverInterface` to `Nowo\AuditKitBundle\Blame\SecurityUserIdBlameValueResolver`
  (needs a `getId()` on the security user returning the entity id). See USAGE, "Using it from a DDD / hexagonal
  application".
- Code constructing `AuditableEntityListener` by hand keeps working; any `CurrentUserResolverInterface` is accepted.
- Prefer type-hinting `ActionLogRecorderInterface` over the final `ActionLogRecorder`.

## To 1.11.0

From **1.10.0**:

```bash
composer update nowo-tech/audit-kit-bundle
```

Target **1.11.0**, from **1.10.0**:

```bash
composer update nowo-tech/audit-kit-bundle
```

No action needed: with the default `NullActionLogAvatarResolver` the timeline renders as before. To show the actor's
and the subject's pictures, alias the resolver to a service of yours:

```yaml
# config/services.yaml
services:
    Nowo\AuditKitBundle\Avatar\ActionLogAvatarResolverInterface: '@App\Audit\AuditAvatarUrls'
```

```php
use Nowo\AuditKitBundle\Avatar\ActionLogAvatarResolverInterface;
use Nowo\AuditKitBundle\Avatar\AuditAvatarKey;

final class AuditAvatarUrls implements ActionLogAvatarResolverInterface
{
    public function __construct(private readonly AvatarUrls $core) {}

    public function urlFor(object $user, int $size): ?string
    {
        return $this->urlsFor([$user], $size)[AuditAvatarKey::of($user)] ?? null;
    }

    public function urlsFor(array $users, int $size): array
    {
        $urls = [];
        foreach ($this->core->forUsers($users, $size) as $userId => $url) {   // null for anonymised / erased accounts
            $urls[(string) $userId] = $url;
        }

        return $urls;
    }
}
```

- Return stable, access-checked, host-generated URLs (`https://…` or a same-origin `/path`, no bearer secret, never a
  user-entered URL); anything else is dropped by `AvatarUrlPolicy`, including relative paths without a leading `/`,
  protocol-relative or `http:` asset URLs, IPv6-literal hosts and URLs with quotes, `<`, `>` or backtick. Return null
  for anonymised or erased accounts.
- The timeline now calls the Twig functions `nowo_audit_kit_prime_avatars()` / `nowo_audit_kit_avatar_url()`, which
  the bundle registers. A test that renders `@NowoAuditKitBundle/action_log/_timeline.html.twig` in a hand-built Twig
  `Environment` adds `new ActionLogAvatarExtension(new ActionLogAvatars(new NullActionLogAvatarResolver()))`.
  A full-file override of `_timeline.html.twig` keeps working and shows no avatars until you merge the new markup.
- With UiKit ≥ 2.26 on the page (`nowo-ui.css` and `js/nowo-ui-avatar.js`), the picture covers the initials and a
  broken one falls back to them. Without UiKit the initials sit next to the picture.

## To 1.10.0

From **1.9.0**:

```bash
composer update nowo-tech/audit-kit-bundle
```

- **Twig namespace (REQ-TWIG-002):** include the timeline as `@NowoAuditKitBundle/action_log/_timeline.html.twig` instead of `@NowoAuditKit/action_log/_timeline.html.twig`. The old name keeps working in 1.x (Symfony still registers it automatically) but is deprecated. Overrides stay where they were: `templates/bundles/NowoAuditKitBundle/action_log/_timeline.html.twig` wins for both names. No configuration change is needed.

## To 1.9.0

From **1.8.0**:

```bash
composer update nowo-tech/audit-kit-bundle
```

- `twig/extra-bundle` and `twig/string-extra` are now runtime requirements (REQ-TWIG-004), which also install `symfony/twig-bundle`. With Symfony Flex, `TwigExtraBundle` (and `TwigBundle`, if it was not installed yet) are added to `config/bundles.php` automatically; without Flex, register `Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true]` yourself. No configuration or code change is needed.

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
