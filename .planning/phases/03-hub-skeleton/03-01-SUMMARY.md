---
phase: 03-hub-skeleton
plan: 01
subsystem: hub-data-model
tags:
  - laravel
  - eloquent
  - migrations
  - factories
  - sanctum
  - multi-tenant
  - encryption
requirements:
  - HUB-01
dependency-graph:
  requires: []
  provides:
    - "consumers, accounts, connections tables in Postgres/SQLite"
    - "App\\Models\\Consumer (HasApiTokens, accounts() HasMany)"
    - "App\\Models\\Account (BelongsTo Consumer, HasMany Connections)"
    - "App\\Models\\Connection (encrypted casts + fingerprint() + #[Hidden] on credentials)"
    - "Consumer/Account/Connection factories (forSnelstart()/forMollie() states)"
  affects:
    - "Phase 3 plan 03-02 (Sanctum guard binds Consumer-model)"
    - "Phase 3 plan 03-03 (PingController consumeert Consumer + PAT)"
    - "Phase 3 plan 03-04 (ConnectionEncryptionTest verifieert encrypted casts + #[Hidden])"
    - "Phase 3 plan 03-05 (hub:consumer:create + DatabaseSeeder consumeert Consumer/Account)"
    - "Phase 4 (MollieConnectOAuthFlow schrijft Mollie-shape naar connections)"
    - "Phase 5b (HubSnelstartCredentialResolver leest Snelstart-shape uit connections)"
tech-stack:
  added: []
  patterns:
    - "PHP 8.4 attribute-syntax #[Fillable]/#[Hidden] (geen $fillable property)"
    - "casts() methode (geen $casts property) — conform User.php"
    - "Eloquent 'encrypted' cast op secret-velden + text() kolommen voor base64-payload"
    - "fingerprint() accessor: sha256(secret)[0..12] per provider — nooit raw secrets in logs"
    - "Anonymous-class migration return-pattern (Laravel 11+/13)"
    - "Factory state-methodes returnen static (UserFactory::unverified-pattern)"
    - "PHPDoc /** @use HasFactory<XxxFactory> */ boven trait-use voor IDE-introspectie"
key-files:
  created:
    - "database/migrations/2026_05_14_000001_create_consumers_table.php"
    - "database/migrations/2026_05_14_000002_create_accounts_table.php"
    - "database/migrations/2026_05_14_000003_create_connections_table.php"
    - "app/Models/Consumer.php"
    - "app/Models/Account.php"
    - "app/Models/Connection.php"
    - "database/factories/ConsumerFactory.php"
    - "database/factories/AccountFactory.php"
    - "database/factories/ConnectionFactory.php"
    - ".planning/phases/03-hub-skeleton/deferred-items.md"
  modified: []
decisions:
  - "subscription_id niet versleuteld (tenant-UUID, geen secret) — conform CONTEXT.md Claude's Discretion"
  - "Connection-factory default is Snelstart-shape (eerste pass-through-use-case in Phase 5b); forSnelstart()/forMollie() state-methodes leveren mutually-exclusive shapes"
  - "ConsumerFactory slug krijgt Str::lower(Str::random(4))-suffix — voorkomt collision bij parallelle test-creates (eigen verbetering bovenop PATTERNS.md die alleen Str::random(4) toonde, lowercase houdt slug regex-veilig)"
metrics:
  duration_minutes: 5
  tasks_completed: 3
  tasks_total: 3
  files_created: 10
  files_modified: 0
  commits: 3
  completed_at: "2026-05-14"
---

# Phase 3 Plan 01: Migrations + Models + Factories Summary

Multi-tenant data-model voor de Hub: drie tabellen (`consumers`, `accounts`, `connections`) met bijbehorende Eloquent-models en factories, waarbij `Connection` encrypted credential-velden draagt voor zowel OAuth-shape (Mollie) als key-based-shape (Snelstart) via één rij-vorm.

## What Was Built

### Task 1 — Drie migrations (commit `842976c`)

- `2026_05_14_000001_create_consumers_table.php` — `id`, `name`, `slug` (unique), timestamps.
- `2026_05_14_000002_create_accounts_table.php` — `id`, `consumer_id` (FK cascadeOnDelete), `external_id`, `display_name` (nullable), timestamps, plus `unique(consumer_id, external_id)` + `index(consumer_id)`.
- `2026_05_14_000003_create_connections_table.php` — `id`, `account_id` (FK cascadeOnDelete), `provider`, `status` (default `active`), OAuth-velden (`access_token`/`refresh_token` als `text()` voor base64-payload, `expires_at`, `scopes` JSON), key-based-velden (`client_key`/`subscription_key` als `text()`, `subscription_id` string), `metadata` JSON, `revoked_at`, timestamps, plus `index(account_id, provider)`.

