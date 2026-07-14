# Code inventory — traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/audit-kit-bundle`  
**Last audited**: 2026-07-14  
**Status**: Implemented

## Symfony config (`src/Resources/config/`)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/config/services.yaml` | DI wiring | FR-ORM-001 | Mapped |
| `Resources/config/packages/nowo_audit_kit.yaml` | Default config template | FR-CFG-002 | Mapped |

## PHP — bundle core

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `NowoAuditKitBundle.php` | Bundle entry | FR-BUNDLE-001 | Mapped |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 | Mapped |
| `DependencyInjection/NowoAuditKitExtension.php` | DI extension | FR-CFG-002 | Mapped |

## PHP — contracts & traits

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Model/TimestampableInterface.php` | Entity contract | FR-CONTRACT-001 | Mapped |
| `Model/BlameableInterface.php` | Entity contract | FR-CONTRACT-002 | Mapped |
| `Model/AuditableInterface.php` | Marker interface | FR-CONTRACT-003 | Mapped |
| `Model/TimestampableTrait.php` | Doctrine trait | FR-TRAIT-001 | Mapped |
| `Model/BlameableTrait.php` | Doctrine trait | FR-TRAIT-002 | Mapped |
| `Model/AuditableTrait.php` | Combined trait | FR-TRAIT-003 | Mapped |

## PHP — Doctrine & security

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Doctrine/AuditableEntityListener.php` | prePersist/preUpdate | FR-ORM-001, FR-ORM-004 | Mapped |
| `Doctrine/AuditablePropertyResolver.php` | Field name resolution | FR-ORM-003 | Mapped |
| `Security/CurrentUserResolver.php` | Current user | FR-SEC-001 | Mapped |

## PHP — attributes

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Attribute/Auditable.php` | Per-entity opt-out | US-04 | Mapped |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle + DI | 3 | 3 |
| Contracts & traits | 6 | 6 |
| Doctrine & security | 3 | 3 |
| Attributes | 1 | 1 |
| Symfony config | 2 | 2 |
| **Total production sources** | **15** | **15** |
