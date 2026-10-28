# Code inventory — traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/audit-kit-bundle`  
**Last audited**: 2026-10-28 (specs [`002`](../002-doctrine-helpers/spec.md)–[`005`](../005-sql-fulltext/spec.md) included)  
**Status**: Implemented

## Symfony config, views and translations (`src/Resources/`)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/config/services.yaml` | DI wiring | FR-ORM-001, FR-SEC-002, FR-SEC-003 | Mapped |
| `Resources/config/packages/nowo_audit_kit.yaml` | Default config template | FR-CFG-002 | Mapped |
| `Resources/config/action_log.yaml` | Action log services (opt-in) | 003 FR-AL-004, FR-AL-006 | Mapped |
| `Resources/views/action_log/_timeline.html.twig` | Timeline partial | 003 FR-AL-008 | Mapped |
| `Resources/translations/NowoAuditKitBundle.en.yaml` | Timeline strings (English) | 003 FR-AL-008 | Mapped |
| `Resources/translations/NowoAuditKitBundle.es.yaml` | Timeline strings (Spanish) | 003 FR-AL-008 | Mapped |
| `Resources/translations/NowoAuditKitBundle.it.yaml` | Timeline strings (Italian) | 003 FR-AL-008 | Mapped |
| `Resources/translations/NowoAuditKitBundle.fr.yaml` | Timeline strings (French) | 003 FR-AL-008 | Mapped |
| `Resources/translations/NowoAuditKitBundle.pt.yaml` | Timeline strings (Portuguese) | 003 FR-AL-008 | Mapped |
| `Resources/translations/NowoAuditKitBundle.de.yaml` | Timeline strings (German) | 003 FR-AL-008 | Mapped |
| `Resources/translations/NowoAuditKitBundle.nl.yaml` | Timeline strings (Dutch) | 003 FR-AL-008 | Mapped |

## PHP — bundle core

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `NowoAuditKitBundle.php` | Bundle entry | FR-BUNDLE-001 | Mapped |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001, FR-CFG-003 | Mapped |
| `DependencyInjection/NowoAuditKitExtension.php` | DI extension | FR-CFG-002, FR-CFG-003 | Mapped |
| `DependencyInjection/Compiler/TwigPathsPass.php` | `@NowoAuditKitBundle` Twig namespace, app override first | FR-TWIG-001 | Mapped |

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
| `Doctrine/SqlFulltext.php` | FULLTEXT / LIKE search condition, snippets | 005 FR-FT-001 | Mapped |
| `Doctrine/KeysetDelete.php` | Keyset batch delete (DBAL) | 004 FR-KD-001 | Mapped |
| `Security/CurrentUserResolver.php` | Current user | FR-SEC-001, FR-SEC-002, FR-SEC-003 | Mapped |

## PHP — action log

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `ActionLog/AbstractActionLog.php` | Mapped superclass | 003 FR-AL-001, FR-AL-002 | Mapped |
| `ActionLog/ActionLogRecorder.php` | Recorder | 003 FR-AL-004 | Mapped |
| `ActionLog/ActionLogRepository.php` | Filters, keyset purge | 003 FR-AL-005, 004 FR-KD-006 | Mapped |
| `ActionLog/ActionLogCriteria.php` | Filter value object | 003 FR-AL-005 | Mapped |
| `ActionLog/Command/PurgeActionLogCommand.php` | Purge command | 003 FR-AL-006 | Mapped |

## PHP — attributes

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Attribute/Auditable.php` | Per-entity opt-out | US-04 | Mapped |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Symfony config, views and translations | 11 | 11 |
| PHP — bundle core | 4 | 4 |
| PHP — contracts & traits | 10 | 10 |
| PHP — profiles | 3 | 3 |
| PHP — Doctrine & security | 8 | 8 |
| PHP — action log | 5 | 5 |
| PHP — attributes | 1 | 1 |
| **Total production sources** | **42** | **42** |

Audit command (production count): `git ls-files src | wc -l` → **42** (2026-10-28).