Migration-timestamps geforceerd naar `000001`/`000002`/`000003` zodat FK-volgorde gegarandeerd is (eerst consumers, dan accounts, dan connections).

### Task 2 — Drie Eloquent-models (commit `b8fb46d`)

- `app/Models/Consumer.php` — extends `Authenticatable`, gebruikt `HasApiTokens` + `HasFactory`. `#[Fillable(['name', 'slug'])]`. `accounts(): HasMany` relation.
- `app/Models/Account.php` — extends `Model`. `#[Fillable(['consumer_id', 'external_id', 'display_name'])]`. `consumer(): BelongsTo` en `connections(): HasMany`.
- `app/Models/Connection.php` — extends `Model`. `#[Fillable]` met alle 12 credential-relateerde velden + `#[Hidden(['access_token', 'refresh_token', 'client_key', 'subscription_key'])]`. `casts()`-methode mapt deze vier velden naar `'encrypted'` + `scopes`/`metadata` naar `'array'` + `expires_at`/`revoked_at` naar `'datetime'`. `fingerprint()`-accessor gebruikt `match($this->provider)` om de juiste secret-bron te kiezen en retourneert `substr(hash('sha256', $secret), 0, 12)` (of `null` voor unknown provider).

### Task 3 — Drie factories (commit `1973acc`)

- `ConsumerFactory` — unieke slug via `Str::slug($name).'-'.Str::lower(Str::random(4))`.
- `AccountFactory` — `Consumer::factory()` als FK-value, unieke `external_id` via `'ext-'.fake()->unique()->numerify('######')`.
- `ConnectionFactory` — default-state levert kale Snelstart-row (`provider=snelstart`, `status=active`, `account_id=Account::factory()`). Twee state-methodes (`forSnelstart()`/`forMollie()`) vullen mutually-exclusive credential-velden, exact zoals SC-5 uit ROADMAP HUB-01 vereist.

## Factory-states beschikbaar

| State | Provider | Gevulde velden | NULL-velden |
|-------|----------|----------------|-------------|
| `Connection::factory()` (default) | snelstart | — (alleen pivot-velden) | alle credential-velden |
| `Connection::factory()->forSnelstart()` | snelstart | `client_key`, `subscription_key`, `subscription_id` | `access_token`, `refresh_token`, `expires_at`, `scopes` |
| `Connection::factory()->forMollie()` | mollie | `access_token`, `refresh_token`, `expires_at`, `scopes` | `client_key`, `subscription_key`, `subscription_id` |

## Verification Results

| Acceptance criterion | Status |
|---|---|
| `php artisan migrate:fresh --no-interaction` exit 0 | OK |
| `consumers`, `accounts`, `connections` aanwezig in DB | OK (`consumers:ok accounts:ok connections:ok` via tinker `Schema::hasTable`) |
| Elke migration heeft exactly één `Schema::create(...)` | OK (`grep -c` == 1 voor alle drie) |
| `connections` heeft 4 `$table->text(...)->nullable()` (access_token, refresh_token, client_key, subscription_key) | OK |
| `accounts` heeft `$table->unique(['consumer_id', 'external_id'])` | OK |
| `Consumer` heeft `use Laravel\Sanctum\HasApiTokens` | OK |
| `Connection` heeft 4× `'encrypted'` cast | OK |
| `Connection` heeft `#[Hidden]` op exact 4 velden | OK |
| `Connection::fingerprint()` gebruikt `match` | OK |
| Sanctum-PAT-uitgifte werkt op Consumer (`Consumer::create(...)->createToken(...)` retourneert `plainTextToken`) | OK (`token:ok` via tinker) |
| `Connection::factory()->forSnelstart()` levert Snelstart-shape (client_key set, access_token NULL) | OK (`snel:ok` via tinker) |
| `Connection::factory()->forMollie()` levert Mollie-shape (access_token set, client_key NULL) | OK (`moll:ok` via tinker) |
| `fingerprint()` retourneert 12-char sha256-prefix per provider | OK (`ac942340c588` snelstart, `e1b2eb40d6d0` mollie) |
| `Connection::toArray()` heeft géén `client_key`-key (`#[Hidden]` werkt) | OK |
| Pint clean op `app/Models` + `database/factories` + nieuwe migrations | OK (`vendor/bin/pint --dirty --format agent` passed) |
| Bestaande testsuite niet kapot | OK (5/5 tests, 11 assertions, 262ms) |

