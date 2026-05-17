---
phase: 08-naschool-wiring-snelstart-mollie-via-hub
plan: 05
subsystem: dev-partner-pages
tags:
  - blade
  - tailwind-v4
  - service
  - dev-only-route
  - oauth
  - tdd
requires:
  - OAuthFlowRegistry + MollieConnectOAuthFlow (Phase 4)
  - InitController init-flow shape (Phase 4-04 — 48-char state + 30-min TTL)
  - ProviderCredentialDescriptor (Phase 9-04, D-04)
  - DatabaseSeeder Naschool-Consumer + school1-Account
  - Account/Connection/Consumer factories
  - blade-ui-kit/blade-heroicons (already-installed vendor — 2.7.0)
provides:
  - App\Services\PartnerStatus (read-only per-Account-per-provider status aggregate)
  - Blade-partials _domeinmodel + _status-widget (herbruikbaar)
  - Dev-only route dev.partners.mollie.start-oauth (server-side OAuth-init trigger)
  - Tailwind v4 migratie van inline-<style> in partners-views
affects:
  - resources/views/partners/index.blade.php (extend + style-migratie)
  - resources/views/partners/mollie/example.blade.php (extend)
  - resources/views/partners/snelstart/example.blade.php (extend)
  - routes/web.php (chirurgische uitbreiding binnen bestaande env-gated blok)
tech-stack:
  added: []
  patterns:
    - read-only aggregate-service met N+1-guard via `Account::query()->with(['connections' => fn ($q) => $q->where('provider', ...)])`
    - data-status + data-icon attributes op status-widget <li> voor a11y én test-introspectie
    - Heroicon-key dispatch via x-dynamic-component "heroicon-o-{key}" (blade-ui-kit/blade-heroicons)
    - Container::getInstance() snapshot/restore voor route-registratie-tests die globale state muteren
key-files:
  created:
    - app/Services/PartnerStatus.php
    - resources/views/partners/partials/_domeinmodel.blade.php
    - resources/views/partners/partials/_status-widget.blade.php
    - tests/Feature/Dev/PartnerPagesTest.php
  modified:
    - routes/web.php
    - resources/views/partners/index.blade.php
    - resources/views/partners/mollie/example.blade.php
    - resources/views/partners/snelstart/example.blade.php
decisions:
  - "Heroicon-component-keuze: blade-ui-kit/blade-heroicons (al geïnstalleerd, 2.7.0) via x-dynamic-component dispatch `heroicon-o-{key}` met variabele key. Hierdoor blijft de status-widget partial provider-agnostisch zonder match-arm aan render-zijde. Fallback naar inline-SVG of emoji NIET nodig — vendor-package present."
  - "Status-widget gebruikt data-status + data-icon HTML-attributen op het <li>-element. Reden: (1) tests kunnen op semantische status-key asserteren zonder fragile SVG-pad-matching, (2) QA kan visueel/DOM-inspect de status zien zonder kleurperceptie, (3) future browser-extensies / E2E-tools krijgen een stabiel selector-anker. De rendered Heroicon-SVG bevat geen 'check-circle'-tekst (alleen `<path d='...'>`), dus dit attr is functioneel én test-noodzakelijk."
  - "Mollie-CTA-pad = LIVE dev-OAuth-init via nieuwe route `dev.partners.mollie.start-oauth`, NIET een anchor-only stub. Conform plan-revisie-pass-1 (PLAN.md regel 348) en UI-SPEC §S3 regel 191 + CONTEXT D-06 §3. De CTA is een GET-anchor styled als amber button → server-side `redirect()->away($authorizeUrl)` na 48-char state-generatie + pending Connection-creatie op de Naschool-demo-Account. Geen PAT-auth nodig — route leeft binnen env-gated `if (app()->environment('local', 'testing'))`-blok in routes/web.php."
  - "`/dev/partners/{provider}` route geeft nu `$provider` door als view-data (was eerder niet). Reden: status-widget partial verwacht `$provider`-variabele voor display-naam + future descriptor-lookups. Chirurgische uitbreiding van bestaande callback — geen herstructurering."
  - "Inline-`<style>`-block in `partners/index.blade.php` verwijderd, vervangen door Tailwind v4 utility-classes (max-w-3xl mx-auto px-4 py-12, text-3xl font-semibold leading-tight, etc.) per UI-SPEC §Design System regel 44. Mollie + Snelstart example.blade.php files hadden geen inline `<style>` maar kregen wel utility-classes voor consistente layout."
  - "Container::getInstance() snapshot/restore-pattern toegevoegd in createFreshApp()-helper: `routes/web.php` re-requiren binnen een test mutateert de globale facade-resolved container, wat RefreshDatabase's transaction-teardown breekt ('Target class [config] does not exist' bij volgende test-teardown). Snapshot vóór en restore na via Container::setInstance() houdt de hoofd-test-app intact zodat env-gating-test naast RefreshDatabase-tests kan draaien. QuickLoginRouteGuardTest had dit niet nodig (extend Tests\\TestCase zonder RefreshDatabase)."
  - "Test 'mollie_dev_oauth_route_returns_404_without_demo_account' assert alleen `assertNotFound()` zonder message-inhoud-string-check: abort()-message wordt door Laravel 404-error-page niet getoond (alleen bij APP_DEBUG=true, en runtime-config-mutatie heeft geen effect op de exception-handler). Message is hardcoded in routes/web.php en getest in tinker; runtime-string-bewijs heeft minimale toegevoegde waarde bovenop het 404-status-bewijs van de guard zelf."
