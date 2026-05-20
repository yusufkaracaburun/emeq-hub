---
phase: 14-naschool-live-e2e
plan: 02
subsystem: naschool-support-classes
tags: [stancl-tenancy, multi-tenant, http-client, jsonb-data-column, cross-repo]

requires:
  - phase: 14-naschool-live-e2e
    plan: 01
    provides: EmeqHubConfig (env-driven base_url + pat + naschool_account_id)
provides:
  - StancltenancyCredentialResolver — per-tenant X-Account-Id uit Stancl data-jsonb (geen migration)
  - EmeqHubClient — thin HTTP-wrapper rond Http::-facade die Bearer-PAT + X-Account-Id auto-attached
affects: [14-03 listener-DI consumeert EmeqHubClient]

tech-stack:
  added: []
  patterns: [virtual-attribute access via Stancl data-jsonb, Http::fake wiring-test pattern voor cross-repo SDK-wrapper]

key-files:
  created:
    - "[NASCHOOL-REPO] app/Support/EmeqHub/StancltenancyCredentialResolver.php"
    - "[NASCHOOL-REPO] app/Support/EmeqHub/EmeqHubClient.php"
    - "[NASCHOOL-REPO] tests/Feature/Support/EmeqHub/StancltenancyCredentialResolverTest.php"
    - "[NASCHOOL-REPO] tests/Feature/Support/EmeqHub/EmeqHubClientTest.php"
  modified: []

key-decisions:
  - "Resolver gebruikt Stancl's virtual-attribute pattern (\\$tenant->emeq_hub_account_id) i.p.v. raw \\$tenant->data['emeq_hub_account_id'] — Stancl mapt automatisch naar data-jsonb omdat de key niet in Tenant::getCustomColumns() staat. Equivalente jsonb-route, cleaner accessor."
  - "Geen Naschool-migration toegevoegd — verifieerbaar via `git status` op feature-branch. Tenant-attribute-route LOCKED gevolgd."
  - "EmeqHubClient zonder retry-logica — Horizon-job in Plan 14-03 doet retry op listener-niveau (single retry-point)."
  - "Body-truncate op 500 chars in exception-message + assertStringNotContainsString(PAT, message) als security-bewijs (geen credential-leak in Sentry/logs)."

patterns-established:
  - "Cross-repo Hub-consumer Support-class pattern: EmeqHubConfig + Resolver + Client = drie kleine final classes met constructor-DI, herbruikbaar voor toekomstige Naschool features (refunds, customer-create, subscriptions)"
  - "Multi-tenant test-pattern in Naschool: Tenant::create met emeq_hub_account_id key in extra-args → automatisch in data-jsonb → tenancy()->initialize() → assert"

requirements-completed: [NSCH-05]

duration: ~20 min
completed: 2026-05-20
---

# Phase 14 Plan 02: StancltenancyCredentialResolver + EmeqHubClient Summary

**Naschool heeft nu een per-tenant X-Account-Id resolver (Stancl data-jsonb route, geen migration) én een herbruikbare HTTP-client die EmeqHubConfig + resolver bundelt voor Mollie + Snelstart pass-through — klaar voor de listener in Plan 14-03.**

## Performance

- **Duration:** ~20 min
- **Tasks:** 2
- **Files modified:** 4 nieuw, 0 gemodificeerd
- **Tests:** 8 passed (4 resolver, 4 client), 16 assertions, ~2.4s totaal

## Accomplishments

- Resolver leest expliciet uit `$tenant->emeq_hub_account_id` (Stancl virtual-attribute → data-jsonb mapping omdat key niet in `Tenant::getCustomColumns()`). Multi-tenant switch-test bewijst tenant A → acc_AAA, tenant B → acc_BBB zonder state-leak.
- Throw-tests dekken: outside-tenant-context + missing-jsonb-key (met tenant-id in error-message als debug-hint).
- EmeqHubClient bundelt Config + Resolver in één DI-resolvable final class met twee methodes (Mollie + Snelstart). Http::fake-tests bewijzen Bearer + X-Account-Id headers per endpoint.
- Security-bewijs: 4xx/5xx response-throws bevatten exacte HTTP-status maar **geen PAT** (`assertStringNotContainsString(self::PAT, $e->getMessage())`).

## Task Commits

Atomic per-task op feature-branch `feat/nsch-04-emeq-hub-foundation` in Naschool:

1. **Task 1: StancltenancyCredentialResolver + unit-test** — `e3e680b4` (feat: `feat(emeq-hub): StancltenancyCredentialResolver per-tenant X-Account-Id mapping via Stancl data-jsonb`)
2. **Task 2: EmeqHubClient + Http::fake-wiring-test** — `c8ef284d` (feat: `feat(emeq-hub): EmeqHubClient HTTP-wrapper voor Mollie + Snelstart pass-through`)

## Files Created/Modified

### Naschool (`/Users/yusufkaracaburun/Sites/localhost/school-activities-hub/backend/`)

- `app/Support/EmeqHub/StancltenancyCredentialResolver.php` — `final class` met één publieke methode `accountIdForCurrentTenant(): string`. Reads `tenancy()->tenant->emeq_hub_account_id`. Throws `RuntimeException` outside tenant-context of bij missing jsonb-key.
- `app/Support/EmeqHub/EmeqHubClient.php` — `final class` met constructor-DI voor `EmeqHubConfig` + `StancltenancyCredentialResolver`. `createMolliePayment(array): array` + `createSnelstartVerkoopfactuur(array): array`. Pint auto-applied: `mb_rtrim` voor base-URL-trim, brace-position fixes.
- `tests/Feature/Support/EmeqHub/StancltenancyCredentialResolverTest.php` — 4 tests (happy + no-tenant + no-jsonb-key + multi-tenant switch).
- `tests/Feature/Support/EmeqHub/EmeqHubClientTest.php` — 4 Http::fake-tests (Mollie header-assert + Snelstart header-assert + 4xx throw + 5xx throw met PAT-leak-check).

## Tested / Verified

- `php artisan test --compact --filter=StancltenancyCredentialResolverTest`: 4 passed, 8 assertions, 1179ms.
- `php artisan test --compact --filter=EmeqHubClientTest`: 4 passed, 8 assertions, 1207ms.
- `git status` op feature-branch toont **geen** nieuwe migration in `database/migrations/` (acceptance Task-1 #3 ✓).
- `assertStringNotContainsString(self::PAT, $e->getMessage())` slaagt op zowel 4xx als 5xx throws (acceptance Task-2 #4 ✓).
- Pint exit 0 op alle nieuwe files (acceptance Task-2 #5 ✓).

## Deviations from Plan

Geen deviaties. Stancl virtual-attribute vs raw `data['...']` is plan-conform ("gebruik die accessor maar blijf op de jsonb-route").

## Self-Check: PASSED

- [x] Resolver-class met één publieke methode `accountIdForCurrentTenant(): string`
- [x] Resolver leest uit Stancl jsonb-route (virtual-attribute) — geen dedicated column, geen migration
- [x] 8/8 tests passed (4 resolver + 4 client)
- [x] Multi-tenant switch-test bewijst geen state-leak
- [x] Http::fake-tests bevestigen Bearer + X-Account-Id headers per endpoint
- [x] Non-2xx-throws bevatten geen PAT
- [x] Atomic per-task commits op Naschool feature-branch
- [x] Hub-repo: alleen dit SUMMARY (geen code-edits)

Plan 14-03 (listener) kan EmeqHubClient via DI consumeren voor de Snelstart-Verkoopfactuur-creation in `SyncEnrollmentToSnelstartJob`.
