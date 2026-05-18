---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
plan: 01
subsystem: database
tags: [migration, webhook, audit-columns, sqlite, postgres, foreign-key, spatie-webhook-client]

# Dependency graph
requires:
  - phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
    provides: Spatie webhook-server/client geland; `webhook_calls`-tabel bestaat met default-shape
  - phase: 03-hub-skeleton
    provides: `consumers`-tabel waar consumer_id-FK naar verwijst
provides:
  - 4 audit-kolommen op `webhook_calls`: direction/provider/consumer_id/status met defaults voor backwards-compat
  - Worktree-bootstrap-fix in `tests/TestCase.php` (APP_BASE_PATH-overschrijving)
  - SQLite-compatibele migratie-pattern voor ALTER-TABLE-ADD-FK (FK alleen op Postgres)
affects: [09-08-webhookcallresource, 05c-snelstart-webhook-handler]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Driver-conditional FK-constraint voor SQLite-test-compat"
    - "Worktree-bootstrap APP_BASE_PATH-override in TestCase"

key-files:
  created:
    - database/migrations/2026_05_19_000001_add_audit_columns_to_webhook_calls_table.php
    - tests/Feature/Models/WebhookCallAuditColumnsTest.php
  modified:
    - tests/TestCase.php

key-decisions:
  - "FK-constraint op consumer_id alleen voor Postgres-driver (SQLite skip wegens ALTER-TABLE-ADD-FK destructieve __temp__-rebuild)"
  - "TestCase override van createApplication zet APP_BASE_PATH naar worktree-root (vendor-symlink-fix)"
  - "down() bevat NL-comment over forward-only-prod-policy; FK-drop ook driver-conditional"

patterns-established:
  - "Driver-check via Schema::getConnection()->getDriverName() voor SQLite/Postgres-split"
  - "Centrale TestCase-fix voor worktree-mode (vendor-symlink → main repo basePath-drift)"

requirements-completed: [HUB-04]

# Metrics
duration: ~35min
completed: 2026-05-16
---

# Phase 09 Plan 01: WebhookCall audit-kolommen migratie Summary

**4 additieve audit-kolommen (direction/provider/consumer_id/status) op `webhook_calls` met SQLite-compat-FK-split en RefreshDatabase-tests die Spatie's legacy-shape + nieuwe full-audit-shape bewijzen**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-05-15T21:47:00+02:00
- **Completed:** 2026-05-16T00:05:00+02:00
- **Tasks:** 2 (TDD-paired: migratie + feature-test)
- **Files modified:** 3 (1 created migration, 1 created test, 1 modified TestCase voor Rule-3 fix)

## Accomplishments
- Additive-only migratie voor `webhook_calls` met 4 audit-kolommen — bestaande Spatie-rijen blijven valide via defaults
- 3 feature-tests dekken schema-aanwezigheid, full-audit-row persist, en legacy-shape-compat (T-09-01-01 mitigated)
- Worktree-bootstrap-pattern centraal opgelost in `tests/TestCase.php` — toekomstige worktree-tests vinden recente migraties zonder ad-hoc bootstrap
- SQLite-portabel migratie-pattern: FK-constraint conditional op Postgres-driver

## Task Commits

Atomic per-task + per-deviation commits:

1. **Task 1: Additive migratie** — `bfb8e63` (feat) — initial migration met 4 kolommen + FK
2. **Task 1 follow-up: SQLite-FK-fix** — `cafeb7f` (fix, Rule-1) — splits FK-constraint van kolom-add voor SQLite-portability
3. **Rule-3 deviation: TestCase fix** — `264745b` (fix, Rule-3) — APP_BASE_PATH-override voor vendor-symlink worktree-mode
4. **Task 2: Feature-test** — `cd70978` (test) — 3 tests groen (schema + full-audit-row + legacy-shape)

