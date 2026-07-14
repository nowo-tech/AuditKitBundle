# Upgrading

This document describes how to upgrade between versions of Audit Kit Bundle.

## 1.x

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
