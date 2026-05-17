---
phase: 08-naschool-wiring-snelstart-mollie-via-hub
verified: 2026-05-17T17:00:00Z
status: human_needed
score: 13/13 must-haves verified (Hub-side scope per D-03)
overrides_applied: 0
re_verification: false
scope_fence: D-03 — Hub-side only; Naschool-repo deliverables marked out_of_scope_per_D-03
human_verification:
  - test: "End-to-end Mollie checkout via Hub-Connect op school A's eigen Mollie-account (SC-3)"
    expected: "Naschool POSTs naar Hub /v1/mollie/payments met Consumer-PAT + Account-id (school A) → Hub resolved Connection.access_token van school A → Mollie checkout-URL terug → ouder doorloopt Mollie test-mode → betaling verschijnt op school A's eigen Mollie test-dashboard (NIET op Emeq's eigen Mollie)"
    why_human: "Vereist Naschool-repo wiring + live OAuth-koppeling op een fysiek Mollie test-account voor school A. Hub-side substrate (pass-through API + StartOAuthFlowAction + OnboardConsumer-wizard) staat klaar; daadwerkelijke end-to-end flow vereist parallel werk in school-activities-hub/backend/ + manuele UAT met test-ouder + test-Mollie-account voor school A."
  - test: "Webhook → Hub → Naschool-callback → enrollment-status `paid` (SC-4)"
    expected: "Na succesvolle Mollie-betaling: Mollie POST naar Hub webhook → Hub signature-verifies + audit-logs (Phase 5a) → fan-out naar Naschool's webhook_callback_url met HMAC-signature → Naschool callback handler verifieert + update enrollment-status naar 'paid' zonder handmatige interventie"
    why_human: "Vereist Naschool's webhook-callback endpoint + signature-verify (Naschool repo). Hub-side fan-out (Spatie webhook-server, Phase 5a) bestaat; round-trip vereist beide kanten + test-Mollie + test-enrollment."
  - test: "EnrollmentConfirmed → Snelstart-verkoopfactuur via Hub-pass-through (SC-2 + NSCH-02)"
    expected: "Naschool dispatched `SyncEnrollmentToSnelstartJob` op `EnrollmentConfirmed`; job POSTs naar Hub /v1/snelstart/Verkoopfacturen met X-Account-Id (school A) → Hub resolved school A's Snelstart-Connection.client_key + subscription_key + subscription_id → verkoopfactuur aangemaakt in Snelstart test-env → zichtbaar in Snelstart-UI of via API-GET"
    why_human: "NSCH-02 + SC-2 vereisen Naschool-repo werk (job + listener + demo-seed); volledig out_of_scope_per_D-03. Hub-side substrate (pass-through Phase 5b + onboard-wizard voor Snelstart-credentials) staat klaar."
  - test: "Composer-resolve van emeq/snelstart-api + emeq/mollie-api in Naschool backend (SC-1)"
    expected: "`composer install` in school-activities-hub/backend/ resolved publieke VCS-repos zonder GitHub-auth-token; lock-file vermeldt beide SDK-packages met juiste version-pin"
    why_human: "Vereist Naschool composer.json edits + run in Naschool repo; out_of_scope_per_D-03. Hub-side levert geen composer-config voor Naschool."
  - test: "End-to-end smoke-runbook gedocumenteerd (SC-5)"
    expected: "Handmatige doorloop van NSCH-01 + NSCH-03 happy paths is genoteerd in .docs/ van Naschool-repo OF in een gedeeld document; Hub-side runbook-pointer (indien aanwezig) verwijst correct ernaar"
    why_human: "Shared deliverable — Naschool-repo werk + e2e-validatie van bovenstaande items 1-4. Hub-side documentatie alleen pointer."
---

# Phase 8: Naschool wiring (Snelstart + Mollie-via-Hub) Verification Report

