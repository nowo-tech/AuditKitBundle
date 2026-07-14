# Installation

## Requirements

- PHP 8.2 or higher
- Symfony 7.x or 8.x
- Doctrine ORM 2.15+ or 3.x
- A user entity class (for blame fields; timestamps work without authentication)

## Composer

```bash
composer require nowo-tech/audit-kit-bundle
```

## Enable the bundle

Symfony Flex registers the bundle automatically. Manual registration:

```php
// config/bundles.php
return [
    // ...
    Nowo\AuditKitBundle\NowoAuditKitBundle::class => ['all' => true],
];
```

## Configuration file

Create `config/packages/nowo_audit_kit.yaml`:

```yaml
nowo_audit_kit:
    default_profile: default
    profiles:
        default:
            user_class: App\Entity\User
```

The legacy flat layout (`user_class` at root) remains supported. See [Configuration](CONFIGURATION.md) for all options.

## Verify

```bash
php bin/console debug:config nowo_audit_kit
php bin/console debug:container AuditableEntityListener
```

## Demo

See [Demo with FrankenPHP](DEMO-FRANKENPHP.md).

## Symfony Flex recipe

When using Symfony Flex, the recipe at `.symfony/recipe/nowo-tech/audit-kit-bundle/1.0/` copies:

- `config/packages/nowo_audit_kit.yaml` — default bundle configuration (`profiles.default.user_class` placeholder)

See `post-install.txt` in the recipe for next steps after `composer require`.
