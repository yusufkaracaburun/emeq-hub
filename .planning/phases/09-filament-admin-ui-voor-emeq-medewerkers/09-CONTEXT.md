---
phase: 9
slug: filament-admin-ui-voor-emeq-medewerkers
title: Filament admin-UI voor Emeq-medewerkers
milestone: v0.2
status: not-started
added: 2026-05-14
plan_source: .claude/plans/ow-dit-wil-ik-immutable-snowglobe.md
requirements:
  - HUB-04
depends_on:
  - phase-3
  - phase-4
parallelizable_with:
  - phase-6
  - phase-7
---

# Phase 9: Filament admin-UI voor Emeq-medewerkers

## Phase Goal

Een intern Filament v4 admin-paneel op `/admin` waarmee Emeq-medewerkers Consumers, Connections, Accounts en WebhookCalls kunnen beheren zonder `php artisan tinker` te openen — met de harde Hub-invariant dat raw tokens nooit in de UI verschijnen.

## Motivation

emeq-hub is een API-first multi-tenant integration platform. Tot nu toe is er géén frontend — alleen `/up` health-check en geplande `/v1/*` REST-API met Sanctum-PAT. Zodra Phase 3 het domeinmodel (`Consumer` / `Account` / `Connection` / `WebhookCall`) heeft uitgerold ontstaat een concrete operationele behoefte:

- Emeq-medewerkers moeten Consumers kunnen aanmaken en Personal Access Tokens uitgeven/intrekken zonder tinker.
- OAuth-koppelingen (Connections) moeten zichtbaar en revoke-baar zijn — zónder dat raw access-tokens ergens in een UI verschijnen (PROJECT.md-invariant: encrypted at rest, fingerprint-only in logs/UI).
- Inkomende/uitgaande webhook-calls moeten doorzoekbaar zijn voor debugging van Connect-flows.

## Stack-keuze: Filament v4

Beslissing genomen op 2026-05-14 (zie plan-bron `.claude/plans/ow-dit-wil-ik-immutable-snowglobe.md`):

**Gekozen:** Filament v4 — PHP-only, Livewire onder de motorkap, auto-CRUD vanuit Eloquent. Eigen panel op `/admin` met ingebouwde login. Geen Fortify, geen Sanctum SPA-tokens.

**Niet gekozen (en waarom):**

- **React + shadcn via Inertia / losse SPA** — overkill voor intern admin-werk, voegt nieuwe categorie tooling toe (Inertia-adapter of Sanctum SPA-auth) voor zero gain. Gereserveerd voor toekomstig v1.0+ Consumer-self-service dashboard op aparte panel-route.
- **Laravel Fortify** — lost human-facing login/2FA/registration/password-reset op die we niet nodig hebben. Filament's ingebouwde panel-auth is volledig.
- **Breeze / Jetstream** — overlap met Filament's eigen views; geen toegevoegde waarde.

## Dependencies

| Phase | Why |
|-------|-----|
| Phase 3 (Hub-skeleton) | `Consumer` / `Account` / `Connection` modellen + `personal_access_tokens` tabel (Sanctum) moeten bestaan voordat resources kunnen binden |
| Phase 4 (OAuthFlow-broker) | `ConnectionResource` revoke-action roept `OAuthFlow::revoke($connection)` aan voor upstream provider-revoke — niet alleen een DB-flag |

**Parallelliseerbaar met:** Phase 6 (Cashier-Mollie) en Phase 7 (Account-subscriptions). Beide schrijven naar of lezen uit dezelfde `Connection`/`Account`-modellen maar raken Filament-code niet.

**Blokkeert niet:** Phase 8 (Naschool wiring) — Naschool gebruikt de pass-through API, niet het admin-paneel.

## Scope

### In scope (4 resources)

**ConsumerResource** (`app/Filament/Resources/ConsumerResource.php`) — full CRUD:
- Form: `name`, `slug` (unique-rule)
- Table-action `Issue PAT`: modal met `name` + `abilities[]` multi-select → `$consumer->createToken(...)` → plain-text token éénmalig zichtbaar in `Notification::send()` (niet in DB-list)
- Table-action `Revoke token`: lijst van `personalAccessTokens` relation → delete

**ConnectionResource** (`app/Filament/Resources/ConnectionResource.php`) — read + revoke:
- Form fully `->disabled()` — read-only
- Toont alleen `access_token_fingerprint` (computed accessor op `Connection`: `sha256(decrypted)[0..12]`). Raw `access_token` / `refresh_token` velden komen nooit in form/table — invariant
- Action `Revoke` roept `OAuthFlow::revoke($connection)` aan uit Phase 4-contract → zet `revoked_at`
- Filters: `provider`, `account_id`, `consumer_id` (via Account-relation)

**AccountResource** (`app/Filament/Resources/AccountResource.php`) — read-only:
- Table-kolom `connections_count` via `getEloquentQuery()` met `withCount('connections')`
- Filter `consumer_id`

**WebhookCallResource** (`app/Filament/Resources/WebhookCallResource.php`) — read-only viewer:
- `ViewAction` met `TextEntry::make('payload')->json()` voor collapsible JSON-payload
- Status-badge (processed/failed)
- Filters: `direction` (incoming/outgoing — kolom toegevoegd in Phase 5-aanvullende migratie op de bestaande `spatie/laravel-webhook-client` tabel), `provider`, date-range

### Out of scope (expliciet uitgesteld)

