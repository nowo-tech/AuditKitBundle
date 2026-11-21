# Concurrency

How the bundle stays correct when two requests, two workers or a replayed message write at the same time (Nowo bundle
requirement REQ-RUNTIME-003). Applies from 1.17. Worker mode with the kernel reset off is covered in
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## Table of contents

- [Principles](#principles)
- [Write paths](#write-paths)
- [Conflict answers and codes](#conflict-answers-and-codes)
- [A failed audit write and the host action](#a-failed-audit-write-and-the-host-action)
- [Host options](#host-options)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Principles

- **Append-only.** The action log only inserts rows. There is no read-modify-write, no counter and no "only one" rule
  on it, so no row lock, version column or unique key is needed (rule 4). Two writers of the same actor or subject
  each store their row. The audit columns (`created_at`, `updated_by`, …) are set on the host's own entities inside
  the host's flush, so they follow the host's unit of work.
- **The host owns its unit of work.** `record()` / `recordIds()` only persist into the entity manager that maps the
  action log class. The host flushes. `recordAndFlush()` / `recordIdsAndFlush()` write only the row in the default
  `isolated` mode (since 2.0, REQ-ARCH-001 rule 6); with an explicit `flush_mode: host` they flush that manager because
  the host asked for it by name (rule 2), and the row then commits or fails with the host's changes.
- **The host's manager is never cleared or reset while open.** Before it persists a row, the recorder checks the
  manager. If it is already closed (a failed flush of the host closes it), the recorder replaces it through the
  registry (`ManagerRegistry::resetManager()`), so the row can still be written. Actor and subject entities loaded by
  the closed manager are then referenced through the new one by their identifier (`getReference()`); a user without
  an identifier, or not a mapped entity, is passed as it is. An open manager is used as it is.
- **An inner conflict is kept.** A `ConcurrencyConflict` thrown inside the isolated write (for example by a host
  listener running its own unit) is rethrown unchanged and never replayed, so its `committed` flag (`CONFLICT_002`)
  is kept.
- **The isolated mode writes through a runner.** With `flush_mode: isolated`, the row is written on a short-lived
  entity manager of the bundle (same connection), through nowo-tech/transaction-doctrine's
  `DoctrineTransactionRunner::forEntityManager()`. After a failure the runner rolls the connection back to the level
  where it started, savepoint-aware, and it never reports a lost savepoint as success (rule 3). The bundle's manager
  is cleared and closed after every call.
- **Replays only when nothing was written.** Outside a transaction, a deadlock or serialization failure of an
  isolated write, or of a purge batch, rolled back on its own. That one write is replayed, at most twice. A lock wait
  timeout is never replayed, so no write waits twice. Inside a transaction of the connection nothing is replayed: the
  caller's transaction owns the failure.
- **Listeners run once per attempt.** A replay flushes again, so Doctrine listeners of the host (`prePersist`,
  `onFlush`, `postPersist`, `postFlush`) on the shared event manager can run up to three times for one row. Their
  side effects (mail, HTTP, files, messages) must happen after the commit, or be idempotent (rule 7).
- **Transient failures are never swallowed** (rule 8). They reach the caller. This includes a lock conflict met by an
  avatar resolver while the timeline renders.
- **Bounded work** (rule 9). The purge deletes in short primary-key batches, each in auto-commit. No HTTP call, mail or
  file I/O runs while the bundle holds a lock.

## Write paths

| Path | Transaction | Control (rule 4) | Conflict answer (rule 6) |
| --- | --- | --- | --- |
| `record()`, `recordIds()` | None of its own: the row is persisted into the host's unit of work, and the host's flush writes it | Append-only insert | The host's (its flush fails as a whole) |
| `recordAndFlush()`, `recordIdsAndFlush()`, `flush_mode: host` (explicit) | The host's flush: one transaction with the host's pending changes, or a savepoint inside the host's open transaction | Append-only insert. A closed host manager is replaced first. | The storage error reaches the host unchanged |
| `recordAndFlush()`, `recordIdsAndFlush()`, `flush_mode: isolated` (default since 2.0) | One transaction of transaction-doctrine's runner on the bundle's own manager, or a savepoint inside the host's open transaction | Append-only insert. Outside a transaction a deadlock or serialization failure is replayed at most twice on a new manager. | `ConcurrencyConflict` (`committed` false: the row was not written), `CONFLICT_001` |
| `ActionLogRepository::purgeBefore()`, `KeysetDelete::run()`, `nowo:audit-kit:action-log:purge` | Batches of `batch_size` ids: a `SELECT` then a `DELETE … WHERE id IN (…)`, each in auto-commit | Idempotent: deleting by id twice deletes nothing the second time. A deadlocked or serialization-failed batch is replayed at most twice. | `ConcurrencyConflict`: `committed` true (`CONFLICT_002`) once a batch was deleted, else `CONFLICT_001`. The command prints the code and exits with 75. Inside the caller's transaction: rethrown unchanged. |
| `AuditableEntityListener`, `PublicUuidListener` | The host's flush (they only set fields on the host's entities) | — | The host's |

Read-only paths: the repository finders, the timeline and the avatar resolver.

## Conflict answers and codes

A **lock conflict** is a deadlock, a lock wait timeout or a serialization failure: MySQL 1213 / 1205, MariaDB 1020,
PostgreSQL 40001 / 40P01 / 55P03. The bundle has no HTTP routes and no Messenger handlers, so it does not answer HTTP
itself. It throws `Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict`. That class extends
`Nowo\TransactionContract\TransientStorageFailure` and carries the storage error as its previous exception. The host's
responder turns it into the platform answer. For example, nowo-tech/transaction-doctrine's
`ConcurrencyConflictResponder` gives:

| Code | When | Status | Headers | Client |
| --- | --- | --- | --- | --- |
| `CONFLICT_001` | Nothing was committed (`committed` false, and nothing earlier in the request when the host uses the request watermark) | 409 (or the host's 503) | `X-Conflict-Code: CONFLICT_001`, `Retry-After: 1` | Retrying is safe. |
| `CONFLICT_002` | A purge batch was already deleted (`committed` true), or the host committed earlier in the same request | 409 (or 503) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` | Read again, do not repeat blindly. A purge can be run again: it only deletes what is left. |

- `ConflictCode::forFailure($conflict)` gives the code of a failure alone. A host that answers HTTP uses
  `ConflictCode::forRequest($request, $conflict)`, which also counts the commits made earlier in the same request.
- **Not conflicts:** unique-constraint violations and lost connections are never mapped. They are rethrown unchanged.
  The action log has no unique key, so a unique violation there is a real error.
- **Host mode is not wrapped.** In `flush_mode: host` the failing flush is the host's own, so the storage error
  reaches the host as the ORM throws it. That keeps the 1.x behaviour.
- **The console.** `nowo:audit-kit:action-log:purge` prints `CONFLICT_001` (nothing deleted, run it again later) or
  `CONFLICT_002` (some rows were deleted and stay deleted, run it again to delete the rest), and exits with 75
  (`EX_TEMPFAIL`).

## A failed audit write and the host action

Audit rows record what the host did. The bundle never swallows a failed write. Whether it fails the host action
depends on the mode, and the host chooses:

- **`flush_mode: host` and `record()`.** The row is part of the host's unit of work. A failed write fails the host's
  flush, so the action and its audit row commit together or not at all. No action is stored without its row.
- **`flush_mode: isolated` inside the host's transaction** (`wrapInTransaction()`, or `beginTransaction()` on the same
  connection). The row is in a savepoint of that transaction. If the write fails, the exception reaches the host, and
  the host's transaction should roll back, unless the host catches it. This is the recommended way to keep an action
  and its row atomic in isolated mode.
- **`flush_mode: isolated` outside a transaction**, for example after the host's own flush. The host's action is
  already committed when the row is written. After the replays, a lock conflict that remains is thrown as
  `ConcurrencyConflict`. A host that wants the action to succeed regardless catches it, and may log it or record the
  row again later. That row is then lost: this is the host's explicit choice. With transaction-doctrine's request
  watermark, an uncaught conflict answers `CONFLICT_002`, because the host's action already committed.

A replay never duplicates a row: it runs only after the attempt rolled back (a deadlock or serialization failure
outside any transaction). A lost connection, whose outcome is unknown, is never replayed.

## Host options

```yaml
nowo_audit_kit:
    action_log:
        flush_mode: isolated    # isolated (default since 2.0) | host: see "A failed audit write and the host action"
        purge:
            batch_size: 1000    # rows per purge statement: smaller batches hold fewer locks
```

- **Lock timeouts.** MySQL and MariaDB wait `innodb_lock_wait_timeout` (50 s by default) for a row lock. PostgreSQL
  waits `lock_timeout`, which has no limit by default. An action log insert waits only when another transaction holds
  a lock on its actor or subject row (the foreign-key check). A purge batch waits only for rows that another
  transaction locks. To give up sooner, set the timeout on the connection. For PostgreSQL, use
  `options: '-c lock_timeout=5s'` in the DSN or the connection options. For MySQL, use `SET SESSION
  innodb_lock_wait_timeout = 5` in the connection's init command.
- **Savepoints.** On DBAL 3, set `use_savepoints: true` on the connection (always on with DBAL 4). Then a failed
  isolated write inside your transaction does not mark the transaction rollback-only.
- **`CONFLICT_002` across the request.** Register nowo-tech/transaction-doctrine's Symfony bundle with
  `commit_detection: true`, so `ConflictCode::forRequest()` sees the host's earlier commits.
- **Worker mode with the kernel reset off.** The recorder replaces a closed host manager before it writes a row. Other
  services of the host that hold a closed manager are not repaired by the bundle. Doctrine's own lazy proxy is reset in
  place, so services that received it see the new manager. A service that kept the closed instance itself is replaced
  only when the host resets the kernel, or runs transaction-doctrine's `WorkerConnectionHygiene` at the next request.

## Tests

- `tests/Database/ConcurrentWritesDbTest.php` uses two connections on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16. It
  runs in the CI job "Databases" (DBAL 3 and 4, a required check). Both sessions wait at most 1 s for a lock. Each case
  asserts the answer, the stored rows and that the connection is still usable:
  - two writers of the same actor: both rows stored (append-only, no blocking);
  - an isolated write blocked by a lock on its actor: `ConcurrencyConflict`, `CONFLICT_001`, not replayed, nothing
    written; the next write succeeds;
  - a host-mode flush blocked the same way: it fails as a whole (the host's pending user is not written either), the
    storage error is not wrapped, and the next record replaces the closed manager and writes;
  - a purge blocked on its second batch: `CONFLICT_002`, the first batch stays deleted, and a rerun completes;
  - a purge blocked on its first batch: `CONFLICT_001`, nothing deleted;
  - rows written by another transaction while a purge runs are kept.
- On the DBAL 3 legs the job runs the suites `Database` and `Concurrency` (the injected-conflict tests below). Some
  older unit stubs use DBAL 4 API, so the full `Unit` and `Integration` suites run on DBAL 4 only.
- `tests/Integration/ActionLogConflictTest.php` injects deadlocks, serialization failures, lock wait timeouts and unique
  violations at the flush of the row (SQLite). It covers the replays (one row, never two), a conflict that remains,
  no replay inside the host's transaction, host mode unchanged, and the closed-manager replacement.
- `tests/Unit/Doctrine/KeysetDeleteConflictTest.php` and `tests/Unit/ActionLog/Command/PurgeActionLogCommandConflictTest.php`
  cover the purge replays, `committed`, the codes and the exit code.
- The CI job "Dependency floor (nowo-tech/transaction-* lowest, demo lock)" runs PHPStan and the suites with the lowest
  nowo-tech/transaction-* versions allowed, and checks that the demo lock installs what the bundle requires.

## Residual risks

- **An isolated write outside a transaction can be lost after the host's commit.** If a lock conflict remains after
  the replays, the host's action stays committed without its row, unless the host retries or writes inside its own
  transaction. See [A failed audit write and the host action](#a-failed-audit-write-and-the-host-action).
- **PostgreSQL waits without limit by default.** An insert or a purge batch may wait as long as the transaction that
  holds the lock. Set `lock_timeout` (see [Host options](#host-options)).
- **Replayed listeners.** See "Listeners run once per attempt" above: a listener with a non-idempotent side effect
  runs it again on each replay.
- **Replacing a closed manager.** The bundle replaces the host's manager only when it is already closed, so the
  host's unit of work is already lost at that point. Services that hold the closed instance itself (not Doctrine's
  lazy proxy) keep it (see [Host options](#host-options)).
- **A `KeysetDelete::run()` called inside the caller's transaction is not replayed.** On MySQL and MariaDB a deadlock
  rolls back the whole transaction, so the caller must decide. The storage error is rethrown unchanged.