**Phase Goal:** Naschool als eerste concrete Consumer: Snelstart-verkoopfactuur op `EnrollmentConfirmed` + vrijwillige-bijdrage-checkout via Hub-Connect op school A's eigen Mollie-account, end-to-end smoke-getest.

**Verified:** 2026-05-17T17:00:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

**Scope-fence (D-03):** Hub-side scope only. NSCH-02 (Naschool's `SyncEnrollmentToSnelstartJob`-listener) lives in `school-activities-hub/backend/` (Naschool repo) and is **out_of_scope_per_D-03** for this verification. ROADMAP success criteria that depend on Naschool-repo work (SC-1 composer-resolve, SC-2 listener, SC-3 end-to-end Mollie checkout, SC-4 webhook → status `paid`, SC-5 documented smoke) are surfaced as `human_verification` items.

## Goal Achievement (Hub-side scope per D-03)

### Observable Truths

| #   | Truth (Hub-side per D-03) | Status | Evidence |
| --- | ------------------------- | ------ | -------- |
| 1 | Atomic `ConsumerOnboarding` service maakt Consumer+Account+Connection+PAT in één DB::transaction, met rollback bij failure | ✓ VERIFIED | `app/Services/ConsumerOnboarding.php:42` `DB::transaction`-closure; `tests/Feature/Services/ConsumerOnboardingTest.php` 7/7 passed incl. rollback-test via `__force_failure` marker |
| 2 | Bestaande `hub:consumer:create` CLI-signature (--slug, --name, --abilities, --token-name) blijft 1:1 werken na refactor naar service-delegate | ✓ VERIFIED | `app/Console/Commands/HubConsumerCreate.php` met `handle(ConsumerOnboarding $onboarding): int`; `tests/Feature/Console/HubConsumerCreateTest.php` 7/7 passed (5 regression + 1 DI + 1 misc) |
| 3 | Filament `OnboardConsumer` Page met 4-staps Wizard (Consumer → Account → Connection → PAT) toegankelijk voor staff met `manage-consumers` | ✓ VERIFIED | `app/Filament/Pages/OnboardConsumer.php:84-178` (Wizard met 4 Steps); route `admin/onboard-consumer` geregistreerd; `canAccess()` checkt `manage-consumers` (regel 70-73); 13 tests groen incl. RBAC + happy-path |
| 4 | Wizard-Stap 3 is descriptor-driven (provider-keuze leest uit `ProviderCredentialDescriptor::all()`) — geen hardcoded provider-switch | ✓ VERIFIED | `OnboardConsumer::providerOptions()` regel 290-298 itereert descriptors; toevoegen provider = config-edit, geen code-edit |
| 5 | Plain PAT-token + plain webhook_callback_secret eenmalig via Cache-flash zichtbaar op redirect-target; daarna nooit zichtbaar | ✓ VERIFIED (CR-01/CR-02 fix) | Write site `OnboardConsumer.php:270-274` gebruikt `pat-flash:user:{userId}`; read site `list-consumers.blade.php:20-22` gebruikt zelfde key-scope; commit d0b0955 fix |
| 6 | Shared `StartOAuthFlowAction` met `forAccount()` (primary) + `forConnection()` (secondary, pending-only) operationeel | ✓ VERIFIED | `app/Filament/Actions/StartOAuthFlowAction.php:66-99`; 17 tests in `StartOAuthFlowActionTest` passed |
| 7 | `oauthCapableProviders()` filtert op `oauthFlowKey !== null` ÉN op Pennant feature-flag — kill-switch-respectful (CR-03) | ✓ VERIFIED | `StartOAuthFlowAction.php:46-61` met `Feature::active("provider-{$descriptor->key}-enabled")`-check; commit cc316c2 fix |
| 8 | `dispatch()` catcht zowel `InvalidArgumentException` (unknown provider) ALS `ProviderDisabledException` (Pennant kill-switch) — geen 500 (CR-03) | ✓ VERIFIED | `StartOAuthFlowAction.php:112-134` heeft beide catch-blocks; commit cc316c2 fix |
| 9 | `dispatch()` bouwt authorize-URL VÓÓR de pending Connection wordt geschreven — geen orphan-row bij failure (CR-04-equivalent) | ✓ VERIFIED | `StartOAuthFlowAction.php:142-168` (URL-build try/catch eerst, Connection-create daarna) |
| 10 | Dev-only OAuth-init route `/dev/partners/mollie/start-oauth` bouwt URL eerst, schrijft Connection daarna — geen orphan-row bij failure (CR-04) | ✓ VERIFIED | `routes/web.php:70-94` (try/catch op `getAuthorizationUrl` regel 79-84 vóór `connections()->create` regel 86-91); commit 727be72 fix |
| 11 | Dev-route is env-gated (`local`/`testing` only) — 404 in production | ✓ VERIFIED | `routes/web.php:30` `if (app()->environment('local', 'testing'))`-wrapper omvat regel 70-94; `PartnerPagesTest::test_dev_partner_routes_404_in_non_local_envs` verifieert |
| 12 | Filament Resource-infolist hints + Tenants-navgroup-tooltip leveren operator-onboarding-clarity met canonical D-07 copy | ✓ VERIFIED | `ConsumerInfolist.php:23-25` + `AccountInfolist.php:17-20` met canonical copy; `AdminPanelProvider.php:52-58` met `extraSidebarAttributes(['title' => …])` op Tenants-navgroup; 9 hint-tests passed |
| 13 | `/dev/partners`-pages tonen domeinmodel-blokje + per-provider live status-widget + (Mollie) live OAuth-CTA, met no-secret-leak | ✓ VERIFIED | `partners/index.blade.php:21` + `mollie/example.blade.php:29-53` + `snelstart/example.blade.php:29-46`; bg-amber-500 CTA wijst naar `route('dev.partners.mollie.start-oauth')`; `PartnerStatus` service met N+1-guard; 23 tests passed; `assertDontSee` op plain client_key in snelstart-test |

**Score:** 13/13 truths verified (Hub-side scope per D-03)

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `app/Services/ConsumerOnboarding.php` | Atomic Consumer+Account+Connection+PAT service | ✓ VERIFIED | `final readonly class`; DB::transaction; assertAbilitiesWhitelisted; 95 LOC |
| `app/Services/PartnerStatus.php` | Read-only aggregate Account×Provider Connection-status | ✓ VERIFIED | `final class` met N+1-guard via `Account::query()->with(...)`; consumerSlug-scope per WR-04 fix |
| `app/Filament/Pages/OnboardConsumer.php` | Standalone Filament Page met 4-staps Wizard | ✓ VERIFIED | extends Page; canAccess; Wizard::make met 4 Step::make; descriptor-driven; submit() met try/catch InvalidArgumentException (WR-05) + buildConnectionPayload null-guard (WR-06) |
| `app/Filament/Actions/StartOAuthFlowAction.php` | Shared Filament Action met forAccount + forConnection | ✓ VERIFIED | 3 static methods; Pennant-aware filtering; ProviderDisabledException-catch (CR-03); URL-eerst-Connection-later (CR-04-equivalent) |
| `app/Filament/Resources/Consumers/Schemas/ConsumerInfolist.php` | Hint-Section "Wat is een Consumer?" + basis-velden | ✓ VERIFIED | canonical D-07/UI-SPEC §S4 copy aanwezig, `->collapsed()` |
| `app/Filament/Resources/Consumers/Pages/ViewConsumer.php` | ViewRecord-subclass voor ConsumerResource | ✓ VERIFIED | Wired in `ConsumerResource::getPages()` regel 163 + `infolist()`-method regel 114-117 |
| `app/Filament/Resources/Accounts/Schemas/AccountInfolist.php` (extended) | Hint-Section "Wat is een Account?" toegevoegd | ✓ VERIFIED | Section met canonical D-07 copy als eerste component |
| `app/Providers/Filament/AdminPanelProvider.php` (extended) | Tenants-navgroup met tooltip via `extraSidebarAttributes` | ✓ VERIFIED | 4 NavigationGroups expliciet declared; Tenants heeft `title`-attr met canonical D-07 copy |
| `resources/views/filament/pages/onboard-consumer.blade.php` | Wrapper rond `{{ $this->form }}` | ✓ VERIFIED | `<x-filament-panels::page>`-wrapper |
| `resources/views/partners/partials/_domeinmodel.blade.php` | Herbruikbaar Tailwind-partial met Consumer/Account/Connection-uitleg | ✓ VERIFIED | 3 bullets met canonical UI-SPEC §S3 copy |
| `resources/views/partners/partials/_status-widget.blade.php` | Herbruikbaar partial met per-Account status-regels per provider | ✓ VERIFIED | Heroicon-dispatch via x-dynamic-component; 4 semantic Tailwind kleur-tokens; sr-only label; WR-07 fix (geen `optional()` wrapper) |
| `resources/views/partners/index.blade.php` + `mollie/example.blade.php` + `snelstart/example.blade.php` | Uitbreiding met domeinmodel + koppel-stappen + status-widget + (Mollie) CTA | ✓ VERIFIED | Alle 3 views includen `_domeinmodel`; mollie heeft bg-amber-500 CTA naar `dev.partners.mollie.start-oauth`; snelstart heeft canonical cURL-snippet |
| `routes/web.php` (extended) | Dev-only OAuth-init route binnen env-gated blok | ✓ VERIFIED | Route binnen `if (app()->environment('local', 'testing'))`; URL-eerst-pattern (CR-04 fix) |
| `tests/Feature/Services/ConsumerOnboardingTest.php` | Happy-path + rollback + validatie coverage | ✓ VERIFIED | 7 tests passed |
| `tests/Feature/Console/HubConsumerCreateTest.php` | Regression coverage CLI | ✓ VERIFIED | 7 tests passed (5 regression + DI + extra) |
| `tests/Feature/Admin/OnboardConsumerTest.php` | RBAC + happy-path + no-secret-leak coverage | ✓ VERIFIED | 13 tests passed |
| `tests/Feature/Admin/StartOAuthFlowActionTest.php` | RBAC + visibility + redirect-URL coverage | ✓ VERIFIED | 17 tests passed |
| `tests/Feature/Admin/ConsumerInfolistHintTest.php` | Hint-Section coverage | ✓ VERIFIED | 5 tests passed |
| `tests/Feature/Admin/AccountInfolistHintTest.php` | Hint-Section + Tenants-navgroup coverage | ✓ VERIFIED | 4 tests passed |
| `tests/Feature/Dev/PartnerPagesTest.php` | env-gating + canonical copy + status-widget + PartnerStatus + dev OAuth-init coverage | ✓ VERIFIED | 23 tests passed |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `HubConsumerCreate` | `ConsumerOnboarding` | constructor type-hint `handle(ConsumerOnboarding $onboarding)` | ✓ WIRED | `app/Console/Commands/HubConsumerCreate.php` `handle(ConsumerOnboarding $onboarding): int` confirmed via tests; DI resolution verified |
| `ConsumerOnboarding` | `Consumer/Account/Connection` models | `Consumer::create + accounts()->create + connections()->create + createToken` binnen `DB::transaction` | ✓ WIRED | service-code regel 42-81; rollback-test bewijst transaction-scope |
| `OnboardConsumer` | `ConsumerOnboarding` | `app(ConsumerOnboarding::class)->onboard($payload)` in submit-handler | ✓ WIRED | `OnboardConsumer.php:235` |
| `OnboardConsumer` | `ProviderCredentialDescriptor::all()` | `providerOptions()` itereert descriptors voor Radio-options | ✓ WIRED | `OnboardConsumer.php:290-298` |
| `ListConsumers` | `OnboardConsumer` | header-action 'Onboarden' met `url(OnboardConsumer::getUrl())` + `visible(fn () => OnboardConsumer::canAccess())` | ✓ WIRED | `app/Filament/Resources/Consumers/Pages/ListConsumers.php:30-34` |
| `OnboardConsumer` `submit()` | `list-consumers.blade.php` Cache-flash | `Cache::put('pat-flash:user:{userId}')` matched door `Cache::pull('pat-flash:user:'.$userId)` | ✓ WIRED (CR-01 fix) | write site OnboardConsumer.php:270; read site list-consumers.blade.php:20 |
| `OnboardConsumer` `submit()` | `list-consumers.blade.php` webhook-secret-flash | `Cache::put('webhook-secret-flash:user:{userId}')` matched door `Cache::pull('webhook-secret-flash:user:'.$userId)` | ✓ WIRED (CR-02 fix) | write site OnboardConsumer.php:274; read site list-consumers.blade.php:22 |
| `StartOAuthFlowAction::dispatch()` | `OAuthFlowRegistry` | `app(OAuthFlowRegistry::class)->for($provider)->getAuthorizationUrl(...)` | ✓ WIRED | StartOAuthFlowAction.php:113+143 |
| `StartOAuthFlowAction::oauthCapableProviders()` | `Feature::active('provider-{...}-enabled')` | Pennant feature-flag check per descriptor | ✓ WIRED (CR-03 fix) | StartOAuthFlowAction.php:54 |
| `ConnectionResource::table()` | `StartOAuthFlowAction::forConnection` | `recordActions([..., StartOAuthFlowAction::forConnection(), ...])` | ✓ WIRED | ConnectionResource.php:151 |
| `AccountsTable::configure()` | `StartOAuthFlowAction::forAccount` | `recordActions([..., StartOAuthFlowAction::forAccount()])` | ✓ WIRED | AccountsTable.php:47 |
| `ConsumerResource` | `ConsumerInfolist` + `ViewConsumer` | `infolist()` method + `'view' => ViewConsumer::route(...)` in `getPages()` | ✓ WIRED | ConsumerResource.php:114, 163 |
| `mollie/example.blade.php` CTA | `routes/web.php :: dev.partners.mollie.start-oauth` | `<a href="{{ route('dev.partners.mollie.start-oauth') }}">` | ✓ WIRED | mollie/example.blade.php:38 |
| `dev.partners.mollie.start-oauth` route | `OAuthFlowRegistry` | server-side `app(OAuthFlowRegistry::class)->for('mollie')->getAuthorizationUrl(...)` | ✓ WIRED (CR-04 fix) | routes/web.php:79-84 (URL-first, Connection-later) |
| `partners/{index,mollie,snelstart}` | `PartnerStatus` service | `app(PartnerStatus::class)->forProvider(...)` / `->totalsForProvider(...)` | ✓ WIRED | index.blade.php, mollie/example.blade.php:50-53, snelstart/example.blade.php:43-46 |
| `partners/{index,mollie,snelstart}` | `_domeinmodel` + `_status-widget` partials | `@include('partners.partials._domeinmodel')` + `@include('partners.partials._status-widget', ...)` | ✓ WIRED | alle 3 partner-views |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| `OnboardConsumer` wizard | `$data['*']` (form-state) | Filament form bind via `statePath('data')`; submit → service-call met live DB-write | ✓ Yes | ✓ FLOWING |
| `list-consumers.blade.php` PAT-flash | `$issuedToken` | `Cache::pull('pat-flash:user:'.$userId)` na `OnboardConsumer::submit()` Cache::put | ✓ Yes (na fix) | ✓ FLOWING |
| `StartOAuthFlowAction::dispatch()` redirect | `$url` (authorize-URL) | `OAuthFlowRegistry::for($provider)->getAuthorizationUrl(...)` met live config + state | ✓ Yes | ✓ FLOWING |
| `_status-widget` per-Account rows | `$accountStatus` | `PartnerStatus::forProvider($provider)` met eager-loaded Account+Connection-query | ✓ Yes | ✓ FLOWING |
| `partners/index.blade.php` totals | `$totals` | `PartnerStatus::totalsForProvider($provider)` (DB query) | ✓ Yes | ✓ FLOWING |
| `partners/mollie/example.blade.php` CTA-href | `route('dev.partners.mollie.start-oauth')` | named route-resolver → real env-gated callback | ✓ Yes | ✓ FLOWING |
| `ConsumerInfolist` hint-Section | `description` text | hardcoded canonical D-07 copy (no dynamic data; intentional) | n/a | ✓ FLOWING (static-by-design) |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Full Phase-8 test suite | `php artisan test --compact --filter='ConsumerOnboardingTest\|HubConsumerCreateTest\|OnboardConsumerTest\|StartOAuthFlowActionTest\|ConsumerInfolistHintTest\|AccountInfolistHintTest\|PartnerPagesTest'` | 76 passed / 254 assertions / 3.96s | ✓ PASS |
| Full test suite (regression) | `php artisan test --compact` | 507 passed / 1715 assertions / 21.2s / 1 pre-existing incomplete | ✓ PASS |
| Admin onboard-route registered | `php artisan route:list --path=admin/onboard-consumer` | `GET\|HEAD admin/onboard-consumer filament.admin.pages.onboard-consumer` | ✓ PASS |
| Dev partner-routes registered | `php artisan route:list --path=dev/partners` | 3 routes (index, preview, start-oauth) zichtbaar in local env | ✓ PASS |

### Probe Execution

| Probe | Command | Result | Status |
| ----- | ------- | ------ | ------ |
| (none) | — | Phase 8 is not a migration/tooling phase; geen probes gedeclareerd in PLAN/SUMMARY; geen `scripts/*/tests/probe-*.sh` conventional probes (folder bestaat niet) | n/a |

### Requirements Coverage

| Requirement | Source Plan(s) | Description | Status | Evidence |
| ----------- | -------------- | ----------- | ------ | -------- |
| NSCH-01 | 08-01, 08-02, 08-04 | composer-wiring + Stancl-resolver Naschool + Hub-substrate onboard | ⚠️ PARTIAL (Hub-side complete; Naschool-repo werk out_of_scope_per_D-03) | Hub-side: `ConsumerOnboarding` service + `OnboardConsumer` wizard + Infolist hints — alle 4 plans uitgevoerd, 36 tests groen (7+7+13+9). Naschool-side composer.json + StancltenancyCredentialResolver = out_of_scope_per_D-03. REQUIREMENTS.md status `In Progress (Hub-substrate landed 08-01; Naschool-repo werk pending)` correct. |
| NSCH-02 | — (geen Hub-side plan; staat als `Pending` in REQUIREMENTS.md) | `SyncEnrollmentToSnelstartJob` listener op `EnrollmentConfirmed` in Naschool | ⚠️ OUT_OF_SCOPE_PER_D-03 | Volledig Naschool-repo werk; geen Hub-side deliverable nodig (Phase 5b pass-through is voldoende substrate). REQUIREMENTS.md status `Pending` accuraat. |
| NSCH-03 | 08-03, 08-05 (+ 08-02 wizard ondersteunt) | Mollie checkout-flow via Hub-Connect op school A's eigen Mollie | ⚠️ PARTIAL (Hub-side admin-trigger + partner-pages compleet; Naschool checkout-uitvoering + e2e UAT vereist Naschool-repo + ouder-test) | Hub-side: `StartOAuthFlowAction` (17 tests) + dev `/dev/partners/mollie/start-oauth` route + partner-pages met live status-widget (23 tests). Naschool-repo Mollie-checkout-call + webhook-callback-handler + e2e UAT = out_of_scope_per_D-03. REQUIREMENTS.md status `In Progress (Hub-admin-trigger landed 08-03; partner-pages + onboard-wizard pending; e2e UAT in Naschool-repo)` is iets achterhaald (partner-pages + wizard ZIJN gelanded in 08-02/05); blijft echter correct dat e2e UAT pending. |

**Orphaned requirements check:** REQUIREMENTS.md regel 98-100 mapt NSCH-01/02/03 op Phase 8. NSCH-02 is niet aan een Phase-8-plan toegewezen — dit is INTENTIONAL per D-03 scope-fence (Naschool-only werk). Geen oprhan-requirement-issue: PLAN 08-01 frontmatter `requirements: [NSCH-01]`, 08-02 `[NSCH-01]`, 08-03 `[NSCH-03]`, 08-04 `[NSCH-01]`, 08-05 `[NSCH-03]` — gezamenlijk dekken NSCH-01 + NSCH-03 Hub-side surface. NSCH-02 bewust uitgesloten per D-03 + bevestigd in PLAN 08-01 `<objective>` scope-fence.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| `app/Filament/Pages/OnboardConsumer.php` | 90, 124 | `->placeholder('Naschool')`, `->placeholder('School A')` | ℹ️ Info | Filament form-field placeholders (UX hints), NOT stub-implementations. Acceptable — geen verandering nodig. |
| `app/Services/ConsumerOnboarding.php` | 68-72 | Test-only `__force_failure`-marker guard | ℹ️ Info | Documented in service-comment + SUMMARY; one-line guard met zero runtime impact bij normale paden; bewijst rollback-volledigheid in test zonder model-event-listener of FK-violation. Acceptable engineering tradeoff. |

Geen 🛑 Blockers en geen ⚠️ Warnings — alle Critical+Warning review findings (CR-01 t/m CR-04, WR-01 t/m WR-07) zijn ge-fixed in commits d0b0955..2609782 conform REVIEW.md frontmatter `fix_results.fixed`. Geen `TBD/FIXME/XXX/TODO/HACK` markers in Phase-8 files.

### Human Verification Required

5 items zijn surfaced naar het human-UAT-pad omdat ze of (a) **end-to-end met Naschool-repo** vereisen (out_of_scope_per_D-03 voor automated verification), of (b) ouder-doorloop / live-Mollie-test-account vereisen die geen grep of artisan-call kan simuleren.

#### 1. End-to-end Mollie checkout via Hub-Connect op school A's eigen Mollie-account (SC-3)

**Test:** Naschool POSTs naar Hub `/v1/mollie/payments` met Consumer-PAT + Account-id (school A) → Hub resolved Connection.access_token van school A → Mollie checkout-URL terug → ouder doorloopt Mollie test-mode → betaling verschijnt op **school A's eigen Mollie test-dashboard** (niet op Emeq's eigen Mollie)
**Expected:** Betaling-rij zichtbaar in school A's Mollie dashboard met juiste amount + reference; geen rij in Emeq's dashboard
**Why human:** Vereist Naschool-repo wiring (Mollie-call in Naschool-controller) + live OAuth-koppeling op een fysiek Mollie test-account voor school A + manuele ouder-doorloop. Hub-side substrate (`/v1/mollie/*` pass-through Phase 5a + `StartOAuthFlowAction` voor OAuth-roundtrip + `OnboardConsumer`-wizard voor initial-koppel) staat klaar.

