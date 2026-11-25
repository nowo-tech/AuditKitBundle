# Code inventory — traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/audit-kit-bundle`  
**Last audited**: 2026-11-27 (2.1 read port added; 1.11–1.16 sources added 2026-11-16; 2026-10-28: specs [`002`](../002-doctrine-helpers/spec.md)–[`005`](../005-sql-fulltext/spec.md) included)  
**Status**: Implemented

## Symfony config, views and translations (`src/Resources/`)

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Resources/config/services.yaml` | DI wiring | FR-ORM-001, FR-SEC-002, FR-SEC-003 | Mapped |
| `Resources/config/packages/nowo_audit_kit.yaml` | Default config template | FR-CFG-002 | Mapped |
| `Resources/config/action_log.yaml` | Action log services (opt-in) | 003 FR-AL-004, FR-AL-006 | Mapped |
| `Resources/views/action_log/_timeline.html.twig` | Timeline partial | 003 FR-AL-008 | Mapped |
| `Resources/views/action_log/_avatar.html.twig` | Actor/subject picture partial | FR-AVATAR-001 | Mapped |
| `Resources/config/doctrine/action_log/AbstractActionLog.orm.xml` | XML mapping (DDD hosts) | 003 FR-AL-001, FR-ID-001 | Mapped |
| `Resources/config/doctrine/action_log/AbstractIdActionLog.orm.xml` | XML mapping (DDD hosts) | FR-ID-001 | Mapped |
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
| `DependencyInjection/Compiler/BlamePortsPass.php` | BC for 1.14 port overrides in id mode | FR-BLAME-ID-001 | Mapped |

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
| `Model/ReferenceId.php` | User reference to id string | FR-ID-001, FR-KEY-001 | Mapped |

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
| `Security/CurrentUserResolverInterface.php` | Current user port | FR-BLAME-001 | Mapped |
| `Security/CurrentActorProviderInterface.php` | Framework-free actor port | FR-BLAME-ID-001 | Mapped |
| `Security/SecurityCurrentActorProvider.php` | Default actor provider | FR-BLAME-ID-001, FR-SEC-003 | Mapped |
| `Security/AuditActor.php` | Actor value object | FR-BLAME-ID-001 | Mapped |
| `Blame/BlameValueResolverInterface.php` | Blame value port | FR-BLAME-001 | Mapped |
| `Blame/EntityReferenceBlameValueResolver.php` | Default: Doctrine reference | FR-BLAME-001, FR-ORM-006 | Mapped |
| `Blame/UserIdBlameValueResolver.php` | Identifier, no reference | FR-BLAME-001 | Mapped |
| `Blame/SecurityUserIdBlameValueResolver.php` | Identifier of a non-entity firewall user | FR-BLAME-001 | Mapped |
| `Blame/BlameIdResolverInterface.php` | Id-mode blame port | FR-BLAME-ID-001 | Mapped |
| `Blame/ActorIdBlameIdResolver.php` | Default id-mode blame | FR-BLAME-ID-001 | Mapped |
| `Doctrine/ReferenceCollationListener.php` | Binary NO PAD on id columns | FR-KEY-001 | Mapped |
| `Collation/ActionLogExactKeyColumns.php` | Identity-key declaration | FR-KEY-001 | Mapped |

## PHP — action log

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `ActionLog/AbstractActionLog.php` | Mapped superclass | 003 FR-AL-001, FR-AL-002 | Mapped |
| `ActionLog/ActionLogRecorder.php` | Recorder | 003 FR-AL-004 | Mapped |
| `ActionLog/ActionLogRepository.php` | Filters, keyset purge | 003 FR-AL-005, 004 FR-KD-006 | Mapped |
| `ActionLog/ActionLogCriteria.php` | Filter value object | 003 FR-AL-005 | Mapped |
| `ActionLog/Command/PurgeActionLogCommand.php` | Purge command | 003 FR-AL-006 | Mapped |
| `ActionLog/AbstractIdActionLog.php` | Id-mode mapped superclass | FR-ID-001 | Mapped |
| `ActionLog/ActionLogEntryInterface.php` | Shared read model | FR-ID-001 | Mapped |
| `ActionLog/ActionLogCursor.php` | Keyset position in a newest-first action log listing (since 2.1): the `createdAt` and `id` of the last row a page returned. | FR-READ-001 | Mapped |
| `ActionLog/ActionLogPage.php` | One newest-first page of `ActionLogView` rows (since 2.1). | FR-READ-001 | Mapped |
| `ActionLog/ActionLogReader.php` | Default `ActionLogReaderInterface` (since 2.1): forwards to the repository of the configured action log entity when it extends `ActionLogRepository` ( | FR-READ-001 | Mapped |
| `ActionLog/ActionLogReaderInterface.php` | Read port over the action log (since 2.1, REQ-ARCH-001 / DDD-host B7): lists, counts and pages rows without the host writing DQL over the log entity or extendin | FR-READ-001 | Mapped |
| `ActionLog/ActionLogView.php` | Immutable snapshot of one action log row, returned by `ActionLogReaderInterface` (since 2.1). | FR-READ-001 | Mapped |
| `ActionLog/ActionLogRecorderInterface.php` | Recording port | FR-ID-001, 003 FR-AL-004 | Mapped |
| `ActionLog/ActionLogIdRecorderInterface.php` | Id recording port | FR-ID-001 | Mapped |

## PHP — avatars and Twig

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Avatar/ActionLogAvatarResolverInterface.php` | Avatar URL port | FR-AVATAR-001 | Mapped |
| `Avatar/NullActionLogAvatarResolver.php` | Default: no picture | FR-AVATAR-001 | Mapped |
| `Avatar/ActionLogAvatars.php` | Per-request batch + memo | FR-AVATAR-001, FR-SEC-003 | Mapped |
| `Avatar/ActionLogAvatarsResetSubscriber.php` | Memo cleared at boundaries | FR-AVATAR-001 | Mapped |
| `Avatar/AuditAvatarKey.php` | Resolver key | FR-AVATAR-001 | Mapped |
| `Avatar/AvatarUrlPolicy.php` | Safe URL policy | FR-AVATAR-001 | Mapped |
| `Twig/ActionLogAvatarExtension.php` | `nowo_audit_kit_prime_avatars` | FR-AVATAR-001 | Mapped |

## PHP — attributes

| Source file | Spec section | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Attribute/Auditable.php` | Per-entity opt-out | US-04 | Mapped |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Symfony config, views and translations | 14 | 14 |
| PHP — bundle core | 5 | 5 |
| PHP — contracts & traits | 11 | 11 |
| PHP — profiles | 3 | 3 |
| PHP — Doctrine & security | 20 | 20 |
| PHP — action log | 14 | 14 |
| PHP — avatars and Twig | 7 | 7 |
| PHP — attributes | 1 | 1 |
| **Total production sources** | **75** | **75** |

Audit command (production count): `find src -type f ! -name '*.test.*' | wc -l` → **75** (2026-11-27, 2.1.0; 75 on 2026-11-22; 70 on 2026-11-16).