- **Multi-rol RBAC** — alleen `is_emeq_staff` boolean. Spatie-permission komt pas als meer dan één rol ontstaat.
- **Consumer self-service dashboard** op `/portal` met eigen creds → v1.0+ commerciële launch, React+shadcn op aparte panel-route.
- **E-mail notificaties** uit Filament → apart queue/mailer-werk.
- **2FA/MFA voor admin login** → v1.0+ als compliance dit eist.
- **Audit-log via `spatie/laravel-activitylog`** → geparkeerd als `HUB-AUDIT` backlog-item. OAuth-revoke wordt sowieso gelogd via de bestaande `webhook_calls`-outgoing-flow uit Phase 5.
- **Tailwind-thema-customizing** → default Filament-look is goed genoeg voor intern gebruik.

## Implementatie-skelet

### 1. Filament v4 installeren

```bash
composer require filament/filament:"^4.0" -W
php artisan filament:install --panels --no-interaction
```

Genereert `app/Providers/Filament/AdminPanelProvider.php` met `->path('admin')`, `->login()`, `->authGuard('web')`. Registreert via `bootstrap/providers.php`. Geen route-conflict met `routes/web.php` (`/`, `/up`) of toekomstige `routes/api.php` (`/v1/*`).

**Verifieer bij executie** via `mcp__plugin_context7_context7__query-docs` met `filament/filament` dat v4 nog de actuele major is en de install-commands kloppen — Filament major-releases bewegen snel.

### 2. User-model opwaarderen

Nieuwe migratie (forward-only):

```php
// database/migrations/2026_xx_xx_add_emeq_staff_to_users.php
$table->boolean('is_emeq_staff')->default(false)->index();
```

`app/Models/User.php`:
- `implements \Filament\Models\Contracts\FilamentUser`
- `canAccessPanel(Panel $panel): bool => $panel->getId() === 'admin' && $this->is_emeq_staff === true`
- `is_emeq_staff` **niet** in `$fillable` — alleen via seeder/command

### 3. Seeder

`database/seeders/EmeqStaffSeeder.php` leest `EMEQ_STAFF_SEED_EMAIL` + `EMEQ_STAFF_SEED_PASSWORD` uit env. Geen hardcoded creds. Productie: aanmaken via `php artisan tinker` of dedicated `php artisan emeq:staff:create` command (optioneel, niet vereist in deze phase).

### 4. Security-vangnetten

- `Connection::fingerprint(string $field): string` accessor op het model. Alle Filament-kolommen lezen alleen via deze accessor — raw decryption gebeurt nergens in de UI-laag.
- Feature-test `tests/Feature/Admin/ConnectionFingerprintTest.php` asserteert dat de plain-text `access_token`-waarde nooit voorkomt in de HTML-respons van `livewire(ListConnections::class)`.
- Feature-test `tests/Feature/Admin/PanelAccessTest.php` asserteert dat een non-staff `User` 403 krijgt op `/admin`.

## Success Criteria

1. Een geseede staff-user kan inloggen op `/admin` en zien Consumers/Connections/Accounts/WebhookCalls in 4 aparte resource-lijsten
2. Een non-staff `User` (waar `is_emeq_staff = false`) krijgt 403 op `/admin` — `canAccessPanel()` blokkeert
3. `ConsumerResource` issue-PAT-action retourneert plain-text token in een notification (éénmalig zichtbaar) + maakt een rij in `personal_access_tokens`
4. `ConnectionResource` toont alleen fingerprints (sha256[0..12]) — een feature-test asserteert dat de plain-text `access_token` waarde nooit in de HTML-respons van `livewire(ListConnections::class)` voorkomt
5. `ConnectionResource` revoke-action roept `OAuthFlow::revoke($connection)` aan (uit Phase 4-contract) en zet `revoked_at` — niet alleen een DB-flag zonder upstream revoke

## Critical Files

- `app/Models/User.php` — `FilamentUser`-interface + `canAccessPanel`
- `app/Providers/Filament/AdminPanelProvider.php` — gegenereerd door `filament:install`
- `app/Filament/Resources/{Consumer,Connection,Account,WebhookCall}Resource.php` — nieuwe resources
- `app/Models/Connection.php` — `fingerprint()` accessor (model komt uit Phase 3)
- `database/migrations/2026_xx_xx_add_emeq_staff_to_users.php` — nieuwe boolean-kolom
- `database/seeders/EmeqStaffSeeder.php` — env-driven staff-user
- `composer.json` — `filament/filament: ^4.0`

## Verification Path

End-to-end pad (na install + migrate + seed, vereist Phase 3 + 4 geland):

```bash
php artisan migrate
EMEQ_STAFF_SEED_EMAIL=admin@emeq.nl EMEQ_STAFF_SEED_PASSWORD=secret \
  php artisan db:seed --class=EmeqStaffSeeder
php artisan serve --port=8001
# Open http://hub.emeq.test:8090/admin → login
# 1. Consumers → Create "test-consumer" → Issue PAT met ability "read"
#    → kopieer plain-token uit notification (één keer zichtbaar)
# 2. curl -H "Authorization: Bearer <token>" http://hub.emeq.test:8090/v1/ping → 200
# 3. (Vereist Phase 4) Connections → revoke seeded test-Connection
# 4. tinker: Connection::first()->access_token  → encrypted blob, niet plain
#    Filament toont alleen "sha256:abc123def456" fingerprint
```

Feature-tests (PHPUnit):

- `tests/Feature/Admin/PanelAccessTest.php` — non-staff user → 403 op `/admin`
- `tests/Feature/Admin/ConsumerTokenActionTest.php` — issue-action maakt `personal_access_tokens` rij + retourneert plain token in notification
- `tests/Feature/Admin/ConnectionFingerprintTest.php` — raw token komt nooit in HTML-response van `livewire(ListConnections::class)`

## Next Action

`/gsd-plan-phase 9` zodra Phase 3 en Phase 4 in execution gaan of klaar zijn.
