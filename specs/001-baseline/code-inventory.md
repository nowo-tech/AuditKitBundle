# Code inventory — traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/audit-kit-bundle`  
**Last audited**: 2026-10-13 (spec [`002-doctrine-helpers`](../002-doctrine-helpers/spec.md))  
**Status**: Implemented

## Symfony config (`src/Resources/config/`)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/config/services.yaml` | DI wiring | FR-ORM-001, FR-SEC-002, FR-SEC-003 | Mapped |
| `Resources/config/packages/nowo_audit_kit.yaml` | Default config template | FR-CFG-002 | Mapped |

## PHP — bundle core

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `NowoAuditKitBundle.php` | Bundle entry | FR-BUNDLE-001 | Mapped |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001, FR-CFG-003 | Mapped |
| `DependencyInjection/NowoAuditKitExtension.php` | DI extension | FR-CFG-002, FR-CFG-003 | Mapped |

## PHP — contracts & traits

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Model/TimestampableInterface.php` | Entity contract | FR-CONTRACT-001 | Mapped |
| `Model/BlameableInterface.php` | Entity contract | FR-CONTRACT-002 | Mapped |
| `Model/AuditableInterface.php` | Marker interface | FR-CONTRACT-003 | Mapped |
| `Model/TimestampableTrait.php` | Doctrine trait | FR-TRAIT-001 | Mapped |
| `Model/BlameableTrait.php` | Doctrine trait | FR-TRAIT-002 | Mapped |
| `Model/AuditableTrait.php` | Combined trait | FR-TRAIT-003 | Mapped |
| `Model/AuditUserInterface.php` | Blame association target | FR-TRAIT-002 | Mapped |
| `Model/PublicUuidInterface.php` | Public UUID contract | 002 FR-UUID-001 | Mapped |
| `Model/PublicUuidTrait.php` | Public UUID column | 002 FR-UUID-002 | Mapped |
| `Model/CreatedAtImmutableTrait.php` | Append-only created-at | 002 FR-CREATED-001 | Mapped |

## PHP — profiles

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Profile/ProfileSettings.php` | Named profile DTO | FR-PROFILE-001 | Mapped |
| `Profile/ProfileRegistry.php` | Resolve profile by user class | FR-PROFILE-002 | Mapped |
| `Profile/UnknownProfileException.php` | Invalid default/profile name | FR-PROFILE-003 | Mapped |

## PHP — Doctrine & security

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Doctrine/AuditableEntityListener.php` | prePersist/preUpdate | FR-ORM-001, FR-ORM-004, FR-ORM-006, FR-OBS-001 | Mapped |
| `Doctrine/AuditablePropertyResolver.php` | Field name resolution | FR-ORM-003 | Mapped |
| `Doctrine/AuditableDoctrineListener.php` | Global prePersist/preUpdate | FR-ORM-001 | Mapped |
| `Doctrine/PublicUuidListener.php` | Public UUID prePersist | 002 FR-UUID-003 | Mapped |
| `Doctrine/SqlLike.php` | LIKE escaping | 002 FR-LIKE-001 | Mapped |
| `Security/CurrentUserResolver.php` | Current user | FR-SEC-001, FR-SEC-002, FR-SEC-003 | Mapped |

## PHP — attributes

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Attribute/Auditable.php` | Per-entity opt-out | US-04 | Mapped |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle + DI | 3 | 3 |
| Contracts & traits | 10 | 10 |
| Profiles | 3 | 3 |
| Doctrine & security | 6 | 6 |
| Attributes | 1 | 1 |
| Symfony config | 2 | 2 |
| **Total production sources** | **25** | **25** |
