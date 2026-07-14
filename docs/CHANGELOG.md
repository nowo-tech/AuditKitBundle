# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.0] - 2026-07-14

### Added

- Initial release: automatic `createdAt`, `updatedAt`, `createdBy`, and `updatedBy` fields on opt-in Doctrine entities.
- Optional traits: `TimestampableTrait`, `BlameableTrait`, `AuditableTrait`.
- Marker interfaces: `TimestampableInterface`, `BlameableInterface`, `AuditableInterface`.
- Doctrine entity listener (`prePersist` / `preUpdate`) with configurable property names.
- Per-entity opt-out via `#[Auditable(enabled: false)]`.
- Symfony configuration tree `nowo_audit_kit` (`user_class`, `fields`, `timestamp_type`, `blameable`, `timestampable`, `enabled`).
- `CurrentUserResolver` integration with Symfony Security (guest / CLI safe — blame fields stay `null`).
- Demo applications for Symfony 7.4 and 8.x (FrankenPHP, SQLite).
- GitHub Spec Kit baseline (`specs/001-baseline/`), CI matrix (PHP 8.2–8.5, Symfony 7.0 / 7.4 / 8.0 / 8.1), and **100%** unit test coverage on `src/`.
