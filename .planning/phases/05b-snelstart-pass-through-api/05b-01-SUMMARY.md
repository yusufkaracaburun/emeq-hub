---
phase: 05b-snelstart-pass-through-api
plan: 01
subsystem: database
tags:
  - laravel
  - migrations
  - postgres
  - eloquent
  - audit-log
  - tdd
  - phpunit

# Dependency graph
requires:
  - phase: 03-hub-skeleton
    provides: "Consumer/Account/Connection models + factories + encrypted casts + cross-Consumer query-isolation"
provides:
  - "Postgres-tabel `pass_through_calls` (immutable audit-rij voor Snelstart pass-through-calls) met 3 indexen"
  - "Eloquent-model `App\\Models\\PassThroughCall` met BelongsTo-relaties naar Consumer/Account/Connection"
  - "Database factory `PassThroughCallFactory` met linked Consumer→Account→Connection chain (Snelstart-state by default)"
  - "ADR `pass-through-calls-table.md` die de deviatie van HUB-05 ROADMAP-tekst (`webhook_calls` → `pass_through_calls`) documenteert"
  - "Plan-level TDD-gate bewezen: RED-commit (`test`) → GREEN-commit (`feat`) sequence in git log"
affects:
  - 05b-02 (PassThroughController + audit-write pad)
  - 05b-03 (ResolveSnelstartAccount middleware — gebruikt nieuwe model)
  - 05b-04 (provisioning-endpoints leveren Connection-FKs aan)
  - 05b-05 (end-to-end smoke: write naar pass_through_calls verifieren)
  - 05a-* (Mollie pass-through kan dezelfde tabel hergebruiken via `provider`-kolom)
  - 09-filament-admin-ui (toekomstige PassThroughCallResource)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Immutable Eloquent-model — `$timestamps = false` + DB-level `useCurrent()` op `created_at`; geen `updated_at`-kolom"
    - "Postgres partial index (`WHERE status >= 500`) via raw `DB::statement` — zelfde pattern als `connections_account_id_provider_active_unique` uit Phase 3-cleanup"
    - "`nullOnDelete` foreign key voor audit-immutability (`connection_id` overleeft revoke/delete van Connection)"
    - "TDD RED→GREEN met PHPUnit-Feature-test op model + factory + nullOnDelete-gedrag in dezelfde test-file"

key-files:
  created:
    - "database/migrations/2026_05_15_000001_create_pass_through_calls_table.php"
    - "app/Models/PassThroughCall.php"
    - "database/factories/PassThroughCallFactory.php"
    - "tests/Feature/PassThroughCallModelTest.php"
    - ".docs/decisions/pass-through-calls-table.md (gitignored — content ook ingebed in deze SUMMARY onder 'ADR-tekst')"
  modified: []

key-decisions:
  - "Aparte `pass_through_calls`-tabel i.p.v. hergebruik van Spatie `webhook_calls` — andere stream-pattern (pass-through ≠ fan-out); deviatie van HUB-05 ROADMAP-tekst expliciet vastgelegd in ADR"
  - "Immutability via `$timestamps = false` + alleen `created_at` met DB-default `useCurrent()` — Eloquent's auto-`updated_at`-pad wordt zo geheel vermeden zonder een `creating`-event te schrijven"
  - "`provider`-kolom in de tabel — provider-agnostisch zodat 5a (Mollie) en toekomstige 5c+ dezelfde tabel kunnen hergebruiken zonder schema-wijziging"
  - "Factory's default-state = Snelstart-shape via `Connection::factory()->forSnelstart()` (Phase 3 03-01 vestigde dezelfde keuze voor `ConnectionFactory` — consistentie)"
  - "Partial index op `status >= 500` voor failure-monitoring i.p.v. full index op `status` — kleinere index, gerichte query-shape"

patterns-established:
  - "Audit-row schema-template voor Phase 5a/5c (provider-agnostisch via `provider`-kolom + fingerprint(12) i.p.v. body-snapshot)"
  - "TDD-flow voor model+factory+schema-gedrag in één feature-test (model-loadability + factory-relations + nullOnDelete in 3 tests)"

requirements-completed:
  - HUB-05

# Metrics
duration: ~18 min
completed: 2026-05-14
---

# Phase 05b Plan 01: Pass_through_calls audit-tabel + Eloquent-model Summary

