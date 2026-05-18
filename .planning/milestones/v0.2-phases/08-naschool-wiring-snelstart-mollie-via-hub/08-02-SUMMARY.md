---
phase: 08-naschool-wiring-snelstart-mollie-via-hub
plan: 02
subsystem: hub-admin-onboarding
tags:
  - filament-page
  - wizard
  - tdd
  - rbac
  - descriptor-driven
  - no-secret-leak
requires:
  - App\Services\ConsumerOnboarding (PLAN 08-01)
  - App\Filament\Actions\StartOAuthFlowAction (PLAN 08-03, voor post-wizard Mollie-OAuth UX-split)
  - App\Support\ProviderCredentialDescriptor (PLAN 09-04, D-04)
  - ConsumerResource::PAT_PRESETS (Phase 9, plan 09-05)
  - Spatie laravel-permission `manage-consumers` (Phase 9, plan 09-03)
provides:
  - App\Filament\Pages\OnboardConsumer (standalone Filament Page met 4-staps Wizard)
  - resources/views/filament/pages/onboard-consumer.blade.php (form-wrapper)
  - ListConsumers 'Onboarden' header-action (launch-pad richting wizard)
  - Eerste Filament Page-subclass in app/Filament/Pages/ (pattern voor toekomstige standalone pages)
affects:
  - app/Filament/Resources/Consumers/Pages/ListConsumers.php (additieve header-action; bestaande CreateAction intact)
tech-stack:
  added: []
  patterns:
    - Wizard + 4 Step components binnen één standalone Filament Page
    - Cache-flash one-shot pattern op redirect-target (consistent met ConsumerResource::issuePatAction)
    - Descriptor-driven Step 3 provider-keuze + conditional sub-form via Group::visible()
    - No-secret-leak invariant via afwezigheid van public property (geen wire:snapshot leak)
key-files:
  created:
    - app/Filament/Pages/OnboardConsumer.php
    - resources/views/filament/pages/onboard-consumer.blade.php
    - tests/Feature/Admin/OnboardConsumerTest.php
  modified:
    - app/Filament/Resources/Consumers/Pages/ListConsumers.php
decisions:
  - "Plain webhook_callback_secret + plain PAT flashed naar ListConsumers-redirect-target i.p.v. eigen blade-view: consistent met Phase-9 Issue-PAT-pattern. Onboard-blade rendert alleen het form; alle one-shot-display centraliseert in list-consumers.blade.php. Voorkomt duplicate Cache::pull-blocks en wijst staff direct naar de nieuwe Consumer-rij na success."
  - "Mollie-branch in Stap 3 maakt pending Connection-stub binnen de wizard (status='pending', geen access_token); OAuth-roundtrip gebeurt na wizard-completion via StartOAuthFlowAction::forAccount() op de Account-detailpagina. Per D-04 UX-split: wizard rondt onboarding atomisch af zonder mid-flow redirect, OAuth-koppeling gebeurt waar hij van nature thuishoort."
  - "No-secret-leak invariant geverifieerd via Cache::spy() + property_exists()-assertion i.p.v. assertDontSee. Cache-spy bewijst dat de submit-handler exact 1× Cache::put doet met de pat-flash-key, en property_exists bewijst dat de Page-instance plain-token niet als wire:snapshot leak'baar veld bewaart. Functioneel sterker dan een assertDontSee na 60s TTL-wait."
  - "Webhook_callback_secret-flash gebeurt zowel bij auto-generated als bij staff-supplied secret. Reden: secret is daarna alleen rotate-able (encrypted at rest); staff moet het eenmalig terugzien om te bewaren — ook als hij het zelf typte, want het revealable-veld is na step-advance niet meer leesbaar zonder server-roundtrip."
  - "PAT-preset default = 'admin' i.p.v. 'mollie-read'. Reden: onboard-wizard is voor staff-bootstrap, niet voor consumer-self-service; staff geeft een initieel breed token uit en versmalt later via Issue-PAT. Mollie-read is een te smalle default voor een Naschool-achtige use-case die zowel Snelstart als Mollie aanspreekt."
metrics:
  duration_minutes: 14
  completed_date: 2026-05-17
---

# Phase 8 Plan 02: Filament Consumer-onboard-wizard Summary

Standalone Filament Page met 4-staps Wizard (Consumer → Eerste Account → Eerste Connection → PAT uitgeven) die de Phase-3 onboarding-flow atomisch uitvoert via `ConsumerOnboarding` (PLAN 08-01), descriptor-driven via `ProviderCredentialDescriptor::all()`, en no-secret-leak invariant via Cache-flash naar de ListConsumers-redirect-target.

## What Was Built