#### 2. Webhook → Hub → Naschool-callback → enrollment-status `paid` (SC-4)

**Test:** Na succesvolle Mollie-betaling: Mollie POST naar Hub webhook → Hub signature-verifies + audit-logs (Phase 5a) → fan-out naar Naschool's `webhook_callback_url` met HMAC-signature → Naschool callback-handler verifieert + update enrollment-status
**Expected:** Naschool's enrollment-rij heeft status `paid` zonder handmatige interventie binnen ~5s na Mollie-betaling
**Why human:** Vereist Naschool's webhook-callback endpoint + signature-verify (Naschool-repo deliverable). Hub-side fan-out (Spatie webhook-server, Phase 5a) bestaat en is getest; round-trip vereist beide kanten + test-Mollie + test-enrollment.

#### 3. EnrollmentConfirmed → Snelstart-verkoopfactuur via Hub-pass-through (SC-2 + NSCH-02)

**Test:** Naschool dispatched `SyncEnrollmentToSnelstartJob` op `EnrollmentConfirmed`; job POSTs naar Hub `/v1/snelstart/Verkoopfacturen` met `X-Account-Id` (school A) → Hub resolved school A's Snelstart-Connection.client_key + subscription_key + subscription_id → verkoopfactuur aangemaakt in Snelstart test-env
**Expected:** Verkoopfactuur zichtbaar in Snelstart-UI of via API-GET met juiste relatie + bedrag
**Why human:** NSCH-02 + SC-2 zijn volledig Naschool-repo werk; out_of_scope_per_D-03. Hub-side substrate (Phase 5b pass-through + onboard-wizard voor Snelstart-credentials) staat klaar.

