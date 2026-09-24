# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/audit-kit-bundle` (`symfony-bundle`) |
| Audited revision | `v1.1.16` |
| Audit date | 2026-09-24 |
| Method | Manual review of every file under `src/` (Doctrine entity listener, property resolver, profile registry, user resolver, DI extension, config) + PHPStan FrankenPHP classic/worker rulesets |
| **Verdict** | ✅ **Compatible with scenario B (100% for bundle-owned behaviour)** — services are effectively request-safe; blame ignores leftover tokens unless the current main request passed a firewall with security enabled; blame references use the event `ObjectManager` |
| Remediation | W-01: `CurrentUserResolver` firewall guard (`RequestStack` + `@?security.firewall.map`). W-02: prefer `$event->getObjectManager()` for `getReference()`. Tests: `CurrentUserResolverWorkerTest`, `AuditableEntityListenerTest::testSecondRequestOutsideFirewallIsNotBlamedOnPreviousUser` |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests (`reset` / reboot disabled), so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Only `ProfileRegistry::$resolveCache` (class-keyed, bounded); listener, resolvers are `readonly` |
| Static properties / `static` locals | ✅ | None |
| `ResetInterface` / `kernel.reset` coverage | ✅ | No bundle service needs a reset; blame no longer depends on Symfony's `TokenStorage` reset (W-01) |
| Request / user / locale captured in services | ✅ | User read from `TokenStorage` on each Doctrine event only when the main request passed a security-enabled firewall; time from `ClockInterface` |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used |
| Doctrine / EntityManager | ✅ | Blame metadata/`getReference` use the event `ObjectManager` when it is an `EntityManagerInterface` (W-02); injected EM is BC fallback only |
| Output, headers, `exit`, shutdown functions | ✅ | None |
| Resources (files, sockets, cURL) held open | ✅ | None |
| Memory growth across requests | ✅ | No accumulating arrays |
| Blocking I/O and timeouts | ✅ N/A | No I/O besides Doctrine metadata lookups |
| Third-party static state | ✅ | Only Symfony Security / PropertyAccess / Doctrine ORM |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` in `phpstan.neon.dist` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Nowo\AuditKitBundle\Doctrine\AuditableEntityListener` (`doctrine.orm.entity_listener`, lazy, `prePersist` / `preUpdate`) | yes (removed when no profile is enabled) | none (`readonly` deps) | ✅ | ✅ |
| `Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver` | yes | none (`readonly` property accessor; `ReflectionClass` created per call) | ✅ | ✅ |
| `Nowo\AuditKitBundle\Security\CurrentUserResolver` | yes | none (reads `TokenStorage`, `RequestStack` and the firewall map per call) | ✅ | ✅ |
| `Nowo\AuditKitBundle\Profile\ProfileRegistry` | yes | `$resolveCache` (class-string → profile), bounded | ✅ | ✅ |

`ProfileSettings` is `final readonly`. Traits and interfaces under `src/Model/` and the `#[Auditable]` attribute are excluded from the container (`src/Resources/config/services.yaml`).

## Findings

### W-01 — Blame user depends on `security.token_storage` being reset (Medium) — **Resolved**

- **Where:** `src/Security/CurrentUserResolver.php`, used by `AuditableEntityListener` for profile selection and blame fields.
- **Worker impact (before fix):** under scenario B the previous request's token could remain on paths where no firewall replaces it (outside a firewall, `security: false`, flush before firewall). Entities would get the **previous request's user** as `created_by` / `updated_by`.
- **Fix:** optional `RequestStack` + `security.firewall.map`. With a main request, the token is trusted only if `_firewall_context` is set and the firewall reports security enabled; otherwise `resolve()` returns `null`. Without a main request (CLI, Messenger) the caller's token is used.
- **Residual (framework):** a **stateless** firewall that does not authenticate the current request can leave Symfony's previous token in storage under scenario B; the bundle cannot distinguish that from a successful auth on the same firewall. Prefer authenticators that clear or replace the token, or keep `services_resetter` for those apps. This is outside the bundle's control.

### W-02 — Injected EntityManager used for blame references (Low) — **Resolved**

- **Where:** `AuditableEntityListener::resolveBlameUser()`.
- **Fix:** prefer `$event->getObjectManager()` when it is an `EntityManagerInterface`; fall back to the injected EM (constructor kept for BC / manual wiring).
- **Worker impact:** correct manager under multi-EM; identifier-only `getReference()` remains correct if an older proxy is returned under B.

### W-03 — Profile resolution cache is class-keyed and bounded (Info)

- **Where:** `ProfileRegistry::$resolveCache`.
- **Worker impact:** bounded by user class count; holds no user data. No change required.

## Usage recommendations in worker mode

- Default FrankenPHP + Symfony Runtime still runs `services_resetter` (scenario A). The bundle is also safe when that reset is disabled (scenario B) for public / `security: false` routes.
- Under B, clearing the application identity map between requests remains the host app's responsibility (the listener never calls `clear()`).
- Messenger / console code that impersonates users should clear or replace the token between messages.
- Do not decorate `CurrentUserResolver` with a cache of the resolved user.
- Demo: `demo/symfony8/docker/frankenphp/Caddyfile` runs FrankenPHP in `worker` mode.

## Re-audit triggers

Re-run when a change adds: properties to the listener / property resolver / user resolver; an `onFlush` / `postFlush` buffer; a cache of reflection keyed by object identity; a new current-user source; or runtime use of `$_SERVER` / `$_ENV`.
