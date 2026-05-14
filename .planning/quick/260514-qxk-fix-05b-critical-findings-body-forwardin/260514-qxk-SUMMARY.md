---
phase: 260514-qxk-quick
plan: 01
subsystem: api
tags: [snelstart, pass-through, audit-log, content-negotiation, fingerprint, security]

# Dependency graph
requires:
  - phase: 05b-snelstart-pass-through-api
    provides: pass-through-controller + pass_through_calls-tabel + Phase-5b-test-suite
provides:
  - 415-guard voor non-JSON POST/PATCH op pass-through (CR-01 closed)
  - query_keys-kolom + endpoint-only path in audit-log (CR-02 closed)
  - NULL request_fingerprint voor lege POST/PATCH-body (CR-03 closed)
affects: [05b-snelstart-pass-through-api, 08-naschool-integration, future-pass-through-providers]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "415-guard pattern: Content-Type check vóór SDK-call, geen audit-rij bij contract-violation (consistent met bestaande 403/405-paden)"
    - "Keys-only audit pattern: query-parameter sleutels in aparte csv-kolom; waarden nooit in DB"
    - "Conditional fingerprint pattern: hash alleen wanneer body daadwerkelijk content heeft (NULL voor [] én null)"

key-files:
  created:
    - database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php
  modified:
    - app/Models/PassThroughCall.php
    - app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php
    - tests/Feature/PassThroughCallModelTest.php
    - tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php
    - tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php
    - tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php

key-decisions:
  - "415-pad schrijft geen audit-rij (T-qxk-04 accept): infra-laag logt al, HTTP-laag forensics is voldoende"
  - "after('path') op SQLite is no-op (silent) — geaccepteerd; PG honoreert het wel; Pint en migration draaien schoon op beide drivers"
  - "Test-asserts checken alle string-kolommen op PII-substring i.p.v. alleen path; toekomstige drift waarbij e-mail in een andere kolom belandt wordt zo ook gevangen"

patterns-established:
  - "Audit-fingerprint NULL-semantiek: NULL betekent 'geen onderscheidbare body', niet 'lege body met vaste hash' — voorkomt false-positive replay-signals"
  - "Audit-PII-isolatie: query-string-waarden + body-content blijven buiten DB; alleen keys + 12-char fingerprint"

requirements-completed:
  - HUB-05
  - PHASE-05b-REVIEW-CR-01
  - PHASE-05b-REVIEW-CR-02
  - PHASE-05b-REVIEW-CR-03

# Metrics
duration: ~40min
completed: 2026-05-14
---

# Quick-task 260514-qxk: 05b CRITICAL-fixes Summary

**Sluit drie BLOCKER-findings (CR-01/02/03) op de Snelstart pass-through controller; 415-guard + keys-only audit + conditional fingerprint; tests/Feature/Api/V1/Snelstart/ 28/28 groen, hele suite 120 passed + 1 incomplete.**

## Performance

- **Duration:** ~40 min (incl. ~10 min diagnose van Composer-shim/symlink-issue in worktree-vendor)
- **Started:** 2026-05-14T17:00:00Z (ongeveer, na plan-dispatch)
- **Completed:** 2026-05-14T17:41:25Z
- **Tasks:** 3 (alle TDD: RED → GREEN per task)
- **Files modified:** 6 (1 nieuwe migration, 1 model, 1 controller, 3 tests) + 1 nieuw artifact (SUMMARY)

## Accomplishments

- **CR-01 closed**: `POST/PATCH` met niet-`application/json` Content-Type retourneert nu 415 met `{"error":"unsupported_content_type","message":"…"}` **vóór** de SDK-call; geen audit-rij. Bewezen door `PassThroughEchoPingTest::test_post_with_non_json_content_type_returns_415_and_writes_no_audit_row`.
- **CR-02 closed**: `pass_through_calls.path` bevat alleen het endpoint-pad (geen `?`-segment, geen PII). Nieuwe `pass_through_calls.query_keys` kolom houdt csv van query-parameter-keys bij (of NULL). Bewezen door `PassThroughOdataRelatiesTest::test_get_relaties_with_top_5_query_string_is_proxied_verbatim_to_sdk` (aangepast) + nieuwe `test_complex_odata_query_stores_only_query_keys_no_values_in_audit` (asserts dat `a@b.nl` en `Email eq` in geen enkele string-kolom voorkomen).
- **CR-03 closed**: Lege POST/PATCH-body (`[]`) → `request_fingerprint = NULL` i.p.v. constante `sha256('[]')`-prefix `4f53cda18c2b`. Bestaande "body met content"-assert blijft groen. Bewezen door nieuwe `PassThroughAuditNoSecretsTest::test_empty_post_body_yields_null_fingerprint`.

## Task Commits

Vier task-atomic commits (TDD: 2× RED, 2× GREEN):

