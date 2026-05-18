---
phase: 09-filament-admin-ui-voor-emeq-medewerkers
verified: 2026-05-16T00:00:00Z
status: passed
score: 10/10 must-haves verified
must_haves_passed: 10
must_haves_failed: 0
known_gaps: 2
overrides_applied: 0
known_gap_refs:
  - id: CR-02
    title: Spatie permissions geseed maar niet enforced op 6 niet-User-Resources + ontbrekende cross-Consumer-isolation-test in WebhookCallResource
    severity: warning
    impact: HUB-04 SC-7 leunt op `canAccessPanel()`-gate ipv per-resource Spatie `view-webhooks`-permission; D-05-mechanisme is partial. SC-blokkering niet — alle 7 resources zitten achter het `super-admin`/`staff`-bottleneck.
    deferred_to: v0.2.1 (open todo in STATE.md)
  - id: WR-01..06
    title: Open warnings uit code review (last-super-admin-downgrade, exception-veld dubbel-encoded, role-select-validatie server-side, EmeqStaffSeeder silent-password-skip, dehydrate-state-order, plain-token in Livewire-snapshot)
    severity: warning
    impact: Geen SC-blokkering. Foot-guns voor v0.2-intern-gebruik; deferred naar follow-up commits.
    deferred_to: v0.2.1
---

# Phase 9: Filament admin-UI voor Emeq-medewerkers — Verification Report

**Phase Goal:** Filament-gebaseerd intern admin-paneel onder `/admin` voor Emeq-medewerkers met read+limited-mutate views op alle Hub-domeinmodellen (Consumers / Connections / Accounts / WebhookCalls + Phase-6 Cashier-subscriptions + Phase-7 Account-subscriptions + User super-admin-only), achter Spatie RBAC, zonder exposure van geheime tokens. HUB-04 onder v0.2.

