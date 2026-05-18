---
phase: 9
plan: 11
type: acceptance
status: pending-checkpoint
created: 2026-05-16
branch: feat/v02-account-subscriptions
executor: 09-11 (autonomous=false; awaits human-verify checkpoint voor visuele review)
---

# Phase 9 Acceptance — Filament admin-UI

**Datum:** 2026-05-16
**Status:** pending-checkpoint (human-verify open — visuele review admin-paneel)
**Executor:** Plan 09-11 (autonomous=false)
**Branch:** `feat/v02-account-subscriptions`

## Acceptance Evidence

10/10 success-criteria uit `09-CONTEXT.md` §"Success Criteria (revised)" (regels 234-246).

| # | SC-statement (samengevat) | Status | Evidence |
|---|---------------------------|--------|----------|
| 1 | Super-admin ziet 7 resource-lijsten op `/admin` (Consumers / Connections / Accounts / WebhookCalls / AccountSubscriptions / Cashier-Subscriptions / Users) | ✅ (CLI) / ⏭️ (browser-render = human-verify) | `php artisan route:list --path=admin` toont alle 7 resource-prefixes: `admin/consumers/*` (CRUD), `admin/connections/*` (read+view), `admin/accounts/*` (read+view), `admin/webhook-calls/*` (read+view), `admin/account-subscriptions/*` (read+view), `admin/cashier-subscriptions/*` (read+view), `admin/users/*` (CRUD super-admin-only). Visuele sidebar-check verschoven naar human-verify checkpoint (zie `<how-to-verify>` in 09-11-PLAN.md). |
| 2 | Staff (zonder super-admin-role) ziet 6 resources (geen UserResource in sidebar) | ✅ | `tests/Feature/Admin/PermissionGatingTest::test_staff_user_does_not_see_user_navigation_link` — staff-User → `assertDontSee('admin/users')` op `/admin`-landing. Resource verbergt zich via `UserResource::shouldRegisterNavigation()` gated op `Gate::allows('manage-staff')`. |
| 3 | User zonder Spatie-rol krijgt 403 op `/admin` — `canAccessPanel()` blokkeert | ✅ | `tests/Feature/Admin/PanelAccessTest::test_authenticated_user_without_role_cannot_access_admin_panel` — User zonder `super-admin`/`staff`-role → `assertForbidden`. Implementatie: `User::canAccessPanel(Panel $panel)` returnt `$panel->getId() === 'admin' && $this->hasAnyRole(['super-admin', 'staff'])`. |
| 4 | `ConsumerResource` Issue-PAT-action retourneert plain-token (éénmalig zichtbaar) + maakt rij in `personal_access_tokens`; preset-test asserteert dat alle `TokenAbilities` afgedekt zijn | ✅ | `tests/Feature/Admin/ConsumerTokenActionTest::test_staff_user_can_issue_pat_with_mollie_read_preset` (token-row + plain-token in `persistent` Notification) + `tests/Feature/Admin/ConsumerTokenActionTest::test_staff_user_can_issue_pat_with_custom_abilities` + `tests/Feature/Admin/PatAbilityPresetsTest::test_every_token_ability_is_covered_by_a_preset_or_custom_only_list` (discovery-contract: ⋃ preset-abilities + `PAT_CUSTOM_ONLY` ⊇ `TokenAbilities::all()`, mutatie-test bewees non-vacuous). |
| 5 | `ConnectionResource` toont alleen fingerprints — geen plain-text `access_token` / `refresh_token` / `client_key` / `subscription_key` in HTML-respons | ✅ | 4 tests in `tests/Feature/Admin/ConnectionFingerprintTest`: `test_list_page_html_contains_no_raw_credentials` + `test_livewire_list_render_contains_no_raw_credentials` + `test_view_page_html_contains_no_raw_credentials_mollie` + `test_view_page_html_contains_no_raw_credentials_snelstart`. Alle 4 RAW_*-credentials gescand op HTTP + Livewire-HTML voor zowel List- als View-page. |
| 6 | `ConnectionResource` revoke-action roept `OAuthFlow::revoke($connection)` aan + zet `revoked_at` | ✅ | `tests/Feature/Admin/ConnectionRevokeActionTest::test_revoke_action_calls_oauth_flow_revoke` — `FakeOAuthFlow::wasCalled('revoke') === 1` + post-action state-flip + `revoked_at` gevuld. Visibility-guards: `test_revoke_action_visible_for_mollie_connection` + `test_revoke_action_hidden_for_snelstart_connection` (descriptor.oauthFlowKey === null) + `test_revoke_action_hidden_for_already_revoked_connection`. |
| 7 | `WebhookCallResource` toont direction-/provider-/status-filters (vereist 09-01-migratie) + cross-Consumer-isolatie via gefilterde queries | ✅ | `tests/Feature/Admin/WebhookCallResourceTest::test_direction_filter_narrows_to_incoming` (SelectFilter op `direction` slijpt set tot incoming-rows) + `test_list_shows_audit_rows_for_staff_user` (staff ziet audit-rows met `consumer.slug`-resolve) + `test_view_page_renders_payload_json` (collapsible JSON-payload). Migratie 09-01 (`2026_05_19_000001_add_audit_columns_to_webhook_calls_table.php`) levert 4 audit-kolommen incl. `direction` + `provider` + `consumer_id` + `status`. |
| 8 | `AccountSubscriptionResource` Pause/Resume/Cancel respecteren state-machine — illegale transition → Filament-Notification-error + GEEN DB-mutatie | ✅ | `tests/Feature/Admin/AccountSubscriptionStateActionsTest::test_illegal_transition_throws_without_db_mutation` — `pause(canceled)` via `AccountSubscriptionManager` → `InvalidStateTransitionException` gevangen + `$canceled->fresh()->status === Canceled` (geen mutatie). Manager-only delegation: `test_pause_action_flips_status_via_manager` bewijst dat de Resource `app(AccountSubscriptionManager::class)->pause()` aanroept, niet `$sub->update(['status'…])`. Visibility-matrices: `test_pause_action_visible_on_active_subscription_only` + `test_resume_action_visible_only_on_paused_subscription` + `test_cancel_action_visible_on_active_and_paused_not_canceled`. |
| 9 | `UserResource` is super-admin-only — staff-user 403 | ✅ | `tests/Feature/Admin/PermissionGatingTest::test_staff_user_cannot_access_user_resource` (`assertForbidden`) + `test_super_admin_can_access_user_resource` (`assertOk`). Gate: `Gate::define('manage-staff', fn (User $user) => $user->hasRole('super-admin'))` in `AppServiceProvider::boot()`. `UserResource::canAccess()` returnt `Gate::allows('manage-staff')` + `shouldRegisterNavigation()` gated op dezelfde gate. |
| 10 | Adding nieuwe provider (theoretisch `moneybird`) vereist alleen nieuwe `ProviderCredentialDescriptor`-rij in config + factory-update — GEEN nieuwe Filament Resource-class (D-04 invariant) | ✅ | `tests/Feature/Admin/ProviderDescriptorTest::test_adding_theoretical_provider_appears_in_all` — runtime config-override met `'moneybird'`-rij → `ProviderCredentialDescriptor::all()` includes 3 descriptors zonder code-edit. `ConnectionResource` consumeert descriptors via `Section::make()->visible(fn (?Connection $r) => $r?->provider === '…')`-pattern, dus toevoegen = config + factory + nieuwe Section, niet nieuwe Resource. Aanvullende coverage: `test_mollie_descriptor_resolves_from_config` + `test_snelstart_descriptor_has_null_oauth_flow_key` + `test_unknown_provider_throws_invalid_argument_exception`. |

