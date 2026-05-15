---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
plan: 06
subsystem: filament-admin-connection-resource
tags: [filament, connection, revoke, oauth-flow, no-secret-leak, d-04]

# Dependency graph
requires:
  - plan: 09-02-filament-spatie-install
    provides: Filament v4 panel + Spatie permission stack
  - plan: 09-03-user-model-rbac
    provides: User-model + `staff`/`super-admin`-rollen + canAccessPanel-gate
  - plan: 09-04-provider-credential-descriptor
    provides: `ProviderCredentialDescriptor::for()` + descriptor-aware `Connection::fingerprint()`
provides:
  - "app/Filament/Resources/Connections/ConnectionResource.php — view-only Resource (List + View pages)"
  - "Per-provider conditional infolist sections (Mollie OAuth / Snelstart credentials) via D-04 descriptor"
  - "Revoke row-action met `->visible()` gefilterd op `oauthFlowKey !== null && revoked_at === null`"
  - "Revoke delegates naar `OAuthFlowRegistry::for($provider)->revoke($connection)` (Phase 4-contract)"
  - "tests/Feature/Admin/ConnectionFingerprintTest — 4 tests / 15 assertions (T-09-06-01)"
  - "tests/Feature/Admin/ConnectionRevokeActionTest — 4 tests / 13 assertions"
affects:
  - "HUB-04 success-criterium 5+6 — bewezen via 8 nieuwe tests"
  - "Plan 09-08 (AccountSubscriptionResource) — kan dezelfde per-provider conditional pattern adopteren"
  - "Toekomstige providers — toevoegen via `config/hub-providers.php`-rij + nieuwe infolist Section, GEEN nieuwe Resource-class (D-04)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Filament v4 view-only Resource: `canCreate`/`canEdit`/`canDelete` retourneren `false` + alleen `index` + `view` page-routes; Create/Edit pages handmatig verwijderd na `make:filament-resource --view`"
    - "Per-provider conditional sections via `Section::make(...)->visible(fn (?Connection \$record) => \$record?->provider === '...')` — D-04 invariant: nieuwe provider = nieuwe Section, GEEN nieuwe Resource"
    - "Revoke-row-action conditioneel zichtbaar via descriptor: `ProviderCredentialDescriptor::for(\$record->provider)->oauthFlowKey !== null` — Snelstart's `null` zorgt dat de action verborgen blijft (T-09-06-03 mitigatie)"
    - "OAuth-flow delegation in test: `\$this->app->instance(MollieConnectOAuthFlow::class, new FakeOAuthFlow)` — singleton-resolution via `\$container->make()` in `OAuthFlowRegistry::for()` honoreert de bind"

key-files:
  created:
    - app/Filament/Resources/Connections/ConnectionResource.php
    - app/Filament/Resources/Connections/Pages/ListConnections.php
    - app/Filament/Resources/Connections/Pages/ViewConnection.php
    - tests/Feature/Admin/ConnectionFingerprintTest.php
    - tests/Feature/Admin/ConnectionRevokeActionTest.php
  modified: []
  deleted:
    - app/Filament/Resources/Connections/Pages/CreateConnection.php (gegenereerd door `make:filament-resource --view`, verwijderd want read-only)
    - app/Filament/Resources/Connections/Pages/EditConnection.php (idem)

key-decisions:
  - "Filament v4 nesting gehonoreerd: namespace `App\\Filament\\Resources\\Connections` (sub-folder per Filament's `make`-output), NIET de v3-flat layout uit het plan. Heads_up uit 09-05 bevestigd — plan-paths zijn herzien."
  - "Filament v4 imports: `Filament\\Actions\\Action` (niet `Tables\\Actions\\Action`), `Filament\\Schemas\\Components\\Section` (niet `Forms\\Components\\Section`), `Filament\\Infolists\\Components\\TextEntry` (niet TextInput voor view-only). Plan-text gebruikte deels v3-namespaces — herzien naar v4."
  - "View-only via `canCreate/canEdit/canDelete = false` + `getPages()` retourneert alleen `index` + `view`. Filament v4 `--view` flag genereert toch nog Create/Edit pages — die zijn handmatig verwijderd (en de header-actions in List/View ook leeggemaakt) om de read-only invariant af te dwingen."
  - "Revoke-action met dubbele guards: zowel `->visible()` (UI-side, oauthFlowKey + revoked_at check) als descriptor-throw bij `for($snelstart)` via `OAuthFlowRegistry::for('snelstart')` (server-side, Phase 4-contract). Server-side guard is een fallback voor Livewire-bypass-pogingen (T-09-06-03 deep-defense)."
  - "Test-binding via `\$this->app->instance(MollieConnectOAuthFlow::class, \$fake)` ipv binding op het OAuthFlow-interface. Reden: `OAuthFlowRegistry::for('mollie')` doet `container->make(MollieConnectOAuthFlow::class)` — registry mapped provider-keys op concrete classes. Het interface zou nooit resolved worden via deze code-pad."