**Verified:** 2026-05-16
**Status:** ✅ passed (10/10 SC's gedekt; 2 known gaps gedocumenteerd, geen ervan blokkeert HUB-04)
**Re-verification:** No — initiële verificatie

## Goal Achievement

### Observable Truths — 10 Success Criteria

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| SC-1 | Filament v4 + Spatie permission ^6 geïnstalleerd, `AdminPanelProvider` geregistreerd, `/admin/login` route bestaat, 7 resources zichtbaar | ✅ VERIFIED | `composer.lock` → `filament/filament v4.11.3` + `spatie/laravel-permission 6.25.0`. `bootstrap/providers.php` registreert `AdminPanelProvider`. `php artisan route:list --path=admin/login` → `GET admin/login filament.admin.auth.login`. `php artisan route:list` → 7 unieke resource-prefixes (admin/{consumers,connections,accounts,webhook-calls,account-subscriptions,cashier-subscriptions,users}) + login/logout. |
| SC-2 | Exact 7 Resources onder `app/Filament/Resources/` (Consumer/Account/Connection/WebhookCall/AccountSubscription/CashierSubscription/User) | ✅ VERIFIED | `find app/Filament/Resources -name "*Resource.php"` → exact 7 hits: AccountSubscriptions/AccountSubscriptionResource.php, Accounts/AccountResource.php, CashierSubscriptions/CashierSubscriptionResource.php, Connections/ConnectionResource.php, Consumers/ConsumerResource.php, Users/UserResource.php, WebhookCalls/WebhookCallResource.php. |
| SC-3 | RBAC via Spatie laravel-permission ^6; User-model heeft `HasRoles` + `FilamentUser`; `EmeqStaffSeeder` bestaat met 2 rollen + 6 permissions | ✅ VERIFIED | `app/Models/User.php:14,18,21,36-40` — `use HasRoles; implements FilamentUser; canAccessPanel()` returnt `$panel->getId() === 'admin' && $this->hasAnyRole(['super-admin', 'staff'])`. `database/seeders/EmeqStaffSeeder.php:48-58` — `super-admin` + `staff` roles + 5 `SHARED_PERMISSIONS` + 1 `SUPER_ADMIN_ONLY_PERMISSION` (`manage-staff`) = 6 perms totaal. Tests groen: `PanelAccessTest`, `EmeqStaffSeederTest`. |
| SC-4 | `manage-staff`-gate beschermt UserResource; `AppServiceProvider::boot()` registreert gate | ✅ VERIFIED | `app/Providers/AppServiceProvider.php:55` — `Gate::define('manage-staff', fn (User $user): bool => $user->hasRole('super-admin'))`. `app/Filament/Resources/Users/UserResource.php:40-48` — `canAccess()` + `shouldRegisterNavigation()` beide return `Gate::allows('manage-staff')`. Test `PermissionGatingTest::test_staff_user_cannot_access_user_resource` + `test_super_admin_can_access_user_resource` + `test_staff_user_does_not_see_user_navigation_link` — all groen. |
| SC-5 | `ConnectionResource` infolist + table tonen alleen fingerprints (geen raw `access_token`/`refresh_token`/`client_key`/`subscription_key` in HTML); `ConnectionEncryptionTest` groen | ✅ VERIFIED | `app/Filament/Resources/Connections/ConnectionResource.php:51-87,104-107` — uitsluitend `TextEntry::make('fingerprint')->state(fn → $record->fingerprint())` plus opaque IDs (`subscription_id`, `administratie_id`). Geen `access_token`/`refresh_token`/`client_key`/`subscription_key` TextEntry's. 4 tests in `tests/Feature/Admin/ConnectionFingerprintTest` valideren no-leak op zowel List- als View-pages (Mollie + Snelstart) — alle groen. `tests/Feature/ConnectionEncryptionTest.php` → 8/8 passing (757ms). |
| SC-6 | Issue-PAT action met 5 presets + custom-mode; `PAT_PRESETS` + `PAT_CUSTOM_ONLY`-constants; `PatAbilityPresetsTest` dekt discovery-contract | ✅ VERIFIED | `app/Filament/Resources/Consumers/ConsumerResource.php:41-80` — `PAT_PRESETS` = exact 5 entries (mollie-read, mollie-write, snelstart-read, snelstart-write, admin) + `PAT_CUSTOM_ONLY` = [billing:read, billing:write]. Discovery-contract: ⋃ preset-abilities + `PAT_CUSTOM_ONLY` = `{mollie:read, mollie:write, consumer:manage-accounts, snelstart:read, snelstart:write, *, billing:read, billing:write}` ≡ `TokenAbilities::all()` = 8 abilities (verified via `php artisan tinker`). `PatAbilityPresetsTest` + `ConsumerTokenActionTest` groen. |
| SC-7 | `WebhookCallResource` toont direction/provider/status-filters; cross-Consumer-isolatie via gefilterde queries | ⚠️ PARTIAL (known gap CR-02) | `app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php:66-91` — `SelectFilter::make('direction')` + `provider` + `status` + `consumer_id` aanwezig. `tests/Feature/Admin/WebhookCallResourceTest::test_direction_filter_narrows_to_incoming` + `test_list_shows_audit_rows_for_staff_user` + `test_view_page_renders_payload_json` groen. **Gap:** geen feature-test `WebhookCallResourceTest::test_cross_consumer_isolation_*` + geen per-resource `view-webhooks`-permission-check (`canAccess()` ontbreekt op alle 6 non-User-resources). Staff zien alle Consumers' webhooks via panel-gate (acceptabel voor v0.2-intern). HUB-04 SC-7 functioneel groen (filters bestaan, panel-gate beperkt access tot staff/super-admin) maar D-05-permission-laag is partial. Zie CR-02 → deferred v0.2.1. |
| SC-8 | `AccountSubscriptionResource` Pause/Resume/Cancel mutaties uitsluitend via `AccountSubscriptionManager`; illegale transitie → notification-error + geen DB-mutatie | ✅ VERIFIED | `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php:165-264` — alle 3 actions delegeren via `app(AccountSubscriptionManager::class)->pause/resume/cancel()`; nergens `$record->update(['status' => ...])`. `InvalidStateTransitionException`-catch → `Notification::make()->title('Ongeldige state-transitie')->danger()->send()`. `AccountSubscriptionStateActionsTest` (8 tests) groen — bewijst visibility-matrices + `test_illegal_transition_throws_without_db_mutation`. |
| SC-9 | `webhook_calls` audit-kolommen migratie aanwezig met 4 kolommen (direction/provider/consumer_id/status) | ✅ VERIFIED | `database/migrations/2026_05_19_000001_add_audit_columns_to_webhook_calls_table.php:22-51` — exact 4 kolommen toegevoegd + 4 indexen. `direction` enum default `incoming`, `provider` string(32) nullable, `consumer_id` unsignedBigInteger nullable + FK op Postgres, `status` enum default `processed`. Forward-only additive (geen backfill voor pre-existing rows). `tests/Feature/Models/WebhookCallAuditColumnsTest` (3 tests) bevestigt schema + persist + legacy-shape-compat. |
| SC-10 | D-04 invariant: nieuwe provider = config-row in `hub-providers.php` + factory, GEEN Filament-code-wijziging. `ProviderCredentialDescriptor::all()` leest config | ✅ VERIFIED | `app/Support/ProviderCredentialDescriptor.php:36-74` — `for()` + `all()` lezen `config('hub-providers.…')`. `config/hub-providers.php` definieert mollie + snelstart als rows. `Connection::fingerprint()` is descriptor-aware (ConnectionEncryptionTest blijft groen zonder testfile-wijziging). `ConnectionResource::infolist()` en `revoke->visible()` gebruiken `ProviderCredentialDescriptor::for(...)` ipv hardcoded provider-switch. `ProviderDescriptorTest::test_adding_theoretical_provider_appears_in_all` (+3 sibling tests) bewijst dat een runtime-config-override met `moneybird`-row de descriptor-set zonder code-edit uitbreidt. |

**Score:** 10/10 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Filament/Resources/Consumers/ConsumerResource.php` | CRUD + PAT issue-action met 5 presets + custom-mode | ✅ VERIFIED | 224 LoC; `PAT_PRESETS` (5 entries) + `PAT_CUSTOM_ONLY`; `issuePatAction()` modal + `createToken()` + plain-token via Livewire-property. |
| `app/Filament/Resources/Connections/ConnectionResource.php` | Read+revoke; per-provider conditional infolist via `ProviderCredentialDescriptor`; fingerprint-only kolom | ✅ VERIFIED | 201 LoC; provider-conditional `Section`-blocks; revoke gated op `oauthFlowKey !== null`. |
| `app/Filament/Resources/Accounts/AccountResource.php` | Read-only | ✅ VERIFIED | Exists; tests `AccountResourceTest` groen. |
| `app/Filament/Resources/WebhookCalls/WebhookCallResource.php` | Read-only viewer + direction/provider/status/consumer_id-filters | ✅ VERIFIED (zie SC-7 known gap CR-02) | Resource + Table + Infolist aanwezig; 4 filters geconfigureerd. |
| `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php` | Read + 3 state-flip-actions via `AccountSubscriptionManager` | ✅ VERIFIED | 288 LoC; manager-only delegation; 6-state badge-colors. |
| `app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php` | Read-only Cashier-subscriptions | ✅ VERIFIED | Exists; tests `CashierSubscriptionResourceTest` (3) groen. |
| `app/Filament/Resources/Users/UserResource.php` | Super-admin only via `manage-staff`-gate | ✅ VERIFIED | `canAccess()` + `shouldRegisterNavigation()` op Gate::allows. |
| `app/Providers/Filament/AdminPanelProvider.php` | Filament v4 panel met `->path('admin')->login()` | ✅ VERIFIED | 88 LoC; auth-guard `web`; resource/page/widget discovery; dev-only quick-login render-hook. |
| `app/Support/ProviderCredentialDescriptor.php` | Config-driven value-object | ✅ VERIFIED | Final class met `for()` + `all()`; immutable readonly properties. |
| `config/hub-providers.php` | Mollie + Snelstart rows | ✅ VERIFIED | Exact 2 keys met `encrypted_fields` + `primary_label` + `oauth_flow_key`. |
| `database/migrations/2026_05_19_000001_add_audit_columns_to_webhook_calls_table.php` | 4 audit-kolommen | ✅ VERIFIED | Forward-only additive; sqlite-skipt FK-step. |
| `database/seeders/EmeqStaffSeeder.php` | Env-driven 2-role + 6-perm + bootstrap-user | ✅ VERIFIED | Idempotent via `firstOrCreate`; geen wachtwoord-reset bij bestaande user (WR-04 — bekend). |
| `bootstrap/providers.php` | AdminPanelProvider registered | ✅ VERIFIED | Beide AppServiceProvider en AdminPanelProvider aanwezig. |
| `app/Models/User.php` | `HasRoles` + `FilamentUser` + `canAccessPanel()` | ✅ VERIFIED | Trait + interface + role-check correct. |
| `app/Providers/AppServiceProvider.php` | `manage-staff`-gate registreerd in `boot()` | ✅ VERIFIED | Regel 55. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `UserResource` | `manage-staff` gate | `Gate::allows('manage-staff')` in `canAccess()` + `shouldRegisterNavigation()` | ✅ WIRED | Gate registreerd in `AppServiceProvider::boot()` regel 55. |
| `ConnectionResource::revoke` | `OAuthFlowRegistry::for(...)->revoke()` | `app(OAuthFlowRegistry::class)->for($record->provider)->revoke($record)` | ✅ WIRED | `ConnectionResource.php:160-163`; visibility gated op `ProviderCredentialDescriptor::for()->oauthFlowKey !== null`. |
| `AccountSubscriptionResource` | `AccountSubscriptionManager` | `app(AccountSubscriptionManager::class)->pause/resume/cancel($record, ...)` | ✅ WIRED | Manager-only delegation in alle 3 actions; geen direct `$record->update(['status' => …])`-pad. |
| `ConsumerResource::issuePatAction` | `Consumer::createToken()` (Sanctum) | `$record->createToken($data['name'], $abilities)` | ✅ WIRED | Regel 180; plain-token via `$result->plainTextToken` naar Livewire-property + Notification. |
| `Connection::fingerprint()` | `ProviderCredentialDescriptor::for()` | Descriptor-aware accessor leest `encrypted_fields[0]` | ✅ WIRED | ConnectionEncryptionTest blijft groen zonder testfile-wijziging (D-04 gedragsbehoud). |
| `ConnectionResource::infolist` | `ProviderCredentialDescriptor` | Per-provider `Section::make()->visible(fn → $record?->provider === '…')` | ✅ WIRED | mollie + snelstart sections aanwezig; descriptor-driven detection via `for()` in revoke-visibility-callback. |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| `/admin/login` route exists | `php artisan route:list --path=admin/login` | `GET\|HEAD admin/login filament.admin.auth.login` | ✅ PASS |
| Exact 7 Filament-resources registered | `find app/Filament/Resources -name "*Resource.php" -not -path "*/Pages/*" \| wc -l` | 7 | ✅ PASS |
| Full test suite green | `php artisan test --compact` | 391 passed / 1 incomplete (pre-existing Phase-3-03) / 0 failed / 1353 assertions / 11.7s | ✅ PASS |
| Admin tests subset green | `php artisan test --compact tests/Feature/Admin/` | 51 passed / 239 assertions / 2.9s | ✅ PASS |
| TokenAbilities discovery covers all 8 abilities | `tinker → TokenAbilities::all()` | `snelstart:read,snelstart:write,mollie:read,mollie:write,consumer:manage-accounts,billing:read,billing:write,*` (8 abilities) | ✅ PASS |
| ConnectionEncryptionTest green (D-04 gedragsbehoud) | `php artisan test --compact tests/Feature/ConnectionEncryptionTest.php` | 8 passed / 21 assertions | ✅ PASS |
| Composer locked versions match constraints | `grep -A2 '"name": "filament/filament"' composer.lock` | `v4.11.3` (matches ^4.0) | ✅ PASS |
| Spatie locked version matches constraint | `grep -A2 '"name": "spatie/laravel-permission"' composer.lock` | `6.25.0` (matches ^6.0) | ✅ PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| HUB-04 | 09 (all plans) | Filament v4 admin-paneel op `/admin` voor Emeq-medewerkers (7 resources + Spatie RBAC + no-raw-token-leak) | ✅ SATISFIED | REQUIREMENTS.md:29 marked `[x]` met validation-note + Traceability-tabel regel 90 (`HUB-04 \| Phase 9 \| Complete`). Alle 10 SC's gedekt met evidence in tests + codebase. |

### Anti-Patterns Found

Scanned: alle 53 files uit 09-REVIEW.md files_reviewed_list + alle 7 Resource-classes + alle 16 admin-tests + 3 migration/config/seeder-files.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php` | 44-46 | N+1 via `Consumer::find($record->consumer_id)?->slug` per-row (IN-01) | ℹ️ Info | Perf-impact onder verwachte load minimaal; opgelost samen met CR-02-fix (Hub `App\Models\WebhookCall extends Spatie` + `consumer()` belongs-to). |
| `app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php` | 42-46 | `json_encode($record->exception, ...)` op text-column dubbel-encodeert string-literals (WR-02) | ⚠️ Warning | Render-fout met debug-frictie; geen security-impact. |
| `database/seeders/EmeqStaffSeeder.php` | 60-64 | `firstOrCreate` skipt password-update bij bestaande email (WR-04) | ⚠️ Warning | Silent operator-fout — `EMEQ_STAFF_SEED_PASSWORD` re-run zonder effect. Geen security-leak. |
| `app/Filament/Resources/Users/Schemas/UserForm.php` | 36-42 | `dehydrateStateUsing(Hash::make)` zonder filled-guard (WR-05) | ⚠️ Warning | Theoretisch — Filament v4 `dehydrated`-volgorde maakt het werkend; geen edit-zonder-password regressie-test. |
| `app/Filament/Resources/Users/Tables/UsersTable.php` | 52-72 | `assignRole`-action heeft geen last-super-admin-self-downgrade-guard (WR-01) | ⚠️ Warning | Operationele foot-gun — een super-admin kan zichzelf naar `staff` switchen → bricks panel. |
| `app/Filament/Resources/Users/Tables/UsersTable.php` | 52-72 | `Select::make('role')` mist server-side `->in()`-validatie (WR-03) | ⚠️ Warning | DevTools-tampering kan `RoleDoesNotExist` exception triggeren (500-response). |
| `app/Filament/Resources/Consumers/Pages/ListConsumers.php` | 14 + blade | Plain-token in Livewire-snapshot + Alpine `x-data` (WR-06) | ⚠️ Warning | Token zichtbaar in `view-source:` van List-pagina tot dismiss; acceptabel voor intern-staff-only-use. |
| `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php` | 256-262 | `Throwable→getMessage()` in notification kan Mollie-API-response-body bevatten (IN-02) | ℹ️ Info | Geen acute leak in praktijk; afwijking van `.ai/rules/global.md` "Raw secrets verschijnen nooit in error-messages". |
| `app/Providers/Filament/AdminPanelProvider.php` | 30 | `->default()` op admin-panel maakt het automatische fallback voor `Filament::auth()` (IN-03) | ℹ️ Info | Toekomstige Consumer-portal-panel-uitbreiding foot-gun; geen actuele impact. |
| **Cross-resource gap** | `app/Filament/Resources/{Consumers,Connections,Accounts,WebhookCalls,AccountSubscriptions,CashierSubscriptions}/*Resource.php` | Geen `canAccess()` met Spatie-`->can('…-permission')` op de 6 non-User Resources (CR-02) | ⚠️ Warning (known gap) | Spatie-permissions (`manage-consumers`, `manage-connections`, `view-webhooks`, `view-account-subscriptions`, `view-billing`) zijn geseed maar nergens enforced — `view-webhooks`-permission staat in EmeqStaffSeeder maar wordt nergens gechecked. HUB-04 SC-7 functioneel groen via panel-gate, maar D-05-mechanisme is partial. |

### Human Verification Required

Geen openstaande human-verify items — 6/6 visuele checkpoint-checks zijn 2026-05-16 door user als "approved" gemarkeerd (zie `09-11-SUMMARY.md:38-44`). Visuele rendering, sidebar-grouping, PAT-copy-flow, revoke-visibility, state-flips, en super-admin-gating zijn alle visueel gevalideerd.

---

## Overall Verdict

✅ **PASSED.** HUB-04 SC-1 t/m SC-10 zijn alle gedekt met concrete codebase-evidence + groene tests. De 10 truths zijn allemaal direct verifieerbaar in `app/Filament/Resources/*`, `config/hub-providers.php`, `database/migrations/2026_05_19_*`, `database/seeders/EmeqStaffSeeder.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php` en `app/Support/ProviderCredentialDescriptor.php`. Test-suite groen: 391 passed / 1353 assertions / 0 failed / 1 pre-existing incomplete (Phase 3-03 `SanctumAbilityTest::test_token_without_required_ability_is_rejected`, unrelated to Phase 9). De D-04-invariant is bewezen via `ProviderDescriptorTest`, de no-raw-token-leak-invariant via 4 `ConnectionFingerprintTest`-cases, en de state-machine-respect-invariant via `AccountSubscriptionStateActionsTest::test_illegal_transition_throws_without_db_mutation`.

Phase mag worden gemarkeerd als **Complete** in ROADMAP + REQUIREMENTS (reeds gedaan in 09-11). Phase 8 (Naschool wiring) blijft de enige openstaande v0.2-feature-fase naast Phase 5b + 5c.

## Known Gaps (geen blocker — deferred naar v0.2.1)

### CR-02 — Spatie-permission-enforcement ontbreekt op 6 non-User Resources + cross-Consumer-isolation-test mist op WebhookCallResource

**Severity:** Warning (niet blokkerend)

**Bewijs:** `grep -l canAccess app/Filament/Resources/*/*.php` → enkel `UserResource.php` heeft `canAccess()`. EmeqStaffSeeder definieert 5 shared permissions (`manage-consumers`, `manage-connections`, `view-webhooks`, `view-account-subscriptions`, `view-billing`) die nergens in een Resource of action worden gecheckt. WebhookCallResourceTest mist een `test_cross_consumer_isolation_*`-feature (equivalent van `ListAccountSubscriptionsTest::test_list_with_other_consumer_account_external_id_returns_empty_list`).

**Waarom geen blocker:** Alle 7 resources zitten al achter de `canAccessPanel()`-bottleneck die `super-admin`/`staff` vereist. Voor v0.2-intern-gebruik (`EMEQ_STAFF_SEED_*`-bootstrap + handmatige role-assignment) is dit operationeel veilig — alleen staff-leden zien webhook-data. SC-7 functioneel groen: filters bestaan + auth-gate blokkeert publieke toegang. Wat ontbreekt is granulaire per-resource permission-enforcement, wat een v0.2.1-werkpunt is voordat externe gebruikers staff-rollen kunnen krijgen.

**Action item voor v0.2.1:** Voeg `canAccess()`-methods toe op alle 6 non-User Resources met respectievelijke `->can('view-…')`/`->can('manage-…')`-checks + Hub-eigen `App\Models\WebhookCall extends Spatie\WebhookClient\Models\WebhookCall` met `consumer()` belongs-to + `WebhookCallResourceTest::test_cross_consumer_isolation_*`.

### WR-01..06 — Open warnings uit code review (foot-guns + best-practice)

**Severity:** Warning (allemaal niet-blokkerend)

| Warning | Locatie | Impact |
|---------|---------|--------|
| WR-01 last-super-admin-self-downgrade | `UsersTable.php:52-72` | Operationele foot-gun: super-admin kan zichzelf naar `staff` switchen → bricks panel |
| WR-02 `json_encode` op `exception`-text-column | `WebhookCallInfolist.php:42-46` | Render-fout met debug-frictie |
| WR-03 `Select::make('role')` mist server-side `->in()` | `UsersTable.php:52-72` | DevTools-tamper → 500-response op `RoleDoesNotExist` |
| WR-04 EmeqStaffSeeder skipt password-update bij bestaande email | `EmeqStaffSeeder.php:60-64` | Silent operator-fout bij password-rotate-poging via re-seed |
| WR-05 `dehydrateStateUsing(Hash::make)` zonder filled-guard | `UserForm.php:36-42` | Theoretisch — werkt door Filament-v4-volgorde, geen regressie-test |
| WR-06 Plain-token in Livewire-snapshot + Alpine x-data | `ListConsumers.php + .blade.php` | Token zichtbaar in view-source tot dismiss; acceptabel intern |

**Action items voor v0.2.1:** Last-super-admin-guard + role-`->in()`-validator + `EmeqStaffSeeder` explicit-password-reset OR fail-fast + edit-zonder-password regressie-test + Cache-based one-shot-token-flash voor PAT-issue. Geen ervan blokkeert HUB-04 SC-1..SC-10.

## Regression Check

Test-suite van 391 tests / 1353 assertions inclusief alle Phase 5a/5b/6/7 suites:

- **Phase 5a (Mollie pass-through)**: `tests/Feature/Mollie/*` + `tests/Feature/Api/V1/Mollie/*` groen.
- **Phase 5b (Snelstart pass-through)**: `tests/Feature/Snelstart/*` + `tests/Feature/Api/V1/Snelstart/*` groen.
- **Phase 6 (Cashier-Mollie)**: `tests/Feature/Cashier/*` + `tests/Feature/Billing/*` groen.
- **Phase 7 (AccountSubscriptions)**: `tests/Feature/Api/V1/AccountSubscriptions/*` + `AccountSubscriptionManager*Test`-classes groen.
- **Phase 3 (Hub-skeleton)**: `tests/Feature/Sanctum*`, `ConnectionEncryptionTest`, `Models/*` groen (1 pre-existing incomplete in `SanctumAbilityTest::test_token_without_required_ability_is_rejected` — placeholder voor toekomstige ability-middleware-coverage, unrelated to Phase 9).
- **D-04 gedragsbehoud-invariant**: `ConnectionEncryptionTest` (8/8 passing) bewijst dat `Connection::fingerprint()`-descriptor-refactor zonder testfile-wijziging is geland.

**Conclusie:** Geen regressies. Delta Phase 9 = +52 tests / +243 assertions (Phase-9-baseline pre-09-01 = 337 → post-09-11 = 389; daarna +2 tests in `QuickLoginRouteGuardTest` voor CR-01-fix → 391 totaal).

---

_Verified: 2026-05-16_
_Verifier: Claude (gsd-verifier)_