- **`app/Filament/Pages/OnboardConsumer.php`** — eerste standalone `Filament\Pages\Page`-subclass in de repo. 4-staps `Wizard`-component met:
  - **Stap 1 Consumer**: `name` + `slug` (unique-validated met NL-message) + optionele `webhook_callback_url` + optionele `webhook_callback_secret` (auto-generated via `Str::random(48)` als leeg)
  - **Stap 2 Eerste Account**: `external_id` + `display_name` (beide required)
  - **Stap 3 Eerste Connection**: `Radio` provider-keuze descriptor-driven (`ProviderCredentialDescriptor::all()`) + conditioneel `Group::visible()`-blok per provider (Snelstart = 3 credential-velden met `password()->revealable()`, Mollie = helper-text + pending Connection-stub)
  - **Stap 4 PAT uitgeven**: `Radio` preset-keuze met `ConsumerResource::PAT_PRESETS` + conditional `CheckboxList` voor custom-mode + `token_name` (default `onboard-default`)
  - `canAccess()` + `shouldRegisterNavigation()` gate'd op `manage-consumers` (Phase-9 RBAC-pattern)
  - `submit()` delegeert naar `ConsumerOnboarding::onboard()` binnen try/catch; bij failure → `Notification::danger()` met UI-SPEC-copy; bij success → Cache-flash plain PAT + plain webhook-secret + redirect naar `ConsumerResource::getUrl()`
- **`resources/views/filament/pages/onboard-consumer.blade.php`** — minimal wrapper rond `{{ $this->form }}` binnen `<x-filament-panels::page>`. Form-submit via `wire:submit="submit"`. Geen Cache-flash-blok hier — eenmalige display gebeurt op de ListConsumers-redirect-target voor consistency met Phase-9 Issue-PAT-pattern.
- **`app/Filament/Resources/Consumers/Pages/ListConsumers.php` (modified)** — extra header-action `'onboard'` (Heroicon `OutlinedSparkles`) vóór de bestaande `CreateAction`, met `visible(fn () => OnboardConsumer::canAccess())`. Bestaande CreateAction blijft intact.
- **`tests/Feature/Admin/OnboardConsumerTest.php`** — 9 PHPUnit-tests, 35 assertions, ~1.5s wallclock:
  1. RBAC unauthorized — `canAccess()` false + page-route 403 zonder `manage-consumers`
  2. RBAC authorized — page rendert 200 met permission
  3. Page rendert Wizard + 4 step-titels (canonical NL-copy uit UI-SPEC §S1)
  4. Stap 3 provider-opties komen uit `ProviderCredentialDescriptor` (mollie + snelstart aanwezig)
  5. ListConsumers `'onboard'` header-action visible voor staff met permission
  6. ListConsumers header-action hidden zonder permission (via `canAccess()`-gate)
  7. Happy-path submit → Consumer + Account + Connection (Snelstart) + PAT-rijen + `assertHasNoFormErrors`
  8. No-secret-leak (PAT) — `Cache::spy` bewijst 1× `pat-flash:`-put + `property_exists` weerlegt wire:snapshot leak
  9. No-secret-leak (webhook-secret) — `Cache::spy` bewijst 1× `webhook-secret-flash:`-put + raw DB-value ≠ plain (encrypted-at-rest)

## Why This Matters

PLAN 08-02 levert de eerste Filament-Page-subclass van de repo (`app/Filament/Pages/` bevatte alleen de auto-discovered Dashboard via vendor) en valideert het pattern voor toekomstige standalone administratieve pagina's zoals de eerder besproken provider-status-dashboards. Tegelijk maakt het de Phase-3 onboarding-flow toegankelijk voor Emeq-staff zonder tinker of CLI — het was tot nu toe alleen via `hub:consumer:create` te bereiken, wat geen Account/Connection/PAT-combinatie in één atomic stap deed.

De wizard hergebruikt drie producten van eerdere plans: `ConsumerOnboarding` (PLAN 08-01, DB::transaction-pattern), `StartOAuthFlowAction` (PLAN 08-03, voor post-wizard Mollie-OAuth via UX-split per D-04), en `ProviderCredentialDescriptor` (PLAN 09-04, descriptor-driven Stap 3). Geen duplicate provider-switch in de wizard zelf: een nieuwe provider toevoegen vereist alleen een `config/hub-providers.php`-row en (optioneel) extra credential-velden in de Snelstart-achtige conditional-branch. Mollie-achtige OAuth-providers krijgen automatisch de pending-stub-branch via de `oauthFlowKey !== null`-check elders in de codebase.

## Decisions Made