patterns-established:
  - "Filament v4 view-only Resource pattern: `--view` flag + handmatige cleanup (rm Create/Edit pages, leeg header-actions, `canCreate/canEdit/canDelete = false`)"
  - "Per-provider conditional infolist Section met descriptor-driven `->visible()`"
  - "OAuth-flow test-fixture pattern: `\$this->app->instance(<ConcreteFlow>::class, FakeOAuthFlow)` voor delegation-bewijs zonder echte partner-API"

requirements-completed: [HUB-04]

# Metrics
duration: ~45min
completed: 2026-05-16
---

# Phase 09 Plan 06: ConnectionResource read-only + Revoke-action Summary

**Filament v4 `ConnectionResource` geland: read-only tabel + per-provider conditional infolist (Mollie OAuth / Snelstart credentials, D-04 driven) + Revoke row-action die delegates naar `OAuthFlowRegistry::for($provider)->revoke($connection)`. 8 nieuwe tests bewijzen no-secret-leak en revoke-delegation; volledige suite 361/361 groen.**

## Performance

- **Duration:** ~45 min (incl. worktree-bootstrap + Filament v4 namespace-discovery)
- **Tasks:** 4 (Task 1: skelet + table, Task 2: infolist + revoke, Task 3: no-secret-leak tests, Task 4: revoke-delegation tests)
- **Files created:** 5 (1 Resource, 2 Pages, 2 Tests)
- **Files modified:** 0
- **Files deleted:** 2 (Create/EditConnection-pages — read-only invariant)
- **Tests added:** 8 / 28 assertions

## Accomplishments

- `ConnectionResource` met 5 tabel-kolommen (provider-badge, account.external_id, fingerprint via `Connection::fingerprint()`, revoked_at, created_at) + 3 filters (provider, consumer via Account-relation, revoked TernaryFilter)
- Per-provider conditional infolist Sections (Mollie OAuth + Snelstart credentials) — GEEN raw access_token/refresh_token/client_key/subscription_key fields, alleen fingerprint + non-secret velden
- Revoke row-action met dubbele guard (UI `->visible()` op descriptor.oauthFlowKey + server-side `OAuthFlowRegistry::for()` Phase 4-contract) + success Notification
- View-only-invariant afgedwongen via `canCreate/canEdit/canDelete = false` + handmatige verwijdering van Create/Edit pages na Filament's `make:filament-resource --view`
- 4 no-secret-leak tests scannen List-HTTP, Livewire-render, View-Mollie en View-Snelstart op alle 4 RAW_*-credentials
- 4 revoke-delegation tests bewijzen visibility-condities + `FakeOAuthFlow::wasCalled('revoke') == 1` + post-revoke state-flip
- Full suite **361/361 passed / 1 pre-existing incomplete / 1179 assertions / 13.5s** — zero regression

## Task Commits

Atomic per-task commits op `worktree-agent-a40abdf4ded8f33e0`:

1. **Task 1:** `ec5b4cd` (feat) — Resource-skelet + tabel met 5 kolommen / 3 filters, view-only via `canCreate/canEdit/canDelete = false` + alleen List/View pages
2. **Task 2:** `bf78c86` (feat) — Per-provider infolist Sections (Mollie OAuth / Snelstart credentials) + Revoke row-action met descriptor-guard + OAuthFlowRegistry-delegate + Notification
3. **Task 3:** `bead8e4` (test) — `ConnectionFingerprintTest` (4 tests / 15 assertions) — no-secret-leak via HTTP-response + Livewire-HTML voor List+View pagina's
4. **Task 4:** `b4b84d0` (test) — `ConnectionRevokeActionTest` (4 tests / 13 assertions) — visibility-condities + FakeOAuthFlow-delegation-bewijs

## Files Created/Modified