**Immutable Postgres audit-tabel `pass_through_calls` + `App\Models\PassThroughCall` Eloquent-model + factory + ADR die de deviatie van HUB-05 ROADMAP-tekst (`webhook_calls`) vastlegt — fundament voor het Snelstart-pass-through-audit-pad in de overige 5b-plans.**

## Performance

- **Duration:** ~18 min
- **Started:** 2026-05-14 (worktree spawn)
- **Completed:** 2026-05-14
- **Tasks:** 3 (3 auto, geen checkpoints)
- **Files created:** 5 (1 gitignored)

## Accomplishments

- Postgres-tabel `pass_through_calls` met 13 kolommen (`id` + 11 datakolommen + `created_at`) en 3 indexen (`(consumer_id, created_at)`, `(account_id, created_at)`, partial op `status >= 500`).
- `App\Models\PassThroughCall` met `$timestamps = false`, 3 BelongsTo-relaties, integer + datetime casts; gebruikt nieuwe `App\Models\PassThroughCallFactory`.
- TDD RED→GREEN bewezen: `test(05b-01)`-commit (3 falende tests, 1 passte al door immutable migratie) → `feat(05b-01)`-commit (model + factory landen → 3 tests groen, 6 assertions).
- ADR documenteert keuze voor eigen tabel met expliciete `webhook_calls`-vergelijking; bevestigt dat HUB-05 REQUIREMENTS.md aanpassing nodig heeft (`webhook_calls` → `pass_through_calls`).
- Volledige Hub-testsuite blijft groen na alle changes: 35 passed / 1 incomplete (Phase 3-placeholder) / 77 assertions.

## Task Commits

Each task was committed atomically:

1. **Task 1: Migration `create_pass_through_calls_table`** — `8ed8df0` (feat)
2. **Task 2 RED: failing PassThroughCallModelTest** — `bea7ef2` (test) — *plan-level TDD gate*
3. **Task 2 GREEN: PassThroughCall-model + factory** — `5d1a94b` (feat) — *plan-level TDD gate*
4. **Task 3: ADR `pass-through-calls-table.md`** — *(geen git-commit; `.docs/` is gitignored — zie Deviations)*

_TDD-task 2 produceert twee commits per de plan-level TDD-gate (`tdd="true"`-task)._

## Files Created/Modified

- `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php` — Immutable audit-tabel + 3 indexen
- `app/Models/PassThroughCall.php` — Eloquent-model met `$timestamps = false`
- `database/factories/PassThroughCallFactory.php` — Linked-factory chain (Consumer→Account→Connection->forSnelstart)
- `tests/Feature/PassThroughCallModelTest.php` — 3 tests (factory-relations / no updated_at / nullOnDelete-gedrag)
- `.docs/decisions/pass-through-calls-table.md` — ADR (gitignored, content ook hieronder ingebed)

## Decisions Made

- **Aparte tabel i.p.v. `webhook_calls`-hergebruik** — andere stream-pattern (pass-through ≠ fan-out); separate tabel = cleane indexes + retention-flexibiliteit, vermijdt een `direction`-discriminator + NULL-kolommen.
- **Immutable via `$timestamps = false` + DB-default `useCurrent()` op `created_at`** — geen `creating`-event nodig, geen `updated_at`-kolom. Bij handmatig zetten van `created_at` in een test respecteert Eloquent dat omdat `created_at` in `$fillable` staat.
- **`provider`-kolom in de tabel** — toekomstige 5a (Mollie) en 5c+ kunnen dezelfde tabel hergebruiken.
- **Partial Postgres-index** op `status >= 500` — failure-monitoring zonder full-index op `status`.
- **Factory-default = Snelstart-shape** — consistent met `ConnectionFactory` uit Phase 3 03-01.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] ADR-file is gitignored — content ook ingebed in SUMMARY**
- **Found during:** Task 3 (ADR-write)
- **Issue:** `.gitignore` bevat `/.docs` (regel 29) — `.docs/decisions/pass-through-calls-table.md` is dus working-copy-only en wordt niet gecommit. De worktree wordt na merge door de orchestrator force-removed, waardoor de ADR-content anders verloren zou gaan.
- **Fix:** ADR-file aangemaakt op de target-locatie (`.docs/decisions/pass-through-calls-table.md` — alle plan-acceptance-grep-checks slagen). Daarnaast volledige ADR-tekst ingebed in deze SUMMARY onder *"ADR-tekst (gemirrord uit `.docs/decisions/pass-through-calls-table.md`)"*-sectie, zodat de orchestrator of user na merge de file kan recreëren vanuit committed history. Geen aanpassing aan `.gitignore` (`.ai/git-policy` + `.docs/README.md` zijn expliciet over `.docs/` als lokale werkmap).
- **Files affected:** `.docs/decisions/pass-through-calls-table.md` (gitignored), deze SUMMARY (committed)
- **Verification:** `test -f .docs/decisions/pass-through-calls-table.md` exit 0; `grep -cE "^## (Status|Keuze|Context|Consequenties)" .docs/decisions/pass-through-calls-table.md` = 4; `grep -c "pass_through_calls"` = 5 (≥3); ADR-tekst-mirror in SUMMARY identiek aan file-content
- **Committed in:** geen aparte commit — content overleeft via deze SUMMARY-mirror

