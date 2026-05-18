# Phase 3: Hub-skeleton — Context

**Gathered:** 2026-05-14
**Status:** Ready for planning
**Source:** Synthesized from `.claude/plans/volgens-mij-is-snelstart-api-piped-parasol.md` (Plan-mode approved 2026-05-14) + ROADMAP.md Phase 3 details + REQUIREMENTS.md HUB-01

<domain>
## Phase Boundary

Een werkende Hub-app met multi-tenant data-model (`consumers` + `accounts` + `connections`) en Consumer-authenticatie (Sanctum-PAT met provider-abilities) waarop Phase 4 (Mollie OAuth-broker), Phase 5a (Mollie-pass-through) en Phase 5b (Snelstart-pass-through) kunnen landen. Sluit af met één smoke-endpoint (`GET /v1/ping`) zodat de Sanctum-flow end-to-end testbaar is.

**Levert HUB-01:**
- `consumers`-tabel + `Consumer`-model (`HasApiTokens` trait, slug-based identifier)
- `accounts`-tabel + `Account`-model (`consumer_id + external_id` uniek samen)
- `connections`-tabel + `Connection`-model (encrypted credential-velden voor OAuth-shape + key-based-shape)
- Sanctum-config geactiveerd: `bootstrap/app.php` middleware voor `routes/api.php`, guard-config, abilities (`snelstart:read/write`, `mollie:read/write`)
- `routes/api.php` met `/v1/ping`-smoke-endpoint achter `auth:sanctum`
- `DatabaseSeeder` met demo-Consumer ("naschool") + demo-Account ("school1") + lege Connection
- `hub:consumer:create`-artisan-command voor PAT-uitgifte vanaf CLI (geen UI in Phase 3 — Filament komt in Phase 9)
- Feature-tests: ConsumerAuthTest, ConnectionScopingTest

**Niet in Phase 3** (volgt later):
- Mollie Connect OAuth-broker (Phase 4 — MOLL-02 + HUB-02)
- `/v1/mollie/*`-pass-through-API (Phase 5a — MOLL-03 + HUB-03)
- `/v1/snelstart/{path}`-pass-through-API + `POST /v1/accounts` + `POST /v1/connections` provisioning-endpoints (Phase 5b — HUB-05)
- Filament admin-UI (Phase 9 — HUB-04)
- Audit-logging in `webhook_calls` (Phase 5a/5b voegt `direction`-kolom toe)

</domain>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plan-source (autoritief voor scope + architectuur)
- `.claude/plans/volgens-mij-is-snelstart-api-piped-parasol.md` — Plan-mode approved 2026-05-14, secties "Stap 1 — Phase 3 zoals gepland (Hub-skeleton)" en "Bestanden die geraakt worden"
- `.planning/REQUIREMENTS.md` — HUB-01 requirement-tekst (regel 23)
- `.planning/ROADMAP.md` — Phase 3 details (regels 62-83 in de huidige roadmap)

### Architectuur-invariants (uit CLAUDE.md / `.ai/rules/`)
- `.ai/rules/global.md` — taal, security (tokens encrypted at rest, fingerprint-only in logs), multi-tenant scope (Consumer ↔ Account ↔ Connection chain is strict)
- `.ai/rules/engineering.md` — chirurgisch wijzigen, conflicten oppervlakken, lezen vóór schrijven
- `.ai/project` rules — Consumer/Account/Connection-architectuur, encrypted tokens at rest, geen Hub-modellen in SDK-packages
- `CLAUDE.md` — domeinmodel-tabel + invariants ("Bearer-token → Consumer → Account → Connection", nooit query-string `?connection_id=`)

### Laravel-stack docs (Sanctum + migrations)
- `vendor/laravel/sanctum/src/HasApiTokens.php` — trait die op Consumer-model komt
- `vendor/laravel/sanctum/database/migrations/...create_personal_access_tokens_table.php` — al gepubliceerd in deze repo als `database/migrations/2026_05_13_223626_create_personal_access_tokens_table.php`
- `config/auth.php` — voor `guards.sanctum`-aanvulling (driver=sanctum, provider=consumers)
- `bootstrap/app.php` — middleware aliases + `EnsureFrontendRequestsAreStateful`-config