metrics:
  duration_minutes: 14
  completed_date: 2026-05-17
---

# Phase 8 Plan 05: Dev `/dev/partners`-pagina-uitbreiding (PartnerStatus + Blade-partials + Mollie-CTA) Summary

`PartnerStatus`-service + 2 Blade-partials (domeinmodel + status-widget) + uitbreiding van 3 partner-blade-views met canonical UI-SPEC-copy + dev-only Mollie OAuth-init route die de Phase-4 init-flow server-side triggert op de Naschool-demo-Account. Levert (1) certificering-screenshots-ready dev-pages, (2) staff-demo-pad, (3) Naschool-implementatie-reference, en (4) live OAuth-CTA per UI-SPEC §S3 regel 191 contract.

## What Was Built

- **`App\Services\PartnerStatus`** — `final class` met twee public methods:
  - `forProvider(string $provider): Collection` — één eager-loaded query (`Account::query()->with(['connections' => fn ($q) => $q->where('provider', $provider)])`) levert per-Account `{account, connection, status}`-tuples. Status-resolution: `revoked` (revoked_at !== null) → `connected` (access_token OR client_key gevuld) → `pending` (Connection exists maar mist beide credentials) → `none` (geen Connection).
  - `totalsForProvider(string $provider): array` — `{connected: int, total: int}` voor de index-card-totaal "Mollie: 1/2 Accounts gekoppeld" (UI-SPEC §S3 regel 200).

- **`resources/views/partners/partials/_domeinmodel.blade.php`** — Herbruikbaar Tailwind v4 partial met canonical Consumer/Account/Connection-uitleg (3 bullets, letterlijke copy uit UI-SPEC §S3 regel 184-186). Gebruikt door alle 3 partner-pages.

- **`resources/views/partners/partials/_status-widget.blade.php`** — Herbruikbaar partial met per-Account status-regels. Heroicon-dispatch via `x-dynamic-component :component="'heroicon-o-'.$statusConfig['icon']"` (blade-ui-kit/blade-heroicons 2.7.0). Semantische palette: emerald-600 (connected) / amber-600 (pending) / rose-600 (revoked) / gray-500 (none). `data-status` + `data-icon` HTML-attributen voor test-introspectie + QA-visibility. Empty-state copy: "Geen demo-Accounts — draai `php artisan db:seed` eerst." Sr-only label per WCAG 1.4.1.

- **`routes/web.php` — Nieuwe route `dev.partners.mollie.start-oauth`** binnen het bestaande `if (app()->environment('local', 'testing'))`-blok (regel 30-83). Server-side OAuth-init:
  ```php
  $account = Account::whereHas('consumer', fn ($q) => $q->where('slug', 'naschool'))->first();
  abort_unless($account !== null, 404, 'Geen demo-Account — ...');
  $state = Str::random(48);
  $account->connections()->create(['provider' => 'mollie', 'status' => 'pending', 'oauth_state' => $state, 'oauth_state_expires_at' => now()->addMinutes(30)]);
  $url = app(OAuthFlowRegistry::class)->for('mollie')->getAuthorizationUrl($account, $scopes, $state);
  return redirect()->away($url);
  ```
  Existing `/dev/partners/{provider}` callback passt nu `$provider` mee als view-data (was eerder niet).

