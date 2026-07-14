# Audit Kit Bundle — Symfony 8 demo

FrankenPHP demo showing automatic `createdAt`, `updatedAt`, `createdBy`, and `updatedBy` fields via `AuditableTrait`.

## Quick start

```bash
cd demo/symfony8
make up
```

Open the URL printed by `make up` (default **http://localhost:8013**). Each page load persists a new `Article` with audit columns populated.

## Commands

| Command | Description |
| ------- | ----------- |
| `make up` | Build, install deps, sync bundle, run schema update |
| `make down` | Stop containers |
| `make test` | Run demo PHPUnit smoke tests |
| `make link-bundle` | Symlink `/var/audit-kit-bundle` and refresh schema |

## Stack

- PHP 8.4 · FrankenPHP · Symfony 8.1
- SQLite (`var/data/demo.db`)
- Path repository: `../../` mounted at `/var/audit-kit-bundle`
