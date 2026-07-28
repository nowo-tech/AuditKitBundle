# Security Policy

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Mitigations](#mitigations)
- [Secrets & cryptography](#secrets--cryptography)
- [Logging](#logging)
- [Dependencies](#dependencies)
- [Permissions / exposure](#permissions--exposure)
- [Supported Versions](#supported-versions)
- [Reporting a Vulnerability](#reporting-a-vulnerability)
  - [How to Report](#how-to-report)
  - [Response Timeline](#response-timeline)
  - [Disclosure Policy](#disclosure-policy)
- [Preferred Languages](#preferred-languages)
- [Contact](#contact)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit](#ai-security-audit)

## Scope

Audit Kit Bundle sets **created/updated timestamps** and optional **blame** fields (`createdBy` / `updatedBy`) on Doctrine entities that opt in via traits, interfaces, or `#[Auditable]`. It does **not** expose HTTP routes, forms, or admin UI.

## Attack surface

- **Configuration** (`nowo_audit_kit`): `user_class` / profiles, field name maps, `timestamp_type`, blameable/timestampable flags.
- **Doctrine lifecycle**: `prePersist` / `preUpdate` on opted-in entities.
- **Symfony Security token**: current user resolved for blame fields (nullable for CLI/guest).
- **Entity / user metadata**: class metadata and identifier values used to build Doctrine references.

No public HTTP controllers, Messenger handlers, or subprocesses ship in this package.

## Threat model

| Threat | Risk | Notes |
| ------ | ---- | ----- |
| Privilege escalation via blame fields | Low | Blame stores a reference to the **already authenticated** user; it does not grant roles. |
| Arbitrary user_class injection | Low | `user_class` comes from app YAML/config, not request input. Misconfiguration is integrator-owned. |
| PII retention in audit columns | App-owned | User ids (or related identifiers) may be personal data under GDPR — retention/access = host app policy. |
| Silent failure hiding auth issues | Low | Metadata/reference failures fall back to the token user and emit a **warning** log (no secrets). |
| SSRF / XSS / CSRF | N/A | No HTTP surface in the bundle. |

## Mitigations

- **Opt-in only**: entities must use traits/interfaces or remain auditable; `#[Auditable(enabled: false)]` skips an entity.
- **User resolution**: blame uses `TokenStorage` / `CurrentUserResolver`; anonymous and CLI leave blame `null`.
- **Type guard**: blame is set only when the token user is an instance of the configured `user_class`.
- **Doctrine reference**: prefers `EntityManager::getReference` by identifier; on failure logs a warning and uses the token user instance.
- **Clock injection**: timestamps via `Psr\Clock\ClockInterface` (testable; no ambient `time()`).
- **No secrets in package**: recipe templates use placeholders only.

## Secrets & cryptography

This bundle does **not** perform cryptography or store API keys. Never put secrets in `nowo_audit_kit` config.

## Logging

- Injects `Psr\Log\LoggerInterface` into `AuditableEntityListener` (defaults to `NullLogger` when unused).
- On blame-reference failure: **warning** with structured context (`bundle`, `action`, `exception`, `message`) — **no** passwords, tokens, session ids, or full user dumps.
- Integrators should not log raw entity payloads containing sensitive columns in surrounding app code.

## Dependencies

Run `composer audit` in consuming projects and keep `nowo-tech/audit-kit-bundle` updated. Triage findings before release (`composer audit` is part of the release checklist).

## Permissions / exposure

- Bundle registers a Doctrine entity listener only — **no** routes, firewalls, or Controllers.
- Access control for reading audited columns is entirely the host application's responsibility (voters, admin roles, API serializers).

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

We take the security of Audit Kit Bundle seriously. If you believe you have found a security vulnerability, please report it to us as described below.

### How to Report

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please send an email to: **hectorfranco@nowo.tech**

Include the following information in your report:

- Type of issue (e.g., privilege escalation, information disclosure, etc.)
- Full paths of source file(s) related to the issue
- The location of the affected source code (tag/branch/commit or direct URL)
- Any special configuration required to reproduce the issue
- Step-by-step instructions to reproduce the issue
- Proof-of-concept or exploit code (if possible)
- Impact of the issue, including how an attacker might exploit it

### Response Timeline

- **Initial Response**: Within 48 hours
- **Status Update**: Within 7 days
- **Resolution**: Varies depending on complexity

### Disclosure Policy

- We will confirm receipt of your vulnerability report
- We will work with you to understand and validate the issue
- We will develop and release a fix as quickly as possible
- We will publicly acknowledge your responsible disclosure (if desired)

## Preferred Languages

We prefer all communications to be in English or Spanish.

## Contact

- **Maintainer**: [Héctor Franco Aceituno](https://github.com/HecFranco)
- **Organization**: [nowo-tech](https://github.com/nowo-tech)

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|--------|
| **SECURITY.md** | This document is current and linked from the README where applicable. |
| **`.gitignore` and `.env`** | `.env` and local env files are ignored; no committed secrets. |
| **No secrets in repo** | No API keys, passwords, or tokens in tracked files. |
| **Input / output** | User references resolved via Symfony Security; no arbitrary object injection. |
| **Dependencies** | `composer audit` run; issues triaged. |
| **Logging** | Logs do not print secrets, tokens, or session identifiers unnecessarily. |
| **Permissions / exposure** | Bundle does not expose HTTP routes; auditing runs in Doctrine lifecycle only. |
| **AI security audit (REQ-SEC-004)** | Grade **Pass (good)** / risk **Low** (2026-07-27). Recorded in the Nowo monorepo `BUNDLES_SECURITY_ANALYSIS.md`. |

Record confirmation in the release PR or tag notes.

## AI security audit

| Field | Value |
| ----- | ----- |
| Date | 2026-07-27 |
| Grade | Pass (good) |
| Risk | Low |
| Method | Cursor security-review / monorepo static pass |
| Open residuals | None (Critical/High). App-owned: PII retention for audit columns. |