**2. [Workflow note - geen Rule] Worktree-vendor herbouwd voor lokale autoload**
- **Found during:** Task 2 (TDD GREEN-run van PHPUnit)
- **Issue:** Worktree spawned zonder `vendor/`; aanvankelijke symlink `vendor → /Users/.../emeq-hub/vendor` resolveerde PSR-4 `App\\` naar parent-repo `app/` (waar `App\Models\PassThroughCall` nog niet bestaat). Direct PHPUnit-runs op de worktree-files faalden met `Class not found`.
- **Fix:** Symlink vervangen door hybride vendor: lokale `vendor/composer/` (zodat `dirname(__DIR__)` `$baseDir` naar worktree resolveert) + lokale `vendor/bin/` (zodat `_composer_autoload_path` lokaal blijft, geen dubbele autoload-registratie tussen parent en worktree) + per-package symlinks naar parent's `vendor/<pkg>` (geen disk-cost). `composer dump-autoload` na de hybride-opzet regenereert classmap relatief aan de worktree. **Geen impact op committed artifacts** — `vendor/` is gitignored.
- **Files affected:** `vendor/` (gitignored, working-copy only)
- **Verification:** `vendor/bin/phpunit --filter=PassThroughCallModelTest` → 3 passed; volledige suite `vendor/bin/phpunit` → 35 passed / 1 incomplete / 0 failed
- **Committed in:** geen — `vendor/` is gitignored

---

**Total deviations:** 2 (1 documentation-routing, 1 workflow/tooling). **Impact on plan:** geen scope-creep — alle plan-acceptance-criteria gehaald (zie *Verification*-sectie hieronder). Beide deviaties zijn working-copy-only en raken de committed artifacts niet.

## Issues Encountered

- **Initiële `php artisan test`-pad bleef double-load-error geven** zelfs na hybride-vendor: `php artisan test` start een sub-process op `vendor/bin/phpunit` dat de parent-vendor blijkt te raken. **Workaround:** verifiëren via `vendor/bin/phpunit` direct (zelfde tool, geen sub-process). Beide commando's lopen op dezelfde PHPUnit-binary; uitkomst is functioneel identiek. Niet als deviation geclassificeerd omdat het een artisan-test-orchestratie-issue is, geen plan-actie-fout.

## Verification

Acceptance-criteria-summary per task:

**Task 1 (migration):**
- ✅ `grep -c "Schema::create('pass_through_calls'"` = 1
- ✅ `grep -c "CREATE INDEX pass_through_calls_status_failures"` = 1
- ✅ `grep -c "timestamps()"` = 0 (immutable)
- ✅ `php artisan migrate --pretend` runs clean (incl. partial index)
- ✅ `php artisan migrate` runs clean on actual DB (verified via `migrate:status` + `Schema::hasTable`)

**Task 2 (model + factory + tests):**
- ✅ `class PassThroughCall extends Model` aanwezig
- ✅ `public $timestamps = false` aanwezig
- ✅ 3 BelongsTo-methoden (`consumer/account/connection`)
- ✅ `class PassThroughCallFactory extends Factory` aanwezig
- ✅ `vendor/bin/phpunit --filter=PassThroughCallModelTest` → **3 passed / 6 assertions**

**Task 3 (ADR):**
- ✅ File `.docs/decisions/pass-through-calls-table.md` bestaat
- ✅ 4 secties aanwezig: `## Status` `## Keuze` `## Context` `## Consequenties`
- ✅ `grep -c "pass_through_calls"` = 5 (≥3)