## Test counts

**Phase 9 baseline (na 09-10, vóór dit plan):** 389 passed / 1 incomplete / 0 failed / 1343 assertions / 15.96s. Gemeten via `php artisan test --compact` op worktree-branch `worktree-agent-ac0b22b044346a5ae` na bootstrap (cp .env + cp -R vendor + `composer dump-autoload`).

**Pre-Phase-9 baseline (Phase 7-08 close, 2026-05-15):** 337 passed / 1 incomplete / 0 failed / 1100 assertions.

**Delta Phase 9 (09-01 t/m 09-10):** **+52 tests / +243 assertions** verspreid over 15 nieuwe `tests/Feature/Admin/*`-classes + 1 `tests/Feature/Models/WebhookCallAuditColumnsTest.php`.

### Phase-9 testpopulatie per plan

| Plan | Test-classes (Feature/Admin/* tenzij anders) | Tests | Hoofddoel |
|------|----------------------------------------------|-------|-----------|
| 09-01 | `tests/Feature/Models/WebhookCallAuditColumnsTest.php` | 3 | schema-aanwezigheid + full-audit-row persist + legacy-shape-compat |
| 09-02 | `FilamentInstallSmokeTest.php` | 3 | `/admin/login` 200 + 5 Spatie-tabellen + `/admin` → `/admin/login` redirect |
| 09-03 | `PanelAccessTest.php` + `EmeqStaffSeederTest.php` | 6 | 3-tier role-gate (unauth/no-role/staff) + env-driven idempotent seeder (no-op/with-env/2× duplicaat-vrij) |
| 09-04 | `ProviderDescriptorTest.php` | 4 | config-driven discovery (mollie/snelstart/unknown/theoretical moneybird) — D-04 invariant |
| 09-05 | `ConsumerTokenActionTest.php` + `PatAbilityPresetsTest.php` | 5 | Issue-PAT met preset + custom-mode → token-row + plain-token-Notification; discovery-contract dekt alle `TokenAbilities::all()` |
| 09-06 | `ConnectionFingerprintTest.php` + `ConnectionRevokeActionTest.php` | 8 | no-secret-leak (List + Livewire + View-Mollie + View-Snelstart) + revoke-delegation via `OAuthFlowRegistry` (4 visibility + delegation tests) |
| 09-07 | `AccountResourceTest.php` + `WebhookCallResourceTest.php` | 6 | List + consumer-filter + view-page (Accounts); audit-rows + direction-filter + JSON-payload (WebhookCalls) |
| 09-08 | `AccountSubscriptionResourceTest.php` + `AccountSubscriptionStateActionsTest.php` | 8 | List + status-filter + view (Mollie-IDs zichtbaar); 3 state-flip-actions visibility-matrix + manager-delegation + illegale transition zonder DB-mutatie |
| 09-09 | `CashierSubscriptionResourceTest.php` | 3 | List Consumer-subscriptions + derived-status `active` (ends_at null) + derived-status `grace` (ends_at future) |
| 09-10 | `PermissionGatingTest.php` + `UserResourceTest.php` | 6 | manage-staff gate (3-tier) + super-admin create-User + assign-role-action (syncRoles) + email-unique |

**Totaal Phase 9 nieuwe tests:** 3 + 3 + 6 + 4 + 5 + 8 + 6 + 8 + 3 + 6 = **52 tests** (overeen met 389 − 337 baseline + 0 net-deletions).

**Pre-existing incomplete:** 1× `SanctumAbilityTest::test_token_without_required_ability_is_rejected` (Phase 3-03 placeholder voor Phase 5b ability-middleware coverage — unrelated to Phase 9, blijft incomplete).

## Decisions referenced

5 D-decisions uit `09-CONTEXT.md` §"Decisions (2026-05-15 discussion)" (regels 60-160) + plan-locatie:

| D-ID | Decision | Plan(s) |
|------|----------|---------|
| **D-01** | Scope = 7 resources (Consumer CRUD + Connection read+revoke + Account read + WebhookCall read + AccountSubscription read+state-flip + Cashier\Subscription read + User super-admin-only). `PassThroughCall`-viewer expliciet uitgesteld naar backlog `HUB-OBSERVABILITY`. | 09-05 t/m 09-10 (de zeven Resources) |
| **D-02** | `webhook_calls`-tabel uitbreiden vóór WebhookCallResource — 4 audit-kolommen (direction enum / provider string / consumer_id nullable-FK / status enum) additive, geen backfill (NULL voor pre-bestaande Spatie-rijen). | 09-01 (`2026_05_19_000001_add_audit_columns_to_webhook_calls_table.php`) |
| **D-03** | PAT-abilities UX = 5 presets-radio + Custom-mode CheckboxList. Plain-token éénmalig zichtbaar via `Notification::send()` (persistent). Discovery-contract: ⋃ preset-abilities + `PAT_CUSTOM_ONLY` ⊇ `TokenAbilities::all()`. | 09-05 (`ConsumerResource::issuePatAction` + `PAT_PRESETS`/`PAT_CUSTOM_ONLY` constants + `PatAbilityPresetsTest`) |
| **D-04** | `ProviderCredentialDescriptor`-laag — `config/hub-providers.php` + `App\Support\ProviderCredentialDescriptor` value-object. `Connection::fingerprint()` descriptor-aware refactor met gedragsbehoud (`ConnectionEncryptionTest` blijft groen zonder testfile-wijziging). Nieuwe provider toevoegen = config-row + factory-update + nieuwe Section, GEEN nieuwe Filament Resource-class. | 09-04 (descriptor + refactor) + 09-06 (`ConnectionResource` consumeert descriptors) |
| **D-05** | RBAC via Spatie laravel-permission ^6 — 2-rol-model (`super-admin` + `staff`) + 6 permissions (manage-staff / manage-consumers / manage-connections / view-webhooks / view-account-subscriptions / view-billing). Drop `is_emeq_staff` boolean (komt nooit in een migratie). User-model: `HasRoles`-trait + `implements FilamentUser` + `canAccessPanel(Panel)`. Gate `manage-staff` gated `UserResource`. | 09-02 (Spatie install + migratie) + 09-03 (User + EmeqStaffSeeder) + 09-10 (UserResource gate) |

### Expliciet uitgestelde items (CONTEXT.md "Out of scope" + §"Deferred Ideas")

1. **`PassThroughCallResource`** — backlog `HUB-OBSERVABILITY`. Te hoog volume voor flat-list zonder query-optimization; overweeg Telescope of dedicated paginated viewer met aggressieve filters.
2. **Audit-log via `spatie/laravel-activitylog`** — backlog `HUB-AUDIT`. Phase-9 admin-acties (revoke, PAT-issue, role-assign, pause/resume/cancel) genereren op dit moment alleen Filament-Notification + bestaande Hub-audit (`pass_through_calls` voor OAuth-revoke); geen activity-log-trail.
3. **2FA/MFA voor admin-login** — uitgesteld naar v1.0+ wanneer compliance dit eist.
4. **Consumer self-service dashboard op `/portal`** — v1.0+ commerciële launch op aparte panel-route met React/shadcn.
5. **Tailwind-thema-customizing + e-mail notificaties + bulk-actions + per-provider OAuth-status-page** — Filament-default-look volstaat voor intern gebruik; revoke single-record voldoet voor v0.2-ops; bulk + status-monitor verschijnen in backlog (`HUB-OAUTH-MONITOR`) zodra productie-data ze rechtvaardigt.

## Pending human-verify

Visuele review nog vereist na geautomatiseerde acceptance. Checkpoint-criteria (zie `09-11-PLAN.md` `<how-to-verify>`):

1. **Login + sidebar** — User opent `http://hub.emeq.test:8090/admin/login`, logt in als `EMEQ_STAFF_SEED_EMAIL` / `EMEQ_STAFF_SEED_PASSWORD` (super-admin), verifieert dat sidebar exact 7 navigation-items toont (Consumers / Connections / Accounts / Webhook calls / Account subscriptions / Cashier subscriptions / Users).
2. **All-7-lijsten renderen** — klik door alle 7 resource-lijsten; geen 500-errors, alle TextColumns + badges + filters renderen.
3. **Issue-PAT happy-path** — `ConsumerResource` Issue-PAT met preset "Mollie read-only" → Notification toont plain-token; `curl -H "Authorization: Bearer <token>" http://hub.emeq.test:8090/v1/ping` → 200.
4. **Connection-fingerprints + revoke visibility** — open Detail-view Mollie-Connection (Section "Mollie OAuth" zichtbaar, alleen fingerprint, geen access_token); open Snelstart-Connection (Section "Snelstart credentials" zichtbaar, Revoke-action verborgen wegens `oauthFlowKey === null`).
5. **AccountSubscription state-flip** — seed Active-subscription, klik Pause-action → status flipt naar Paused in UI; klik Resume → terug naar Active.
6. **UserResource super-admin-gating** — log uit + log in als staff-user (incognito); sidebar toont GEEN "Users"-link; directe URL `/admin/users` → 403.

User reply "approved" = alle 6 visuele checks slagen → continuation-agent commit planning-sync (`docs(phase-09): accepteer Phase 9 — HUB-04 Complete`).

## Next steps after acceptance

1. **HUB-04 → Complete** in `REQUIREMENTS.md` (T-09-11-04) + Traceability-tabel rij `HUB-04 | Phase 9 | Complete`.
2. **Phase 9 [x] in `ROADMAP.md`** met `11/11 plans` + `Done` + `2026-05-16` (T-09-11-03).
3. **`STATE.md` frontmatter sync** — `completed_phases: 7`, `total_plans: 62`, `completed_plans: 52`, `percent: 84`; `last_activity` + `stopped_at` reflecteren Phase-9-close (T-09-11-05).
4. **Final commit** — `docs(phase-09): accepteer Phase 9 — HUB-04 Complete, Filament admin-paneel live op /admin`; commit bevat `09-11-ACCEPTANCE.md` + `ROADMAP.md` + `REQUIREMENTS.md` + `STATE.md`. NIET `.docs/decisions/filament-admin-panel.md` (gitignored per repo-conventie).
5. **Optioneel quick-task** — `/docs-sync` skill draaien na merge: Phase 9 raakte `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `app/Models/Connection.php` (fingerprint-refactor), `config/hub-providers.php`-nieuw, `database/migrations/2026_05_19_*`-nieuw, en alle `app/Filament/Resources/*` — documentatie-drift mogelijk in `.docs/`. Skill scant CLAUDE.md + `.docs/README.md` + memory-files op stale references.
6. **Phase 8 (Naschool wiring)** is na deze acceptance de enige openstaande v0.2-feature-fase naast Phase 5b + 5c — bespreken via `/gsd-discuss-phase 8` in verse sessie (Phase 8 raakt `school-activities-hub/backend/`, verdient verse context).
