---
phase: 05c-snelstart-webhook-handler
plan: 01
subsystem: database
tags: [laravel, migrations, postgres, sqlite, audit-log, phpunit, webhooks, idempotency]

# Dependency graph
requires:
  - phase: 05b-snelstart-pass-through-api
    provides: pass_through_calls-tabel + audit-pattern (outbound)
  - phase: 03-hub-skeleton
    provides: connections-tabel + Consumer/Account-FK-chain
provides:
  - pass_through_calls.direction (default 'outbound') + event_id-kolom
  - Unique constraint (provider, event_id) — idempotency-DB-guarantee
  - Nullable consumer_id/account_id voor onbekende-administratie-audit-rijen
  - connections.administratie_id + composite index (provider, administratie_id)
  - PassThroughCall::scopeInbound() / scopeOutbound()
  - PassThroughCallFactory::inbound() state
  - ConnectionFactory::forSnelstart() zet administratie_id-UUID
affects: [05c-02-hmac-verifier, 05c-03-controller, 05c-04-job-fan-out, 05c-05-integration-tests]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Audit-tabel hergebruik: één tabel pass_through_calls met `direction`-discriminator voor zowel outbound (Phase 5b) als inbound (Phase 5c) rijen"
    - "DB-level idempotency: Postgres/SQLite unique-index met multi-NULL-tolerance — outbound-rijen blokkeren elkaar niet"
    - "Tenant-UUID's blijven raw queryable (geen encrypted-cast) — composite B-tree-index draagt de webhook-lookup-query"
    - "TDD-cyclus per model-update: RED-commit (test alleen) → GREEN-commit (Fillable + factory)"

key-files:
  created:
    - database/migrations/2026_05_17_000002_add_inbound_columns_to_pass_through_calls_table.php
    - database/migrations/2026_05_17_000003_add_administratie_id_to_connections_table.php
    - tests/Feature/PassThroughCallInboundScopesTest.php
    - tests/Feature/ConnectionAdministratieIdTest.php
  modified:
    - app/Models/PassThroughCall.php
    - app/Models/Connection.php
    - database/factories/PassThroughCallFactory.php
    - database/factories/ConnectionFactory.php

key-decisions:
  - "Migration-filenames: _000002_ + _000003_ (i.p.v. plan's _000001_/_000002_) om lex-clash met bestaande 2026_05_17_000001_align_subscriptions_owner_to_consumers te vermijden — deviation Rule 3"
  - "administratie_id NIET in Connection $casts (geen encrypted) en NIET in #[Hidden] — tenant-UUID per Snelstart OData-conventie is geen secret en mag in API-response (analoog aan subscription_id, Phase 3 03-01)"
  - "Factory definition() heeft expliciete direction='outbound' + event_id=null defaults (i.p.v. enkel DB-default) zodat outbound-pad self-documenting blijft"
  - "down() laat consumer_id/account_id nullable — forward-only-prod-policy uit CLAUDE.md"

patterns-established:
  - "Inbound audit-rij = Inbound webhook met onbekende administratie schrijft een rij met NULL consumer/account/connection FKs en behoudt forensics (forward-only nullable FK-change)"
  - "Composite index (provider, administratie_id) draagt de tenant-resolution-query in plan 03 zonder full-table scan"

requirements-completed: [HUB-06]

# Metrics
duration: ~20min
completed: 2026-05-15
---

# Phase 05c Plan 01: Schema-fundatie voor Snelstart inbound webhooks Summary

**Twee migrations + model/factory-updates die `pass_through_calls` inbound-rijen laten dragen, idempotency op DB-laag forceren, en `connections` doorzoekbaar maken op Snelstart-administratie_id — zonder enige code op het webhook-pad zelf te raken.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-05-15T15:00:00Z (approx)
- **Completed:** 2026-05-15T15:21:01Z
- **Tasks:** 4 (2 auto, 2 TDD)
- **Files modified:** 8 (4 created, 4 modified)
- **Commits:** 6 (4 task-feats + 2 RED-tests)

## Accomplishments

- `pass_through_calls` ondersteunt nu zowel outbound (Phase 5b) als inbound (Phase 5c) via een non-null `direction`-kolom met default `outbound` (retro-vult bestaande 5b-rijen).
- Idempotency op inbound webhooks is afdwingbaar via unique-index `(provider, event_id)` die NULLs toelaat voor outbound-rijen (Postgres + SQLite standaard-gedrag).
- Onbekende-administratie-audit-pad werkt: `consumer_id`/`account_id` zijn nullable, een audit-rij zonder Consumer/Account-FK is toegestaan.
- Snelstart-Connections kunnen gevonden worden op `(provider='snelstart', administratie_id=<uuid>)` zonder full-table scan dankzij de composite index.
- Volledige testsuite blijft groen: 243/243 tests, 787 assertions, 0 regressies.