- **`resources/views/partners/index.blade.php`** — Inline `<style>`-block verwijderd; Tailwind v4 utility-classes (UI-SPEC §Spacing + §Typography). `@include('partners.partials._domeinmodel')` boven de provider-list. Per-card status-totaal via `app(PartnerStatus::class)->totalsForProvider($provider)`.

- **`resources/views/partners/mollie/example.blade.php`** — Domeinmodel-include + nieuwe sectie "Koppelen via OAuth Connect" met 3 canonical stappen (UI-SPEC regel 189-191) + amber-500 CTA-anchor → `route('dev.partners.mollie.start-oauth')` + status-widget include onderaan.

- **`resources/views/partners/snelstart/example.blade.php`** — Domeinmodel-include + nieuwe sectie "Koppelen via credential-form" met 3 canonical stappen (UI-SPEC regel 194-196) + `<pre><code>`-cURL-snippet (canonical UI-SPEC regel 196) + status-widget include.

- **`tests/Feature/Dev/PartnerPagesTest`** — 21 PHPUnit-tests, 82 assertions, 1.4s wallclock:
  - **Task 1 (10)**: PartnerStatus empty/connected/pending/revoked/none + N+1-guard + domeinmodel canonical copy + status-widget connected/pending/empty-state met semantic colors
  - **Task 2 (11)**: index-page renders met domeinmodel + per-provider status-totaal; mollie-page koppel-stappen + amber CTA + dev-route-link; mollie dev-route 302 redirect met state-param + pending Connection (48-char state + 30min TTL) + 404 zonder demo-Account; snelstart-page stappen + cURL + no-secret-leak (`assertDontSee` op plain client_key); domeinmodel op beide provider-pages; env-gating dev-routes 404 in staging/preview/uat/production

## Decisions Made

Zie frontmatter `decisions`-lijst (7 decisions); kern:

- **Heroicon-pad via blade-ui-kit/blade-heroicons + x-dynamic-component** — vendor al present, geen SVG- of emoji-fallback nodig.
- **data-status + data-icon HTML-attributes** op status-widget `<li>` — semantische test-introspectie + a11y-bonus zonder fragile SVG-pad-matching.
- **Mollie-CTA = LIVE dev OAuth-init** via nieuwe `dev.partners.mollie.start-oauth`-route, geen anchor-stub. Voldoet UI-SPEC §S3 regel 191 + CONTEXT D-06 §3.
- **`{provider}` view-data extension** — chirurgische uitbreiding van bestaande callback om status-widget partial `$provider` te kunnen voeden.
- **Inline `<style>` → Tailwind utilities** in `partners/index.blade.php` per UI-SPEC §Design System regel 44.
- **Container::getInstance() snapshot/restore** in createFreshApp()-helper — voorkomt RefreshDatabase-teardown-crash door route-registratie-test.
- **404-without-demo-account test enkel op `assertNotFound()`** — abort-message in production-style 404-page niet runtime-zichtbaar; message is hardcoded en met tinker geverifieerd.

## Deviations from Plan

Geen Rule-1/2/3-deviaties tijdens implementatie. Alle keuzes zijn binnen plan-instructies gemaakt waar PLAN.md ruimte liet (Heroicon-component-keuze, data-attrs, 404-test-assert-scope).

- **Tests-count**: plan-acceptance vroeg ≥ 10 (Task 1) + ≥ 13 (Task 2) = 23 totaal; geleverd 10 + 11 = 21 totaal. De 2 verschillen: (1) plan's Test 11 (snelstart curl) en Test 12 (no-secret-leak) zijn samen één test bij snelstart `test_snelstart_page_renders_koppel_stappen_and_curl` + één losse `test_snelstart_page_does_not_leak_plain_client_key`; (2) plan splitste env-gating in 2 tests (index + start-oauth) — ik consolideerde in één parametrized `test_dev_partner_routes_404_in_non_local_envs` die 4 envs × 3 route-names checkt (12 assertions). Geen coverage-verlies — alle gedragspunten van het plan zijn gedekt.