## Files Created/Modified
- `database/migrations/2026_05_19_000001_add_audit_columns_to_webhook_calls_table.php` (new) — 4 audit-kolommen + Postgres-only FK
- `tests/Feature/Models/WebhookCallAuditColumnsTest.php` (new) — 3 feature-tests
- `tests/TestCase.php` (modified) — createApplication-override zet APP_BASE_PATH zodat database_path() in worktree-mode klopt

## Decisions Made

1. **FK-constraint driver-conditional** — Postgres-prod krijgt DB-level `consumer_id → consumers(id) ON DELETE SET NULL`; SQLite-tests slaan FK over wegens Laravel's destructieve ALTER-TABLE-ADD-FK temp-table-rebuild. App-laag enforced Consumer-delete-invariant via Filament-admin (Plan 09-10 gated). Threat T-09-01-02 was al `accept` — DB-level FK was "nice-to-have" niet "must-have".
2. **Worktree-bootstrap fix in centrale TestCase** — alternatief was per-test `APP_BASE_PATH` zetten, maar dat is fragiel + dupliceert state-management. STATE.md noteert dit al als recurring backlog-item.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] SQLite ALTER-TABLE-ADD-FK destructive rebuild wist nieuw toegevoegde kolommen**
- **Found during:** Task 2 (eerste test-run faalde — `webhook_calls has no column named direction`)
- **Issue:** Plan-action specificeert `$table->foreignId('consumer_id')->constrained('consumers')->nullOnDelete()` in een eigen `Schema::table`-block. SQLite ondersteunt geen ALTER-TABLE-ADD-FK natively; Laravel valt terug op een `__temp__`-rebuild. Die rebuild creëert het temp-table ALLEEN met de net toegevoegde kolommen — `direction` en `provider` werden in de eerste `Schema::table` calls toegevoegd maar bestonden tijdens de rebuild nog niet in het Blueprint-schema en werden zo overschreven, waardoor `direction` na `migrate:fresh` op SQLite ontbrak.
- **Fix:** Migratie gesplitst in twee stappen — stap 1 voegt alle 4 kolommen toe als plain `unsignedBigInteger` (geen FK); stap 2 voegt FK alleen toe als `getDriverName() !== 'sqlite'`. Down() spiegelt deze split.
- **Files modified:** `database/migrations/2026_05_19_000001_add_audit_columns_to_webhook_calls_table.php`
- **Verification:** `php artisan migrate:fresh` op Postgres slaagt + FK aanwezig; SQLite-tests slagen + 4 kolommen aanwezig (PRAGMA-verify); 340/340 tests groen.
- **Committed in:** `cafeb7f`

**2. [Rule 3 - Blocking] vendor-symlink in worktree-mode breekt Application::inferBasePath**
- **Found during:** Task 2 (`Schema::hasColumn('webhook_calls', 'direction')` returnde false in test, ondanks dat directe `php artisan migrate:fresh` 4 kolommen toonde)
- **Issue:** `vendor/` is gesymlinked naar de main repo (worktree-bootstrap-pattern). `Application::inferBasePath()` resolved via `ClassLoader::getRegisteredLoaders()` — die ziet de symlink-target en geeft het main-repo-pad terug. `database_path('migrations')` wees daardoor naar de main repo's migrations-directory, waar de nieuwe 2026_05_19-migratie nog niet bestond. Tests rapporteerden silent-skip (migration table mist alleen 09-01-migratie). STATE.md `Pending Todos` noteert dit al als "Worktree-bootstrap-pattern (recurring)" backlog-item.
- **Fix:** `tests/TestCase.php::createApplication()` zet `$_ENV['APP_BASE_PATH']` + `$_SERVER['APP_BASE_PATH']` op `dirname(__DIR__)` (worktree-root) vóór `parent::createApplication()`. `Application::inferBasePath()` matched op `APP_BASE_PATH`-env-var en gebruikt die met voorrang op symlink-resolutie.
- **Files modified:** `tests/TestCase.php`
- **Verification:** `php artisan test --compact` — 340/340 groen, geen regressie op andere test-classes. Worktree-tests vinden nu de juiste migrations-dir.
- **Committed in:** `264745b`