### Snelstart-SDK referentie-pattern (voor Phase 5b voorbereiding)
- `packages/snelstart-api/src/Contracts/SnelstartCredentialResolver.php` — contract die de Hub straks bindt in Phase 5b
- `packages/snelstart-api/src/Data/SnelstartCredentials.php` — DTO-shape (`clientKey` + `subscriptionKey` + `subscriptionId`); de Hub `Connection`-model moet exact deze drie velden encrypted opslaan

### Sibling phase-context (Phase 2 als reference voor planning-shape)
- `.planning/phases/02-emeq-mollie-api-foundation/02-CONTEXT.md` — referentie-CONTEXT.md voor structuur en granulariteit
- `.planning/phases/02-emeq-mollie-api-foundation/02-01-PLAN.md` t/m `02-08-PLAN.md` — sub-plan-granulariteit (8 plans à ~5-10 min per fase)

### Bestaande Hub-code (lezen vóór planning)
- `app/Models/User.php` — bestaande User-model conventies (factories, casts, fillable)
- `app/Providers/AppServiceProvider.php` — bevat al Scramble + viewApiDocs Gate (Phase-3-voorbereiding; mag uitgebreid met `Consumer`-Sanctum-binding)
- `routes/web.php` — huidige smoke-endpoints (`/`, `/up`); blijven ongewijzigd
- `bootstrap/app.php` — middleware-configuratie (bevat `SetNoIndexHeaders`); Sanctum-middleware toevoegen voor `routes/api.php`
- `database/migrations/0001_01_01_000000_create_users_table.php` — migration-stijl-referentie
- `database/migrations/2026_05_13_223626_create_personal_access_tokens_table.php` — Sanctum-tabel bestaat al, `tokenable_id`/`tokenable_type` polymorphic — werkt met `Consumer`-model

</canonical_refs>

<decisions>
## Implementation Decisions

### Domeinmodel — tabellen

**`consumers`-tabel:**
```
id              uuid (PK) of bigint — kies bigint voor consistency met users-tabel
name            string         — vrije weergave-naam ("Naschool", "Planny")
slug            string unique  — URL-safe identifier ("naschool", "planny"); index
timestamps
```

**`accounts`-tabel:**
```
id              bigint (PK)
consumer_id     foreignId -> consumers.id, cascadeOnDelete
external_id     string         — Consumer-bepaalde identifier voor zijn klant (bv. "school1")
display_name    string nullable
timestamps
UNIQUE (consumer_id, external_id)
INDEX (consumer_id)
```

**`connections`-tabel:**
```
id                  bigint (PK)
account_id          foreignId -> accounts.id, cascadeOnDelete
provider            string        — "snelstart", "mollie", future: "moneybird", "exact"
status              string        — "active", "revoked"; default "active"

# OAuth-shape (Mollie, future Exact/Ibanity)
access_token        text encrypted nullable
refresh_token       text encrypted nullable
expires_at          timestamp nullable
scopes              json nullable

# Key-based-shape (Snelstart)
client_key          text encrypted nullable
subscription_key    text encrypted nullable
subscription_id     string nullable               — Snelstart's subscriptionId is een tenant-UUID, niet secret maar wel handig in dezelfde tabel

# Provider-specifieke overflow
metadata            json nullable

# Audit
revoked_at          timestamp nullable
created_at, updated_at
INDEX (account_id, provider)
```

