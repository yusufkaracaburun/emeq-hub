---
phase: 9
slug: filament-admin-ui-voor-emeq-medewerkers
title: Filament admin-UI voor Emeq-medewerkers
milestone: v0.2
status: discussed
added: 2026-05-14
revised: 2026-05-15
plan_source: .claude/plans/ow-dit-wil-ik-immutable-snowglobe.md
requirements:
  - HUB-04
depends_on:
  - phase-3
  - phase-4
parallelizable_with:
  - phase-8
revision_log:
  - "2026-05-15 — Stale aannames hersteld na Phase 5a/5b/6/7 close: webhook_calls-schema (geen direction/provider-kolom), scope-uitbreiding met AccountSubscription + Cashier\\Subscription + UserResource, Spatie laravel-permission ipv is_emeq_staff boolean, ProviderCredentialDescriptor-laag voor toekomstige providers"
---

# Phase 9: Filament admin-UI voor Emeq-medewerkers

## Phase Goal

Een intern Filament v4 admin-paneel op `/admin` waarmee Emeq-medewerkers Consumers, Connections, Accounts, WebhookCalls, AccountSubscriptions, Cashier-Subscriptions en (super-admin only) Users kunnen beheren zonder `php artisan tinker` te openen — met de harde Hub-invariant dat raw tokens nooit in de UI verschijnen.

## Motivation

emeq-hub is API-first multi-tenant integration platform. Tot 2026-05-15 is er géén frontend buiten `/up` health-check + `/docs/api` Scramble + `/v1/*` REST-API. Met v0.2 grotendeels gereed (6/10 phases ACCEPTED) ontstaat concrete operationele behoefte:

- Emeq-medewerkers moeten Consumers/PATs/Accounts beheren zonder tinker
- OAuth-koppelingen (Connections) moeten zichtbaar en revoke-baar zijn zónder dat raw access-tokens / client-keys / refresh-tokens in een UI verschijnen
- Webhook-flows van Mollie + Snelstart moeten doorzoekbaar zijn op direction (incoming/outgoing) en provider voor debugging
- AccountSubscriptions (Phase 7) state-machine-debugging vereist multi-tenant-filter-views
- Cashier-Subscriptions (Phase 6) billing-overzicht voor Emeq-eigen Consumer-facturatie

## Stack-keuze: Filament v4

**Gekozen** (2026-05-14, ongewijzigd na revisie): Filament v4 — PHP-only, Livewire onder de motorkap, auto-CRUD vanuit Eloquent. Eigen panel op `/admin` met ingebouwde login. Geen Fortify, geen Sanctum SPA-tokens.

**Niet gekozen:**
- React + shadcn via Inertia / losse SPA — overkill voor intern admin; toekomstig v1.0+ Consumer-self-service dashboard op aparte panel-route
- Fortify / Breeze / Jetstream — overlap met Filament's eigen views

## Dependencies (actuele state 2026-05-15)

| Phase | Status | Why |
|---|---|---|
| Phase 3 (Hub-skeleton) | ✅ Complete | `Consumer` / `Account` / `Connection` modellen + `personal_access_tokens` (Sanctum) bestaan + `TokenAbilities`-enum geland |
| Phase 4 (OAuthFlow-broker) | ✅ Complete | `ConnectionResource` revoke roept `OAuthFlow::revoke($connection)` aan voor upstream provider-revoke |
| Phase 5a (Mollie pass-through) | ✅ Complete | Mollie-webhooks landen in `webhook_calls`-tabel (Spatie-default-shape) |
| Phase 5b (Snelstart pass-through) | ✅ Complete | Snelstart-Connections gebruiken `client_key`+`subscription_key`+`subscription_id` (geen OAuth) — ProviderCredentialDescriptor moet dit afdekken |
| Phase 6 (Cashier-Mollie) | ✅ Complete | `Cashier\Subscription`-model bestaat; nieuwe `Cashier\SubscriptionResource` in Phase 9 |
| Phase 7 (AccountSubscriptions) | ✅ Complete | `AccountSubscription`-model + state-machine (6 states) + 6 routes; nieuwe `AccountSubscriptionResource` |

**Parallelliseerbaar met:** Phase 8 (Naschool wiring — werkt in externe repo, raakt Filament niet).