## Threat-mitigaties bewezen (uit plan-frontmatter threat_model)

- **T-03-01** (Information Disclosure on credential columns) — `'encrypted'` cast actief op alle vier secret-velden. Volledige raw-vs-decrypted DB-bypass-test komt in plan 03-04, maar at-rest-encryption is via cast geactiveerd.
- **T-03-02** (Information Disclosure via `toArray()`) — `#[Hidden]` op alle vier credential-velden; tinker bewijst `client_key`-key ontbreekt in `toArray()`.
- **T-03-03** (Log-leakage van credentials) — `fingerprint()`-accessor levert sha256[0..12]; geen raw secrets in mogelijke audit-output.
- **T-03-04** (Tampering FK-integriteit) — `cascadeOnDelete()` op `consumer_id` en `account_id` via DB-level constrained FK.
- **T-03-05** (Tampering slug-collisions) — `consumers.slug` unique-index actief.

## Deviations from Plan

Geen functionele afwijkingen. Twee nuances:

1. **ConsumerFactory slug-suffix lowercase** (eigen verbetering, niet door plan voorgeschreven). PATTERNS.md regel 705 toonde `Str::slug($name).'-'.Str::random(4)`. `Str::random(4)` kan uppercase teruggeven (bv. `AbCd`), wat na `Str::slug` impliciet lowercased zou worden door Laravel's slug-helper bij subsequente reuse — om verwarring + double-slugify-werk te voorkomen forceer ik `Str::lower(Str::random(4))`. Effect: slug blijft consistent kebab-case zonder uppercase-noise.

2. **Migration-timestamp explicit** — plan-acceptance vereist exact `2026_05_14_000001`/`000002`/`000003`. `php artisan make:migration` genereert real-clock-timestamps (`135204`/`135205`), die ik na generatie heb hernoemd. Geen invloed op gedrag, alleen ordering-determinisme bij `migrate:fresh`.

## Auth Gates

Geen. Plan vereist geen externe authenticatie.

## Deferred Issues

Eén out-of-scope discovery vastgelegd in `.planning/phases/03-hub-skeleton/deferred-items.md`:

- Pint formatting-drift op `database/migrations/2026_05_13_223628_create_webhook_calls_table.php` en `2026_05_13_223629_add_attachments_to_webhook_calls_table.php` (Spatie webhook-client vendor-publish; class-stijl + ordered_imports niet conform Pint). Niet door dit plan veroorzaakt → niet meegenomen in deze commits, conform scope-boundary regel en `.ai/rules/engineering.md` "Chirurgisch wijzigen".

## Known Stubs

Geen. Alle artifacts zijn productie-shape; verdere wiring (Sanctum-guard binding, routes, encryption-tests) gebeurt in plan 03-02 t/m 03-05 zoals door HUB-01 voorzien.

## Continuation

Vervolg-werk in deze fase:

- **Plan 03-02** — Sanctum-config (`config/auth.php` `sanctum`-guard + `consumers`-provider, `bootstrap/app.php` `apiPrefix: 'v1'`, `App\Sanctum\TokenAbilities` constants-class, `routes/api.php` skeleton).
- **Plan 03-03** — `routes/api.php` `/v1/ping` + `PingController` + PingTest + SanctumAbilityTest.
- **Plan 03-04** — `ConnectionEncryptionTest` (verifieert dit plan's encrypted cast + `#[Hidden]` + `fingerprint()` via DB-bypass-asserts) + `ConsumerAccountScopingTest`.
- **Plan 03-05** — `hub:consumer:create` artisan-command + `DatabaseSeeder` demo-data + acceptance-run.

## Self-Check: PASSED

**Files exist:**
- FOUND: database/migrations/2026_05_14_000001_create_consumers_table.php
- FOUND: database/migrations/2026_05_14_000002_create_accounts_table.php
- FOUND: database/migrations/2026_05_14_000003_create_connections_table.php
- FOUND: app/Models/Consumer.php
- FOUND: app/Models/Account.php
- FOUND: app/Models/Connection.php
- FOUND: database/factories/ConsumerFactory.php
- FOUND: database/factories/AccountFactory.php
- FOUND: database/factories/ConnectionFactory.php
- FOUND: .planning/phases/03-hub-skeleton/deferred-items.md

**Commits exist:**
- FOUND: 842976c (Task 1: migrations)
- FOUND: b8fb46d (Task 2: models)
- FOUND: 1973acc (Task 3: factories)
