# Audit Kit Bundle — Demo

FrankenPHP demo for **Symfony 8.x** showing automatic audit fields on Doctrine entities.

## Quick start

```bash
make -C demo/symfony8 up
```

Default URL: **http://localhost:8013** (`PORT` in `demo/symfony8/.env.example`).

## Commands

| Command | Description |
| ------- | ----------- |
| `make -C demo/symfony8 up` | Build, install deps, sync bundle, run schema update |
| `make -C demo/symfony8 down` | Stop containers |
| `make -C demo/symfony8 test` | Run demo PHPUnit smoke tests |
| `make update-deps` | Refresh bundle **and** demo Composer dependencies (from bundle root) |

From `demo/`:

```bash
make up          # same as up-symfony8
make test
make release-check
```

## FrankenPHP

See [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) for development vs production (**worker mode**) configuration.

## Stack

- PHP 8.4 · FrankenPHP · Symfony 8.x
- SQLite (`var/data/demo.db`)
- Path repository: bundle mounted at `/var/audit-kit-bundle`
