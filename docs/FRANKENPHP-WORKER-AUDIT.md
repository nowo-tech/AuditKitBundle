# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/audit-kit-bundle` (`symfony-bundle`) |
| Audited revision | `v1.14.0` + fix for 1.14.1 (REQ-RUNTIME-002 sweep, batch A1); first audit `v1.1.16` |
| Audit date | 2026-11-11 (first audit 2026-09-24) |
| Method | Manual review of every file under `src/` (Doctrine entity listener, property resolver, profile registry, user resolver, DI extension, config) + PHPStan FrankenPHP classic/worker rulesets |
| **Verdict** | ✅ **Compatible with scenario B, kernel reset false (REQ-RUNTIME-002)** — services are effectively request-safe; the timeline avatar memo is emptied by the bundle at every unit-of-work boundary (W-04, since 1.14.1); blame ignores leftover tokens unless the current main request passed a firewall with security enabled; blame references use the event `ObjectManager` |
| 1.15 additions | Framework-free ports (`SecurityCurrentActorProvider`, `ActorIdBlameIdResolver`: stateless, read per event) and the isolated action log flush (per-call entity manager, cleared and closed). Tests: `AuditableEntityListenerIdPortsTest::testAlternatingUsersOnOneListenerNeverLeak` (700 alternating writes, one listener), `DddPortsHostIntegrationTest::testAlternatingActorsOnOneKernelNeverLeak` (200 alternating actors, one kernel), `ActionLogFlushIsolationTest::testRepeatedIsolatedWritesKeepTheHostUnitOfWorkAndMemoryFlat` (550 writes, host unit of work unchanged, < 2 MiB growth). REQ-ARCH-001 rule 6 (never flush the host EM) is opt-in in 1.x (`flush_mode: isolated`); the default `host` mode keeps flushing for BC, and switching the default in 2.0 is planned, pending the owner's decision |
| Remediation | W-01: `CurrentUserResolver` firewall guard (`RequestStack` + `@?security.firewall.map`). W-02: prefer `$event->getObjectManager()` for `getReference()`. Tests: `CurrentUserResolverWorkerTest`, `AuditableEntityListenerTest::testSecondRequestOutsideFirewallIsNotBlamedOnPreviousUser`. W-04 (1.14.1): `ActionLogAvatarsResetSubscriber` + bound; test `ActionLogAvatarsWorkerTest` |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
  - [W-01 — Blame user depends on `security.token_storage` being reset (Medium) — **Resolved**](#w-01--blame-user-depends-on-securitytoken_storage-being-reset-medium--resolved)
  - [W-02 — Injected EntityManager used for blame references (Low) — **Resolved**](#w-02--injected-entitymanager-used-for-blame-references-low--resolved)
  - [W-03 — Profile resolution cache is class-keyed and bounded (Info)](#w-03--profile-resolution-cache-is-class-keyed-and-bounded-info)
  - [W-04 — Timeline avatar memo cleared only by `kernel.reset` (Low) — **Resolved**](#w-04--timeline-avatar-memo-cleared-only-by-kernelreset-low--resolved)
- [Boundary events](#boundary-events)
- [Worker tests](#worker-tests)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests (`reset` / reboot disabled), so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `ActionLogAvatars::$urls` (per-user avatar URLs, emptied at every boundary by the bundle, at most 1,000 users; W-04); class-keyed, bounded caches `ProfileRegistry::$resolveCache` and `AuditablePropertyResolver::$decisions` / `$warned` (no user data); listener, recorder, blame resolvers are `readonly` |
| Static properties / `static` locals | ✅ | None |
| `ResetInterface` / `kernel.reset` coverage | ✅ | `ActionLogAvatars` implements it (tested in `ActionLogAvatarsTest::testResetEmptiesTheMemo`) but does not rely on it; blame no longer depends on Symfony's `TokenStorage` reset (W-01) |
| Request / user / locale captured in services | ✅ | User read from `TokenStorage` on each Doctrine event only when the main request passed a security-enabled firewall; time from `ClockInterface` |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used |
| Doctrine / EntityManager | ✅ | Blame metadata/`getReference` use the event `ObjectManager` when it is an `EntityManagerInterface` (W-02); injected EM is BC fallback only |
| Output, headers, `exit`, shutdown functions | ✅ | None |
| Resources (files, sockets, cURL) held open | ✅ | None |
| Memory growth across requests | ✅ | The avatar memo is per unit of work and bounded; the worker test asserts < 1 MiB growth over 1,000 requests and 1,000 messages |
| Blocking I/O and timeouts | ✅ N/A | No I/O besides Doctrine metadata lookups |
| Third-party static state | ✅ | Only Symfony Security / PropertyAccess / Doctrine ORM |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` in `phpstan.neon.dist` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Nowo\AuditKitBundle\Doctrine\AuditableEntityListener` (lazy, called by `AuditableDoctrineListener` or an explicit `#[ORM\EntityListeners]` mapping) | yes (removed when no profile is enabled) | none (`readonly` deps) | ✅ | ✅ |
| `Nowo\AuditKitBundle\Doctrine\AuditableDoctrineListener` (`doctrine.event_listener`, lazy, `prePersist` / `preUpdate`) | yes (removed when no profile is enabled) | none (`readonly` deps) | ✅ | ✅ |
| `Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver` | yes | none (`readonly` property accessor; `ReflectionClass` created per call) | ✅ | ✅ |
| `Nowo\AuditKitBundle\Security\CurrentUserResolver` | yes | none (reads `TokenStorage`, `RequestStack` and the firewall map per call) | ✅ | ✅ |
| `Nowo\AuditKitBundle\Profile\ProfileRegistry` | yes | `$resolveCache` (class-string → profile), bounded | ✅ | ✅ |
| `Nowo\AuditKitBundle\Doctrine\AuditablePropertyResolver` (decisions) | yes | `$decisions` / `$warned` (class-keyed, bounded by mapped classes, no user data) | ✅ | ✅ |
| `Nowo\AuditKitBundle\ActionLog\ActionLogRecorder`, blame value resolvers, `PublicUuidListener` | yes | none (`readonly`) | ✅ | ✅ |
| `Nowo\AuditKitBundle\Security\SecurityCurrentActorProvider`, `Nowo\AuditKitBundle\Blame\ActorIdBlameIdResolver` (since 1.15) | yes | none (`readonly`; the actor is read per Doctrine event) | ✅ | ✅ |
| `ActionLogRecorder` with `action_log.flush_mode: isolated` (since 1.15) | yes | none: the bundle entity manager is created per call, then flushed, cleared and closed in a `finally` (REQ-RUNTIME-002 rule 4); no managed entity outlives the call | ✅ | ✅ |
| `Nowo\AuditKitBundle\Avatar\ActionLogAvatars` (Twig `nowo_audit_kit_avatar_url()` / `nowo_audit_kit_prime_avatars()`) | yes | `$urls` (user key → sanitised URL), per unit of work, ≤ 1,000 users | ✅ `kernel.reset` | ✅ since 1.14.1 (W-04) |
| `Nowo\AuditKitBundle\Avatar\ActionLogAvatarsResetSubscriber` | yes | none (`readonly`) | ✅ | ✅ |
| `Nowo\AuditKitBundle\Twig\ActionLogAvatarExtension` | yes | none (`readonly`) | ✅ | ✅ |

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

### W-04 — Timeline avatar memo cleared only by `kernel.reset` (Low) — **Resolved**

- **Where:** `src/Avatar/ActionLogAvatars.php` (`$urls`), filled by the Twig functions of `ActionLogAvatarExtension`
  since 1.11.0.
- **Worker impact (before fix):** with reset false nothing emptied the memo. A changed or removed picture kept its old
  URL for the life of the worker; the key is the user id only, so a multi-tenant host whose tenants share ids could
  show tenant A's picture URL for tenant B's user; the array grew with every user seen (rule 1 and 6 of
  REQ-RUNTIME-002). Affected: 1.11.0 to 1.14.0.
- **Fix (1.14.1):** `ActionLogAvatarsResetSubscriber` (see [Boundary events](#boundary-events)) and a bound of
  `ActionLogAvatars::MAX_MEMOISED` (1,000) users within one unit of work: a batch that would go over starts the memo
  again. The memo is not meant to persist across units of work (pictures and tenants change), so it is not kept.
  The memo key is user class + id (one resolver call per class, Doctrine proxies mapped to the entity class), so two
  user classes with the same id never collide. Tenants are invisible to the bundle: one unit that renders several
  tenants whose users share ids must call `ActionLogAvatars::reset()` between tenants (documented in USAGE).
  Known and accepted: `kernel.finish_request` empties the memo before a streamed body renders, so a timeline rendered
  inside a `StreamedResponse` resolves one user per call (no leak; the next `kernel.request` empties it again).

## Boundary events

| State | Emptied at | Registered by |
|-------|------------|---------------|
| `ActionLogAvatars::$urls` | `kernel.request` and `kernel.finish_request` of the main request (sub-requests keep it), `WorkerMessageReceivedEvent`, `WorkerMessageHandledEvent`, `WorkerMessageFailedEvent`, `console.terminate`; also `kernel.reset` | the bundle (`ActionLogAvatarsResetSubscriber`, autoconfigured; Messenger event names as strings, Messenger optional) |

Blame needs no boundary: `CurrentUserResolver` reads the token per Doctrine event (W-01).

## Worker tests

- `tests/Integration/ActionLogAvatarsWorkerTest.php` boots **one** `TwigKernel` and never calls `services_resetter`
  (kernel reset false). A tenant-aware resolver gives each tenant its own picture for the same user ids (1, 2).
  - 1,000 main requests alternating `acme` / `globex` (`kernel.request` → render `nowo_audit_kit_avatar_url()` →
    `kernel.finish_request`): each request gets its own tenant's URLs; memory margin 1 MiB after 100 warm-up requests.
  - 1,000 Messenger messages alternating tenants, one in five failing: same assertions.
  - After each end boundary (finish request, handled, failed, console terminate) the memo is already empty.
- `tests/Unit/Avatar/ActionLogAvatarsResetSubscriberTest.php` (events, main vs sub-request) and
  `tests/Unit/Avatar/ActionLogAvatarsTest.php` (`reset()`, bound).
- Blame: `CurrentUserResolverWorkerTest`, `AuditableEntityListenerTest::testSecondRequestOutsideFirewallIsNotBlamedOnPreviousUser`.

Static checks: `composer igor` and the `nowo-tech/phpstan-frankenphp` classic + worker rulesets pass in CI.

## Usage recommendations in worker mode

- Default FrankenPHP + Symfony Runtime still runs `services_resetter` (scenario A). The bundle is also safe when that reset is disabled (scenario B) for public / `security: false` routes.
- Under B, clearing the application identity map between requests remains the host app's responsibility (the listener never calls `clear()`).
- Messenger / console code that impersonates users should clear or replace the token between messages.
- Do not decorate `CurrentUserResolver` with a cache of the resolved user.
- Demo: `demo/symfony8/docker/frankenphp/Caddyfile` runs FrankenPHP in `worker` mode.

## Re-audit triggers

Re-run when a change adds: properties to the listener / property resolver / user resolver / avatar memo; an `onFlush` / `postFlush` buffer; a cache of reflection keyed by object identity; a new current-user source; or runtime use of `$_SERVER` / `$_ENV`.