#### 4. Composer-resolve van emeq/snelstart-api + emeq/mollie-api in Naschool backend (SC-1)

**Test:** `composer install` in `school-activities-hub/backend/` resolved publieke VCS-repos zonder GitHub-auth-token; lock-file vermeldt beide SDK-packages
**Expected:** Exit-code 0; `composer show emeq/snelstart-api emeq/mollie-api` toont beide packages
**Why human:** Vereist Naschool composer.json edits + run in Naschool repo; out_of_scope_per_D-03.

#### 5. End-to-end smoke-runbook gedocumenteerd (SC-5)

**Test:** Handmatige doorloop van NSCH-01 + NSCH-03 happy paths is genoteerd in `.docs/` van Naschool-repo OF in een gedeeld document
**Expected:** Runbook met de stap-volgorde + screenshots / cURL-snippets aanwezig
**Why human:** Shared deliverable — Naschool-repo werk + e2e-validatie van bovenstaande items 1-4.

### Gaps Summary

Geen gaps op de **Hub-side scope per D-03**. Alle 13 Hub-side observable truths VERIFIED met code-evidence; alle 20 required artifacts exist + substantive + wired + data flowing; alle 16 key links wired; 76/76 Phase-8 tests passed; 507/507 volledige suite passed (1 pre-existing incomplete in Phase-5b-placeholder, niet attributable aan Phase 8).