1. **Task 1 RED**: `950de85` `test(260514-qxk): falende test voor query_keys-kolom op pass_through_calls`
2. **Task 1 GREEN**: `c6c41d9` `feat(260514-qxk): voeg query_keys-kolom toe aan pass_through_calls (CR-02)`
3. **Task 2+3 RED**: `3d5b131` `test(260514-qxk): falende tests voor CR-01 + CR-02 + CR-03 controller-fixes` (Task 3 test-updates zijn in deze commit gefold — schoner TDD-cyclus)
4. **Task 2 GREEN**: `be794a5` `feat(260514-qxk): sluit CR-01 + CR-02 + CR-03 op pass-through controller`

Tussen RED en GREEN: Pint groen + `php artisan test` bevestigt exact-verwachte failures vóór commit.

## Files Created/Modified

- **`database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php`** *(created)* — Nullable string kolom `query_keys` op `pass_through_calls`, `after('path')` (MySQL/PG-honored, SQLite-noop, geen index — pure audit)
- **`app/Models/PassThroughCall.php`** *(modified)* — `'query_keys'` toegevoegd aan `#[Fillable]` direct na `'path'`
- **`app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php`** *(modified)*:
  - Body-resolution-block met expliciet contract: niet-JSON → 415, anders `$request->json()->all()`
  - `path` zonder query-string; nieuw `query_keys` veld in `PassThroughCall::create([...])`
  - `request_fingerprint` voorwaardelijk op `is_array($body) && $body !== []`
- **`tests/Feature/PassThroughCallModelTest.php`** *(modified)* — 2 nieuwe tests voor `query_keys`-kolom + fillable + nullable default
- **`tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php`** *(modified)*:
  - `test_get_relaties_with_top_5_query_string_is_proxied_verbatim_to_sdk`: assert nu `path === '/relaties'` exact + `query_keys` csv bevat `$top`
  - Nieuwe `test_complex_odata_query_stores_only_query_keys_no_values_in_audit`: PII-leak-guard over alle string-kolommen
- **`tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php`** *(modified)* — Nieuwe `test_post_with_non_json_content_type_returns_415_and_writes_no_audit_row`
- **`tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php`** *(modified)* — Nieuwe `test_empty_post_body_yields_null_fingerprint`

## Decisions Made

- **`after('path')` op SQLite is silent no-op** — geaccepteerd; PG honoreert het, SQLite zet 'm aan het eind. Schema-orde maakt niet uit voor app-logica; alleen `Schema::hasColumn` matters.
- **Task 3 gefold in Task 2's TDD-cyclus** — Plan beschreef Task 3 als losse test-updates, maar voor schone RED/GREEN-cyclus zijn alle nieuwe + gewijzigde tests in commit 3d5b131 gezet vóór de controller-fix in be794a5. Effectief 4 commits i.p.v. 5; geen functioneel verschil. Plan-success-criteria volledig gedekt.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Vendor symlink in worktree blokkeerde test-execution**
- **Found during:** Task 1 GREEN (`php artisan test --compact --filter='PassThroughCallModelTest'` na migration-add)
- **Issue:** Worktree had geen `vendor/` of `.env`. Eerst geprobeerd via `ln -s /Users/.../emeq-hub/vendor vendor` om sneller te starten, maar Composer's phpunit-shim doet `realpath(__DIR__)` op een fopen-wrapper, wat alle `__DIR__`-references in `bootstrap/app.php` resolved naar het symlink-target (de main repo). Resultaat: `database_path('migrations')` returned het main-repo-pad i.p.v. het worktree-pad → nieuwe migration werd niet gevonden door `migrate:fresh` in tests. Diagnose duurde ~10 min; bevestigd via `dump($app->databasePath())` in een test.
- **Fix:** Symlink verwijderd, `composer install` in worktree gedraaid voor een echte `vendor/`-dir. Daarna `database_path` correct → worktree-pad → migration runs in tests. `.env` van main repo gekopieerd (al gedaan in initiele setup).
- **Files modified:** vendor/ (composer-managed, niet in git); restored main-repo vendor met nog een composer install in main repo na onbedoelde package-removal van shared dir
- **Verification:** `vendor/bin/phpunit … 'test_query_keys_column_exists'` toont `database_path` = worktree-pad + `query_keys` in column-listing + test groen
- **Committed in:** N/A — geen tracked file change; alleen `.gitignored` `vendor/` aangepast

**2. [Rule 1 - Bug] Hot-fix verkeerde commit-doel (main-repo branch i.p.v. worktree-branch)**
- **Found during:** Task 1 RED commit
- **Issue:** Eerste RED-commit landde per ongeluk op `chore/v02-roadmap-split-and-scramble` in de hoofdrepo doordat `cd /Users/.../emeq-hub` voor het main-repo-pad zorgde i.p.v. het worktree-pad. Pre-commit head-assertion (`worktree-agent-*`-namespace check) zou dit hebben gevangen, maar het commit was via een `cd`-prefix dat me uit de worktree-cwd haalde.
- **Fix:** `git reset HEAD~1` in main repo (soft reset), `git checkout -- tests/...` om main-repo test-file restoren. Vervolgens alle edits + bash calls expliciet via worktree-pad. RED-commit (950de85) opnieuw landed op `worktree-agent-ac958bd6b8d006b47` — branch-namespace correct.
- **Files modified:** Geen permanent — alleen tijdelijke main-repo commit (03fa300) gereset
- **Verification:** `git symbolic-ref HEAD` bevestigt `refs/heads/worktree-agent-ac958bd6b8d006b47` vóór elke commit
- **Committed in:** Reset, niet committed in main repo

