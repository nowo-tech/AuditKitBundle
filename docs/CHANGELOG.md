# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.4] - 2026-07-14

### Fixed

- Root `make update-deps` now refreshes Composer dependencies in the bundle **and** every demo (REQ-MAKE-008).
- Demo `symfony8`: added `update` / `update-deps` Makefile targets for containerized `composer update`.
- Demo `symfony8`: removed unused Postgres service and `compose.override.yaml` port mapping; stack is SQLite-only (REQ-DEMO-006).
- `release-check-demos` no longer swallows demo failures during pre-release checks.

### Changed

- Demo `symfony8`: `ensure-up` copies `.env.example` to `.env` when missing so `release-check` and `composer update` post-scripts succeed.
- `docs/DEMO-FRANKENPHP.md`: documents only the maintained `demo/symfony8` demo (removed stale symfony7 / symfony8-php85 references).

## [1.0.3] - 2026-07-14

### Changed

- English PHPDoc on all public classes in `src/` (REQ-CS-001).
- `docs/SPEC-DRIVEN-DEVELOPMENT.md` completed with public API table, `REQ-*` traceability, contributor workflow, and See also (REQ-DOCS-013).
- Demo `symfony8`: commented `PORT` in `.env.example`, DNS comment in `docker-compose.yml`, `REQ-DEMO-*` anchors in Makefile.

## [1.0.2] - 2026-07-14

### Fixed

- Removed accidentally committed `demo/symfony7` and `demo/symfony8-php85` directories (including `vendor/`); only `demo/symfony8` remains.

## [1.0.1] - 2026-07-14

### Changed

- README aligned with Nowo bundle standards: badges, canonical `## Documentation` links, `## Tests and coverage`, demo and FrankenPHP notes.
- `docs/SPEC-DRIVEN-DEVELOPMENT.md` updated for v1.0.0 implementation status.
- GitHub issue templates, Spec Kit constitution, and CodeRabbit workflow comments reference **AuditKitBundle** (removed WalletQr leftovers).
- Symfony Flex recipe: `nowo-tech/audit-kit-bundle` replaces incorrect `wallet-qr-bundle` recipe.
- `demo/README.md` and `demo/symfony8/README.md` document Audit Kit demo only.
- Added `tests/Integration/` smoke tests for bundle extension wiring.

### Removed

- Stale Wallet QR demo projects `demo/symfony7` and `demo/symfony8-php85` (not part of this bundle).

## [1.0.0] - 2026-07-14

### Added

- Initial release: automatic `createdAt`, `updatedAt`, `createdBy`, and `updatedBy` fields on opt-in Doctrine entities.
- Optional traits: `TimestampableTrait`, `BlameableTrait`, `AuditableTrait`.
- Marker interfaces: `TimestampableInterface`, `BlameableInterface`, `AuditableInterface`.
- Doctrine entity listener (`prePersist` / `preUpdate`) with configurable property names.
- Per-entity opt-out via `#[Auditable(enabled: false)]`.
- Symfony configuration tree `nowo_audit_kit` (`user_class`, `fields`, `timestamp_type`, `blameable`, `timestampable`, `enabled`).
- `CurrentUserResolver` integration with Symfony Security (guest / CLI safe — blame fields stay `null`).
- Demo application for Symfony 8.x (FrankenPHP, SQLite).
- GitHub Spec Kit baseline (`specs/001-baseline/`), CI matrix (PHP 8.2–8.5, Symfony 7.0 / 7.4 / 8.0 / 8.1), and **100%** unit test coverage on `src/`.