**Rationale dedicated kolommen vs. enkel JSON:**
- Encrypted casts werken niet op nested JSON-keys zonder custom cast — dedicated kolommen met `encrypted` cast zijn idiomatischer
- Provider-specifieke overflow (bv. Mollie's `connect_app_id` per Connection) gaat in `metadata` JSON
- Een toekomstige provider die geen van beide shapes past, kan eigen kolommen krijgen via aanvullende migration (forward-only)

### Models

**`App\Models\Consumer`:**
- Implements `HasApiTokens` (Sanctum)
- `$fillable = ['name', 'slug']`
- `$casts = []`
- Relationship: `accounts(): HasMany`
- Helpers: `findBySlug(string)`, `createApiToken(string $name, array $abilities)`

**`App\Models\Account`:**
- `$fillable = ['consumer_id', 'external_id', 'display_name']`
- Relationships: `consumer(): BelongsTo`, `connections(): HasMany`, `connection(provider): HasOne` (active-only scope)
- Unique scope `forConsumer(Consumer)` voor query-scoping

**`App\Models\Connection`:**
- `$fillable = ['account_id', 'provider', 'status', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'client_key', 'subscription_key', 'subscription_id', 'metadata']`
- `$casts`:
  - `access_token` => `'encrypted'`
  - `refresh_token` => `'encrypted'`
  - `client_key` => `'encrypted'`
  - `subscription_key` => `'encrypted'`
  - `scopes` => `'array'`
  - `metadata` => `'array'`
  - `expires_at` => `'datetime'`
  - `revoked_at` => `'datetime'`
- `$hidden = ['access_token', 'refresh_token', 'client_key', 'subscription_key']` — komt nooit in `->toArray()` / JSON-output zonder explicit accessor
- Accessor `fingerprint()`: voor Snelstart `sha256(client_key)[0..12]`; voor Mollie `sha256(access_token)[0..12]`; voor andere providers fallback `null`. Wordt gebruikt in audit-logs in Phase 5a/5b.
- Validatie via custom rule: een Snelstart-Connection MOET `client_key` + `subscription_key` + `subscription_id` hebben en MAG geen `access_token`/`refresh_token` hebben (en vice versa voor Mollie). Validatie leeft in een FormRequest in Phase 5b — in Phase 3 alleen via factory- en tinker-asserts.

### Sanctum-config

**`config/auth.php`:**
```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'sanctum' => ['driver' => 'sanctum', 'provider' => 'consumers'],
],
'providers' => [
    'users' => [...],
    'consumers' => [
        'driver' => 'eloquent',
        'model' => App\Models\Consumer::class,
    ],
],
```

**`bootstrap/app.php`:**
- API-routes registreren via `withRouting(api: __DIR__.'/../routes/api.php', apiPrefix: 'v1')` zodat `routes/api.php` automatisch `/v1/*`-prefix krijgt en de `api`-middleware-group (incl. `throttle:api`)
- `auth:sanctum`-middleware voor de meeste `/v1/*`-routes
- `SetNoIndexHeaders` blijft zoals nu

**`routes/api.php`** (nieuwe file):
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/ping', PingController::class)->name('api.ping');
});
```

**PingController:** retourneert `{"pong": true, "consumer": "<slug>", "abilities": [...]}` zodat de smoke-test bewijst dat `Auth::user()` een Consumer is met juiste abilities.

### PAT-abilities

Conventie: `"<provider>:<action>"` met providers `snelstart` | `mollie` (Phase 3 al toevoegen voor toekomstige fasen) en actions `read` | `write`. Specials: `*` (admin) en `consumer:manage-accounts` voor `POST /v1/accounts`-flow in Phase 5b. Lijst hardcoded in `App\Sanctum\TokenAbilities` constants-class voor reuse.

### `hub:consumer:create`-artisan-command

```bash
php artisan hub:consumer:create --slug=naschool --name="Naschool" --abilities=snelstart:read,snelstart:write
# Output (eenmalig zichtbaar):
# Consumer created: id=1, slug=naschool
# Token name: cli-default
# Plain-text token: 1|abcd1234...  ← gebruiker moet dit ergens veilig opslaan
```

Args:
- `--slug=` (required, unique)
- `--name=` (required)
- `--abilities=` (CSV; default `*`)
- `--token-name=` (default "cli-default")

### DatabaseSeeder

```php
$consumer = Consumer::firstOrCreate(['slug' => 'naschool'], ['name' => 'Naschool']);
$consumer->accounts()->firstOrCreate(
    ['external_id' => 'school1'],
    ['display_name' => 'Demo School 1'],
);
// Geen Connection — die komt via Phase 5b's POST /v1/connections of via Phase 9 admin-UI
```

Niet seeden in production (`if (! app()->isProduction()) { ... }` guard).

### Cross-Consumer scoping (vóóruitkijk naar Phase 5b)

Phase 3 levert nog geen routes die scoping nodig hebben behalve `/v1/ping`. Maar de scoping-test bewijst al de query-pattern: `Account::query()->where('consumer_id', $request->user()->id)->where('external_id', $header)`. Cross-Consumer-leakage → 404 (niet 403 — voorkomt info-disclosure waarom een Account wél bestaat maar bij een andere Consumer hoort). Test: `ConnectionScopingTest::test_account_from_other_consumer_returns_404()`.

### Migration-policy

- Forward-only — geen `down()` aangeroepen in production-pad
- `down()`-methodes mogen wel bestaan voor `migrate:fresh` in dev/test, maar mogen geen destructive logic op productie hebben (geen `Schema::drop` op enige tabel die niet vers door deze migration is gemaakt)
- Eén migration per tabel (3 migrations): `*_create_consumers_table.php`, `*_create_accounts_table.php`, `*_create_connections_table.php`

### Bestandstree (Phase 3-deliverable)

```
emeq-hub/
├── app/
│   ├── Console/Commands/HubConsumerCreate.php       (nieuw)
│   ├── Http/Controllers/Api/V1/PingController.php   (nieuw)
│   ├── Models/Consumer.php                          (nieuw, HasApiTokens)
│   ├── Models/Account.php                           (nieuw)
│   ├── Models/Connection.php                        (nieuw, encrypted casts)
│   └── Sanctum/TokenAbilities.php                   (nieuw, constants)
├── bootstrap/app.php                                (uitbreiden — withRouting api: …)
├── config/auth.php                                  (sanctum-guard + consumers-provider)
├── database/
│   ├── factories/ConsumerFactory.php                (nieuw)
│   ├── factories/AccountFactory.php                 (nieuw)
│   ├── factories/ConnectionFactory.php              (nieuw, met `forSnelstart()` + `forMollie()` states)
│   ├── migrations/2026_*_create_consumers_table.php (nieuw)
│   ├── migrations/2026_*_create_accounts_table.php  (nieuw)
│   ├── migrations/2026_*_create_connections_table.php (nieuw)
│   └── seeders/DatabaseSeeder.php                   (uitbreiden — demo-consumer + account)
├── routes/api.php                                   (nieuw)
└── tests/
    ├── Feature/Api/PingTest.php                     (nieuw — happy + unauth-path)
    ├── Feature/Api/SanctumAbilityTest.php           (nieuw — geweigerd zonder juiste ability)
    └── Feature/ConnectionEncryptionTest.php         (nieuw — toArray geeft geen raw creds)
