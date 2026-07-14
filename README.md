# Audit Kit Bundle

[![CI](https://github.com/nowo-tech/AuditKitBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/AuditKitBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/audit-kit-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/audit-kit-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/audit-kit-bundle.svg)](https://packagist.org/packages/nowo-tech/audit-kit-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/AuditKitBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/AuditKitBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install from [Packagist](https://packagist.org/packages/nowo-tech/audit-kit-bundle) and give the repo a star on GitHub.

Symfony + Doctrine bundle for **automatic auditing fields** on any opt-in entity:

| Field | Set on |
| ----- | ------ |
| `createdAt` / `updatedAt` | `prePersist` + `preUpdate` (timestamps only on update) |
| `createdBy` / `updatedBy` | When an authenticated user is present (nullable in CLI) |

Complements [`nowo-tech/user-kit-bundle`](https://github.com/nowo-tech/UserKitBundle) (account state / presence) and [`nowo-tech/auth-kit-bundle`](https://github.com/nowo-tech/AuthKitBundle) (auth flows).

## Features

- Optional traits: `TimestampableTrait`, `BlameableTrait`, `AuditableTrait`
- Doctrine entity listener (`prePersist` / `preUpdate`)
- **Named profiles** — separate field mapping and flags per user entity (`User`, `Admin`, …) with O(1) class resolution
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
    default_profile: default
    profiles:
        default:
            user_class: App\Entity\User
```

The legacy flat layout (`user_class` at root) remains supported. See [Configuration](docs/CONFIGURATION.md).

```php
use Nowo\AuditKitBundle\Model\AuditableTrait;

class Article
{
    use AuditableTrait;
}
```

## Development

```bash
make up
make test-coverage
make release-check
```

## Demo

```bash
make -C demo/symfony8 up   # http://localhost:8013 (default PORT)
```

Each demo persists entities with automatic audit columns (see `demo/symfony8/src/Entity/Article.php` and `LegacyRecord.php` for audited vs opt-out). See [demo/symfony8/README.md](demo/symfony8/README.md) and [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md) for FrankenPHP setup (including **worker mode** for production).

## Tests and coverage

- Tests: PHPUnit (unit + integration)
- PHP: 100%
- TS/JS: N/A
- Python: N/A

**Compatibility:** PHP 8.2+ · Symfony 7.4 / 8.0 / 8.1 (CI matrix).

## License

MIT — see [LICENSE](LICENSE).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [Product spec](specs/001-baseline/spec.md)
- [Code inventory](specs/001-baseline/code-inventory.md)

## Package

- **Composer:** `nowo-tech/audit-kit-bundle`
- **Config root:** `nowo_audit_kit`