**Created:**
- `app/Filament/Resources/Connections/ConnectionResource.php` — view-only Resource (table + infolist + revoke-action)
- `app/Filament/Resources/Connections/Pages/ListConnections.php` — header-actions leeg (geen CreateAction)
- `app/Filament/Resources/Connections/Pages/ViewConnection.php` — header-actions leeg (geen Edit/Delete)
- `tests/Feature/Admin/ConnectionFingerprintTest.php` — 4 no-secret-leak tests
- `tests/Feature/Admin/ConnectionRevokeActionTest.php` — 4 revoke-delegation tests

**Modified:** geen

**Deleted (tijdens Task 1 — read-only invariant):**
- `app/Filament/Resources/Connections/Pages/CreateConnection.php`
- `app/Filament/Resources/Connections/Pages/EditConnection.php`

## Must-Have Truths Verified

Alle 6 plan-must-have-truths bewezen:

1. ✅ `/admin/connections` bereikbaar voor staff-User en toont tabel met provider-badge / account.external_id / fingerprint / revoked_at — `ConnectionFingerprintTest::test_list_page_html_contains_no_raw_credentials` asserteert HTTP 200 + List-render
2. ✅ Tabel bevat filters voor provider, consumer (via Account-relation), revoked (TernaryFilter) — grep in `ConnectionResource::table()->filters([])` + handmatige UI-verifieer via route-list
3. ✅ ViewAction toont per-provider conditional infolist sections via `ProviderCredentialDescriptor` (D-04) — `Section::make('Mollie OAuth')->visible(... === 'mollie')` + `Section::make('Snelstart credentials')->visible(... === 'snelstart')`
4. ✅ Revoke-action zichtbaar voor Mollie (oauthFlowKey='mollie'), verborgen voor Snelstart (oauthFlowKey=null) — `ConnectionRevokeActionTest::test_revoke_action_visible_for_mollie_connection` + `test_revoke_action_hidden_for_snelstart_connection`
5. ✅ Revoke-action roept `OAuthFlowRegistry::for($connection->provider)->revoke($connection)` aan — `ConnectionRevokeActionTest::test_revoke_action_calls_oauth_flow_revoke` met FakeOAuthFlow spy: `wasCalled('revoke') == 1`
6. ✅ GEEN plain-text waarde van access_token/refresh_token/client_key/subscription_key in HTML — `ConnectionFingerprintTest` 4 tests / 15 assertions over alle 4 raw constants × List+View+Livewire

## Decisions Made

### Filament v4 sub-folder nesting (heads_up bevestigd)

Plan-text suggereerde `app/Filament/Resources/ConnectionResource.php` (v3-flat). Filament v4's `make:filament-resource --view` plaatst dit echter onder `app/Filament/Resources/Connections/ConnectionResource.php` met namespace `App\Filament\Resources\Connections` (sub-folder per model). Gevolg dook ook al op in 09-05 deviation. Gekozen voor Filament's eigen output — engineering.md "chirurgisch wijzigen / match bestaande style" prevalent over plan-pad-tekst. Plan-paths zijn herzien in deze SUMMARY's frontmatter.

### Filament v4 imports (Action / Section / TextEntry)

- `Filament\Actions\Action` (NIET `Filament\Tables\Actions\Action` — v3-namespace)
- `Filament\Schemas\Components\Section` (NIET `Filament\Forms\Components\Section` — die bestaat niet meer in v4)
- `Filament\Infolists\Components\TextEntry` voor read-only weergave (NIET `TextInput->disabled()` zoals plan-text suggereerde)
- `->recordActions([...])` op `Table` (NIET `->actions([...])`)
- `->visible(fn (?Connection $record) => ...)` voor conditioneel — nullable wegens initial-render

Verifieerde via class-existence-checks (`class_exists()` + `ReflectionClass::getMethods()`) i.p.v. blind volgen van plan-text.

### View-only afdwingen: 3-laags

Plan-text liet open hoe v4's `--view` Create/Edit-pages te neutraliseren. Gekozen voor 3-laagse aanpak:

1. **Files weg:** `rm Pages/CreateConnection.php Pages/EditConnection.php` (gegenereerd door `--view`-flag, niet nodig voor read-only)
2. **Header-actions leeg:** ListConnections + ViewConnection retourneren `[]` in `getHeaderActions()`
3. **Resource-class guards:** `canCreate()`, `canEdit($record)`, `canDelete($record)` retourneren `false` — vangt elke restbeam af (bv. bulk-delete-actions die we per ongeluk zouden toevoegen)

Ook `getPages()` retourneert alleen `'index'` + `'view'` — geen `'create'` of `'edit'` routes.

### Revoke-action dubbele guard