**Overall verification:**
- ✅ Pint clean (`vendor/bin/pint --dirty --format agent` → passed)
- ✅ Full suite green: **35 passed / 1 incomplete (Phase 3-placeholder) / 77 assertions** — geen regressies

## Threat Flags

Geen nieuwe trust-boundaries die niet al in `<threat_model>` van het plan staan. Alle 4 mitigaties (T-05b-01 t/m T-05b-04) zijn ofwel al schema-niveau geadresseerd (geen body-snapshot, geen credentials in `path` als query-string, geen `updated_at`-kolom, cascadeOnDelete op `consumer_id`) ofwel hebben afhankelijkheden op Plan 03/05 die buiten 5b-01 scope vallen.

## User Setup Required

None - geen externe service-configuratie of `.env`-mutaties.

## Next Phase Readiness

- **Klaar voor Plan 05b-02** (PassThroughController + audit-write): model + factory + tabel staan; controller kan `PassThroughCall::create([...])` aanroepen vanuit de pass-through-request-cycle (audit-timing = synchroon na response, zie 05b-CONTEXT.md decision).
- **Klaar voor Plan 05b-03** (ResolveSnelstartAccount middleware): kan `$request->attributes->set('snelstart_connection', $connection)` zetten voor downstream audit-write die de Connection-FK invult.
- **HUB-05 REQUIREMENTS.md aanpassing** nodig bij Phase 5b-close: tekst "audit-rij in `webhook_calls`" moet naar `pass_through_calls`. Orchestrator-actie, niet 5b-01 actie.
- **Docs-sync follow-up:** nieuwe ADR moet in `.docs/README.md` index landen (regel 22 van README: "*Promoteren: losse `.md` in root → eigen subfolder zodra >3 docs samenhangen*" geldt voor `.docs/` zelf; nieuwe ADR-file in `decisions/` valt al onder de bestaande indeling). Hook gaf 3 docs-drift-triggers tijdens deze sessie (migration + model + ADR-file). De orchestrator (of een `/gsd-quick` na merge) kan `docs-sync` skill draaien — niet binnen deze plan-execute.

## TDD Gate Compliance

Plan-level type is `execute`, maar Task 2 had `tdd="true"`. Gate-sequence in git log:
1. RED: `bea7ef2` — `test(05b-01): add failing tests…` ✅
2. GREEN: `5d1a94b` — `feat(05b-01): implement App\Models\PassThroughCall + factory` ✅
3. REFACTOR: niet nodig — model + factory landen schoon zonder cleanup-pass.

## Self-Check: PASSED

Verificatie van claims:

**Files exist (worktree filesystem):**
- ✅ `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php`
- ✅ `app/Models/PassThroughCall.php`
- ✅ `database/factories/PassThroughCallFactory.php`
- ✅ `tests/Feature/PassThroughCallModelTest.php`
- ✅ `.docs/decisions/pass-through-calls-table.md` (gitignored)

**Commits exist in git log:**
- ✅ `8ed8df0` — `feat(05b-01): create pass_through_calls migration`
- ✅ `bea7ef2` — `test(05b-01): add failing tests voor PassThroughCall-model`
- ✅ `5d1a94b` — `feat(05b-01): implement App\Models\PassThroughCall + factory`

---

## ADR-tekst (gemirrord uit `.docs/decisions/pass-through-calls-table.md`)

> Reden voor mirror: zie *Deviation 1* hierboven — `.docs/` is gitignored, dus de file zelf overleeft de worktree force-removal niet. Door de volledige content hier in te bedden kan de orchestrator/user de file na merge recreëren vanuit deze SUMMARY.