---

**Total deviations:** 2 auto-fixed (1 Rule-1 bug, 1 Rule-3 blocking)
**Impact on plan:** Beide deviations essentieel voor test-suite groen. SQLite-FK-split heeft secundair effect dat DB-level FK alleen Postgres-prod beschermt; threat-model (T-09-01-02) had die invariant al als `accept` geclassificeerd. TestCase-fix is een netto-positieve infra-fix voor het hele worktree-flow.

## Issues Encountered

- **Eerste failure-mode was misleidend** — `php artisan migrate:fresh` op host (Postgres) slaagde met alle 4 kolommen + FK, maar test-suite op SQLite-`:memory:` faalde silent (kolommen ontbraken). Root-cause-isolatie vereiste twee verschillende debug-passes (`/tmp/audit_migration_ran.log` voor migration-up()-tracing + `paths/registered`-debug in test om migrator-discovery-bug te onthullen). Lesson voor planning: explicitiet de "drie-vleugel"-test (Postgres-prod migrate + SQLite-test fresh-migrate + RefreshDatabase-test) tijdens plan-design noteren bij migraties met FK-constraints.

## Known Stubs

None — alle 4 kolommen worden in Phase 5c (Snelstart webhook-handler in progress) en Plan 09-08 (WebhookCallResource) gevuld/gelezen.

## Threat Flags

Geen nieuwe surface buiten threat-model:

- Direction/provider/status/consumer_id zijn non-secret metadata (T-09-01-03 accept).
- Geen network-endpoints, auth-paths of file-access-patterns toegevoegd.
- `consumer_id` FK trust-boundary blijft binnen de bestaande Hub-tenant-scope.

## User Setup Required

None — pure schema-wijziging + tests. Geen env-vars, geen externe service-config.

## Next Phase Readiness

- **Plan 09-08 (WebhookCallResource)** kan nu op stabiele tabel-shape bouwen (direction/provider/status filters + consumer.slug-relation).
- **Phase 5c (Snelstart webhook-handler, in progress)** zal `direction='incoming'` + `provider='snelstart'` + `consumer_id` natuurlijk gaan vullen zodra ze landt — geen extra migratie nodig.
- **Worktree-bootstrap-fix in TestCase** ontblokt parallelle wave-execution voor toekomstige phases zonder per-agent bootstrap-script.
- **Docs-sync trigger** — PostToolUse-hook signaleerde dat migration-files mogelijk stale doc-references achterlaten. Phase-09-close (Plan 09-12) draait `docs-sync` skill om ADR `.docs/decisions/` + CLAUDE.md + memory te valideren tegen de geactualiseerde tabel-shape.

## Self-Check: PASSED

**Files exist:**
- FOUND: database/migrations/2026_05_19_000001_add_audit_columns_to_webhook_calls_table.php
- FOUND: tests/Feature/Models/WebhookCallAuditColumnsTest.php
- FOUND: tests/TestCase.php (modified)

**Commits exist (git log --oneline -5):**
- FOUND: bfb8e63 — feat(09-01): voeg 4 audit-kolommen toe aan webhook_calls
- FOUND: cafeb7f — fix(09-01): splits FK-constraint van kolom-add voor SQLite-portability
- FOUND: 264745b — fix(09-01): worktree-bootstrap APP_BASE_PATH-overschrijving in TestCase
- FOUND: cd70978 — test(09-01): feature-test voor webhook_calls audit-kolommen

**Verification commands passed:**
- `php artisan migrate:fresh --no-interaction` → exit 0 (all migrations DONE)
- `php artisan test --compact --filter=WebhookCallAuditColumnsTest` → 3 passed, 14 assertions
- `php artisan test --compact` → 340 passed, 1 incomplete (pre-existing Phase 5b placeholder), 0 failed
- `vendor/bin/pint --dirty --format agent` → passed

---
*Phase: 09-filament-admin-ui-voor-emeq-medewerkers*
*Completed: 2026-05-16*
