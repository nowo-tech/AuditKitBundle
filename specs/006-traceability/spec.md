# Feature Specification: Traceability, part A (correlation id, changes, caused_by, timeline)

**Feature Branch**: `feat/2.2.0-traceability-a`  
**Created**: 2026-11-28  
**Status**: Implemented (planned 2.2.0)

**Package**: `nowo-tech/audit-kit-bundle`  
**Configuration roots**: `nowo_audit_kit.correlation` (new), `nowo_audit_kit.action_log.changes` (new)  
**Baseline**: [`../003-action-log/spec.md`](../003-action-log/spec.md)  
**Foundation**: [`nowo-tech/correlation-contract`](https://github.com/nowo-tech/correlation-contract) `^0.1`

---

## Summary

Nothing ties together the rows one request, message or command writes, and rows rarely say what changed. This feature
adds, all opt-in and backward compatible within 2.x:

| Unit | Purpose |
| ---- | ------- |
| Correlation port | The bundle consumes `CorrelationIdProviderInterface` (default `NullCorrelationIdProvider`, host alias wins); optional `CorrelationContextProviderInterface` for `caused_by`. |
| `Correlation\CorrelationIdHolder` + subscribers | Opt-in default provider for hosts without one: one id per main request / command / received message, reset-safe. |
| `Correlation\Messenger\CorrelationMiddleware` | Stamps `CorrelationIdStamp` on dispatch, restores it on receive. |
| `Correlation\Monolog\CorrelationLogProcessor` | `extra.correlation_id` / `extra.caused_by`. |
| `Correlation\HttpClient\CorrelationHttpClient` | `X-Correlation-Id` on outgoing requests. |
| `ActionLog\TraceableActionLogInterface` + trait + `Doctrine\TraceableActionLogMetadataListener` | Nullable `correlation_id` (indexed), `caused_by`, `changes` columns. |
| `Change\ChangeSet`, `Change\ChangeSetBuilder` | Masked, size-bounded before → after changes. |
| `Timeline\*` | Contract other packages implement to contribute timeline entries; merger; action log source. |

Out of scope (part B, a later release): hash chain, seal / checkpoint tables, verification, redaction.

---

## User Scenarios

### US-01 — One search finds a request's rows (P1)

A host aliases its provider (or sets `correlation.provider: true`). Every action log row of a traceable entity, every
log record and every outgoing HTTP request of one unit of work carries the same id; `ActionLogCriteria(correlationId:)`
lists the rows.

### US-02 — What changed, without leaking secrets (P1)

The host passes a `ChangeSet` built from before / after snapshots and an allowlist; secrets appear as `{changed: true}`
only; a secret-looking field declared `plain` is refused.

### US-03 — Work crosses a queue (P1)

A message dispatched during a request carries the request's id; its handler's rows carry it (or, with child ids, a new
id with `caused_by` = the request's).

### US-04 — A history page from several packages (P2)

Packages implement `TimelineSourceInterface`; `TimelineMerger` returns stable keyset pages.

---

## Requirements

- **FR-TR-001** The bundle never implements the canonical provider unless asked (`correlation.provider: true`); it
  falls back to `NullCorrelationIdProvider` only when nothing binds the interface.
- **FR-TR-002** Inbound ids are adopted only from trusted proxies by default and only when valid
  (`CorrelationId::tryFromString()` / `tryFromTraceparent()`).
- **FR-TR-003** Worker mode (kernel kept alive, no reset): no id leaks between requests, messages or commands; the holder
  is the only state, emptied at every boundary and by `kernel.reset`, bounded in depth.
- **FR-TR-004** Messenger: stamp on dispatch (caller stamp kept), restore on receive in a `finally`.
- **FR-TR-005** Columns are nullable, mapped only for entities that implement the interface; `correlation_id` indexed;
  a field the entity maps itself is left alone.
- **FR-TR-006** The recorder fills `correlation_id` / `caused_by` with no extra query; changes on a non-traceable
  entity are dropped with a warning that does not contain them.
- **FR-TR-007** Changes: secrets (configured fields, secret-looking names, nested keys) never stored; strings over the
  length limit stored as lengths; sets over the byte limit cut field by field.
- **FR-TR-008** The correlation filter matches byte for byte under any collation.
- **FR-TR-009** Timeline merge is newest first, ties by source then source order, pages stable.
- **FR-TR-011** One configurable header name (`correlation.header_name`) for responses, outgoing requests and e-mails
  (`correlation.mailer`); caller-set headers are kept. `inbound_headers: []` disables inbound adoption.
- **FR-TR-012** A message without a parent stamp runs under a fresh root id, `caused_by` null; with a host provider the
  bundle keeps no state (no holder, no listeners, receive steps aside); bundle provider + host binding is refused.
- **FR-TR-010** Writes keep REQ-RUNTIME-003: no new write path, no unique key, no host entity manager cleared or reset.

---

## Success Criteria

- 100% line coverage; Database suite green on MySQL 8.4, MariaDB 11.4, PostgreSQL 16.
- Worker test: two requests in one kernel, different ids, empty holder after terminate.
- Messenger stamp round trip through a serializer; console runs get distinct ids; log processor adds the fields.

---

## Explicit non-goals

- Hash chain, sealing, checkpoints, `verify`, `redact()` (part B). The schema leaves room: a `redacted_at` column on
  the traceable entity and separate seal / checkpoint tables keyed by the row id.
- An `upstream_id` column for an untrusted inbound id, and bridging to a specific error-tracking SDK.