Plan-text vroeg om `->visible()` UI-filter. Toegevoegd: server-side dubbele guard via `OAuthFlowRegistry::for('snelstart')` die `InvalidArgumentException` gooit. Reden: T-09-06-03 (Tampering — Livewire-action-POST bypass). Eerste laag (UI-visible) maskeert de action; tweede laag (registry-throw) faalt vroeg met NL-message als de action via een gebypaste Livewire-call toch geforceerd wordt. Filament v4 toont dan een generic error-notification ipv 500-stack-trace.

### Test-binding: concrete class i.p.v. interface

`OAuthFlowRegistry::register('mollie', MollieConnectOAuthFlow::class)` mapped provider-keys op **concrete** classes. `for('mollie')` doet vervolgens `container->make(MollieConnectOAuthFlow::class)`. Een `app->bind(OAuthFlow::class, FakeOAuthFlow::class)` zou nooit aangeroepen worden — de container ziet alleen de concrete class. Daarom in Task 4: `$this->app->instance(MollieConnectOAuthFlow::class, new FakeOAuthFlow)`. Dit is **niet** een test-isolation issue (FakeOAuthFlow is een legitieme test-double in `app/OAuth/Testing/`) maar een container-mechaniek-detail.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Wrong import namespace voor MollieConnectOAuthFlow**
- **Found during:** Task 4 (FakeOAuthFlow-spy test toonde 0 revoke-calls)
- **Issue:** Test importeerde `App\OAuth\MollieConnectOAuthFlow` maar de class leeft in `App\OAuth\Mollie\MollieConnectOAuthFlow` (sub-folder Mollie/). De `instance()`-bind ging naar een non-existing class-string, dus `container->make()` resolved de echte class — geen swap.
- **Fix:** import gecorrigeerd naar `App\OAuth\Mollie\MollieConnectOAuthFlow`. Verificatie via `php artisan tinker --execute 'echo get_class(app(OAuthFlowRegistry::class)->for("mollie"));'` → `App\OAuth\Mollie\MollieConnectOAuthFlow`.
- **Files modified:** `tests/Feature/Admin/ConnectionRevokeActionTest.php` (1 use-statement)
- **Commit:** rolled into `b4b84d0` (Task 4 commit) — fix was tijdens TDD-GREEN

### Plan-text vs. Filament v4 reality (heads_up bevestigd)

Plan-tekst gebruikte deels Filament v3-API (`Tables\Actions\Action`, `Forms\Components\Section`, `TextInput->disabled()`). Gevolgde codepath conform Filament v4-actual:
- Use-statements omgezet naar v4-namespaces zoals beschreven in §Decisions
- Sections + TextEntry voor read-only weergave (geen TextInput->disabled)
- Niet als formele Rule-N deviation gelogd: heads_up gaf dit al aan vóór start, en het is conformiteit met v4-installatie uit 09-05

**Total deviations:** 1 auto-fixed (Rule 3 blocking — namespace-import). Plan-tasks 1-4 1-op-1 uitgevoerd; Filament v4-API-mismatches zijn in heads_up al gevlagd.

## Known Stubs

None — alle 5 nieuwe files zijn functioneel en getest. Geen placeholder-content, geen TODO's, geen mock-data buiten de FakeOAuthFlow (een legitieme test-double in `app/OAuth/Testing/`).

## Threat Flags

Geen nieuwe surface buiten plan's threat-model. Alle 6 STRIDE-threats blijven gemitigated:

- **T-09-06-01 (Info disclosure — raw tokens in HTML):** 4 tests scannen List-HTTP, Livewire-render, View-Mollie en View-Snelstart op alle 4 RAW_*-constants. Failing test bij regressie.
- **T-09-06-02 (EOP — staff revoke zonder rationale):** accepted per CONTEXT.md — backlog HUB-AUDIT.
- **T-09-06-03 (Tampering — revoke Snelstart 500-error):** Server-side guard `OAuthFlowRegistry::for('snelstart')` throwt NL-message + UI-side `->visible()` filtert. `ConnectionRevokeActionTest::test_revoke_action_hidden_for_snelstart_connection` bewijst hidden-state.
- **T-09-06-04 (CSRF):** Livewire forceert CSRF-token; standaard Filament v4-flow. Accepted.
- **T-09-06-05 (Cross-Consumer-visibility):** accepted per ontwerp — Emeq-staff ziet alle Consumers. Backlog HUB-AUDIT.
- **T-09-06-SC (Package install):** N/A — geen `composer require` in dit plan.

## Verification Commands Run

