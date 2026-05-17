---
phase: 08-naschool-wiring-snelstart-mollie-via-hub
plan: 03
subsystem: hub-admin-oauth
tags:
  - filament-action
  - oauth
  - tdd
  - rbac
  - descriptor-driven
requires:
  - OAuthFlowRegistry + MollieConnectOAuthFlow (Phase 4)
  - InitController init-flow shape (Phase 4-04 — 48-char state + 30-min TTL)
  - ProviderCredentialDescriptor (Phase 9-04, D-04)
  - Spatie laravel-permission `manage-connections` (Phase 9-03)
  - ConnectionResource + AccountResource + AccountsTable (Phase 9-06 + 9-07)
  - FakeOAuthFlow test-fixture (Phase 4-01, D-12)
provides:
  - App\Filament\Actions\StartOAuthFlowAction (shared Filament-action met forAccount() + forConnection())
  - Descriptor-driven oauthCapableProviders() whitelist — toekomst-bestendig voor v0.3+ Snelstart-OAuth / Exact-OAuth / Ibanity-OAuth
  - Mount-points op ConnectionResource (pending Mollie only) + AccountResource (alle Account-rijen voor staff met manage-connections)
affects:
  - app/Filament/Resources/Connections/ConnectionResource.php (recordActions extend — Phase-9 revoke-action intact)
  - app/Filament/Resources/Accounts/Tables/AccountsTable.php (recordActions extend — was alleen ViewAction)
tech-stack:
  added: []
  patterns:
    - static factory pattern (forAccount() / forConnection() / dispatch()) i.p.v. instantiation — consistent met Phase-9 inline Action::make()-stijl
    - public static dispatch() voor directe unit-testability zonder Livewire-mount-stack
    - try/catch InvalidArgumentException als defense-in-depth bovenop descriptor-whitelist (T-08-03-05 mitigated upstream)
key-files:
  created:
    - app/Filament/Actions/StartOAuthFlowAction.php
    - tests/Feature/Admin/StartOAuthFlowActionTest.php
  modified:
    - app/Filament/Resources/Connections/ConnectionResource.php
    - app/Filament/Resources/Accounts/Tables/AccountsTable.php
decisions:
  - "Test 10 (no-flow notification) gedropt zoals in PLAN.md voorgeschreven — `oauthCapableProviders()`-whitelist maakt het user-path onbereikbaar, en de defensive `try/catch InvalidArgumentException` is al gedekt door Phase-4 `OAuthFlowRegistryTest`. Toevoegen zou dead-code valideren via een bypass die de UI nooit triggert."
  - "`dispatch()` is `public static` i.p.v. `private static`. Reden: Tests 8 + 9 (pending Connection creation + redirect-URL met state-parameter) testen de init-flow direct zonder via Livewire-mount-stack te hoeven gaan. `Action::call()` vereist een actieve Livewire-context (`getLivewire()`), wat in een pure feature-test zonder mounted page niet beschikbaar is. Mount-tests (Task 2, Tests 11-14) gebruiken wel `Livewire::test(ListAccounts::class)` om de Filament-wiring zelf te bewijzen — die twee paden samen dekken de Action-laag én de mount-laag zonder dubbel werk."
  - "Bij `forConnection`-pad updatet `dispatch()` de bestaande Connection-rij (oauth_state + oauth_state_expires_at) i.p.v. een nieuwe pending-rij aan te maken. Dit was niet expliciet in CONTEXT.md/PATTERNS.md gespecificeerd; de keuze houdt het pending-Connection-record uniek per Account-Provider-paar zodat de Phase-4 `CallbackController` op state-resolution geen duplicaat-rijen tegenkomt. Bestaande Phase-4 `oauth:prune-pending` blijft de cleanup-route bij verloop."
  - "Mount-test voor AccountResource vereist BEIDE `manage-consumers` (canAccess op AccountResource zelf) EN `manage-connections` (visibility op de action). Dit is consistent met Phase-9 D-7-pattern: resource-toegang en action-toegang zijn aparte permissies. De negatieve test (`test_account_resource_start_oauth_flow_hidden_without_manage_connections`) geeft alleen `manage-consumers` zodat de gebruiker de tabel kan zien maar de actie niet — bewijst dat de action-visibility zelfstandig de `can('manage-connections')`-check uitvoert."