**3. [Rule 3 - Blocking] Stray `emeq_hub` SQLite-file aangemaakt tijdens diagnose**
- **Found during:** Task 1 GREEN pre-commit `git status --short`
- **Issue:** Tijdens diagnose werd ergens een artisan-call met `DB_CONNECTION=sqlite DB_DATABASE='emeq_hub'` losgelaten, wat een leeg `./emeq_hub`-sqlite-bestand aanmaakte in de worktree-root.
- **Fix:** `rm -f emeq_hub`. Niet aan `.gitignore` toegevoegd want het was geen reproducible artifact, alleen diagnostic-bijproduct.
- **Files modified:** `./emeq_hub` (deleted)
- **Verification:** `git status --short` toont alleen intended files
- **Committed in:** N/A — file is opgeruimd vóór een commit het zou kunnen oppikken

---

**Total deviations:** 3 auto-fixed (2× Rule 3 blocking, 1× Rule 1 misrouted-commit-recovery)
**Impact on plan:** Geen scope-creep; alle drie operationeel/tooling — geen code-changes buiten plan-scope. Het Composer-vendor-symlink-issue (#1) is een herhaalbare worktree-setup-bug die toekomstige worktree-agents kunnen tegenkomen; documenteer dit eventueel in `.claude/skills/` of de worktree-agent-setup-instructies.

## Issues Encountered

- **Composer phpunit-shim `realpath` semantiek + vendor-symlink-incompatibiliteit**: zie deviation #1 hierboven. Voortaan altijd `composer install` in de worktree zelf, niet symlinken.
- **`after('path')` op SQLite is silent no-op**: niet een failure, maar wel een gotcha — column belandt aan het eind van de tabel in SQLite ondanks de hint. Geen impact op tests of app-logica (kolom-volgorde is geen runtime-invariant).

## User Setup Required

None — geen externe services of env-vars nodig. Migration is forward-only en draait via `php artisan migrate` op de bestaande dev/prod DB. Alle credentials/connections blijven werken.

## Open Warnings (Out of Scope)

Deze quick-task closed alleen de CR-as. De volgende findings uit `05b-REVIEW.md` blijven open en zijn buiten scope:

- **WR-01**: Middleware-attributes null-guard in `PassThroughController` (defense-in-depth)
- **WR-02**: Audit-row best-effort wrap voor `JsonException` in catch-block
- **WR-03**: 401-cloaking-policy body-preservering van `upstream_status: 401`
- **WR-04**: `extractStatusFromMessage` regex matching te liberaal
- **WR-05**: Test-coupling aan SDK-interne `InvalidArgumentException`
- **WR-06**: `external_id` trim in `StoreAccountRequest::prepareForValidation`
- **WR-07**: `Route::any` accepteert te veel methods; route-niveau `Route::match` is netter
- **IN-01**: `$timestamps = false` + `created_at` in `$fillable` paradox
- **IN-02**: `PrimesSnelstartTokenCache` dupliceert resolver-credential-bouw
- **IN-03**: `ConnectionController::findOwnedConnection` 2 queries i.p.v. 1 join
- **IN-04**: `ScrambleRouteDiscoveryTest` skipt stilletjes — SC-8 garantie zwak

Apart opvolgen — Phase 5b kan op CR-as mergen.

## Threat Flags

Geen nieuwe attack-surface buiten plan-threat-model. T-qxk-04 (415 zonder audit-rij) is `accept`-disposition zoals plan beschreven.

## Next Phase Readiness

- Phase 5b CR-status: van `issues_found` (3 critical) naar `clean` op CR-as. WR/IN-flags blijven open maar zijn niet-blocking voor merge.
- Pass-through audit-laag heeft nu correcte semantiek voor toekomstige Mollie-pass-through (Phase 5a) en Naschool consumer-flow (Phase 8).

## Docs-Drift Triggers (Out of Scope)

Migration + Model-touched-triggers genoteerd; geen update van `.docs/`, `CLAUDE.md`, of memory uitgevoerd (constraint van deze quick-task: code-only, geen docs-werk). `docs-sync` skill kan apart gedraaid worden vóór de orchestrator de docs-commit maakt.

## Self-Check: PASSED

- `database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php` — FOUND
- `app/Models/PassThroughCall.php` — FOUND (modified)
- `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` — FOUND (modified)
- 4 test-files — FOUND (modified)
- Commit 950de85 — FOUND
- Commit c6c41d9 — FOUND
- Commit 3d5b131 — FOUND
- Commit be794a5 — FOUND
- `tests/Feature/Api/V1/Snelstart/` 28/28 groen
- Hele suite 120 passed + 1 incomplete (pre-existing)
- Pint: groen
- Sanity-grep: `getQueryString` verwijderd, `HTTP_UNSUPPORTED_MEDIA_TYPE` toegevoegd

---
*Quick-task: 260514-qxk-fix-05b-critical-findings-body-forwardin*
*Completed: 2026-05-14*