## Task Commits

1. **Task 1: Migration `add_inbound_columns_to_pass_through_calls_table`** — `47ea287` (feat)
2. **Task 2: Migration `add_administratie_id_to_connections_table`** — `ef02980` (feat)
3. **Task 3 (TDD): `PassThroughCall` scopes + factory inbound-state**
   - RED: `46854e1` (test) — failing tests assert undefined `scopeInbound` + factory state
   - GREEN: `5b3e943` (feat) — Fillable + scopes + factory `inbound()` state + explicit outbound defaults
4. **Task 4 (TDD): `Connection.administratie_id` + factory**
   - RED: `59d0f3d` (test) — `forSnelstart()` returns NULL administratie_id (2/3 passed, 1 failed)
   - GREEN: `226c82e` (feat) — Fillable bijwerken + factory UUID-seed

_Note: Beide TDD-tasks hadden geen REFACTOR-gate nodig — de GREEN-implementaties bleven minimaal._

## Files Created/Modified

- `database/migrations/2026_05_17_000002_add_inbound_columns_to_pass_through_calls_table.php` — direction + event_id + nullable tenant-FKs + unique idempotency-index
- `database/migrations/2026_05_17_000003_add_administratie_id_to_connections_table.php` — administratie_id kolom + composite index
- `app/Models/PassThroughCall.php` — Fillable uitgebreid met direction/event_id; scopeInbound + scopeOutbound toegevoegd; `Builder`-import
- `app/Models/Connection.php` — Fillable uitgebreid met administratie_id (geen cast, geen Hidden)
- `database/factories/PassThroughCallFactory.php` — explicit outbound-default + `inbound()`-state
- `database/factories/ConnectionFactory.php` — `forSnelstart()` zet UUID-administratie_id
- `tests/Feature/PassThroughCallInboundScopesTest.php` — 3 tests: scope-filter, NULL-tenant-row, idempotency-unique-violation
- `tests/Feature/ConnectionAdministratieIdTest.php` — 3 tests: persist unencrypted, factory-seed, lookup-by-provider+UUID

## Decisions Made

- **Migration-filenames `_000002_` + `_000003_` i.p.v. plan's `_000001_` + `_000002_`** — vermijdt lex-clash met de bestaande `2026_05_17_000001_align_subscriptions_owner_to_consumers.php`. Beide migrations runnen nu na de subscriptions-align-migration in dezelfde batch.
- **administratie_id is geen secret** — geen `encrypted`-cast (zou de OData-lookup-query stuk maken) en geen `#[Hidden]` (UUID mag in API-response). Volgt het bestaande `subscription_id`-pattern uit Phase 3.
- **Factory-default `direction='outbound'` + `event_id=null` expliciet** — de DB-default werkt ook, maar de factory moet self-documenting blijven (zichtbare defaults na de migration).
- **`down()` op de inbound-columns-migration revert geen NOT-NULL op consumer_id/account_id** — forward-only-in-prod-policy (CLAUDE.md invariant).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Migration-filenames bumped van `_000001_` + `_000002_` naar `_000002_` + `_000003_`**

- **Found during:** Task 1 (Migration `add_inbound_columns_to_pass_through_calls_table`)
- **Issue:** Plan's `files_modified` whitelist noemt `2026_05_17_000001_*` en `2026_05_17_000002_*`, maar er bestaat al een `2026_05_17_000001_align_subscriptions_owner_to_consumers.php` in `database/migrations/`. Twee migrations met identieke lexicale timestamp leveren onvoorspelbare ordering op.
- **Fix:** Gebruik `2026_05_17_000002_*` voor de inbound-columns-migration en `2026_05_17_000003_*` voor de administratie_id-migration. Volgorde binnen Phase 5c-01 blijft consistent (inbound-columns → administratie_id).
- **Files modified:** twee migration-filenames + de PLAN.md `files_modified`-claim is afgeweken
- **Verification:** `php artisan test --compact` runt RefreshDatabase + alle 243 tests groen op een fresh DB; volgorde van migrations is correct (eerst subscriptions-align, dan inbound-columns, dan administratie_id).
- **Committed in:** `47ea287` (Task 1) + `ef02980` (Task 2)

**2. [Rule 3 - Blocking] Worktree had geen `.env` / `vendor/` — composer install + key:generate uitgevoerd**

- **Found during:** Eerste `php artisan test`-aanroep na Task 1
- **Issue:** Worktree was vers — `vendor/bin/pint` en `php artisan test` werkten niet (geen vendor; geen `APP_KEY`).
- **Fix:** `composer install` (vult `vendor/`) en `cp .env.example .env && php artisan key:generate` (vult `.env` met APP_KEY voor `RefreshDatabase`-tests). `.env` en `vendor/` zijn beide gitignored, geen commit-noise.
- **Files modified:** geen tracked files (`.env`, `vendor/` zijn gitignored)
- **Verification:** `php artisan test --compact` runt groen.
- **Committed in:** niet gecommit (gitignored)