metrics:
  duration_minutes: 18
  completed_date: 2026-05-17
---

# Phase 8 Plan 03: StartOAuthFlowAction — shared Filament OAuth-init Summary

Shared Filament Action `StartOAuthFlowAction` met `forAccount()` (primary CTA) en `forConnection()` (secondary CTA, pending-only) — descriptor-driven, RBAC-gated, hergebruikt Phase-4 `OAuthFlowRegistry` zonder duplicate flow-implementatie. Twee mount-points op `ConnectionResource` + `AccountResource` (via `AccountsTable`-schema).

## What Was Built

- **`App\Filament\Actions\StartOAuthFlowAction`** — plain class (geen instantiation) met drie static methods:
  - `oauthCapableProviders(): array<string, string>` — descriptor-driven `[key => label]`-map; filtert op `oauthFlowKey !== null` zodat Snelstart automatisch ontbreekt en v0.3+ nieuwe OAuth-providers (Snelstart-OAuth / Exact / Ibanity) automatisch verschijnen door alleen `config/hub-providers.php` te updaten.
  - `forAccount(): Action` — "Koppel met provider…"-CTA met provider-Select-modal. Visible voor `can('manage-connections')`. Submit → `dispatch($record, $data['provider'])`.
  - `forConnection(): Action` — "Start OAuth-koppeling"-CTA. Visible alleen op `provider === 'mollie' && access_token === null && revoked_at === null` ÉN `can('manage-connections')`. Submit → `dispatch($record->account, $record->provider, $record)`.
  - `dispatch(Account $account, string $provider, ?Connection $existing = null): RedirectResponse` — single source-of-truth: resolve Registry → 48-char `Str::random` state → create-of-update pending Connection met 30-min TTL → `getAuthorizationUrl(...)` → `redirect()->away($url)`. `try/catch InvalidArgumentException` rondom Registry-resolve als defense-in-depth boven de dropdown-whitelist (T-08-03-05).
- **`tests/Feature/Admin/StartOAuthFlowActionTest`** — 14 PHPUnit-tests (9 Task 1 + 5 Task 2 mount-tests), 25 assertions, 1.2s wallclock:
  1. `forAccount` visible voor staff met `manage-connections`
  2. `forAccount` hidden voor staff zonder permission
  3. `forConnection` visible op pending Mollie-Connection
  4. `forConnection` hidden wanneer `access_token` gevuld
  5. `forConnection` hidden wanneer `revoked_at` gevuld
  6. `forConnection` hidden voor non-Mollie provider (Snelstart)
  7. `oauthCapableProviders()` descriptor-driven (Mollie aanwezig, Snelstart ontbreekt)
  8. `dispatch()` creëert pending Connection met 48-char `oauth_state` + ~30-min TTL
  9. `dispatch()` retourneert `RedirectResponse` met FakeOAuthFlow-authorize-URL + state-param
  10. ConnectionResource mount: action zichtbaar op pending Mollie (Livewire::test)
  11. ConnectionResource: action hidden wanneer `access_token` aanwezig
  12. ConnectionResource: Phase-9 revoke-action intact (regressie-bewijs)
  13. AccountResource mount: action zichtbaar voor staff met beide permissies
  14. AccountResource: action hidden zonder `manage-connections`
- **`ConnectionResource::table()` extend** — `StartOAuthFlowAction::forConnection()` ingevoegd tussen `ViewAction::make()` en `Action::make('revoke')`. Bestaande revoke-action niet aangeraakt.
- **`AccountsTable::configure()` extend** — `StartOAuthFlowAction::forAccount()` toegevoegd na `ViewAction::make()`. `AccountResource.php` zelf ongewijzigd (Phase-9 schema-class-pattern).

