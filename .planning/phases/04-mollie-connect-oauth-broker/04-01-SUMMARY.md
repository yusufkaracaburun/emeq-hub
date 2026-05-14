---
phase: 04-mollie-connect-oauth-broker
plan: 01
subsystem: auth
tags: [laravel, oauth, phpunit, migrations, mollie-connect]

# Dependency graph
requires:
  - phase: 03-hub-skeleton
    provides: Connection model met encrypted casts + ConnectionFactory pattern + RefreshDatabase test-baseline
provides:
  - App\OAuth\Contracts\OAuthFlow interface (4 methods, provider-agnostisch)
  - App\OAuth\Testing\FakeOAuthFlow runtime test-fixture (D-12)
  - connections.oauth_state + oauth_state_expires_at kolommen + indexen
  - Connection-model fillable+casts uitgebreid voor OAuth-state-lifecycle
  - ConnectionFactory states pending() / active() / expired()
  - OAuthFlowContractTest bewijst ROADMAP SC-4 (contract niet Mollie-specifiek)
affects: [04-02 (MollieConnectOAuthFlow), 04-03 (HubMollieCredentialResolver), 04-04 (InitController + CallbackController), 04-05 (prune-command), 05a (pass-through-laag)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "OAuthFlow provider-agnostisch contract in app/OAuth/Contracts/ (D-13) — niet in SDK-tree"
    - "Runtime test-fixture in app/OAuth/Testing/ (D-12) — bindable via container, geen tests/-PSR-4-truc"
    - "oauth_state als rauwe string + 30min TTL (D-02) — geen encrypted cast omdat browser 'm terugstuurt"
    - "Forward-only ALTER-migration patroon op connections-tabel (matched bestaande add_active_unique_to_connections)"
    - "Factory-states voor OAuth-lifecycle: pending() → active() → expired()-overrides"

key-files:
  created:
    - app/OAuth/Contracts/OAuthFlow.php
    - app/OAuth/Testing/FakeOAuthFlow.php
    - database/migrations/2026_05_15_000001_add_oauth_state_to_connections_table.php
    - tests/Feature/OAuth/OAuthFlowContractTest.php
  modified:
    - app/Models/Connection.php
    - database/factories/ConnectionFactory.php

key-decisions:
  - "Task-order omgekeerd (Task 2 vóór Task 1) — schema + factory moeten bestaan voordat de contract-test forMollie()->pending()->create() kan uitvoeren"
  - "OAuthFlow-contract zonder declare(strict_types=1) per PATTERNS.md Hub-tree-conventie (regel 1510) — overruled de strict_types in het PATTERNS.md FakeOAuthFlow-snippet"
  - "FakeOAuthFlow leeft in app/OAuth/Testing/ (NIET tests/) — composer's psr-4 App\\ => app/ pakt 'm op, container-bindbaar in feature-tests"

patterns-established:
  - "Pattern 1: OAuth-contracts in Hub-laag (app/OAuth/Contracts/), niet in SDK-packages — multi-provider scope hoort in Hub"
  - "Pattern 2: Runtime test-fixtures met callCounts + wasCalled()-teller in app/<domain>/Testing/ — drop-in via container.bind() in feature-tests"
  - "Pattern 3: oauth_state-velden naast bestaande encrypted credential-velden — public string + datetime, geen encrypted/Hidden"

requirements-completed: [MOLL-02, HUB-02]

# Metrics
duration: ~25 min
completed: 2026-05-14
---

# Phase 4 Plan 01: OAuthFlow-foundation Summary

**Provider-agnostisch OAuthFlow-contract + FakeOAuthFlow runtime-fixture + connections.oauth_state-kolommen — fundering voor Mollie Connect handshake en alle toekomstige OAuth-providers in v0.3+.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-14T16:35:00Z (ongeveer)
- **Completed:** 2026-05-14T17:00:40Z
- **Tasks:** 2 (auto)
- **Files modified:** 6 (4 created + 2 modified)

## Accomplishments

- `App\OAuth\Contracts\OAuthFlow` interface met 4 methods (`getAuthorizationUrl`, `exchangeCode`, `refreshToken`, `revoke`) gelandt in `app/OAuth/Contracts/` — Hub-laag, niet SDK-laag (D-13)
- `App\OAuth\Testing\FakeOAuthFlow` deterministic test-fixture met `wasCalled()`-teller en fake `access_test_…`/`refresh_test_…` tokens (D-12)
- Migration `2026_05_15_000001_add_oauth_state_to_connections_table` voegt `oauth_state` (varchar(64), nullable) + `oauth_state_expires_at` (timestamp, nullable) + 2 indexen toe — forward-only ALTER, geen create-table-wijziging
- `Connection`-model breidt Fillable + casts uit met de 2 nieuwe velden zonder bestaande Fillable/Hidden/casts/`fingerprint()` te raken
- `ConnectionFactory::pending()` / `::active()` / `::expired()` states leveren de hele Mollie-OAuth-lifecycle als test-helpers
- `OAuthFlowContractTest` bewijst ROADMAP SC-4: 3 tests groen (FakeOAuthFlow implements OAuthFlow, exchangeCode markt active, revoke markt revoked)
- Volledige test-suite blijft groen: 80 tests / 213 assertions / 1 incomplete (pre-existing) / 0 failures

## Task Commits

Elke task is atomic gecommit (uitvoer-volgorde gewisseld om schema-dependency te respecteren):

1. **Task 2: Migration + Connection-model edit + ConnectionFactory states** — `412861d` (feat)
2. **Task 1: OAuthFlow-contract + FakeOAuthFlow + OAuthFlowContractTest** — `2095d8f` (feat)

**Plan metadata** (deze SUMMARY + STATE.md + ROADMAP-update): volgt in vervolg-commit door orchestrator.

## Files Created/Modified

- `app/OAuth/Contracts/OAuthFlow.php` *(created)* — provider-agnostische interface met 4 methods voor de complete OAuth-lifecycle
- `app/OAuth/Testing/FakeOAuthFlow.php` *(created)* — deterministic test-fixture in runtime-namespace, implementeert `OAuthFlow` met call-tracker
- `database/migrations/2026_05_15_000001_add_oauth_state_to_connections_table.php` *(created)* — voegt `oauth_state` (varchar(64)) + `oauth_state_expires_at` (timestamp) + 2 indexen toe aan `connections`
- `tests/Feature/OAuth/OAuthFlowContractTest.php` *(created)* — 3 PHPUnit-tests die SC-4 (contract is niet Mollie-specifiek) bewijzen
- `app/Models/Connection.php` *(modified)* — `oauth_state` + `oauth_state_expires_at` toegevoegd aan `#[Fillable]`; `oauth_state_expires_at` cast als `datetime`; geen aanraking aan `#[Hidden]`, `fingerprint()`, of andere casts
- `database/factories/ConnectionFactory.php` *(modified)* — `pending()` (provider=mollie, status=pending, oauth_state=Str::random(48), expires=now+30min, alle credentials null), `active()` (status=active + oauth_state null), `expired()` (oauth_state_expires_at=now-1min) toegevoegd

## Decisions Made

- **Task-order omgekeerd:** plan stelt Task 1 → Task 2 voor, maar Task 1's contract-test gebruikt `Connection::factory()->forMollie()->pending()->create()` wat zowel de `oauth_state`-kolom (Task 2 migration) als de `pending()`-state (Task 2 factory) nodig heeft. Task 2 eerst landen vermijdt de "manual update()-workaround" die het plan-body als fallback noemde. Beide tasks blijven atomic gecommit; SC-4 wordt nog steeds in dit plan bewezen.
- **`declare(strict_types=1)` overgeslagen in Hub-tree-files:** PATTERNS.md regel 1510 + `Connection.php`/`PingController.php` precedent zeggen expliciet: `app/`-tree gebruikt geen `declare(strict_types=1)` (alleen SDK-tree wel). Het PATTERNS.md-snippet voor FakeOAuthFlow toonde een strict_types-regel die ik chirurgisch verwijderd heb om met Hub-conventie te matchen.
- **`oauth_state` blijft een rauwe string:** geen `encrypted`-cast, geen `#[Hidden]`-toevoeging. Reden: de browser stuurt 'm terug via querystring, dus encryptie heeft geen nut, en het is geen credential (30-min TTL + nullable na callback).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Task-uitvoering-volgorde omgewisseld**
- **Found during:** Task 1 (OAuthFlowContractTest definiëren)
- **Issue:** Plan toont Task 1 vóór Task 2, maar `OAuthFlowContractTest::test_fake_oauth_flow_exchange_code_marks_connection_active` gebruikt `Connection::factory()->forMollie()->pending()->create()` — beide afhankelijk van Task 2's migration én factory-state. Het plan bood een "manual update()-workaround" als fallback, maar dat zou de test inferieur maken aan de PATTERNS.md-canonical-versie.
- **Fix:** Task 2 (schema + model + factory-states) eerst gecommit, Task 1 (contract + fake + test) daarna. Beide tasks blijven atomic gecommit met eigen commit-msg. Resultaat: clean test zonder workaround.
- **Files modified:** geen extra files — alleen commit-volgorde gewijzigd.
- **Verification:** `php artisan test --compact --filter=OAuthFlowContractTest` exits 0 (3 tests passed).
- **Committed in:** Task 2 = `412861d`, Task 1 = `2095d8f`

**2. [Rule 1 — Bug] `declare(strict_types=1)` weggelaten uit FakeOAuthFlow**
- **Found during:** Task 1 (FakeOAuthFlow schrijven)
- **Issue:** PATTERNS.md regel 244-318 toont FakeOAuthFlow met `declare(strict_types=1);`, maar PATTERNS.md regel 1510 zegt expliciet "Hub-tree (`app/`) doet dat NIET — volg Hub-conventie in `app/`-files". Bestaande Hub-files (`Connection.php`, `PingController.php`, `HubConsumerCreate.php`) volgen die regel. PATTERNS.md-snippet was intern inconsistent met zijn eigen Hub-conventie.
- **Fix:** `declare(strict_types=1);` weggelaten uit zowel `OAuthFlow.php` als `FakeOAuthFlow.php`. Plan-body bevestigde deze keuze ("Hub-tree heeft géén `declare(strict_types=1)`").
- **Files modified:** `app/OAuth/Contracts/OAuthFlow.php`, `app/OAuth/Testing/FakeOAuthFlow.php`
- **Verification:** Pint clean (`vendor/bin/pint --dirty --format agent` → passed); tests groen.
- **Committed in:** `2095d8f` (Task 1 commit)

---

**Total deviations:** 2 auto-fixed (1 blocking-fix, 1 bug-fix)
**Impact on plan:** Beide afwijkingen zijn correctness-fixes — geen scope-creep, geen architecturele wijziging. Plan-intent intact gebleven (4 files created + 2 modified, alle acceptance-criteria groen, SC-4 bewezen).

## Issues Encountered

Geen. Migration draait clean, modellen accepteren nieuwe velden zonder MassAssignmentException, factory-states werken met `->make()` én `->create()`.

## Self-Check: PASSED

Bestand-existence:
- FOUND: `app/OAuth/Contracts/OAuthFlow.php`
- FOUND: `app/OAuth/Testing/FakeOAuthFlow.php`
- FOUND: `database/migrations/2026_05_15_000001_add_oauth_state_to_connections_table.php`
- FOUND: `tests/Feature/OAuth/OAuthFlowContractTest.php`

Commit-existence:
- FOUND: `412861d` (Task 2)
- FOUND: `2095d8f` (Task 1)

Test-verificatie:
- `php artisan test --compact --filter=OAuthFlowContractTest`: 3 passed / 6 assertions
- `php artisan test --compact` (full): 80 passed / 213 assertions / 0 failures

## User Setup Required

None - geen externe services nodig voor deze foundation-laag. Mollie Connect env-keys komen in Plan 04-04 (controllers) wanneer `config/services.php` ge-update wordt.

## Next Phase Readiness

- **Plan 04-02** (MollieConnectOAuthFlow): kan direct `implements App\OAuth\Contracts\OAuthFlow` doen en de 4 contract-methods op echte Mollie-endpoints implementeren.
- **Plan 04-03** (HubMollieCredentialResolver): kan `OAuthFlow::refreshToken()` aanroepen via de toekomstige `OAuthFlowRegistry`.
- **Plan 04-04** (InitController + CallbackController): `Connection`-model accepteert `oauth_state` + `oauth_state_expires_at`; factory-state `pending()` beschikbaar voor controller-tests.
- **Plan 04-05** (PruneOAuthPendingConnections): factory-states `pending()->expired()` direct bruikbaar.
- Geen blockers.

---
*Phase: 04-mollie-connect-oauth-broker*
*Completed: 2026-05-14*