---

**Total deviations:** 2 auto-fixed (2 blocking issues — beide Rule 3)
**Impact on plan:** Beide fixes zijn mechanisch (filename-shift + dev-env-setup). Geen scope-creep, geen architecturele aanpassing. De plan's `files_modified`-whitelist klopt op de basenames (`add_inbound_columns_*` + `add_administratie_id_*`) — alleen de timestamp-prefix verschilt.

## Issues Encountered

- **`tinker --execute` test van administratie_id-fill liep tegen Postgres-DB aan zonder gerunde migration** — tijdens het debuggen van Task 4 RED merkte ik dat de project-Postgres-DB (poort 5433) de migration niet had. Niet relevant voor het test-pad (sqlite-in-memory + RefreshDatabase), enkel verwarring tijdens debugging. Geen actie nodig.
- **Pint hernoemde `test_factory_forSnelstart_sets_administratie_id` naar `test_factory_for_snelstart_sets_administratie_id`** — PSR snake_case-conversie. Geaccepteerd, geen functionele impact.

## Threat Flags

Geen nieuwe security-surface geïntroduceerd buiten het threat-model van de plan. T-05c-01..03 zijn correct gemitigeerd door de migrations zelf (idempotency-unique, nullable-FK forward-only).

## Docs-drift signaal

Tijdens de uitvoering vuurde de `docs-sync`-PostToolUse-hook drie keer (op de twee migrations en op `app/Models/PassThroughCall.php`). De schema-uitbreiding kan downstream docs raken:

- `CLAUDE.md` (Domeinmodel-tabel) noemt `PassThroughCall` nog als "outbound-only audit-log" — overweeg te updaten naar "outbound + inbound via `direction`-discriminator" wanneer plan 02-05 landen.
- `.docs/decisions/pass-through-calls-table.md` (zo aanwezig) kan een addendum gebruiken voor de `direction`/`event_id`-kolommen.
- `connections`-schema in eventuele ADR's of CONVENTIONS.md heeft nu een extra kolom `administratie_id`.

Geen actie binnen deze plan-uitvoering (out-of-scope tot het webhook-pad zelf landt) — gemarkeerd voor `/gsd-transition`/`docs-sync`-pass na Phase 5c afronding.

## User Setup Required

None — geen externe service-config nodig voor deze plan. Phase 5c env vars (`SNELSTART_WEBHOOK_SECRET`, etc.) landen pas in plan 02 wanneer de HMAC-verifier wordt gebouwd.

## Next Phase Readiness

- Plan 02 (HMAC-verifier + middleware) kan starten met:
  - `connections.administratie_id` queryable via `Connection::where('provider','snelstart')->where('administratie_id', $uuid)`
  - `PassThroughCall::factory()->inbound()->create([...])` voor fixtures
  - `PassThroughCall::inbound()->where('consumer_id', null)` voor unknown-administratie-audit-queries
  - DB-unique `(provider, event_id)` voor idempotency-tests
- **Open vragen** (uit CONTEXT.md ❓-aannames) blijven blocker voor plan 02-05, niet voor 5c-01. Wacht op partner@snelstart.nl-respons voordat verifier-config (header-naam, algorithme) wordt gecodeerd.

## Self-Check

Verifying claims before returning to orchestrator.

**Files exist:**

- `[FOUND]` database/migrations/2026_05_17_000002_add_inbound_columns_to_pass_through_calls_table.php
- `[FOUND]` database/migrations/2026_05_17_000003_add_administratie_id_to_connections_table.php
- `[FOUND]` app/Models/PassThroughCall.php
- `[FOUND]` app/Models/Connection.php
- `[FOUND]` database/factories/PassThroughCallFactory.php
- `[FOUND]` database/factories/ConnectionFactory.php
- `[FOUND]` tests/Feature/PassThroughCallInboundScopesTest.php
- `[FOUND]` tests/Feature/ConnectionAdministratieIdTest.php

**Commits exist on worktree-agent-a1bef03e43d867656:**

- `[FOUND]` 47ea287 — Task 1 (inbound columns migration)
- `[FOUND]` ef02980 — Task 2 (administratie_id migration)
- `[FOUND]` 46854e1 — Task 3 RED
- `[FOUND]` 5b3e943 — Task 3 GREEN
- `[FOUND]` 59d0f3d — Task 4 RED
- `[FOUND]` 226c82e — Task 4 GREEN

## Self-Check: PASSED

---
*Phase: 05c-snelstart-webhook-handler*
*Completed: 2026-05-15*