```

### Sub-plan-granulariteit (vooruitkijk voor `gsd-planner`)

Phase 2 deelde zich op in 8 sub-plans. Phase 3 is iets kleiner — verwacht 5-6 sub-plans:
1. **03-01:** `consumers`, `accounts`, `connections` migrations + models + factories
2. **03-02:** Sanctum-config (`config/auth.php` + `bootstrap/app.php`) + `TokenAbilities` constants
3. **03-03:** `routes/api.php` + `PingController` + smoke-tests (PingTest + SanctumAbilityTest)
4. **03-04:** `Connection`-encryption casts + `ConnectionEncryptionTest`
5. **03-05:** `DatabaseSeeder` demo-data + `hub:consumer:create`-command
6. **03-06** (optional): Migration-validatie-helper voor Snelstart-vs-Mollie credential-shape (kan ook in Phase 5b)

`gsd-planner` mag de granulariteit aanpassen op basis van pattern-mapping; bovenstaande is een richtlijn.

### Claude's Discretion

- Of `consumers.id` een bigint of uuid is — bigint mirrort `users` (consistency); uuid is "publieker veilig" voor toekomstige API-IDs. Default: bigint, want PAT-tokens zijn de publieke identifier, niet de Consumer-ID.
- Of `Connection`-model factory `forSnelstart()` + `forMollie()` als state-methoden krijgt of als aparte factories — kies wat tests schoner maakt
- Of de PingController een single-action `__invoke` is of een resourceful controller — single-action is leuker voor één route; resourceful kan overkill zijn maar matched mogelijk later patroon
- `hub:consumer:create`-output format (table vs JSON via `--json` flag) — JSON-flag is handig voor scripting maar niet kritisch in Phase 3
- Of de `connections.subscription_id`-kolom wel of niet als `encrypted` cast krijgt — Snelstart's `subscriptionId` is een tenant-UUID, niet zelf een secret. Default: niet-encrypted (alleen `client_key`/`subscription_key` zijn echte secrets).
- Of `users`-tabel of `App\Models\User` aangepast moet worden — NEE, dat hoort niet bij Phase 3. `User` blijft voor Emeq-medewerkers en Filament-admin (Phase 9). Consumer is volledig orthogonaal.

</decisions>

<specifics>
## Specific References / Examples

- **Sanctum-PAT-uitgifte voorbeeld** (uit `hub:consumer:create`-command):
  ```php
  $consumer = Consumer::create(['slug' => $slug, 'name' => $name]);
  $token = $consumer->createToken($tokenName, $abilities);
  $this->info("Plain-text token: {$token->plainTextToken}");
  ```
- **Auth-test happy-path** (uit `PingTest`):
  ```php
  $consumer = Consumer::factory()->create(['slug' => 'naschool']);
  $token = $consumer->createToken('test', ['snelstart:read'])->plainTextToken;
  $response = $this->withHeader('Authorization', "Bearer {$token}")
      ->getJson('/v1/ping');
  $response->assertOk()->assertJson(['pong' => true, 'consumer' => 'naschool']);
  ```
- **Encrypted-cast assertion** (uit `ConnectionEncryptionTest`):
  ```php
  $conn = Connection::factory()->forSnelstart()->create(['client_key' => 'CK-secret-123']);
  $raw = DB::table('connections')->where('id', $conn->id)->value('client_key');
  expect($raw)->not->toBe('CK-secret-123');     // encrypted at rest
  expect($conn->client_key)->toBe('CK-secret-123');  // decrypted on access
  expect($conn->toArray())->not->toHaveKey('client_key');  // hidden in JSON
  ```
- **Fingerprint accessor pattern** (mirror Snelstart-SDK):
  ```php
  public function fingerprint(): ?string
  {
      $secret = match ($this->provider) {
          'snelstart' => $this->client_key,
          'mollie'    => $this->access_token,
          default     => null,
      };
      return $secret ? substr(hash('sha256', $secret), 0, 12) : null;
  }
  ```

</specifics>

<deferred>
## Deferred Ideas (uit scope Phase 3)

| Idee | Wanneer |
|---|---|
| `OAuthFlow`-contract + `MollieConnectOAuthFlow`-implementatie | Phase 4 (HUB-02 + MOLL-02) |
| `MollieConnectOAuthFlow::authorize/callback/refresh`-endpoints | Phase 4 |
| `/v1/mollie/*`-pass-through-API + Mollie-SDK-binding | Phase 5a (MOLL-03 + HUB-03) |
| `/v1/snelstart/{path}`-pass-through-API + `HubSnelstartCredentialResolver`-binding | Phase 5b (HUB-05) |
| `POST /v1/accounts` + `POST /v1/connections`-provisioning-endpoints | Phase 5b (HUB-05) |
| Validatie-FormRequest die credential-shape per provider afdwingt | Phase 5b (komt vanzelf bij de POST /v1/connections-route) |
| Audit-logging via `webhook_calls` met direction-kolom | Phase 5a en/of 5b |
| Filament admin-UI op `/admin` (Consumer/Connection/Account/WebhookCall resources) | Phase 9 (HUB-04) |
| Webhook-routing naar Consumer-callback-URLs | Phase 5a (Mollie Connect-webhook) |
| Token-rotation / refresh-flow op `Connection.refresh_token` | Phase 4 |
| Self-service Consumer-onboarding (publiek registratie-endpoint) | v1.0+ (HUB-ONBOARDING backlog) |

</deferred>

---

*Phase: 03-hub-skeleton*
*Context gathered: 2026-05-14 via PRD-equivalent synthesis from .claude/plans/volgens-mij-is-snelstart-api-piped-parasol.md + plan-mode approval*