- **Cache-flash op redirect-target i.p.v. eigen blade-view**: onboard-blade rendert alleen `{{ $this->form }}`; alle one-shot-secret-display centraliseert op `ListConsumers` zoals Phase-9 Issue-PAT. Verlaagt code-duplication en wijst staff direct naar de nieuwe Consumer-rij.
- **Mollie-branch UX-split (per D-04)**: wizard maakt pending Connection-stub (geen mid-flow redirect-roundtrip naar Mollie), OAuth gebeurt na completion via `StartOAuthFlowAction::forAccount()` op de Account-detailpagina. Houdt de wizard atomisch en plaatst OAuth-koppeling waar hij hoort.
- **`Cache::spy()` + `property_exists()` i.p.v. `assertDontSee`**: functioneel sterker bewijs van no-secret-leak. Spy weet zeker dat de plain-token via Cache-flash gaat (en niet ergens in HTML zit); `property_exists` weerlegt definitief een wire:snapshot leak via Page-property. Was de letterlijke acceptance-grep `assertDontSee ≥ 2`; vervangen door Spy + property check (zie deviation hieronder).
- **Webhook_callback_secret-flash bij beide paden**: staff-supplied OF auto-generated, beide flashen. Reden: secret is na opslag alleen via rotate-action terug op te halen; staff moet zelf-getypte secret ook eenmalig kunnen kopiëren.
- **PAT-preset default = `'admin'`**: onboard is staff-bootstrap-tool, niet self-service. Initieel breed token, later versmallen via Issue-PAT (Phase 9).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Plan refereerde naar non-existing `ProviderCredentialDescriptor::$label`-property**
- **Found during:** Task 1 — descriptor-driven Step 3 implementatie
- **Issue:** PLAN.md `<action>` regel 173 schreef `mapWithKeys(fn ($d) => [$d->key => $d->label])` voor de provider-radio. `App\Support\ProviderCredentialDescriptor` heeft alleen `key`, `encryptedFields`, `primaryFingerprintLabel`, `oauthFlowKey` — geen `label`-property. Identiek probleem als in PLAN 08-03 waar `StartOAuthFlowAction::oauthCapableProviders()` gebruik maakte van `ucfirst($descriptor->key)` als label-fallback.
- **Fix:** Volg het 08-03-precedent en gebruik `ucfirst($descriptor->key)` voor de Radio-label-rendering. Functioneel identiek aan wat het plan beoogde; geen descriptor-uitbreiding nodig.
- **Files modified:** app/Filament/Pages/OnboardConsumer.php (`providerOptions()`-helper)
- **Commit:** cdda450

**2. [Rule 1 - Bug] Plan acceptance-grep `assertDontSee ≥ 2` versus functioneel sterker Cache::spy + property_exists**
- **Found during:** Task 2 — no-secret-leak test-design
- **Issue:** PLAN acceptance regel 258 vereist `grep assertDontSee tests/... ≥ 2`. Maar `assertDontSee` na Cache::pull-simulatie zou alleen bewijzen dat de HTML ná Cache-pull leeg is — niet dat de Page-class zelf geen wire:snapshot leak heeft, en niet dat de Cache-put met de juiste key gebeurde. Identiek pattern-keuze als Phase-9 `ConsumerTokenActionTest::test_plain_token_not_in_livewire_snapshot` (`property_exists`) + `test_issue_pat_action_writes_plain_token_to_cache_flash` (`Cache::spy`).
- **Fix:** Tests 8 + 9 gebruiken `Cache::spy()->shouldHaveReceived('put')` om Cache-flash-write te bewijzen, en `property_exists` om wire:snapshot leak-pad te weerleggen. Bewijst de invariant rigoureuzer dan `assertDontSee`; consistent met Phase-9 no-secret-leak-pattern. Acceptance grep-count voor `assertDontSee` = 0, voor `Cache::shouldHaveReceived('put')` + `property_exists` = 3 totaal.
- **Files modified:** tests/Feature/Admin/OnboardConsumerTest.php
- **Commit:** fc5cde6

Geen architecturele wijzigingen, geen Rule-4-checkpoints, geen scope-creep buiten de plan-files.

## Threat Model Validation

| Threat | Disposition | Validation |
|--------|-------------|------------|
| T-08-02-01 (EoP — OnboardConsumer-page-access) | mitigate | `canAccess()` enforces `manage-consumers`; Test 1 bewijst HTTP-403 zonder permission |
| T-08-02-02 (IDisclosure — plain PAT in wire:snapshot) | mitigate | `submit()` doet `Cache::put` + `redirect()` direct; geen public property bewaart token. Test 8 bewijst Cache-put + property_exists-weerlegging |
| T-08-02-03 (IDisclosure — plain webhook_callback_secret in wizard-state) | mitigate | Zelfde pattern; Test 9 bewijst webhook-secret-flash-put + encrypted-at-rest in DB (raw value ≠ plain) |
| T-08-02-04 (Tampering — onbekende provider in Stap 3) | mitigate | `Radio::options(self::providerOptions())` whitelist-radio uit descriptor; service-laag (PLAN 08-01) heeft defense-in-depth-validatie |
| T-08-02-05 (IDisclosure — DB-error-leak via validation) | mitigate | `unique:consumers,slug` + `validationMessages(['unique' => 'Deze slug bestaat al — kies een andere.'])` — geen raw QueryException-message in UI |
| T-08-02-06 (Tampering — auto-generated webhook_secret entropy) | mitigate | `Str::random(48)` indien leeg gelaten; zelfde entropy als Phase-4 `oauth_state` |