## Decisions Made

- **Plan-conform: Test 10 dropped.** PLAN.md `<behavior>`-blok (regel 171) markeert Test 10 expliciet als dropped — `oauthCapableProviders()`-whitelist op de dropdown maakt het no-flow-pad voor de gebruiker onbereikbaar, en de defensive `try/catch` is al gedekt door Phase-4 `OAuthFlowRegistryTest`. Geen Phase-8-test toegevoegd voor dead UI-code.
- **`dispatch()` is `public static`** zodat Tests 8 + 9 het direct kunnen aanroepen. `Action::call(['provider' => 'mollie'])` vereist een actieve Livewire-context (`getLivewire()`), wat in een pure feature-test zonder mounted page niet werkt. Mount-tests (Tests 10-14) bewijzen via `Livewire::test()` dat de Action ook correct gewired is — die twee paden samen dekken Action-laag + mount-laag zonder dubbel werk.
- **`forConnection`-pad updatet bestaande pending Connection** i.p.v. een nieuwe rij aan te maken. Niet expliciet voorgeschreven in CONTEXT.md/PATTERNS.md; gekozen om Phase-4 `CallbackController` state-resolution op een uniek pending-record te laten landen. `oauth:prune-pending` blijft cleanup-route bij verloop.
- **`actingAs($staff)` in mount-test vereist 2 permissies**: `manage-consumers` (AccountResource canAccess) + `manage-connections` (action visible). Negatieve test geeft alleen `manage-consumers` — bewijst dat de action-visibility-check zelfstandig draait.

## Deviations from Plan

None — plan executed exactly as written, inclusief de in PLAN.md `<behavior>` voorgeschreven Test-10-drop. Drie keuzes onder "Decisions Made" zijn binnen de plan-instructies gemaakt waar PLAN.md ruimte liet (`dispatch` zichtbaarheid, `forConnection`-update-vs-create, mount-test permissions); geen Rule-1/2/3-deviaties nodig.

## Threat Model Validation

| Threat | Disposition | Validation |
|--------|-------------|------------|
| T-08-03-01 (Cross-Consumer Connection-creation via EoP) | mitigate | Action callbacks gebruiken `$account->connections()->create(...)` (geen mass-assign van `account_id`). Tests 1+2 + 13+14 dekken `actingAs($staffWithoutPermission)` → action verborgen. |
| T-08-03-02 (OAuth state-forgery) | mitigate | `Str::random(48)` + 30-min TTL conform Phase-4 InitController-pattern. Test 8 bewijst state-shape. Existing Phase-4 `CallbackControllerTest` dekt verify-pad. |
| T-08-03-03 (Authorize-URL leak via Notification) | accept | `redirect()->away($url)` is directe browser-redirect — geen Notification-body met URL. URL bevat geen credentials (alleen publieke client_id + state + redirect_uri). |
| T-08-03-04 (Spam pending Connections) | accept | Action alleen open voor authenticated staff met `manage-connections`. `oauth:prune-pending` artisan-command (Phase 4-05) ruimt expired rijen op. |
| T-08-03-05 (Onbekende provider in form-submit) | mitigate (upstream) | `Select::make('provider')->options(self::oauthCapableProviders())` whitelist-dropdown levert alleen valid keys. `try/catch InvalidArgumentException` in `dispatch()` blijft als defense-in-depth (geen Phase-8 test — Phase-4 `OAuthFlowRegistryTest` dekt de registry-laag). |

## Verification

