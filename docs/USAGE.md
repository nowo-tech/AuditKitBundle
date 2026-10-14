# Usage

## Table of contents

- [Configuration profiles](#configuration-profiles)
- [Quick start with AuditableTrait](#quick-start-with-auditabletrait)
- [Traits and interfaces](#traits-and-interfaces)
- [Custom property names](#custom-property-names)
- [Opt-out per entity](#opt-out-per-entity)
- [Blame fields without the trait](#blame-fields-without-the-trait)
- [Public UUID](#public-uuid)
- [Append-only created-at](#append-only-created-at)
- [Escaping LIKE searches](#escaping-like-searches)
- [Batched deletes](#batched-deletes)
- [Action log](#action-log)
  - [Entity](#entity)
  - [Existing table (no migration)](#existing-table-no-migration)
  - [Recording](#recording)
  - [Querying](#querying)
  - [Purging](#purging)
  - [Timeline partial](#timeline-partial)
- [Doctrine migrations](#doctrine-migrations)
- [Behaviour summary](#behaviour-summary)
- [Related bundles](#related-bundles)

## Configuration profiles

Since **1.1.0**, settings can be grouped under named **profiles** when the application has more than one user entity. Each profile maps a `user_class` to its own field names, `timestamp_type`, and `blameable` / `timestampable` flags.

The listener resolves the profile automatically from the authenticated entity class at runtime. Timestamps fall back to `default_profile` when no user is present (CLI/guest) or when the user class is not mapped to any profile.

See [Configuration — Profiles](CONFIGURATION.md#profiles) for the YAML structure.

## Quick start with AuditableTrait

```php
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\AuditableTrait;

#[ORM\Entity]
class Article
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
}
```

Generate and run a Doctrine migration for the new columns. On persist/update, the listener sets:

- `createdAt` / `updatedAt` — always (when `timestampable: true`)
- `createdBy` / `updatedBy` — when an authenticated user matches `user_class` (nullable in CLI or guest context)

## Traits and interfaces

| Trait | Fields | Interface |
| ----- | ------ | --------- |
| `TimestampableTrait` | `createdAt`, `updatedAt` | `TimestampableInterface` |
| `BlameableTrait` | `createdBy`, `updatedBy` | `BlameableInterface` |
| `AuditableTrait` | all four | `AuditableInterface` |

`BlameableTrait` maps `createdBy` / `updatedBy` as `ManyToOne` to `Nowo\AuditKitBundle\Model\AuditUserInterface`. The bundle resolves that interface to the **default profile** `user_class` through `doctrine.orm.resolve_target_entities` (implementing the interface on your user entity is optional). If another profile's user class must be the target, declare the blame properties yourself (see [Blame fields without the trait](#blame-fields-without-the-trait)), or override the mapping with your own `resolve_target_entities` entry.

No `#[ORM\EntityListeners]` attribute is needed: the bundle registers a global Doctrine `prePersist` / `preUpdate` listener and only touches auditable entities.

## Custom property names

Map config keys to your entity properties:

```yaml
nowo_audit_kit:
    user_class: App\Entity\User
    fields:
        created_by: createdByUser
        updated_by: updatedByUser
```

## Opt-out per entity

```php
use Nowo\AuditKitBundle\Attribute\Auditable;

#[Auditable(enabled: false)]
#[ORM\Entity]
class LegacyRecord
{
    use AuditableTrait;
}
```

## Blame fields without the trait

When you need a custom `ManyToOne` mapping (e.g. typed to your `User` entity), implement the interface and declare columns manually — see `demo/symfony8/src/Entity/Article.php`.

## Public UUID

Since **1.4.0**. Expose an opaque identifier in URLs while integer primary keys stay internal:

```php
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\PublicUuidInterface;
use Nowo\AuditKitBundle\Model\PublicUuidTrait;

#[ORM\Entity]
class Project implements PublicUuidInterface
{
    use PublicUuidTrait; // uuid: string(36), not null
}
```

- `PublicUuidListener` (registered by the bundle, `prePersist`) calls `ensureUuid()` on every entity implementing `PublicUuidInterface`; other entities are ignored. It runs whatever the audit profile configuration is.
- `ensureUuid()` assigns a UUID v7 (RFC 4122) only when the value is empty; call it from the constructor if the UUID is needed before flush.
- `assignUuid($uuid)` forces a stable value (fixtures, preloaded configuration): the value is trimmed, an empty value is ignored and a non-RFC value throws `InvalidArgumentException` with message `invalid_uuid:<value>`.

## Append-only created-at

Since **1.4.0**. For rows that are written once (logs, events, history), `CreatedAtImmutableTrait` maps a **non-null** `createdAt` (`datetime_immutable`) set by the entity itself:

```php
use Nowo\AuditKitBundle\Model\CreatedAtImmutableTrait;

#[ORM\Entity]
class LoginEvent
{
    use CreatedAtImmutableTrait;

    public function __construct()
    {
        $this->initializeCreatedAt();
    }
}
```

| | `TimestampableTrait` | `CreatedAtImmutableTrait` |
| --- | --- | --- |
| Columns | `createdAt`, `updatedAt` (nullable) | `createdAt` (not null) |
| Written by | the audit listener (`prePersist` / `preUpdate`) | the entity constructor (`initializeCreatedAt()`) |
| Use for | editable entities | append-only rows |

Use one or the other on a given entity, not both (same `createdAt` property).

## Escaping LIKE searches

Since **1.4.0**. `Nowo\AuditKitBundle\Doctrine\SqlLike` escapes `\`, `%` and `_` in user input. Always append `SqlLike::ESCAPE_CLAUSE` (`ESCAPE '\'`): MySQL uses `\` as default escape character but SQLite has none, so the explicit clause gives the same result on both.

```php
use Nowo\AuditKitBundle\Doctrine\SqlLike;

$qb->andWhere('p.name LIKE :q ' . SqlLike::ESCAPE_CLAUSE)
    ->setParameter('q', SqlLike::contains($term)); // '%' . SqlLike::escape($term) . '%'
```

## Batched deletes

Since **1.6.0**. `Nowo\AuditKitBundle\Doctrine\KeysetDelete::run()` deletes the rows that match a `WHERE` clause in short primary-key batches, on DBAL (outside the unit of work). Each batch is `SELECT id … WHERE (<where>) AND id > <last id> ORDER BY id` with the platform limit, then `DELETE … WHERE id IN (…)`, each in autocommit, until a short batch. Use it for purges and mass clears where one `DELETE … WHERE …` would hold locks and undo for millions of rows. Specification: [`specs/004-keyset-delete`](../specs/004-keyset-delete/spec.md).

```php
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Nowo\AuditKitBundle\Doctrine\KeysetDelete;

// Positional placeholders
$deleted = KeysetDelete::run(
    $connection,
    'messenger_processed_messages',
    'finished_at <= ? AND failure_type IS NULL',
    [$cutoff],
    [Types::DATETIME_IMMUTABLE],
    batchSize: 2000,
);

// Named placeholders and array parameters; joins go in the clause as IN (SELECT …)
$deleted = KeysetDelete::run(
    $connection,
    'issue_history',
    'issue_id IN (SELECT id FROM issue WHERE project_id = :project) AND kind IN (:kinds)',
    ['project' => $projectId, 'kinds' => ['a', 'b']],
    ['project' => ParameterType::INTEGER, 'kinds' => ArrayParameterType::STRING],
);
```

- `$where` may be empty (every row). `$table`, `$where` and `$idColumn` (default `id`) are inserted as is: quote reserved identifiers yourself and keep user input in `$params`.
- The name `:keysetDeleteAfterId` (`KeysetDelete::AFTER_ID_PARAMETER`) is reserved for named parameters.
- Integer ids are bound as integers; string primary keys (UUID, …) work too, following the database order.
- Returns the number of deleted rows. `ActionLogRepository::purgeBefore()` uses it.

## Action log

Since **1.5.0**. An opt-in, append-only log of *who did what, to whom*: admin actions, membership changes, page views. It is independent of the entity-level audit fields above. Specification: [`specs/003-action-log`](../specs/003-action-log/spec.md).

### Entity

Extend the mapped superclass and declare the table, indexes and repository in the host:

```php
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;

#[ORM\Entity(repositoryClass: UserActionRepository::class)]
#[ORM\Table(name: 'user_action')]
#[ORM\Index(name: 'idx_user_action_subject_created', columns: ['subject_id', 'created_at'])]
#[ORM\Index(name: 'idx_user_action_actor_created', columns: ['actor_id', 'created_at'])]
#[ORM\Index(name: 'idx_user_action_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_user_action_scope_created', columns: ['scope', 'created_at'])]
class UserAction extends AbstractActionLog
{
}
```

| Field | Column | Mapping |
| --- | --- | --- |
| `id` | `id` | int, generated |
| `action` | `action` | string 48, not null (enum backing value or string key) |
| `actor` | `actor_id` | `ManyToOne` to the default profile `user_class`, nullable, `ON DELETE SET NULL` |
| `subject` | `subject_id` | same as `actor` |
| `context` | `context` | json, not null (`[]` by default) |
| `scope` | `scope` | string 64, nullable (project / tenant / group key, indexed by the host) |
| `ipAddress` | `ip_address` | string 45, nullable |
| `createdAt` | `created_at` | `datetime_immutable`, not null, set in the constructor (`CreatedAtImmutableTrait`) |

Column names are explicit, so they do not depend on the Doctrine naming strategy. The bundle registers the Doctrine mapping of `AbstractActionLog` on the default entity manager (see [`action_log.register_mapping`](CONFIGURATION.md#action-log)). `actor` and `subject` target `AuditUserInterface`, which the bundle already resolves to the default profile `user_class`.

Then enable it:

```yaml
nowo_audit_kit:
    action_log:
        entity_class: App\Entity\UserAction
```

### Existing table (no migration)

Rename columns with Doctrine overrides when the table already exists. Example for Beacon's `user_action` table (`subject_user_id`, `project_uuid` VARCHAR(36), four named indexes):

```php
#[ORM\Entity(repositoryClass: UserActionRepository::class)]
#[ORM\Table(name: 'user_action')]
#[ORM\Index(name: 'idx_user_action_subject_created', columns: ['subject_user_id', 'created_at'])]
#[ORM\Index(name: 'idx_user_action_actor_created', columns: ['actor_id', 'created_at'])]
#[ORM\Index(name: 'idx_user_action_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_user_action_project_created', columns: ['project_uuid', 'created_at'])]
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'scope', column: new ORM\Column(name: 'project_uuid', length: 36, nullable: true)),
])]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'subject', joinColumns: [
        new ORM\JoinColumn(name: 'subject_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL'),
    ]),
])]
class UserAction extends AbstractActionLog
{
    public function getActionType(): UserActionType
    {
        return UserActionType::from($this->getAction());
    }
}
```

An override replaces the whole column / join column definition: repeat `length`, `nullable` and `onDelete`. Check with `php bin/console doctrine:schema:update --dump-sql` (expected: nothing). The bundle test suite runs this exact comparison against a copy of the Beacon entity on SQLite and MySQL 8.

### Recording

```php
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;

public function __construct(private ActionLogRecorder $actions) {}

// Persisted, flushed with your own writes:
$this->actions->record(UserActionType::GroupCreated, $actor, $subject, ['group_uuid' => $group->getUuid()]);

// Navigation / view events with no other write:
$this->actions->recordAndFlush('project.opened', $actor, context: ['project_uuid' => $uuid], scope: $uuid);
```

- `action`: a `BackedEnum` (its backing value is stored) or a string.
- The client IP comes from the current request (`RequestStack`); `null` in CLI or with `capture_ip: false`.
- With `scope_context_key: project_uuid`, `context.project_uuid` is copied into `scope` when no `$scope` is passed (non-empty string that fits the column; otherwise `null`).
- The row is a plain entity: `getAction()`, `isAction()`, `getActor()`, `getSubject()`, `getContext()`, `getScope()`, `getIpAddress()`, `getCreatedAt()`. Hosts may narrow getters covariantly (`getActor(): ?User`).

### Querying

Extend the base repository:

```php
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\ActionLogRepository;

/** @extends ActionLogRepository<UserAction> */
class UserActionRepository extends ActionLogRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAction::class);
    }
}
```

```php
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;

$rows = $repository->findByCriteria(new ActionLogCriteria(
    involving: $user,              // actor OR subject (also: actor:, subject:)
    scope: [$uuidA, $uuidB],       // one string or a list
    actions: $allowedActions,      // one action or a list of enums / strings
    from: new DateTimeImmutable('-30 days'),
    to: null,
    limit: 50,
    offset: 0,
));
$total  = $repository->countByCriteria($criteria);
$latest = $repository->findLatest(50);
$mine   = $repository->findInvolving($user, $allowedActions);
```

Results are ordered `createdAt DESC, id DESC` with actor and subject fetch-joined. `null` means "no filter"; an empty list (`actions: []`, `scope: []`) matches nothing, so a computed allow-list never widens the query. `createCriteriaQueryBuilder()` is protected for host-specific queries.

### Purging

```bash
php bin/console nowo:audit-kit:action-log:purge                        # configured purge.actions, purge.older_than_days
php bin/console nowo:audit-kit:action-log:purge --older-than-days=30 --action=project.opened --action=issue.opened
php bin/console nowo:audit-kit:action-log:purge --all-actions --older-than-days=365
```

Rows are deleted by id in keyset batches with [`KeysetDelete`](#batched-deletes) (`--batch-size`, default `purge.batch_size`): each batch starts after the last id seen, so retained rows are scanned once. Without `--action` and with an empty `purge.actions`, the command refuses unless `--all-actions` is given. From code: `$repository->purgeBefore($before, $actions, $batchSize)` (`null` = every action, `[]` = nothing).

### Timeline partial

With Twig installed, render a list of rows:

```twig
{% include '@NowoAuditKit/action_log/_timeline.html.twig' with {
    entries: entries,
    action_prefix: 'users.activity.action.',   {# action label keys, domain action_domain (messages) #}
    context_prefix: 'users.activity.context.',
} only %}
```

Classes: `nowo-ui-list`, `nowo-ui-list-item`, `nowo-ui-muted`, `nowo-ui-empty` (styled when [`nowo-tech/ui-kit-bundle`](https://github.com/nowo-tech/UiKitBundle) is installed) and `nowo-audit-timeline__*` hooks. Overridable variables: `translation_domain` (default `NowoAuditKitBundle`), `empty_key`, `by_key`, `by_system_key`, `subject_key`, `action_prefix`, `action_domain`, `context_prefix`, `show_ip`, `show_context`, `date_format`, `timeline_id`, `entry_testid`. User labels use `displayName`, then `userIdentifier`, then `id`. Override the whole file in `templates/bundles/NowoAuditKitBundle/action_log/_timeline.html.twig`.

## Doctrine migrations

After adding traits or columns:

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

Recommended column types:

- Timestamps: `datetime_immutable` (or match `timestamp_type` in config)
- Blame: `ManyToOne` to your user entity, nullable, `onDelete: SET NULL`

## Behaviour summary

| Event | Timestamps | Blame |
| ----- | ---------- | ----- |
| `prePersist` | sets `createdAt` and `updatedAt` | sets `createdBy` and `updatedBy` |
| `preUpdate` | refreshes `updatedAt` only | refreshes `updatedBy` only |
| No authenticated user | timestamps use `default_profile` | blame fields remain `null` |
| Authenticated user not in any profile | timestamps use `default_profile` | blame fields remain `null` |

## Related bundles

- [`nowo-tech/user-kit-bundle`](https://github.com/nowo-tech/UserKitBundle) — account state / presence
- [`nowo-tech/auth-kit-bundle`](https://github.com/nowo-tech/AuthKitBundle) — authentication flows

Audit Kit Bundle is independent; it only needs a resolvable `user_class` and Symfony Security when blame fields should be populated.