**Blokkeert niet:** Phase 5c (Snelstart webhook-handler in progress — zal `direction=incoming` `provider=snelstart` rijen toevoegen aan webhook_calls; geen Filament-impact mits onze migratie 09-01 die kolommen al gedefinieerd heeft).

## Decisions (2026-05-15 discussion)

### D-01: Scope = 7 resources

**6 core + 1 meta:**

1. **ConsumerResource** (CRUD) — Hub-tenant management
2. **ConnectionResource** (read + revoke) — OAuth/credential-koppelingen
3. **AccountResource** (read-only) — eindgebruiker-aliasing per Consumer
4. **WebhookCallResource** (read-only viewer) — incoming/outgoing audit
5. **AccountSubscriptionResource** (read + state-flip actions) — Phase 7 multi-tenant subs
6. **Cashier\SubscriptionResource** (read) — Emeq-billing van Consumers
7. **UserResource** (super-admin only) — staff-onboarding

**Out of scope (expliciet geparkeerd):**

- `PassThroughCall`-viewer — te hoog volume (één rij per Hub→partner-call); admin-list-query zou traag worden zonder zware paginate+filter-optimalisatie. Debug via `psql` of toekomstige Telescope-integratie.
- Multi-rol RBAC voorbij `super-admin` + `staff` (Spatie roles met permissions volstaan; complexer rollenmodel komt pas met meer staff-types)
- Consumer self-service dashboard op `/portal` — v1.0+ commercial launch, aparte panel-route, React/shadcn
- E-mail notificaties uit Filament — apart queue/mailer-werk
- 2FA/MFA voor admin login → v1.0+ als compliance dit eist
- Audit-log via `spatie/laravel-activitylog` — backlog `HUB-AUDIT`. OAuth-revoke wordt sowieso gelogd via bestaande `webhook_calls`-outgoing-flow.
- Tailwind-thema-customizing — default Filament-look is goed voor intern gebruik

### D-02: WebhookCall-tabel uitbreiden vóór Resource bouwt

`webhook_calls` (Spatie-default) heeft alleen `name`/`url`/`headers`/`payload`/`attachments`/`exception`/`timestamps`. **Plan 09-01** schrijft migratie:

```php
$table->enum('direction', ['incoming', 'outgoing'])->after('id')->index();
$table->string('provider', 32)->nullable()->after('direction')->index();  // mollie / snelstart / cashier / future
$table->foreignId('consumer_id')->nullable()->after('provider')->constrained()->nullOnDelete();
$table->enum('status', ['pending', 'processed', 'failed'])->default('processed')->after('consumer_id')->index();
```

Bestaande rijen krijgen NULL voor de nieuwe kolommen (geen backfill nodig — historische audit-data is laagrelevant). Spatie's webhook-server + Hub's eigen dispatcher (zie Phase 5a + 5c) moeten de kolommen voor nieuwe rijen vullen. Phase 5c (in progress) zal `direction=incoming` `provider=snelstart` natuurlijk gaan vullen zodra ze landt.

### D-03: PAT-abilities UX = presets + custom-mode

Issue-PAT modal (table-action op `ConsumerResource`):

- **Radio: 5 presets**
  - "Mollie read-only" → `[mollie:read]`
  - "Mollie read+write" → `[mollie:read, mollie:write, consumer:manage-accounts]`
  - "Snelstart read-only" → `[snelstart:read]`
  - "Snelstart read+write" → `[snelstart:read, snelstart:write, consumer:manage-accounts]`
  - "Admin" → `[*]`
- **"Custom..." escape** → multi-select met 8 `TokenAbilities`-constants (snelstart:read/write, mollie:read/write, consumer:manage-accounts, billing:read/write, *)
- Plain-token wordt **éénmalig** zichtbaar via `Filament\Notifications\Notification::send()` na `$consumer->createToken(...)`. Niet gepersisteerd in admin-state; user moet kopiëren tijdens flash.
- **Risico:** presets verouderen bij nieuwe ability. Mitigatie: feature-test `tests/Feature/Admin/PatAbilityPresetsTest.php` asserteert dat elke ability in `TokenAbilities::all()` óf in minstens één preset zit, óf expliciet als "custom-only" gemarkeerd.