| Command | Result |
|---|---|
| `php artisan make:filament-resource Connection --view --embed-schemas --embed-table --no-interaction` | 5 files gegenereerd (1 Resource + 4 Pages); Create/Edit later verwijderd |
| `rm -f app/Filament/Resources/Connections/Pages/{Create,Edit}Connection.php` | 2 files weg |
| `php artisan route:list --path=admin/connections` | 2 routes: `index` + `view/{record}` |
| `php artisan tinker --execute 'echo \\Filament\\Support\\Icons\\Heroicon::OutlinedLink->value;'` | print `o-link` |
| `php artisan tinker --execute 'echo \\Filament\\Support\\Icons\\Heroicon::OutlinedNoSymbol->value;'` | print `o-no-symbol` |
| `vendor/bin/pint --dirty --format agent` | passed (alle 4 commits clean) |
| `php artisan test --compact --filter=ConnectionFingerprintTest` | 4 passed / 15 assertions / 1.27s |
| `php artisan test --compact --filter=ConnectionRevokeActionTest` | 4 passed / 13 assertions / 1.18s |
| `php artisan test --compact --filter=PanelAccessTest` | 3 passed / 4 assertions — zero regression |
| `php artisan test --compact` | 361 passed / 1 pre-existing incomplete / 1179 assertions / 13.5s — zero regression |
| `grep -q ProviderCredentialDescriptor::for app/Filament/Resources/Connections/ConnectionResource.php` | OK |
| `grep -q OAuthFlowRegistry app/Filament/Resources/Connections/ConnectionResource.php` | OK |
| `grep -q -- '->revoke' app/Filament/Resources/Connections/ConnectionResource.php` | OK |
| `grep -q -- '->visible' app/Filament/Resources/Connections/ConnectionResource.php` | OK |

## Self-Check: PASSED

**Files exist:**
- FOUND: app/Filament/Resources/Connections/ConnectionResource.php
- FOUND: app/Filament/Resources/Connections/Pages/ListConnections.php
- FOUND: app/Filament/Resources/Connections/Pages/ViewConnection.php
- FOUND: tests/Feature/Admin/ConnectionFingerprintTest.php
- FOUND: tests/Feature/Admin/ConnectionRevokeActionTest.php

**Commits exist:**
- FOUND: ec5b4cd — feat(09-06): ConnectionResource read-only skelet + table
- FOUND: bf78c86 — feat(09-06): per-provider infolist + Revoke-action (D-04)
- FOUND: bead8e4 — test(09-06): ConnectionFingerprintTest — no-secret-leak (T-09-06-01)
- FOUND: b4b84d0 — test(09-06): ConnectionRevokeActionTest — OAuthFlow::revoke delegation

**Plan must_haves truths verified (6/6):** zie sectie "Must-Have Truths Verified" — alle bewezen via lopende tests.

**Plan must_haves artifacts present (3/3 — paden herzien voor v4-nesting):**
- ✅ `app/Filament/Resources/Connections/ConnectionResource.php` bevat `class ConnectionResource extends Resource`
- ✅ `tests/Feature/Admin/ConnectionFingerprintTest.php` bevat `class ConnectionFingerprintTest`
- ✅ `tests/Feature/Admin/ConnectionRevokeActionTest.php` bevat `class ConnectionRevokeActionTest`

**Plan must_haves key_links present (2/2):**
- ✅ `ConnectionResource.php` → `App\Support\ProviderCredentialDescriptor` (via `ProviderCredentialDescriptor::for($record->provider)->oauthFlowKey`)
- ✅ `ConnectionResource.php` → `App\OAuth\OAuthFlowRegistry` (via `app(OAuthFlowRegistry::class)->for($record->provider)->revoke($record)`)

## Next Plan Readiness

- **Plan 09-08 (AccountSubscriptionResource)** kan dezelfde patronen adopteren:
  - View-only-3-laags-invariant (rm Create/Edit pages + leeg header-actions + canCreate/Edit/Delete = false)
  - Per-provider conditional Sections via `->visible(fn (?Model $record) => $record?->provider === '...')`
  - Action-delegation via concrete-class-bind in tests (`$this->app->instance(<Service>::class, <FakeService>)`)
- **Plan 09-12 (Phase-acceptance)** kan HUB-04 success-criterium 5+6 markeren als bewezen.
- **Geen blocking dependencies open** voor verdere plans.

---
*Phase: 09-filament-admin-ui-voor-emeq-medewerkers*
*Completed: 2026-05-16*