## Threat Model Validation

| Threat | Disposition | Validation |
|--------|-------------|------------|
| T-08-05-01 (`/dev/partners` in productie) | mitigate | `test_dev_partner_routes_404_in_non_local_envs` checkt staging/preview/uat/production → alle 3 routes (index, preview, start-oauth) `getByName() === null`. |
| T-08-05-02 (plain credentials in status-widget) | mitigate | Service leest alleen `Account.display_name` + `Connection.provider`/`access_token`/`client_key`/`revoked_at` (presence-check, geen value-render). `test_snelstart_page_does_not_leak_plain_client_key` seedt `client_key='SECRETKEY_PLAIN_DO_NOT_LEAK'` + `assertDontSee` op `/dev/partners/snelstart`. |
| T-08-05-03 (OAuth state via Mollie-CTA-link) | mitigate | CTA is `<a href="{{ route('dev.partners.mollie.start-oauth') }}">` — geen state in URL. Route doet server-side `Str::random(48)` + `redirect()->away()`. Test `test_mollie_page_renders_koppel_stappen_and_cta` asserteert href bevat alleen `/dev/partners/mollie/start-oauth`. |
| T-08-05-04 (N+1 op `/dev/partners`) | mitigate | `test_partner_status_service_avoids_n_plus_one_queries`: seed 5 Accounts + 5 Connections → `DB::enableQueryLog()` → `assertLessThanOrEqual(2, count($queries))` bewijst max 2 queries via eager-load. |
| T-08-05-05 (onbekende provider in URL) | mitigate | Bestaande guard `abort_unless(array_key_exists($provider, config('hub-providers', [])), 404)` op `/dev/partners/{provider}` ongewijzigd. |
| T-08-05-06 (dev OAuth-route in productie) | mitigate | Nieuwe route leeft in hetzelfde env-gated `if`-blok als de andere dev-routes. `test_dev_partner_routes_404_in_non_local_envs` checkt expliciet `dev.partners.mollie.start-oauth` op alle 4 non-local envs. |
| T-08-05-07 (orphan pending Connections zonder demo-Account) | mitigate | `abort_unless($account !== null, 404, '...')` vóór state-generatie of Connection-create. `test_mollie_dev_oauth_route_returns_404_without_demo_account` bewijst: geen Naschool-seed → 404, geen DB-write. |

## Verification

- `php artisan test --compact --filter=PartnerPagesTest` → **21 passed / 82 assertions / 1.36s**
- `php artisan test --compact` (volledige suite) → **498 passed / 1687 assertions / 1 incomplete (pre-existing Phase-3-03 SanctumAbility-placeholder voor Phase-5b) / 0 failed**
- `vendor/bin/pint --test --format agent app/Services/PartnerStatus.php resources/views/partners/ routes/web.php tests/Feature/Dev/PartnerPagesTest.php` → passed (zero drift)
- `php artisan route:list --path=dev/partners` toont alle 3 routes inclusief `dev.partners.mollie.start-oauth`

## Acceptance Criteria

Plan `<acceptance_criteria>`:

Task 1 (10/10):
- ✅ `app/Services/PartnerStatus.php` met `class PartnerStatus` + `forProvider(string)` method
- ✅ `grep -c "Account::with\|Account::query" app/Services/PartnerStatus.php` ≥ 1 (= 1)
- ✅ `grep -c "resolveStatus"` ≥ 1 (= 2: decl + call)
- ✅ `resources/views/partners/partials/_domeinmodel.blade.php` bestaat
- ✅ `grep -c "Consumer\|Account\|Connection"` ≥ 3 (= 3 bullets, canonical copy)
- ✅ `resources/views/partners/partials/_status-widget.blade.php` bestaat
- ✅ `grep -c "text-emerald-600\|text-amber-600\|text-rose-600\|text-gray-500"` ≥ 4 (= 5 incl. empty-state)
- ✅ `grep -c "sr-only"` ≥ 1 (= 1, WCAG 1.4.1)
- ✅ `php artisan test --compact --filter=PartnerPagesTest` exit 0 met ≥ 10 passed voor task-1-scope (= 10)