Alle 4 BLOCKER-class review findings (CR-01..04) en alle 7 WARNING findings (WR-01..07) zijn ge-fixed in commits `d0b0955..2609782` zoals geclaimd in REVIEW.md frontmatter — code-evidence in deze verification bevestigt dat per fix:
- CR-01: write+read site gebruiken matched `pat-flash:user:{userId}` key
- CR-02: write+read site gebruiken matched `webhook-secret-flash:user:{userId}` key
- CR-03: `oauthCapableProviders()` filtert op `Feature::active(...)`; `dispatch()` catcht zowel `InvalidArgumentException` als `ProviderDisabledException`
- CR-04: `routes/web.php:70-94` bouwt URL eerst (try/catch), Connection daarna; identiek pattern in `StartOAuthFlowAction.php:142-168`
- WR-04: `PartnerStatus::forProvider($provider, ?string $consumerSlug = null)` heeft optionele scope-arg
- WR-05+06: `OnboardConsumer::submit()` heeft `\InvalidArgumentException`-catch met message-passthrough + `buildConnectionPayload === null` server-side guard

De 4 deferred IN-findings (IN-02, IN-03, IN-04, IN-05) zijn cosmetic / defensive / scope-buiten-Phase-8 en hebben `deferred:` rationale in REVIEW.md frontmatter (regel 54-62) — geen action item voor Phase 8 close-out.

**End-to-end SC-1/SC-2/SC-3/SC-4/SC-5 verification vereist parallel Naschool-repo werk + manuele UAT** — gesurfacd in human_verification-list (5 items). Hub-side substrate is compleet en klaar om die Naschool-werk te ontvangen.

---

_Verified: 2026-05-17T17:00:00Z_
_Verifier: Claude (gsd-verifier)_
