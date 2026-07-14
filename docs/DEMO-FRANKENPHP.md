# Demo applications with FrankenPHP (development and production)

This document describes how the bundle's demo applications run under **FrankenPHP** in Docker, and how to reproduce **development** (no cache, changes visible on refresh) and **production** (worker mode, cache enabled) configurations.

## Contents

- [Overview](#overview)
- [What the demos include](#what-the-demos-include)
- [Development configuration](#development-configuration)
- [Production configuration](#production-configuration)
- [Switching between development and production](#switching-between-development-and-production)
- [Reproducing in another bundle](#reproducing-in-another-bundle)
- [Troubleshooting](#troubleshooting)

---

## Overview

**The `demo/` folder is not shipped when the bundle is installed** (e.g. via `composer require nowo-tech/audit-kit-bundle`). It is excluded from the Composer package (via `archive.exclude` in the bundle's `composer.json`). The demo applications exist only in the bundle's source repository and are intended for development, testing, and documentation. To run or modify the demos, clone this repository.

The demos use:

- **FrankenPHP** (Caddy + PHP) in a single container.
- **Docker Compose** with the app and the parent bundle mounted as volumes (`../..` → `/var/audit-kit-bundle`).
- **Two Caddyfiles**: `Caddyfile` (production, with worker) and `Caddyfile.dev` (development, no worker).
- An **entrypoint** that, when `APP_ENV=dev`, copies `Caddyfile.dev` over the default Caddyfile and then starts FrankenPHP.

Demos are available for **Symfony 7.4** and **8.x** (`demo/symfony7`, `demo/symfony8`, `demo/symfony8-php85`). From the bundle root run e.g. `make -C demo/symfony8 up` (see each demo README for URL and port).

| Aspect | Development | Production |
|--------|-------------|------------|
| FrankenPHP worker mode | **Off** | **On** |
| Twig cache | **Off** | **On** |
| OPcache revalidation | Every request | Default |
| `APP_ENV` / `APP_DEBUG` | `dev` / `1` | `prod` / `0` |

**Ports:** Each demo uses `PORT` from its `.env`. Set a different `PORT` per demo to run several at once.

---

## What the demos include

- **Symfony Web Profiler** — enabled in `dev` and `test` environments.
- **Audit Kit Bundle** (`Nowo\AuditKitBundle\NowoAuditKitBundle`) — the bundle under test.

Example `config/bundles.php` (aligned with **demo/symfony8**):

```php
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Nowo\AuditKitBundle\NowoAuditKitBundle::class => ['all' => true],
];
```

Each demo persists entities with automatic audit columns (see `demo/symfony8/src/Entity/Article.php`).

---

## Development configuration

Goal: every change to PHP, Twig, or config is visible on the next browser refresh without restarting the container.

1. Use **docker/frankenphp/Caddyfile.dev** (no worker, cache-busting headers).
2. Mount **docker/php-dev.ini** with `opcache.revalidate_freq=0`.
3. Use **config/packages/dev/twig.yaml** with `twig.cache: false`.
4. Set `APP_ENV=dev` and `APP_DEBUG=1` in docker-compose.

Start from the bundle root:

```bash
make -C demo/symfony8 up
```

---

## Production configuration

Use the default Caddyfile (with worker). Set `APP_ENV=prod` and `APP_DEBUG=0`. Do not mount `php-dev.ini`.

---

## Switching between development and production

After changing env or Caddyfile, restart: `docker-compose restart` or `make -C demo/symfony8 restart`.

---

## Reproducing in another bundle

See [TwigInspectorBundle DEMO-FRANKENPHP](https://github.com/nowo-tech/TwigInspectorBundle/blob/main/docs/DEMO-FRANKENPHP.md) for a full checklist applicable to other Nowo bundles.

---

## Troubleshooting

- **Changes not visible:** Ensure worker mode is off in dev, Twig cache is disabled, restart the container, hard-refresh the browser.
- **Audit fields null:** Check `nowo_audit_kit.user_class` and that a user is authenticated in the demo request.
- **Demo times out:** Check port availability and container logs (`docker-compose logs php`).