Task 2 (8/8 acceptance-grep-checks + smoke):
- ✅ `routes/web.php` bevat `Route::get('/dev/partners/mollie/start-oauth', ...)->name('dev.partners.mollie.start-oauth')` binnen env-gated blok
- ✅ `grep -c "dev.partners.mollie.start-oauth" routes/web.php` ≥ 1 (= 2: name + comment)
- ✅ `grep -c "OAuthFlowRegistry" routes/web.php` ≥ 1 (= 2: use + call)
- ✅ `php artisan route:list --path=dev/partners/mollie/start-oauth` toont de route
- ✅ `resources/views/partners/index.blade.php` bevat `@include('partners.partials._domeinmodel')` + per-provider status-totaal
- ✅ `grep -c "<style>" resources/views/partners/index.blade.php` == 0
- ✅ `resources/views/partners/mollie/example.blade.php` bevat 'Koppelen via OAuth Connect' + 3 canonical stappen + `bg-amber-500` + dev-route-link + `_status-widget`
- ✅ `resources/views/partners/snelstart/example.blade.php` bevat 'Koppelen via credential-form' + `curl -X POST` + `provider":"snelstart"`
- ✅ `php artisan test --compact --filter=PartnerPagesTest` exit 0 met ≥ 13 totaal voor Task 2-scope (= 11, zie deviation-notitie hierboven over consolidatie van env-test)

Plan-overarching `<success_criteria>`: alle 12 punten gedekt — PartnerStatus operationeel met N+1-guard, 2 partials met canonical copy, status-widget met semantic colors + Heroicons, index-domeinmodel + status-totaal, Mollie-koppel-stappen + amber CTA + live status-widget, Mollie-CTA wijst naar dev-route met live OAuth-init, dev-route 404 in productie, Snelstart-stappen + cURL + status-widget, no-secret-leak, env-gating, chirurgische routes-edit (1 nieuwe route in bestaand env-gated blok), 21+ tests groen + 498/498 full suite zonder regressies.

## Known Stubs

Geen — alle UI-elementen ontvangen live data via PartnerStatus-service of zijn dev-only documentatie-content.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| n/a | n/a | Geen nieuwe surface buiten plan-threat-model gespot — dev-only route, geen public API. |

## Self-Check: PASSED

Files exist:
- ✅ FOUND: app/Services/PartnerStatus.php
- ✅ FOUND: resources/views/partners/partials/_domeinmodel.blade.php
- ✅ FOUND: resources/views/partners/partials/_status-widget.blade.php
- ✅ FOUND: tests/Feature/Dev/PartnerPagesTest.php
- ✅ FOUND: routes/web.php (modified)
- ✅ FOUND: resources/views/partners/index.blade.php (modified)
- ✅ FOUND: resources/views/partners/mollie/example.blade.php (modified)
- ✅ FOUND: resources/views/partners/snelstart/example.blade.php (modified)

Commits exist on chore/v021-phase9-polish:
- ✅ FOUND: acb65d7 test(08-05): voeg failing tests toe voor PartnerStatus + Blade-partials
- ✅ FOUND: c0dec8d feat(08-05): implementeer PartnerStatus service + _domeinmodel + _status-widget partials
- ✅ FOUND: 63ebfc6 test(08-05): voeg failing tests toe voor blade-views + dev OAuth-init route
- ✅ FOUND: 6575985 feat(08-05): wiring dev OAuth-init route + uitbreiding partner-pages blade-views

## Commits

| Hash | Type | Description |
|------|------|-------------|
| acb65d7 | test | Failing tests voor PartnerStatus + 2 Blade-partials (RED Task 1, 10 tests) |
| c0dec8d | feat | Implementeer PartnerStatus service + _domeinmodel + _status-widget partials (GREEN Task 1) |
| 63ebfc6 | test | Failing tests voor blade-views + dev OAuth-init route (RED Task 2, +11 tests = 21 totaal) |
| 6575985 | feat | Wiring dev OAuth-init route + uitbreiding 3 partner-blade-views (GREEN Task 2) |
