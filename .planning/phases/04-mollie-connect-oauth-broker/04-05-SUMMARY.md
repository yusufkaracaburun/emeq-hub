---
phase: 04-mollie-connect-oauth-broker
plan: 05
subsystem: operations
tags: [laravel, artisan, oauth, cleanup, phpunit, blocking, phase-acceptance]

# Dependency graph
requires:
  - phase: 04-mollie-connect-oauth-broker
    provides: Connection-model met oauth_state-velden (04-01), ConnectionFactory pending()/active()/expired() (04-01), OAuthFlowRegistry + Mollie-binding (04-02), HubMollieCredentialResolver (04-03), InitController + CallbackController + routes + 7 tests (04-04)
provides:
  - App\Console\Commands\PruneOAuthPendingConnections (D-09 operationele cleanup)
  - tests/Feature/Console/PruneOAuthPendingConnectionsTest (2 paden — prunes-expired + dry-run-no-delete)
  - BLOCKING phase-acceptance bewijs (8 verificatiestappen + full test-suite 129/129 + pint clean)
affects:
  - Phase 4 acceptance closed — alle 5 ROADMAP SC's (SC-1 t/m SC-5) gedekt door automated tests
  - Phase 5a (Mollie pass-through): onaffected — prune-command is operationeel, geen API-surface

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Artisan-command property-stijl signature (NIET #[AsCommand]-attribute) — match HubConsumerCreate conventie (STATE.md decision 03-05)"
    - "D-09 cleanup-policy: handmatige/deploy-hook trigger, GEEN schedule-registratie — past bij D-04 'geen-cron-filosofie'"
    - "Pure hard-delete (Connection heeft geen SoftDeletes-trait); expired-pending rows zijn orphans zonder business-value"

key-files:
  created:
    - app/Console/Commands/PruneOAuthPendingConnections.php
    - tests/Feature/Console/PruneOAuthPendingConnectionsTest.php
  modified: []

key-decisions:
  - "TDD RED/GREEN expliciet gesplitst in twee commits (test eerst, impl tweede) — past binnen plan's tdd='true' attribute. RED-commit toont initial-fail met 'command does not exist' (2 errors / 1 assertion), GREEN-commit landt impl + maakt suite groen (2 passed / 7 assertions)."
  - "Acceptance-Step 8 ROADMAP-vs-CONTEXT delta surfacd: ROADMAP SC-1 wording (`GET /v1/oauth/mollie/authorize`) is gedateerd; implementatie volgt CONTEXT D-01/D-08 (`POST /v1/oauth/mollie/init` met JSON-return {connection_id, redirect_url}). ROADMAP heeft een wording-update nodig bij phase-close-commit."

patterns-established:
  - "Pattern 1: artisan-command in app/Console/Commands/ met property-stijl `$signature` + `$description` (NL-text) + `handle(): int` returning self::SUCCESS/INVALID/FAILURE — matched HubConsumerCreate"
  - "Pattern 2: cleanup-commands NIET in routes/console.php registreren tenzij scheduler-trigger expliciet gewenst — past bij D-09 'handmatig of deploy-hook'"

requirements-completed: [MOLL-02, HUB-02]

# Metrics
duration: ~8 min
completed: 2026-05-14
---

# Phase 4 Plan 05: oauth:prune-pending Command + BLOCKING Phase-Acceptance Summary

**`oauth:prune-pending` operationele cleanup-command + alle 8 phase-acceptance-stappen GREEN — Phase 4 is volledig acceptable; 5/5 ROADMAP SC's (SC-1 t/m SC-5) bewezen door 26 automated tests in dedicated test-files.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-05-14T20:11:00Z
- **Completed:** 2026-05-14T20:19:00Z
- **Tasks:** 2 (1 auto+tdd, 1 blocking-acceptance)
- **Files created:** 2

## Accomplishments

- `App\Console\Commands\PruneOAuthPendingConnections` levert `oauth:prune-pending` met property-stijl signature, `--dry-run` flag, NL-description, en pure hard-delete van `(status=pending AND oauth_state_expires_at < now())`-rows. D-09 honored: GEEN scheduler-registratie, GEEN soft-delete.
- `PruneOAuthPendingConnectionsTest` (2 paden / 7 assertions) bewijst: `prunes-expired` (expired weg, fresh blijft, active blijft) + `dry-run-no-delete` (output bevat 'Dry-run', row blijft bestaan). Beide groen.
- TDD-cyclus expliciet gevolgd: RED-commit (`de42922`) toont 2 failures met "command does not exist", GREEN-commit (`edab209`) landt impl en maakt beide tests groen.
- **BLOCKING phase-acceptance:** alle 8 verificatiestappen GREEN (zie tabel onder).
- **Volledige test-suite:** 129 passed / 383 assertions / 1 incomplete (pre-existing `markTestIncomplete` in `MollieConnectOAuthFlowTest` voor race-test + `SanctumAbilityTest`-skip uit Phase 3) / 0 failures. Was 127 vóór dit plan (Phase 04-04-SUMMARY) → +2 nieuwe Prune-tests.
- **Pint clean** op alle gewijzigde files (RED + GREEN beide passed Pint).
- **ROADMAP-vs-CONTEXT delta** op SC-1-wording expliciet surfacd voor follow-up-fix bij phase-close-commit.

## Task Commits

Elke task atomic gecommit; Task 1 gesplitst per TDD-protocol:

1. **Task 1 RED: failing test voor oauth:prune-pending command** — `de42922` (test)
2. **Task 1 GREEN: oauth:prune-pending artisan-command (D-09)** — `edab209` (feat)
3. **Task 2: BLOCKING phase-acceptance** — geen commit (alleen runtime-verificatie van 8 stappen)

**Plan metadata** (deze SUMMARY): volgt in vervolg-commit door orchestrator.

## Files Created

- `app/Console/Commands/PruneOAuthPendingConnections.php` *(created)* — class extends `Illuminate\Console\Command`; property-stijl signature `oauth:prune-pending {--dry-run : …}`; NL `$description`; `handle(): int` met query-builder waar `status=pending AND oauth_state_expires_at < now()`; dry-run-pad toont count, regular-pad doet `delete()` en toont count. Beide returnen `self::SUCCESS`.
- `tests/Feature/Console/PruneOAuthPendingConnectionsTest.php` *(created)* — `Tests\Feature\Console`-namespace, `RefreshDatabase`-trait, 2 testmethodes per PATTERNS.md §"tests/Feature/Console/PruneOAuthPendingConnectionsTest.php" exact copy.

## 8 Acceptance-step uitkomsten

Alle stappen geëxecuteerd in volgorde; stdout-bewijs hieronder:

| # | Stap | Verwacht | Uitkomst |
|---|------|----------|----------|
| 1 | `php artisan migrate --no-interaction --force` | exit 0 + "Nothing to migrate" OF fresh-apply | **PASS** — `INFO Nothing to migrate.` (Plan 04-01 had migratie al toegepast) |
| 2 | `Schema::hasColumns("connections", ["oauth_state","oauth_state_expires_at"])` | `SCHEMA_OK` | **PASS** — stdout: `SCHEMA_OK` |
| 3 | `php artisan route:list --path=oauth/mollie --json` | 2 routes (POST init + GET callback) | **PASS** — 2 routes returned: `GET v1/oauth/mollie/callback` + `POST v1/oauth/mollie/init` (laatste met middleware `auth:sanctum,ability:mollie:write`) |
| 4a | `app(MollieCredentialResolver::class)` class-name | `App\Mollie\HubMollieCredentialResolver` | **PASS** — exact match |
| 4b | `OAuthFlowRegistry->for("mollie")` class-name | `App\OAuth\Mollie\MollieConnectOAuthFlow` | **PASS** — exact match |
| 5 | `php artisan list | grep oauth:prune-pending` | 1 regel met description | **PASS** — `oauth:prune-pending          Ruim expired pending OAuth-Connections op (status=pending AND oauth_state_expires_at < now)` |
| 6 | `php artisan test --compact` (BLOCKING) | 0 failed | **PASS** — 129 passed / 383 assertions / 1 incomplete / 0 failures (1954 ms) |
| 7 | `./vendor/bin/pint --dirty --format agent` | 0 errors | **PASS** — `{"tool":"pint","result":"passed"}` |
| 8 | ROADMAP-vs-CONTEXT delta op SC-1-wording | gedocumenteerd in SUMMARY | **PASS** — zie "ROADMAP follow-up" sectie onder |

**All 8 GREEN.** Phase 4 acceptance is volledig groen.

## ROADMAP SC mapping (Phase 4 — bewijs per success criterion)

| ROADMAP SC | Beschrijving | Bewezen door (test-naam → commit-trail) |
|------------|--------------|------------------------------------------|
| SC-1 | `/init` pre-creëert pending Connection + returnt redirect-URL met juiste client_id/state/redirect_uri | `Tests\Feature\Api\OAuth\InitTest::test_init_creates_pending_connection_and_returns_redirect_url` (Plan 04-04, commit `7f58793`) |
| SC-2 | Callback ruilt code in voor access+refresh tokens encrypted-at-rest | `Tests\Feature\Api\OAuth\CallbackTest::test_callback_exchanges_code_when_state_matches` (Plan 04-04, `7f58793`) + Phase 3 `Tests\Feature\ConnectionEncryptionTest` (al groen, regression-checked) |
| SC-3 | Connection met `expires_at < 5min` triggert refresh, pass-through ziet geen 401 | `Tests\Feature\Mollie\HubMollieCredentialResolverTest::test_resolve_triggers_refresh_when_within_five_minute_window` (Plan 04-03, `bdbb701`) + `Tests\Feature\OAuth\MollieConnectOAuthFlowTest::test_exchange_code_writes_encrypted_tokens` voor refresh-shape (Plan 04-02, `bc73adb`) |
| SC-4 | OAuthFlow-contract heeft een tweede dummy-implementatie die laat zien dat het pattern niet Mollie-specifiek is | `Tests\Feature\OAuth\OAuthFlowContractTest` (3 tests — Plan 04-01, commit `2095d8f`) |
| SC-5 | Tampered state-parameter → 400 | `Tests\Feature\Api\OAuth\CallbackTest::test_callback_with_invalid_state_returns_400` + `test_callback_with_expired_state_returns_400` + `test_second_callback_with_same_state_returns_400` (Plan 04-04, `7f58793`) |

**Combined:** 26 tests / 66 assertions tegen 5 SC's — alle PASS.

## CONTEXT decisions (D-01 t/m D-16) traceerbaarheid

| Decision | Honored in | Bewijs |
|----------|------------|--------|
| D-01 (pre-create pending bij /init) | InitController (Plan 04-04) | `assertDatabaseHas('connections', ['status' => 'pending', ...])` in InitTest |
| D-02 (30min state-TTL) | ConnectionFactory::pending() (Plan 04-01) + InitController | factory state `oauth_state_expires_at = now()->addMinutes(30)` |
| D-03 (state-verify + idempotency) | CallbackController + CallbackTest 4 paden | tampered/expired/replay alle drie → 400 |
| D-04 (pure lazy refresh, geen cron) | HubMollieCredentialResolver (Plan 04-03) + dit plan's "geen schedule-registratie" | prune-command staat NIET in routes/console.php |
| D-05 (Redis-lock per connection_id) | MollieConnectOAuthFlow::refreshToken (Plan 04-02) | `Cache::lock("oauth:refresh:{...}", 30)->block(15, ...)` |
| D-06 (5min refresh-window) | HubMollieCredentialResolver + MollieConnectOAuthFlow | `addMinutes(5)`-check vóór delegate naar refresh |
| D-07 (twee endpoints, twee auth-modes) | routes/api.php (Plan 04-04) | POST init binnen `auth:sanctum`+`ability:mollie:write`, GET callback publiek |
| D-08 (JSON-return, geen HTTP-redirect) | InitController (Plan 04-04) | `assertJsonStructure(['connection_id','redirect_url'])` in InitTest |
| D-09 (prune via command, geen cron) | **PruneOAuthPendingConnections (dit plan)** | command bestaat, scheduler-registratie afwezig, 2 tests groen |
| D-10 (scopes hard-coded in config) | config/services.php (Plan 04-02) | 9-scopes-array onder `mollie.connect.scopes` |
| D-11 (scopes-jsonb-kolom) | MollieConnectOAuthFlow::exchangeCode | `scopes` veld populated met response-scopes |
| D-12 (FakeOAuthFlow in app/OAuth/Testing/) | Plan 04-01 | `App\OAuth\Testing\FakeOAuthFlow` bestaat |
| D-13 (OAuthFlow-contract in app/, niet packages/) | Plan 04-01 | `App\OAuth\Contracts\OAuthFlow` namespace bevestigd |
| D-14 (Registry::for) | OAuthFlowRegistry (Plan 04-02) + AppServiceProvider | tinker resolveert `for('mollie')` correct (Step 4) |
| D-15 (geen Saloon, directe Http::post) | MollieConnectOAuthFlow (Plan 04-02) | `Http::asForm()->post('https://api.mollie.com/oauth2/tokens', ...)` |
| D-16 (HubMollieCredentialResolver bindt in Phase 4) | Plan 04-03 + AppServiceProvider | tinker resolveert correct (Step 4a) |

**Alle 16 decisions honored.**

## ROADMAP follow-up (TODO bij phase-close-commit)

**SC-1 wording in `.planning/ROADMAP.md` Phase 4-block heeft een update nodig.**

- **Huidige tekst:** "SC-1: `GET /v1/oauth/mollie/authorize?account=…` returnt 200 met redirect-URL naar `https://my.mollie.com/oauth2/authorize?...`"
- **Werkelijke implementatie (CONTEXT D-01 + D-08 + Plan 04-04):** "SC-1: `POST /v1/oauth/mollie/init` returnt 200 met JSON `{connection_id, redirect_url}` waar redirect_url naar `https://my.mollie.com/oauth2/authorize?…` wijst"
- **Reason:** CONTEXT D-08 lockt expliciet op JSON-return zodat de Consumer-app analytics/logging-hooks vóór de browser-redirect kan plaatsen (`window.location.href = redirect_url`). ROADMAP's wording was pre-CONTEXT.
- **Suggested fix:** wijzig SC-1-zin in ROADMAP.md regel ~96 naar de D-08 wording. Niet uitgevoerd in dit plan (orchestrator-owned doc-update).

## Deviations from Plan

None — plan executed exactly as written.

Toelichting:
- TDD RED/GREEN gesplitst per `tdd="true"` attribute — geen ceremonie-overslag zoals Plan 04-02/04-03 die expliciet RED+GREEN merged. Hier had de RED-fase informatie-winst (de test was niet copy-pasted uit een al-werkende file maar nieuw geschreven tegen een nog-niet-bestaande command).
- Alle PATTERNS.md copy-patterns (regel 705-740 voor command, regel 1443-1479 voor test) zijn 1-op-1 gevolgd.
- Property-stijl signature gebruikt zoals plan + PATTERNS expliciet specificeren (NIET `#[AsCommand]` zoals Symfony-attributen).
- Geen `declare(strict_types=1)` in nieuwe file (Hub-tree-conventie per PATTERNS.md regel 1510).
- D-09 honored: command zit niet in `routes/console.php` — handmatige/deploy-hook trigger.
- Alle 8 acceptance-stappen GREEN zonder retries of fixes.
- 0 deviation-fixes nodig; geen architecturele beslissingen; geen auth-gates; geen blocked-paths.

## Auth Gates

Geen. Geen externe Mollie-API-roundtrips in dit plan (Task 2 verifieert alleen container-bindings + routes + tests — geen echte Mollie-handshake).

## Issues Encountered

- **Worktree HEAD initieel op verkeerd commit** (`907a0af` Phase 2 closure ipv expected base `64e013b4` na Phase 04-04): `<worktree_branch_check>` Step 2 hard-reset naar de juiste base. Geen werk verloren (worktree-branch had geen Phase 4 commits). Geen actie nodig — dit was setup, niet Phase 4-content.
- **Composer-vendor en .env ontbraken in worktree** (worktrees zijn self-contained per setup-conventie): `composer install` + `cp ../../.env .env` uitgevoerd bij setup vóór Task 1. Geen impact op deliverables.

## Threat Flags

Geen nieuwe trust-boundaries of security-surface — prune-command opereert puur op DB-rows zonder business-value (orphans zonder tokens). De twee `where`-clauses (status=pending EN expires_at<now) sluiten correctness uit; Test 1 dekt alle 3 paden expliciet.

## Self-Check: PASSED

Bestand-existence:
- FOUND: `app/Console/Commands/PruneOAuthPendingConnections.php`
- FOUND: `tests/Feature/Console/PruneOAuthPendingConnectionsTest.php`

Commit-existence:
- FOUND: `de42922` (Task 1 RED — failing test)
- FOUND: `edab209` (Task 1 GREEN — implementation)

Acceptance-criteria-greps (alle exit 0):
- `oauth:prune-pending` in command-file ✓
- `--dry-run` in command-file ✓
- `where('status', 'pending')` in command-file ✓
- `where('oauth_state_expires_at', '<', now())` in command-file ✓
- `php artisan list | grep oauth:prune-pending` returns 1 line ✓
- `grep -c "public function test_" test-file` returns 2 ✓

Runtime-verificatie (alle 8 acceptance-stappen):
- Step 1 migrate exit 0 ✓
- Step 2 SCHEMA_OK ✓
- Step 3 route:list returns 2 OAuth routes ✓
- Step 4a HubMollieCredentialResolver bound ✓
- Step 4b MollieConnectOAuthFlow bound ✓
- Step 5 oauth:prune-pending in artisan list ✓
- Step 6 test --compact: 129 passed / 0 failed / 1 incomplete (pre-existing) ✓
- Step 7 Pint clean ✓
- Step 8 ROADMAP-vs-CONTEXT delta documented ✓

## TDD Gate Compliance

Plan 04-05 Task 1 had `tdd="true"`. Gate-sequence-validation:
1. **RED gate** — `test(...)` commit BEFORE implementation: `de42922 test(04-05): failing test voor oauth:prune-pending command` ✓
2. **GREEN gate** — `feat(...)` commit AFTER RED: `edab209 feat(04-05): oauth:prune-pending artisan-command (D-09)` ✓
3. **REFACTOR gate** — niet nodig (impl was minimaal en past Hub-conventie out-of-the-box; Pint clean op eerste run)

TDD-cyclus compliant.

## User Setup Required

None — `oauth:prune-pending` is een lokaal artisan-command zonder externe dependencies. Productie-trigger is operator-discretion (D-09: handmatig of via deploy-hook).

## Next Phase Readiness

- **Phase 4 fully closed** — alle 5 ROADMAP SC's groen, alle 5 plans (04-01 t/m 04-05) gemerged + summaries geschreven.
- **Phase 5a** (Mollie pass-through API): kan starten — depends-on Phase 4 (OAuth-broker) is nu compleet. `Mollie::client()` resolveert via `HubMollieCredentialResolver` met lazy refresh; `MollieConnectionContext::set()` is per-request-singleton ready voor pass-through-middleware.
- **Phase 5b** (Snelstart pass-through): kan parallel met Phase 4 al starten (geen OAuth-broker nodig) — onaffected door dit plan.
- **Orchestrator-owned follow-ups vóór phase-close-commit:**
  1. Update ROADMAP.md SC-1 wording naar D-08-compliant tekst (zie "ROADMAP follow-up" hierboven).
  2. Mark Phase 4 als `[x]` voltooid in ROADMAP.md regel 25 + plan-checkboxes (regel 108-111 toon nu 04-01 + 04-02 + 04-03 + 04-04; 04-05 ontbreekt nog).
  3. STATE.md update — `state advance-plan` + `state update-progress` + decisions/metrics-recording.
  4. Run `.claude/skills/docs-sync` op `.docs/`-tree (genoteerd uit Plan 04-02-SUMMARY).
- Geen blockers.

---
*Phase: 04-mollie-connect-oauth-broker*
*Completed: 2026-05-14*
