# Feature Specification: Keyset batch delete helper

**Feature Branch**: `004-keyset-delete`  
**Created**: 2026-10-14  
**Status**: Implemented (v1.6.0)

**Package**: `nowo-tech/audit-kit-bundle`  
**Configuration root**: `nowo_audit_kit` (no new keys)  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md) · [`../002-doctrine-helpers/spec.md`](../002-doctrine-helpers/spec.md) · [`../003-action-log/spec.md`](../003-action-log/spec.md)  
**Origin**: Symfony Beacon `docs/ops/KIT-EXTRACTION.md` row #21 — the same keyset-batched `DELETE` is written three times in Beacon (`MessengerMonitorPurger`, `ProjectHistoryClearer`, and the action log purge adopted from this kit).

---

## Summary

`Nowo\AuditKitBundle\Doctrine\KeysetDelete::run()` deletes the rows of one table that match a `WHERE` clause in short primary-key batches, on DBAL (no unit of work):

1. `SELECT <id> FROM <table> WHERE (<where>) [AND <id> > <last id>] ORDER BY <id> ASC` with the platform limit (`modifyLimitQuery`);
2. `DELETE FROM <table> WHERE <id> IN (<ids>)`;
3. repeat until a batch returns fewer ids than the batch size.

Each statement is short and runs in autocommit, so no single statement holds locks / undo / one binlog event for millions of rows. The keyset bound means rows the clause matches but that survive (concurrent writes, rows excluded by triggers) are never rescanned. `ActionLogRepository::purgeBefore()` now delegates to it (same SQL shape, same results).

---

## User Scenarios

### US-01 — Purge a large table in batches (P1)

**Given** 13 matching rows and a batch size of 5,  
**When** `run()` is called,  
**Then** it runs three `SELECT … LIMIT 5` / `DELETE … IN (…)` pairs (5, 5, 3 ids), returns 13 and leaves the non-matching rows.

### US-02 — Empty table (P1)

**Given** no matching row, **Then** one `SELECT` runs, no `DELETE`, and `run()` returns 0.

### US-03 — Exact multiple of the batch size (P2)

**Given** 6 rows and a batch size of 3, **Then** a final empty `SELECT` ends the loop (2 deletes, 3 selects).

### US-04 — Placeholders (P1)

**Given** a clause with positional (`?`) or named (`:name`) placeholders, including `ArrayParameterType` lists (`kind IN (:kinds)`, `n IN (?)`),  
**Then** the values and types are passed through; the keyset bound is appended as `?` (positional) or `:keysetDeleteAfterId` (named).

### US-05 — Non-integer primary keys (P3)

**Given** a string primary key (`idColumn: 'code'`), **Then** ids are bound as strings and the keyset follows the database order (the last id of each batch).

---

## Requirements

- **FR-KD-001**: `KeysetDelete` (final, static, not a service) — `run(Connection $connection, string $table, string $where, array $params = [], array $types = [], int $batchSize = self::DEFAULT_BATCH_SIZE, string $idColumn = 'id'): int` returns the number of deleted rows (sum of `executeStatement()` results).
- **FR-KD-002**: `DEFAULT_BATCH_SIZE = 1000`; `$batchSize < 1` is treated as 1.
- **FR-KD-003**: `$where` is wrapped in parentheses; an empty / blank `$where` matches every row. `$table`, `$where` and `$idColumn` are SQL fragments inserted as is (caller quotes identifiers; never user input). Joins are expressed in the clause (`col IN (SELECT …)`, `EXISTS (…)`).
- **FR-KD-004**: The first `SELECT` has no keyset condition (no assumption on the smallest id); later ones add `<id> > ?` (when `$params` is a list) or `<id> > :keysetDeleteAfterId` (otherwise), typed `ParameterType::INTEGER` for integer ids and `STRING` otherwise. A named `$params` / `$types` key equal to `AFTER_ID_PARAMETER` throws `InvalidArgumentException`.
- **FR-KD-005**: Ids returned as integers or integer strings (driver-dependent) are bound as `ArrayParameterType::INTEGER`; if any id is not an integer, all are bound as `ArrayParameterType::STRING`. The keyset bound is the last id of the batch (database order).
- **FR-KD-006**: `ActionLogRepository::purgeBefore()` delegates to `run()` with the quoted table / id / `created_at` / `action` columns from the entity metadata and named parameters. `ActionLogRepository::DEFAULT_BATCH_SIZE` stays 1000 (now `KeysetDelete::DEFAULT_BATCH_SIZE`). Behaviour is unchanged except that the first batch no longer includes `id > 0`.

---

## Success Criteria

- **SC-001**: Integration test on in-memory SQLite (plain DBAL, logging middleware to assert the executed SQL): more rows than one batch with retained rows, empty table, exact multiple, positional / named / array parameters, empty clause, batch size clamp, string primary key, reserved parameter name.
- **SC-002**: Unit test on the MySQL platform (stub connection): generated `LIMIT` SQL with backtick-quoted identifiers, numeric-string ids normalised to integers, string ids bound as strings.
- **SC-003**: Existing action log purge tests unchanged and green; 100% PHP line coverage, PHPStan, CS, Rector, Igor green (`make release-check`).

## Explicit non-goals

- No MySQL service in the bundle test setup (see spec 002): the SQL is portable DBAL and the MySQL platform output is asserted without a server.
- No composite primary keys, no transaction wrapping (each batch commits on its own by design), no progress callback.
- Beacon adoption (`MessengerMonitorPurger`, `ProjectHistoryClearer`) happens in Beacon.