- `php artisan test --compact --filter=StartOAuthFlowActionTest` → 14 passed / 25 assertions / 1.2s
- `php artisan test --compact --filter='StartOAuthFlowAction|ConnectionResource|AccountResource'` → 17 passed / 35 assertions / 1.5s
- `php artisan test --compact tests/Feature/Admin/` → 100 passed / 366 assertions / 6.4s (Phase-9 admin-regressie clean — revoke-action + Filament-install-smoke + permission-gating + RelationManagers-render allemaal groen)
- `vendor/bin/pint --test --format agent app/Filament/Actions/ app/Filament/Resources/Connections/ConnectionResource.php app/Filament/Resources/Accounts/Tables/AccountsTable.php` → passed (zero drift)

## Acceptance Criteria

Plan `<acceptance_criteria>`:

Task 1:
- ✅ `app/Filament/Actions/StartOAuthFlowAction.php` met namespace `App\Filament\Actions`
- ✅ `grep -c "public static function forAccount\|public static function forConnection\|public static function oauthCapableProviders"` == 3
- ✅ `grep -c "OAuthFlowRegistry"` ≥ 1 (= 3)
- ✅ `grep -c "ProviderCredentialDescriptor"` ≥ 1 (= 3)
- ✅ `grep -c "Str::random(48)"` ≥ 1 (= 1)
- ✅ `grep -c "manage-connections"` ≥ 2 (= 2)
- ✅ `tests/Feature/Admin/StartOAuthFlowActionTest.php` ≥ 9 test-methods (= 14 cumulatief)
- ✅ `php artisan test --compact --filter=StartOAuthFlowActionTest` exit 0 met ≥ 9 passed (= 14)

Task 2:
- ✅ `ConnectionResource.php` heeft `use App\Filament\Actions\StartOAuthFlowAction;`
- ✅ `grep -c "StartOAuthFlowAction::forConnection" ConnectionResource.php` ≥ 1
- ✅ `AccountsTable.php` heeft `use App\Filament\Actions\StartOAuthFlowAction;`
- ✅ `grep -c "StartOAuthFlowAction::forAccount" AccountsTable.php` ≥ 1
- ✅ Bestaande revoke-action intact (`grep -c "Action::make('revoke')" ConnectionResource.php` = 1)
- ✅ Filter-test exit 0
- ✅ Geen Phase-9 regressies in admin-suite

Plan-overarching `<success_criteria>`: alle 9 punten gedekt (shared action / descriptor-driven / RBAC / connection-visibility-rules / account-mount-via-schema / Phase-4-pattern-hergebruik / redirect-met-state / geen Phase-4-controller-wijzigingen / geen Phase-9 regressies).

## Known Stubs

None — geen UI-stubs of placeholders.

## Self-Check: PASSED

Files exist:
- ✅ FOUND: app/Filament/Actions/StartOAuthFlowAction.php
- ✅ FOUND: tests/Feature/Admin/StartOAuthFlowActionTest.php
- ✅ FOUND: app/Filament/Resources/Connections/ConnectionResource.php (modified)
- ✅ FOUND: app/Filament/Resources/Accounts/Tables/AccountsTable.php (modified)

Commits exist on chore/v021-phase9-polish:
- ✅ FOUND: b84de3b test(08-03): voeg failing tests toe voor StartOAuthFlowAction
- ✅ FOUND: d4d2b5b feat(08-03): implementeer StartOAuthFlowAction shared Filament-action
- ✅ FOUND: e238ff6 test(08-03): voeg failing mount-tests toe voor ConnectionResource + AccountResource
- ✅ FOUND: dc9b181 feat(08-03): mount StartOAuthFlowAction op ConnectionResource + AccountResource

## Commits

| Hash | Type | Description |
|------|------|-------------|
| b84de3b | test | Failing tests voor StartOAuthFlowAction (RED phase Task 1, 9 tests) |
| d4d2b5b | feat | Implementeer StartOAuthFlowAction shared Filament-action (GREEN Task 1) |
| e238ff6 | test | Failing mount-tests voor ConnectionResource + AccountResource (RED Task 2, +5 tests) |
| dc9b181 | feat | Mount StartOAuthFlowAction op beide resources (GREEN Task 2) |