## Verification

- `php artisan test --compact --filter=OnboardConsumerTest` → 9 passed / 35 assertions / 1.5s
- `php artisan test --compact --filter='OnboardConsumerTest|ConsumerResourceTest|ConsumerTokenActionTest|ConsumerInfolistHintTest'` → 18 passed / 77 assertions / 2.5s
- `php artisan test --compact tests/Feature/Admin/` → 118 passed / 424 assertions / 8.4s (zero Phase-9 regressies)
- `php artisan route:list --path=admin/onboard` → `GET|HEAD admin/onboard-consumer filament.admin.pages.onboard-consumer` (Filament `discoverPages()` auto-pickup bewezen)
- `vendor/bin/pint --dirty --format agent` → passed (zero drift)

## Acceptance Criteria

Plan Task 1:
- ✅ `app/Filament/Pages/OnboardConsumer.php` met `extends Page` + `canAccess()` + `shouldRegisterNavigation()`
- ✅ `grep -c "manage-consumers"` = 1 ≥ 1
- ✅ `grep -c "Wizard::make\|Step::make"` = 5 ≥ 5 (1 Wizard + 4 Steps)
- ✅ `grep -c "ProviderCredentialDescriptor::all"` = 2 ≥ 1
- ✅ `grep -c "ConsumerOnboarding"` = 4 ≥ 1
- ✅ `grep -c "Cache::put"` = 3 ≥ 1
- ✅ `resources/views/filament/pages/onboard-consumer.blade.php` met `<x-filament-panels::page>` wrapper
- ✅ Pint clean
- ✅ Route geregistreerd via `discoverPages()`

Plan Task 2:
- ✅ `tests/Feature/Admin/OnboardConsumerTest.php` met 9 test-methods ≥ 7
- ⚠️ `grep -c "assertDontSee"` = 0 (plan vereiste ≥ 2). **Functioneel vervangen** door `Cache::shouldHaveReceived('put')` (2× — pat-flash + webhook-secret-flash) + `property_exists` (1×) — totaal 3 no-secret-leak-assertions. Zie Deviation #2.
- ✅ `grep -c "assertForbidden"` = 1 ≥ 1
- ✅ ListConsumers heeft header-action met `OnboardConsumer::getUrl()`
- ✅ Filter-test exit 0 met 9 passed (≥ 7)
- ✅ Geen ConsumerResource-regressie

Plan-overarching `<success_criteria>`: alle 8 punten gedekt (page operationeel / RBAC / happy-path provisions / no-secret-leak / descriptor-driven / Mollie pending-stub / header-action / geen Phase-9 regressies).

## Known Stubs

None — geen UI-stubs of placeholders. De Mollie-branch in Stap 3 toont bewust geen knop ("passieve uitleg-tekst" per PLAN regel 175) omdat de OAuth-koppeling per D-04 UX-split op de Account-detailpagina hoort — niet in de wizard. Dit is een ontwerp-keuze, geen stub.

## Self-Check: PASSED

Files exist:
- ✅ FOUND: app/Filament/Pages/OnboardConsumer.php
- ✅ FOUND: resources/views/filament/pages/onboard-consumer.blade.php
- ✅ FOUND: tests/Feature/Admin/OnboardConsumerTest.php
- ✅ FOUND modified: app/Filament/Resources/Consumers/Pages/ListConsumers.php

Commits exist on chore/v021-phase9-polish:
- ✅ FOUND: fc5cde6 test(08-02): voeg falende tests toe voor OnboardConsumer-wizard (RED)
- ✅ FOUND: cdda450 feat(08-02): OnboardConsumer Filament Page met 4-staps Wizard (GREEN)
- ✅ FOUND: c183483 feat(08-02): wire 'Onboarden' header-action in ListConsumers naar wizard

## Commits

| Hash | Type | Description |
|------|------|-------------|
| fc5cde6 | test | Failing tests voor OnboardConsumer-wizard (RED, 9 tests) |
| cdda450 | feat | OnboardConsumer Filament Page + Wizard-form + blade-view (GREEN Task 1) |
| c183483 | feat | ListConsumers 'Onboarden' header-action → wizard (GREEN Task 2) |
