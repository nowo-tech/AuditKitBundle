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
- [Full-text search](#full-text-search)
- [Batched deletes](#batched-deletes)
- [Action log](#action-log)
  - [Entity](#entity)
  - [Existing table (no migration)](#existing-table-no-migration)
  - [Recording](#recording)
  - [Querying](#querying)
  - [Purging](#purging)
  - [Timeline partial](#timeline-partial)
  - [Timeline avatars (1.11)](#timeline-avatars-111)
- [Overriding templates](#overriding-templates)
- [Doctrine migrations](#doctrine-migrations)
- [Behaviour summary](#behaviour-summary)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
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

## Full-text search

Since **1.7.0**. `Nowo\AuditKitBundle\Doctrine\SqlFulltext` builds a DQL text condition: on MySQL / MariaDB it uses the `FULLTEXT` index (`MATCH … AGAINST` in boolean mode, every word required as a prefix); on other platforms (SQLite, PostgreSQL, …), and when the query has no usable word, it falls back to an escaped `LIKE` with [`SqlLike`](#escaping-like-searches). Specification: [`specs/005-sql-fulltext`](../specs/005-sql-fulltext/spec.md).

**Requirement (MySQL / MariaDB):** register a `MATCH` DQL function that accepts `AGAINST(:q BOOLEAN)`, for example `MatchAgainst` from [`beberlei/doctrineextensions`](https://github.com/beberlei/DoctrineExtensions). The bundle does not require it.

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        dql:
            string_functions:
                MATCH: DoctrineExtensions\Query\Mysql\MatchAgainst
```

The fields must be the columns of one `FULLTEXT` index, in index order (e.g. `#[ORM\Index(columns: ['title', 'culprit'], flags: ['fulltext'])]`).

```php
use Nowo\AuditKitBundle\Doctrine\SqlFulltext;

// 4th argument: bool, or a Doctrine DBAL Connection / platform (MySQL and MariaDB use FULLTEXT)
[$condition, $parameters] = SqlFulltext::clause(['i.title', 'i.culprit'], $term, 'q', $this->getEntityManager()->getConnection());
$qb->andWhere($condition);
foreach ($parameters as $name => $value) {
    $qb->setParameter($name, $value);
}
// MySQL:  MATCH(i.title, i.culprit) AGAINST(:q BOOLEAN) > 0               q = '+fatal* +e2e* +xss*'  ("Fatal e2e-xss")
// Others: (i.title LIKE :q ESCAPE '\' OR i.culprit LIKE :q ESCAPE '\')    q = '%Fatal e2e-xss%'
```

- `SqlFulltext::booleanQuery($term)` — lower-cases and splits like the InnoDB parser (on anything but letters, digits and `_`), drops words shorter than the minimum token length (default `3`, InnoDB `innodb_ft_min_token_size`) and stopwords (default `SqlFulltext::INNODB_STOPWORDS`), removes duplicates and returns `+word*` terms, or `""` (then `clause()` uses `LIKE`). Pass `$minTokenLength` / `$stopwords` to `clause()` or `booleanQuery()` when the server uses other settings (e.g. `innodb_ft_min_token_size`, a custom `innodb_ft_server_stopword_table`, or MyISAM's `ft_min_word_len = 4`).
- `SqlFulltext::snippet($text, $term, $width = 90)` — about `$width` characters around the first literal, case-insensitive match of a query word, with `…` at the cuts (the start of the text when no word matches literally, e.g. a FULLTEXT prefix match). Escape it in the template as usual.
- `SqlFulltext::isMysql($connectionOrPlatform)` — the platform check used by `clause()` (`AbstractMySQLPlatform`).
- Ranking is not applied: order the results yourself (e.g. by date). Boolean mode does not sort by relevance.

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
{% include '@NowoAuditKitBundle/action_log/_timeline.html.twig' with {
    entries: entries,
    action_prefix: 'users.activity.action.',   {# action label keys, domain action_domain (messages) #}
    context_prefix: 'users.activity.context.',
} only %}
```

Classes: `nowo-ui-list`, `nowo-ui-list-item`, `nowo-ui-muted`, `nowo-ui-empty` (styled when [`nowo-tech/ui-kit-bundle`](https://github.com/nowo-tech/UiKitBundle) is installed) and `nowo-audit-timeline__*` hooks. Overridable variables: `translation_domain` (default `NowoAuditKitBundle`), `empty_key`, `by_key`, `by_system_key`, `subject_key`, `action_prefix`, `action_domain`, `context_prefix`, `show_ip`, `show_context`, `date_format`, `timeline_id`, `entry_testid`. User labels use `displayName`, then `userIdentifier`, then `id`. Prefer these variables over copying the file; for a full override see [Overriding templates](#overriding-templates).

### Timeline avatars (1.11)

The actor and the subject get a 24 px picture before their name when the application aliases
`Nowo\AuditKitBundle\Avatar\ActionLogAvatarResolverInterface` (default: `NullActionLogAvatarResolver`, no pictures).

```yaml
# config/services.yaml
services:
    Nowo\AuditKitBundle\Avatar\ActionLogAvatarResolverInterface: '@App\Audit\AuditAvatarUrls'
```

- **One call per timeline.** The partial calls `nowo_audit_kit_prime_avatars(entries)` first: every actor and subject
  not seen yet goes to **one** `urlsFor(list<object> $users, 24)` call, keyed by `AuditAvatarKey::of($user)` (the id
  as a string). The bundle never calls `urlFor()`. Results are memoised for the request (`kernel.reset`). Do not run a
  query per user in the resolver.
- **Who gets none:** a deleted actor ("by system"), a user without an id, and any user the resolver returns null for.
  Return null for anonymised or erased accounts.
- **URL policy** (`Avatar\AvatarUrlPolicy`, the same rules and case table as UiKit's): `https://` without userinfo
  or a same-origin `/path`; `http:`, `data:`, `javascript:`, `blob:`, `//host`, IPv6-literal hosts, ports above 65535,
  backslashes, quotes, `<`, `>`, backtick, control characters (also a trailing line feed) and more than 2048 bytes are
  dropped. The URL should be stable and access-checked when fetched (no bearer secret in it). A resolver that throws
  gives no pictures (and a `warning` log), never an error page.
- **Never return a user-entered URL.** Generate it from the account (e.g. an uploaded file's id): an accepted
  same-origin path is a credentialed `GET` from every viewer of the timeline, and an accepted `https:` host is fetched
  by every viewer's browser.
- **Markup** (`action_log/_avatar.html.twig`, UiKit's avatar classes, decorative because the name follows):

  ```html
  <span class="nowo-ui-avatar nowo-ui-avatar--sm has-image nowo-audit-timeline__avatar" aria-hidden="true" data-nowo-ui-avatar>AL<img
        class="nowo-ui-avatar__image" data-nowo-avatar-image src="/files/…/s64" alt="" width="24" height="24"
        loading="lazy" decoding="async" referrerpolicy="same-origin"></span>
  ```

  With UiKit ≥ 2.26 (`nowo-ui.css` + `js/nowo-ui-avatar.js`) the picture covers the initials once loaded and a broken
  one falls back to the initials. Without UiKit the initials sit next to the picture and a broken picture paints
  nothing.
- `nowo_audit_kit_avatar_url(user)` returns the memoised URL (or null) for hosts that render their own avatar.

## Overriding templates

The bundle registers the Twig namespace **`@NowoAuditKitBundle/`** (REQ-TWIG-002; same string as the bundle class and the `NowoAuditKitBundle` translation domain). **`TwigPathsPass`** maps **`templates/bundles/NowoAuditKitBundle/`** with **`prependPath()`** when that folder exists, then registers the bundle **`src/Resources/views`** path with **`addPath()`**, so application copies are always tried before vendor templates. Registration is unconditional whenever Twig is installed (it is a runtime requirement); there is no flag in [CONFIGURATION.md](CONFIGURATION.md) and no entry is needed in `config/packages/twig.yaml`.

**Overridable templates (`<subpath>`):**

| `<subpath>` | Purpose |
| ----------- | ------- |
| `action_log/_timeline.html.twig` | Read-only list of action-log rows (actor, subject, action label, context, IP, date) with `nowo-ui-*` classes and `nowo-audit-timeline__*` hooks. |
| `action_log/_avatar.html.twig` | *(1.11)* The 24 px actor / subject avatar of the timeline (UiKit's avatar markup and classes). |

**Override vs upgrade.** Choose the shallowest depth that does the job:

| Depth | How | On bundle upgrade |
| ----- | --- | ----------------- |
| Preferred — no Twig fork | Pass the [timeline variables](#timeline-partial) (`translation_domain`, `*_key`, `action_prefix`, `show_ip`, `show_context`, `date_format`, …) and style the `nowo-ui-*` / `nowo-audit-timeline__*` classes in your CSS. | Vendor fixes apply automatically. |
| Wrapper | Include the partial from your own template and add markup around it. | Vendor fixes apply automatically. |
| Full-file override | Copy the file to `templates/bundles/NowoAuditKitBundle/<subpath>`. | That `<subpath>` is frozen. |

**Freeze rule:** an application file at the override path **always wins** and will **not** pick up vendor changes for that path until you remove or merge it. Prefer the variables and CSS hooks above when you want upgrades to keep applying.

**Procedure (app):**

1. Take the `<subpath>` from the table above (path relative to the bundle views root `src/Resources/views`).
2. Create **`templates/bundles/NowoAuditKitBundle/<subpath>`** in your project, e.g. `templates/bundles/NowoAuditKitBundle/action_log/_timeline.html.twig`.
3. Clear the Twig / Symfony cache in dev if needed: **`php bin/console cache:clear`**.
4. Treat the copy as a fork: it will not change when the bundle ships a new version of that `<subpath>`.

**Recovering upstream after a freeze:** delete (or rename aside) the application file, clear the cache, and re-apply only the customisations you still need through variables or CSS. Diff your copy against `vendor/nowo-tech/audit-kit-bundle/src/Resources/views/<subpath>` before re-copying.

Renaming or removing a `<subpath>` listed above is a breaking change and is announced in [UPGRADING.md](UPGRADING.md). The legacy namespace `@NowoAuditKit/` (Symfony's automatic bundle namespace) still resolves the same files for 1.x but is deprecated; use `@NowoAuditKitBundle/`.

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

## Using it from a DDD / hexagonal application

**Layer:** infrastructure. AuditKit fills persistence fields from Doctrine lifecycle events: timestamps, blame and
the public UUID. It also ships SQL helpers (`SqlLike`, `SqlFulltext`, `KeysetDelete`). The action log is optional;
its table and entity belong to your application, and the bundle provides the mapped superclass, recorder,
repository and timeline partial.
It dispatches no Symfony events and owns no tables.

**Keeping the domain free of the bundle**

- Apply the traits (`TimestampableTrait`, `BlameableTrait`, `PublicUuidTrait`, `CreatedAtImmutableTrait`) to your
  **Doctrine persistence model**, not to pure domain entities: the traits carry `#[ORM\…]` attributes.
- If the Doctrine entity is your domain entity, implement `TimestampableInterface` (or `BlameableInterface`) and
  declare the properties yourself; `fields.*` maps them to custom names. The listener only processes classes that
  implement one of the bundle interfaces or use one of its traits.
- Type-hint `ActionLogRecorderInterface` (since 1.12) and wrap it, with your `ActionLogRepository` subclass, in your
  own port, for example `AuditTrail::record(string $action, ?UserId $actor, ?UserId $subject, array $context)`. Map
  your ids to user entity references inside that adapter.

```php
final class DoctrineAuditTrail implements AuditTrail
{
    public function __construct(
        private ActionLogRecorderInterface $recorder,
        private EntityManagerInterface $em,
    ) {}

    public function record(string $action, ?UserId $actor, ?UserId $subject, array $context = []): void
    {
        $this->recorder->record(
            $action,
            $actor ? $this->em->getReference(User::class, $actor->toInt()) : null,
            $subject ? $this->em->getReference(User::class, $subject->toInt()) : null,
            $context,
        );
    }
}
```

**Your security user is not the Doctrine user entity (since 1.12)**

Blame is computed by two ports, both overridable by alias. The defaults keep the behaviour of earlier versions.

| Port | Default | What it decides |
| --- | --- | --- |
| `Nowo\AuditKitBundle\Security\CurrentUserResolverInterface` | `CurrentUserResolver` (Symfony token storage, worker-safe) | who is acting (`resolve(): ?UserInterface`) |
| `Nowo\AuditKitBundle\Blame\BlameValueResolverInterface` | `EntityReferenceBlameValueResolver` (reference to the user when it is an instance of the profile `user_class`, else `null`) | the value written into `created_by` / `updated_by` (`resolve(ProfileSettings, ObjectManager): mixed`) |

- Firewall user is a wrapper / DTO exposing the user's id through `getId()`: alias the opt-in
  `SecurityUserIdBlameValueResolver`. It writes `getReference($userClass, $user->getId())`, so the blame relation and
  `BlameableTrait` stay unchanged.

```yaml
# config/services.yaml
services:
    Nowo\AuditKitBundle\Blame\BlameValueResolverInterface: '@Nowo\AuditKitBundle\Blame\SecurityUserIdBlameValueResolver'
```

- Actor outside the firewall (a Messenger worker replaying the command's initiator, impersonation): implement
  `CurrentUserResolverInterface` and alias it.
- No Doctrine user entity at all: implement `BlameValueResolverInterface` and return your own value (for example a
  UUID string), with `fields.created_by` / `fields.updated_by` pointing at properties whose setters accept it.
  `BlameableInterface` / `BlameableTrait` keep `?object`, so use your own properties in that case.

**Integration points**

- Ports: `CurrentUserResolverInterface`, `BlameValueResolverInterface`, `ActionLogRecorderInterface` (since 1.12),
  `ActionLogAvatarResolverInterface` (avatar URLs for the timeline).
- Contracts: `TimestampableInterface`, `BlameableInterface`, `AuditableInterface`, `PublicUuidInterface`.
- Query object: `ActionLogCriteria`. It accepts user entities or ids for `actor`, `subject` and `involving`.
- Config: `profiles.*` (`user_class`, `fields`, `blameable`, `timestampable`, `timestamp_type`), `action_log.*`
  (`register_mapping: false` to map the superclass yourself), `#[Auditable(enabled: false)]` to opt out.

**Known limitations**

- Opting a class in still needs a bundle interface or trait; `#[Auditable]` alone does not opt in.
- Action log `actor`/`subject` are `ManyToOne` relations to the user table (resolved through
  `resolve_target_entities`). Columns can be renamed with association overrides but cannot be plain id columns.
- `user_class` is required even when blame and the action log are unused.
- No events are dispatched when audit fields are written or an action is recorded.

## Related bundles

- [`nowo-tech/user-kit-bundle`](https://github.com/nowo-tech/UserKitBundle) — account state / presence
- [`nowo-tech/auth-kit-bundle`](https://github.com/nowo-tech/AuthKitBundle) — authentication flows

Audit Kit Bundle is independent; it only needs a resolvable `user_class` and Symfony Security when blame fields should be populated.
