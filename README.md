# Audit Kit Bundle

Symfony + Doctrine bundle for **automatic auditing fields** on any opt-in entity:

| Field | Set on |
| ----- | ------ |
| `createdAt` / `updatedAt` | `prePersist` + `preUpdate` (timestamps only on update) |
| `createdBy` / `updatedBy` | When an authenticated user is present (nullable in CLI) |

Complements [`nowo-tech/user-kit-bundle`](../UserKitBundle) (account state / presence) and [`nowo-tech/auth-kit-bundle`](https://github.com/nowo-tech/AuthKitBundle) (auth flows).

## Features

- Optional traits: `TimestampableTrait`, `BlameableTrait`, `AuditableTrait`
- Doctrine entity listener (`prePersist` / `preUpdate`)
- Configurable property names and `user_class`
- Per-entity opt-out via `#[Auditable(enabled: false)]`
- Guest / CLI safe — blame fields stay `null` without errors

## Requirements

- PHP 8.2+
- Symfony 7.4 | 8.x
- Doctrine ORM 2.15+ | 3.x

## Quick start

```bash
composer require nowo-tech/audit-kit-bundle
```

```yaml
# config/packages/nowo_audit_kit.yaml
nowo_audit_kit:
    user_class: App\Entity\User
```

```php
use Nowo\AuditKitBundle\Model\AuditableTrait;

class Article
{
    use AuditableTrait;
}
```

## Documentation

| Document | Purpose |
| -------- | ------- |
| [`docs/INSTALLATION.md`](docs/INSTALLATION.md) | Install and enable |
| [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) | Configuration reference |
| [`docs/USAGE.md`](docs/USAGE.md) | Traits, listener, migrations |
| [`docs/CHANGELOG.md`](docs/CHANGELOG.md) | Version history |
| [`docs/UPGRADING.md`](docs/UPGRADING.md) | Upgrade guide |
| [`docs/RELEASE.md`](docs/RELEASE.md) | Release process |
| [`specs/001-baseline/spec.md`](specs/001-baseline/spec.md) | Product spec |
| [`specs/001-baseline/code-inventory.md`](specs/001-baseline/code-inventory.md) | Source traceability |

## Development

```bash
make up
make test-coverage
make phpstan
```

## Tests

PHPUnit unit tests target **100% line coverage** on `src/` (verified via `make test-coverage-100`).

**Compatibility:** PHP 8.2+ · Symfony 7.4 / 8.x (CI matrix).

## Package

- **Composer:** `nowo-tech/audit-kit-bundle`
- **Config root:** `nowo_audit_kit`

## Found this useful?

If this bundle helps your project, consider starring the repository on GitHub.