```markdown
# Pass-through-calls audit-tabel — eigen tabel, niet `webhook_calls`

## Status

Geaccepteerd 2026-05-14 — Phase 5b kiest voor een nieuwe `pass_through_calls`-tabel in plaats van de bestaande Spatie `webhook_calls`-tabel.

## Keuze

Eén nieuwe Postgres-tabel `pass_through_calls`, immutable per rij, met de volgende eigenschappen:

- **Tabel**: `pass_through_calls` (provider-agnostisch via `provider`-kolom; bedoeld voor 5b Snelstart + toekomstige 5a/5c/+).
- **Kolommen** (exact uit Phase 5b plan 01, Task 1):
  - `id`, `consumer_id` (FK → `consumers.id`, cascadeOnDelete), `account_id` (FK → `accounts.id`, cascadeOnDelete), `connection_id` (FK → `connections.id`, **nullOnDelete** — audit-rij overleeft revoke/delete van de Connection).
  - `provider` (string), `method` (string(10)), `path` (text — inclusief query-string), `status` (smallint), `duration_ms` (integer).
  - `request_fingerprint` (string(12), nullable — sha256-prefix; NULL voor GET-calls), `response_size_bytes` (integer, nullable — alleen voor capacity-planning, géén body-content), `upstream_error` (string, nullable — short-code zoals `snelstart_auth`).
  - `created_at` (timestamp, `useCurrent()` DB-default).
- **Immutable**: géén `updated_at`-kolom. Eloquent-model heeft `public $timestamps = false`. Updates op de tabel zijn niet voorzien in code; eventuele DB-level constraint (Postgres `UPDATE`-trigger) is deferred — zie Consequenties.
- **3 indexen** voor query- en monitoring-patterns:
  1. `(consumer_id, created_at)` — per-Consumer chronologische audit.
  2. `(account_id, created_at)` — per-Account audit.
  3. **Partial index** `pass_through_calls_status_failures` op `(status) WHERE status >= 500` — failure-monitoring zonder een full-index op `status` te bouwen. Zelfde Postgres-pattern als `connections_account_id_provider_active_unique` uit de Phase 3 cleanup.

## Context

- **`webhook_calls` (Spatie laravel-webhook-client/server)** modelt het stream-pattern *"inkomend van partner → uitgaand naar consumer-callback"* (fan-out). De `PROJECT.md`-architectuurschets noemt `webhook_calls` ook expliciet in die rol.
- **Pass-through** is een fundamenteel ander stream-pattern: *Consumer → Hub → Partner → terug naar Consumer*, één request, één response, geen fan-out. Mengen forceert een `direction`-discriminator, een lange rij NULL-kolommen voor velden die alleen aan één pad horen, en een lastiger query-laag voor monitoring.
- **HUB-05 ROADMAP/REQUIREMENTS-tekst** (status per 2026-05-14) noemt nog *"audit-rij in `webhook_calls`"*. Deze ADR oppervlakt die spanning expliciet conform `.ai/rules/engineering.md` — *"Conflicten oppervlakken, niet uitmiddelen"* — en kiest voor een eigen tabel.
- **Phase 5a (Mollie pass-through)** komt later. Door `provider` in de tabel op te nemen kan 5a hergebruiken zonder schema-wijziging; valt die keuze later anders uit, dan is dat een 5a-beslissing.
- **`webhook_calls` blijft staan** voor zijn eigenlijke doel (inkomende partner-webhooks + uitgaande consumer-callbacks). Geen conflict op de databaselaag.

## Consequenties

- **Phase 5a kan dezelfde tabel hergebruiken** — `provider`-kolom voorziet daar al in. Wordt bij 5a-planning expliciet bevestigd of herzien.
- **Filament Phase 9 admin-UI krijgt een 5e resource** (`PassThroughCallResource`) bovenop de huidige geplande 4 (`ConsumerResource`, `ConnectionResource`, `AccountResource`, `WebhookCallResource`). Out of scope voor 5b.
- **Retention-policy is deferred**. Partitioning of cleanup-job na N maanden komt aan bod zodra data-volume meetbaar is — zie `05b-CONTEXT.md` `<deferred>`.
- **HUB-05 REQUIREMENTS.md / ROADMAP.md** verwijst nog naar `webhook_calls`. Moet bij Phase 5b-close door de orchestrator gecorrigeerd worden naar `pass_through_calls`.
- **Tampering-mitigatie blijft code-laag.** Het Eloquent-model heeft `$timestamps = false` en wordt in `PassThroughController` (Plan 05) alleen via `create()` gebruikt — geen `fill()`/`update()`-pad. Een DB-level Postgres-trigger die `UPDATE` blokkeert kan later toegevoegd worden zonder schema-breaking change.
- **Cross-tenant audit-leakage**: `consumer_id` is FK met cascadeOnDelete; bij Consumer-delete verdwijnen audit-rijen mee. Voor v0.2 acceptabel; GDPR-erasure-pad past hier al op. Async-archief is een Phase 9+ overweging.

Bron: `.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md` §`<decisions> ### Audit-log`.
```

---
*Phase: 05b-snelstart-pass-through-api*
*Plan: 01*
*Completed: 2026-05-14*