### D-04: ProviderCredentialDescriptor-laag

`Connection` heeft per provider verschillende credentials. Huidige + toekomstige providers:

| Provider | Credential shape |
|---|---|
| Mollie | `access_token` + `refresh_token` (OAuth2) |
| Snelstart | `client_key` + `subscription_key` + `subscription_id` (Snelstart's eigen scheme) |
| Moneybird (toekomst) | OAuth2 (vergelijkbaar met Mollie) |
| Exact (toekomst) | OAuth2 |
| Ibanity (toekomst) | mTLS-certificaten + client_id + client_secret |
| Generic basic-auth (toekomst, denkbaar) | `username` + `password` |

**Ontwerp:** een `ProviderCredentialDescriptor`-interface (of config-array in `config/hub-providers.php`) beschrijft per provider:

- `string $key` — `'mollie'` / `'snelstart'` / etc.
- `array $encryptedFields` — welke `Connection`-velden zijn encrypted credentials (`['access_token', 'refresh_token']`)
- `string $primaryFingerprintLabel` — display-label voor de fingerprint-kolom (`"OAuth token"` / `"Client key"` / `"Certificate"`)
- `?callable $authorizeFlow` — verwijzing naar `OAuthFlow` als provider OAuth gebruikt; null bij clientKey/cert/basic-auth

**Filament-impact:** `ConnectionResource` heeft **één** tabel-kolom `fingerprint` die `Connection::fingerprint()` aanroept (bestaande accessor, returnt provider-specific waarde). Detail-form is per-provider conditional via `Forms\Components\Section::make()->visible(fn($record) => $record->provider === 'mollie')` etc. Bij nieuwe provider toevoegen: nieuwe descriptor + Snippet form-section, **geen nieuwe Resource-class**. Zelfde scaling-lesson als Scramble-groepering (zie ROADMAP-backlog `SCRAMBLE-NESTED-GROUPS`): vermijd resource-multiplicatie per provider.

`Connection::fingerprint()` accessor bestaat al en doet de juiste provider-switch (Mollie: `access_token`-fingerprint, Snelstart: `client_key`-fingerprint). Phase 9 voegt aan deze laag toe — geen rewrite.

### D-05: RBAC via Spatie laravel-permission (drop `is_emeq_staff` boolean)

**Aankoop:** `spatie/laravel-permission` ^6.x

Past bij bestaande Spatie-stack (webhook-server/client al in dependencies); schaalt mee als meer rollen ontstaan; alignment met toekomstige `HUB-AUDIT` backlog-item (Spatie activitylog).

**Wat verandert vs. originele CONTEXT.md:**

- ❌ Drop: `is_emeq_staff` boolean op `users`-tabel (komt nooit in een migratie)
- ✅ Toevoeg: `roles` + `permissions` + `model_has_roles` + `model_has_permissions` + `role_has_permissions` tabellen (Spatie default-migratie)
- ✅ User-model: `use HasRoles` trait + `implements FilamentUser` + `canAccessPanel(Panel $panel): bool => $this->hasAnyRole(['super-admin', 'staff'])`

**Initial roles + permissions:**

| Role | Granted permissions |
|---|---|
| `super-admin` | `manage-staff` + `manage-consumers` + `manage-connections` + `view-webhooks` + `view-account-subscriptions` + `view-billing` (alle 6) |
| `staff` | `manage-consumers` + `manage-connections` + `view-webhooks` + `view-account-subscriptions` + `view-billing` (geen `manage-staff`) |

`UserResource` toggles `super-admin`/`staff`-role en is alléén zichtbaar voor super-admins (gate via Spatie). Reguliere staff: geen UserResource in sidebar.

**Seeder:** `EmeqStaffSeeder` leest `EMEQ_STAFF_SEED_EMAIL` + `EMEQ_STAFF_SEED_PASSWORD` uit env voor de **bootstrap super-admin** (productie-eenmalig). Daarna kunnen super-admins via Filament nieuwe staff-users + role-assignments doen.

**Feature-test** `tests/Feature/Admin/PermissionGatingTest.php`:
- staff zonder super-admin role → 403 op `UserResource`-list
- staff met `staff`-role-only → kan WebhookCall lezen maar geen `manage-staff`-action zien

## Scope (concreet)

### In scope (7 resources + meta)

#### 1. ConsumerResource — `app/Filament/Resources/ConsumerResource.php`
**CRUD.** Form: `name`, `slug` (unique). Tabel: `name`, `slug`, `accounts_count`, `connections_count` (via withCount). Table-actions: `Issue PAT` (modal D-03), `Revoke token` (lijst van `personalAccessTokens`-relation → delete).

#### 2. ConnectionResource — `app/Filament/Resources/ConnectionResource.php`
**Read + revoke.** Form `->disabled()`. Tabel: `provider` (badge), `account.external_id`, `fingerprint` (via accessor — provider-agnostisch per D-04), `status`, `revoked_at`. Filters: `provider`, `account_id`, `consumer_id` (via Account-relation), `revoked` (bool). Detail-form is per-provider conditional (D-04). Action `Revoke`: `OAuthFlow::revoke($connection)` (Phase 4-contract).

#### 3. AccountResource — `app/Filament/Resources/AccountResource.php`
**Read-only.** Tabel: `consumer.slug`, `external_id`, `connections_count`, `created_at`. Filter: `consumer_id`.

#### 4. WebhookCallResource — `app/Filament/Resources/WebhookCallResource.php`
**Read-only viewer.** Pre-requisite: plan 09-01 migratie (D-02). Tabel: `direction` (badge), `provider` (badge), `consumer.slug` (nullable), `name`, `status`, `created_at`. Filters: `direction`, `provider`, `status`, `consumer_id`, date-range. `ViewAction` met `TextEntry::make('payload')->json()` voor collapsible JSON + exception text-area.

#### 5. AccountSubscriptionResource — `app/Filament/Resources/AccountSubscriptionResource.php`
**Read + state-flip actions.** Tabel: `account.external_id`, `connection.provider`, `status` (badge — 6 states: pending/active/paused/canceled/completed/unknown), `amount_currency`+`amount_value` (formatted), `interval`, `description`, `last_webhook_event_at`. Filters: `status`, `account_id`, `connection.provider`. Actions: `Pause`/`Resume`/`Cancel` (roept `AccountSubscriptionManager` aan — Phase 7-service; respecteert state-machine-transities). Detail-view toont `mollie_customer_id`/`mollie_subscription_id`/`mollie_mandate_id`-fingerprints (Mollie-IDs zijn opaque, geen secret-risk; tonen helpt Mollie-dashboard-cross-reference).

#### 6. Cashier\SubscriptionResource — `app/Filament/Resources/CashierSubscriptionResource.php`
**Read-only.** Cashier-Mollie's `subscriptions`-tabel heeft géén status-kolom — afgeleide status via `Subscription::active()` / `cancelled()` / `ended()` / `onTrial()` / `onGracePeriod()`. Tabel: `owner.slug` (= Consumer), `name`, `plan` (afgeleid), `derived_status` (badge), `ends_at`. Filter: `derived_status` (computed where). Geen mutate-actions in Filament — Cashier-billing wordt via `app/Http/Controllers/Api/V1/Admin/Billing/SubscriptionController.php` REST-API gemuteerd (Phase 6-geland).

#### 7. UserResource — `app/Filament/Resources/UserResource.php`
**Super-admin only.** Tabel: `email`, `roles` (lijst), `created_at`. Form: `email`, `password` (alleen op create), action `Assign role` (selecteer uit `super-admin`/`staff`/none). Gate: `Gate::define('manage-staff', fn(User $user) => $user->hasRole('super-admin'))`. Resource-class registreert zichzelf alleen in `AdminPanelProvider` als gate passes (Filament v4: `canAccessPanel` of resource-level `static::shouldRegisterNavigation`).

### Out of scope (zie D-01)

PassThroughCall-viewer; multi-rol RBAC voorbij super-admin/staff; Consumer self-service dashboard; e-mail notifications; 2FA/MFA; activitylog; Tailwind-theming.

## Implementatie-skelet

### Plan 09-01: WebhookCall-migratie

Schrijft `database/migrations/2026_xx_xx_add_audit_columns_to_webhook_calls_table.php` (D-02). Backfill: laat NULL — historische audit-data is laagrelevant. Update Spatie's webhook-server config / Hub's dispatcher om nieuwe kolommen te vullen voor nieuwe rijen.

### Plan 09-02: Filament v4 install + Spatie permission

```bash
composer require filament/filament:"^4.0" spatie/laravel-permission:"^6.0" -W
php artisan filament:install --panels --no-interaction
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

Genereert `app/Providers/Filament/AdminPanelProvider.php` met `->path('admin')`, `->login()`, `->authGuard('web')`. Spatie's migratie maakt `roles`/`permissions`/`model_has_roles`/etc.

**Verifieer via `mcp__plugin_context7_context7__query-docs`** dat Filament v4 nog actuele major is + Spatie permission ^6.x compatibel met Laravel 13.

### Plan 09-03: User-model + EmeqStaffSeeder

User-model:
- `use Spatie\Permission\Traits\HasRoles`
- `implements Filament\Models\Contracts\FilamentUser`
- `canAccessPanel(Panel $panel): bool => $panel->getId() === 'admin' && $this->hasAnyRole(['super-admin', 'staff'])`

`EmeqStaffSeeder` (env-driven, eenmalig productie):
1. Run Spatie role-seed: maak `super-admin` + `staff` rollen
2. Wijs alle 6 permissions toe aan `super-admin`; alle behalve `manage-staff` aan `staff`
3. Maak bootstrap-user via `EMEQ_STAFF_SEED_EMAIL`/`EMEQ_STAFF_SEED_PASSWORD` + assign `super-admin`-role

### Plan 09-04..09-10: Resources

Volgorde wave-based te bepalen door planner. Voorstel: 09-04 ConsumerResource (CRUD = grootste) → 09-05 ConnectionResource (D-04 ProviderCredentialDescriptor) → 09-06 AccountResource (kort) → 09-07 WebhookCallResource → 09-08 AccountSubscriptionResource → 09-09 Cashier\SubscriptionResource → 09-10 UserResource (incl. gate D-05).

### Plan 09-11: ProviderCredentialDescriptor implementatie

Apart plan want raakt zowel `app/Models/Connection.php` (fingerprint-resolution) als `config/hub-providers.php` (declarations) als `app/Filament/Resources/ConnectionResource.php` (form-detail conditional).

### Plan 09-12: Phase-acceptance + ADR + planning-sync

Standard phase-close pattern (zoals Phase 7-08). Schrijft `09-12-ACCEPTANCE.md` + ADR `.docs/decisions/filament-admin-panel.md` + ROADMAP/REQUIREMENTS/STATE sync. HUB-04 → Complete.

## Success Criteria (revised)

1. Een geseede super-admin kan inloggen op `/admin` en ziet 7 resource-lijsten (Consumers / Connections / Accounts / WebhookCalls / AccountSubscriptions / Cashier-Subscriptions / Users)
2. Een geseede staff (zonder super-admin-role) ziet 6 resources (geen UserResource in sidebar) en krijgt 403 als ze `/admin/users` direct opent
3. Een user zonder Spatie-rol krijgt 403 op `/admin` — `canAccessPanel()` blokkeert
4. `ConsumerResource` issue-PAT-action retourneert plain-text token in notification (éénmalig zichtbaar) + maakt rij in `personal_access_tokens`; preset-test asserteert alle `TokenAbilities` afgedekt
5. `ConnectionResource` toont alleen fingerprints — feature-test asserteert dat **geen** van de plain-text waarden van `access_token`/`refresh_token`/`client_key`/`subscription_key` voorkomt in HTML-respons van `livewire(ListConnections::class)`
6. `ConnectionResource` revoke roept `OAuthFlow::revoke($connection)` aan en zet `revoked_at`
7. `WebhookCallResource` toont direction-/provider-/status-filters (vereist 09-01-migratie) en cross-Consumer-isolatie via gefilterde queries
8. `AccountSubscriptionResource` Pause/Resume-actions respecteren state-machine — een Cancel op een al-canceled subscription geeft Filament-validation-error, geen DB-mutatie
9. `UserResource` is super-admin-only — feature-test asserteert dat `staff`-user 403 krijgt
10. Adding nieuwe provider (theoretisch: `moneybird`) vereist alleen nieuwe `ProviderCredentialDescriptor`-rij in config + factory-update, **niet** een nieuwe Filament Resource-class (D-04 invariant)

## Critical Files

**Nieuw:**
- `app/Filament/Resources/{Consumer,Connection,Account,WebhookCall,AccountSubscription,CashierSubscription,User}Resource.php` (7)
- `app/Providers/Filament/AdminPanelProvider.php` — gegenereerd door `filament:install`
- `database/migrations/2026_xx_xx_add_audit_columns_to_webhook_calls_table.php` (09-01)
- `database/seeders/EmeqStaffSeeder.php` (env-driven super-admin + role-seed)
- `config/hub-providers.php` — ProviderCredentialDescriptor declarations (D-04)
- `app/Support/ProviderCredentialDescriptor.php` — value-object (D-04)
- `tests/Feature/Admin/` — PanelAccessTest, ConsumerTokenActionTest, ConnectionFingerprintTest, PermissionGatingTest, PatAbilityPresetsTest, ProviderDescriptorTest

**Gewijzigd:**
- `app/Models/User.php` — `HasRoles` + `FilamentUser` interface + `canAccessPanel`
- `app/Models/Connection.php` — descriptor-aware `fingerprint()` (kleine wijziging; accessor bestaat al)
- `composer.json` — `filament/filament: ^4.0` + `spatie/laravel-permission: ^6.0`
- `bootstrap/providers.php` — `AdminPanelProvider`

## Verification Path

```bash
php artisan migrate
EMEQ_STAFF_SEED_EMAIL=admin@emeq.nl EMEQ_STAFF_SEED_PASSWORD=secret \
  php artisan db:seed --class=EmeqStaffSeeder
php artisan serve --port=8001
docker compose up -d  # caddy + postgres + redis
# Browser: http://hub.emeq.test:8090/admin → login als admin@emeq.nl
# 1. Consumers → Create "test-consumer" → Issue PAT met preset "Mollie read+write"
#    → kopieer plain-token uit notification (één keer)
# 2. curl -H "Authorization: Bearer <token>" http://hub.emeq.test:8090/v1/ping → 200
# 3. Connections → revoke seeded Mollie-test-Connection → check upstream Mollie-dashboard dat token revoked is
# 4. WebhookCalls → filter op direction=incoming + provider=mollie → toont alleen relevante rijen
# 5. AccountSubscriptions → kies state-active → Pause-action → status flipt naar Paused
# 6. Users (super-admin only) → check dat een non-super-admin direct /admin/users → 403 krijgt
# 7. tinker: Connection::first()->access_token → encrypted blob; Connection::first()->fingerprint() → "sha256:abc..."
```

Feature-tests (PHPUnit):
- `PanelAccessTest` — non-role user → 403
- `PermissionGatingTest` — staff zonder super-admin → 403 op UserResource
- `ConsumerTokenActionTest` — issue-action maakt rij + plain-token in notification
- `PatAbilityPresetsTest` — alle `TokenAbilities` afgedekt
- `ConnectionFingerprintTest` — plain raw credentials komen nooit in HTML
- `ProviderDescriptorTest` — adding theoretical descriptor "moneybird" werkt zonder Filament-code-wijziging

## Deferred Ideas

(uit discussie 2026-05-15 — opgespoord maar buiten Phase 9-scope)

- **`PassThroughCallResource`** — admin-viewer voor `pass_through_calls`. Te hoog volume voor flat-list zonder query-optimization. Backlog: `HUB-OBSERVABILITY` — overweeg Telescope-integratie of dedicated paginated viewer met aggressieve filters.
- **Per-provider OAuth-flow-status-page** — bv. "alle expiring access_tokens binnen 7 dagen" lijst. Mooi voor ops, maar vereist scheduled-job-laag + extra Filament-page-class. Backlog: `HUB-OAUTH-MONITOR`.
- **Bulk-actions** voor revoke (revoke all Connections van Consumer X). Filament v4 ondersteunt bulk-actions native; alleen nu uitgesteld omdat single-record revoke voldoet voor v0.2-ops.
- **Activitylog-integratie** voor admin-acties (wie revoked wanneer welke Connection?) — backlog `HUB-AUDIT`.

## Next Action

`/gsd-plan-phase 09` — planner heeft nu locked decisions (D-01..D-05) + 12 plan-suggesties. Verwachte plan-count: 11-13.
