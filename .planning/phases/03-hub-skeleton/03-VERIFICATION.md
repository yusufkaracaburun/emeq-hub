---
phase: 03-hub-skeleton
verified: 2026-05-14T14:44:48Z
status: gaps_found
score: 4/5 must-haves verified
overrides_applied: 0
gaps:
  - truth: "Een Connection met test-credentials toont nooit raw waardes in `->toArray()` zonder expliciete decrypt-call (SC-3)"
    status: partial
    reason: "`toArray()`-hiding is volledig bewezen voor alle 4 velden. Encryption-at-rest is bewezen voor 3 van de 4 encrypted velden (`client_key`, `subscription_key`, `access_token`). `refresh_token` heeft de `encrypted` cast in `Connection::casts()` maar er is GEEN raw-DB-bypass test die bewijst dat het ciphertext op disk is — zelfde issue als REVIEW BL-01. Voor de spec van SC-3 ('Connection met test-credentials toont nooit raw waardes') is dit een gat: toArray hides werkt, maar de bewijslast dat 'het in de DB encrypted staat' is niet rond voor de hoogste-impact OAuth-credential."
    artifacts:
      - path: "tests/Feature/ConnectionEncryptionTest.php"
        issue: "Geen `test_*_refresh_token_is_encrypted_at_rest` testmethod; refresh_token wordt alleen in test_to_array_hides_all_credential_fields geverifieerd (toArray-pad, niet at-rest-pad)"
    missing:
      - "Voeg `test_mollie_refresh_token_is_encrypted_at_rest()` toe naar `tests/Feature/ConnectionEncryptionTest.php` die `DB::table('connections')->value('refresh_token')` leest en `assertNotSame('plain-secret', $raw)` + `assertSame('plain-secret', $conn->fresh()->refresh_token)` controleert (zie REVIEW BL-01 voor exact patroon)"
---

# Phase 03: Hub-skeleton Verification Report

**Phase Goal:** "Een werkende Hub-app met multi-tenant data-model en Consumer-authenticatie waarop de OAuth-broker (Phase 4), Mollie-pass-through (Phase 5a) en Snelstart-pass-through (Phase 5b) kunnen landen."
**Verified:** 2026-05-14T14:44:48Z
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria)

| #   | Truth                                                                                                                                                                                                                | Status     | Evidence |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- | -------- |
| SC-1 | `php artisan migrate:fresh --seed` levert demo-Consumer ("naschool"), demo-Account (school1) en lege `connections`-tabel                                                                                            | VERIFIED   | Live-run: 9 migrations DONE; tinker bevestigt `Consumers count: 1`, `Accounts count: 1`, `Connections count: 0`, `Consumer naschool exists: yes`, `Account school1 exists: yes`, `Account consumer_id matches: yes` |
| SC-2 | Een Consumer kan een Sanctum-PAT verkrijgen en authenticeren tegen `/v1/ping`-smoke-endpoint met `Authorization: Bearer …`                                                                                            | VERIFIED   | Live tinker-kernel call: `Bearer <PAT>` → status 200, body `{"pong":true,"consumer":"smoke-test","abilities":["snelstart:read"]}`. Route `v1/ping` met middleware `["api","Authenticate:sanctum"]` bevestigd via `route:list --json`. PingTest 3/3, SanctumAbilityTest 2 passed + 1 incomplete (intentional Phase 5b placeholder) |
| SC-3 | Een Connection met test-credentials toont nooit raw waardes in `->toArray()` zonder expliciete decrypt-call                                                                                                          | FAILED     | toArray-hiding bewezen voor alle 4 velden (live tinker + test_to_array_hides_all_credential_fields). Encryption-at-rest bewezen voor `client_key`, `subscription_key`, `access_token` (3/4). `refresh_token` heeft `encrypted` cast maar NIET getest via raw-DB-bypass — REVIEW BL-01. Voor SC-3-letterlijk ("nooit raw waardes") is encryption-at-rest deel niet volledig bewijsbaar |
| SC-4 | Cross-Consumer query-poging faalt met 403/404 (route- OF query-level scoping)                                                                                                                                       | VERIFIED   | ROADMAP zegt expliciet "route- OF query-level scoping". Query-level is bewezen: live tinker bevestigt `Account::query()->where('consumer_id', $cA->id)->where('external_id', 'b-only')->first()` returnt `null`. ConsumerAccountScopingTest 4/4 groen, inclusief unique-constraint (consumer_id, external_id) test. Route-level scoping volgt in Phase 5b (geen scoped routes in Phase 3) |
| SC-5 | Snelstart-Connection (alleen `client_key`+`subscription_key`+`subscription_id`) én Mollie-Connection (alleen `access_token`+`refresh_token`+`expires_at`) beide valid                                                | VERIFIED   | Live tinker bevestigt: Snelstart-row → `provider=snelstart, has client_key=yes, has subscription_key=yes, has subscription_id=yes, access_token NULL=yes`. Mollie-row → `provider=mollie, has access_token=yes, has refresh_token=yes, has expires_at=yes, client_key NULL=yes`. ConnectionFactory `forSnelstart()` en `forMollie()` state-methodes produceren mutually-exclusive shapes |

**Score:** 4/5 truths verified (SC-3 partial; toArray-hiding ok, refresh_token encryption-at-rest niet test-bewezen)

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `app/Models/Consumer.php` | HasApiTokens + Authenticatable + accounts() HasMany | VERIFIED | Extends `Authenticatable`, uses `HasApiTokens, HasFactory`, `#[Fillable(['name','slug'])]`, `accounts(): HasMany` |
| `app/Models/Account.php` | BelongsTo Consumer + HasMany Connections | VERIFIED | Extends `Model`, `consumer(): BelongsTo`, `connections(): HasMany`, `#[Fillable(['consumer_id','external_id','display_name'])]` |
| `app/Models/Connection.php` | encrypted casts + `#[Hidden]` + fingerprint() | VERIFIED | 4 encrypted casts (access_token, refresh_token, client_key, subscription_key), `#[Hidden]` op exact dezelfde 4 velden, `fingerprint(): ?string` met match snelstart/mollie/default |
| `app/Sanctum/TokenAbilities.php` | 6 constants + all() helper | VERIFIED | `final class`, 6 `public const`, `all(): array` returns list<string> met 6 entries |
| `database/migrations/2026_05_14_000001_create_consumers_table.php` | consumers tabel | VERIFIED | `Schema::create('consumers')` met id, name, slug (unique), timestamps |
| `database/migrations/2026_05_14_000002_create_accounts_table.php` | accounts met unique(consumer_id, external_id) | VERIFIED | `foreignId('consumer_id')->constrained()->cascadeOnDelete()`, `unique(['consumer_id','external_id'])`, `index('consumer_id')` |
| `database/migrations/2026_05_14_000003_create_connections_table.php` | OAuth + key-based velden | VERIFIED | text() voor 4 encrypted velden, `account_id` FK cascadeOnDelete, scopes/metadata JSON, expires_at/revoked_at timestamps |
| `database/factories/ConnectionFactory.php` | forSnelstart() + forMollie() states | VERIFIED | Beide state-methodes aanwezig, return `static`, produceren mutually-exclusive shapes |
| `config/auth.php` | sanctum-guard + consumers-provider | VERIFIED | `'sanctum' => ['driver'=>'sanctum','provider'=>'consumers']`, `'consumers' => ['driver'=>'eloquent','model'=>Consumer::class]`, web/users intact |
| `bootstrap/app.php` | withRouting met api: + apiPrefix: 'v1' | VERIFIED | `api: __DIR__.'/../routes/api.php'`, `apiPrefix: 'v1'`, SetNoIndexHeaders middleware-append intact |
| `routes/api.php` | /v1/ping in auth:sanctum group | VERIFIED | `Route::middleware('auth:sanctum')->group(...)` met `Route::get('/ping', PingController::class)` |
| `app/Http/Controllers/Api/V1/PingController.php` | Invokable controller | VERIFIED | `public function __invoke(Request $request): array`, retourneert `pong/consumer/abilities` |
| `app/Console/Commands/HubConsumerCreate.php` | hub:consumer:create command | VERIFIED | Signature `hub:consumer:create {--slug=} {--name=} {--abilities=*} {--token-name=cli-default}`, INVALID/FAILURE/SUCCESS exit codes |
| `database/seeders/DatabaseSeeder.php` | demo-data + production guard | VERIFIED | `app()->isProduction()` early return, `firstOrCreate` op Consumer + Account, `User::factory()` voor Filament Phase 9 |
| `tests/Feature/Api/PingTest.php` | happy + unauth + abilities | VERIFIED | 3 testmethods, alle groen |
| `tests/Feature/Api/SanctumAbilityTest.php` | admin wildcard + specific + placeholder | VERIFIED | 2 groen + 1 markTestIncomplete (intentional Phase 5b placeholder) |
| `tests/Feature/ConnectionEncryptionTest.php` | at-rest + toArray + fingerprint | PARTIAL | 7 tests groen voor client_key/subscription_key/access_token at-rest + toArray (alle 4) + fingerprint (3 providers). `refresh_token` at-rest niet getest — REVIEW BL-01 |
| `tests/Feature/ConsumerAccountScopingTest.php` | 4 scoping tests | VERIFIED | 4 testmethods groen, dekt cross-consumer leak, relation scope, duplicate external_id constraint |
| `tests/Feature/Console/HubConsumerCreateTest.php` | command happy + invalid + duplicate | VERIFIED | 5 testmethods groen, dekt happy/missing-slug/missing-name/duplicate-slug/abilities-csv |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `config/auth.php` | `app/Models/Consumer.php` | `providers.consumers.model = Consumer::class` | WIRED | Live tinker: `config('auth.providers.consumers.model')` = `App\Models\Consumer` |
| `bootstrap/app.php` | `routes/api.php` | `withRouting(api: ...)` | WIRED | route:list toont `v1/ping` met `api`-middleware-group geactiveerd |
| `routes/api.php` | `PingController` | invokable `PingController::class` | WIRED | route:list --json bevestigt action = `App\Http\Controllers\Api\V1\PingController` |
| `PingController` | `Consumer` model | `$request->user()` via sanctum-guard | WIRED | E2E smoke: ping body bevat slug `"smoke-test"` van geauthenticeerd Consumer |
| `HubConsumerCreate` | `Consumer + HasApiTokens` | `Consumer::create()` + `createToken()` | WIRED | Test bevestigt PAT-record in `personal_access_tokens` met `tokenable_type = App\Models\Consumer` |
| `DatabaseSeeder` | `Consumer + Account` | `firstOrCreate` chain | WIRED | Live `migrate:fresh --seed` produceert 1 Consumer + 1 Account zoals verwacht |
| `Account` | `Consumer` | `belongsTo(Consumer::class)` | WIRED | Scoping tests bewijzen `consumer_id` FK + relation queries werken |
| `Connection` | `Account` | `belongsTo(Account::class)` + factory `Account::factory()` als FK | WIRED | Factories produceren valide rows zonder FK-errors |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| PingController | `$request->user()` | sanctum-guard → `personal_access_tokens` lookup → Consumer-model | Yes | FLOWING (E2E smoke met fresh PAT bewijst echte Consumer-slug in response) |
| HubConsumerCreate | `$consumer + $token` | Eloquent `Consumer::create()` + Sanctum `createToken()` | Yes | FLOWING (test bewijst persistent row in `consumers` en `personal_access_tokens`) |
| DatabaseSeeder | `Consumer + Account` via firstOrCreate | Eloquent writes | Yes | FLOWING (live run bevestigt rows persisten in DB) |
| Connection encryption | Eloquent `encrypted` cast | `APP_KEY` → Laravel Crypt | Yes | FLOWING voor 3/4 secret-velden; `refresh_token` cast aanwezig maar at-rest-data-flow niet test-bewezen |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| migrate:fresh --seed slaagt | `php artisan migrate:fresh --seed --no-interaction` | All migrations DONE, seeders run | PASS |
| Demo Consumer + Account aanwezig | tinker count + lookup | Consumer:1, Account:1, Connection:0, naschool/school1 gevonden | PASS |
| Sanctum guard config gelaagd | `config('auth.guards.sanctum.driver')` etc | `sanctum/consumers`, model = `App\Models\Consumer`, 6 abilities | PASS |
| Route /v1/ping geregistreerd met auth:sanctum | `route:list --path=v1 --json` | URI `v1/ping`, middleware bevat `Authenticate:sanctum` | PASS |
| End-to-end /v1/ping met PAT | tinker-kernel `Bearer <token>` → handle | HTTP 200, body bevat juiste slug + abilities | PASS |
| toArray hides 4 secret velden | tinker check op forSnelstart connection | client_key/access_token/refresh_token/subscription_key allemaal hidden | PASS |
| Explicit decrypt geeft plaintext terug | `$snel->client_key === "PLAIN-CK-XYZ"` | ok | PASS |
| Snelstart shape valid | `forSnelstart()->create()` | provider=snelstart, key-based velden gevuld, OAuth-velden NULL | PASS |
| Mollie shape valid | `forMollie()->create()` | provider=mollie, OAuth-velden gevuld, key-based velden NULL | PASS |
| Cross-consumer query leak | `Account::query()->where('consumer_id', cA)->where('external_id', 'b-only')->first()` | null | PASS |
| Full test suite | `php artisan test --compact` | 27 passed, 61 assertions, 1 incomplete | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ----------- | ----------- | ------ | -------- |
| HUB-01 | 03-01, 03-02, 03-03, 03-04, 03-05 | `consumers`/`accounts`/`connections` tabellen + Sanctum-PAT auth voor Consumer-routes; provider-specifieke credentials (Mollie OAuth-shape, Snelstart key-based-shape); encrypted-at-rest | PARTIAL | Tabellen ✓, Sanctum-PAT auth ✓, dual credential-shapes ✓, encrypted-at-rest 3/4 secret-velden bewezen, 4e (refresh_token) heeft cast maar geen raw-DB-bypass-test → REVIEW BL-01. Geen orphaned requirements in REQUIREMENTS.md vs plans (alle 5 plans declareren HUB-01) |

### Anti-Patterns Found

Geen nieuwe anti-patterns te flaggen. REVIEW (03-REVIEW.md) heeft de codebase reeds gescand op:
- BL-01: refresh_token encryption-at-rest niet test-bewezen (overlapt met SC-3 gap hieronder)
- BL-02: HubConsumerCreate abilities-validatie ontbreekt (verbetering, raakt SC niet)
- WR-01 t/m WR-11: throttle, ability-test naamgeving, unique-constraint, fingerprint-null-on-unknown, untyped enums, residual User-model, slug-format, raw QueryException-echo, factory wall-clock, info-disclosure in ping-response

Deze findings raken niet de phase-doelen — alleen WR-03 (geen unique-constraint op (account_id, provider)) is een data-modelling gap die in Phase 5b alsnog moet landen vóór `POST /v1/connections`. Niet blocking voor Phase 3 closure.

### Human Verification Required

Geen items vereisen human verification. Alle 5 ROADMAP success criteria zijn programmatisch verifieerbaar en het enige gat (SC-3 partial) is een test-toevoeging die per BL-01 al gespecificeerd is door de code-reviewer.

### Gaps Summary

Het Hub-skeleton voldoet voor 4 van de 5 ROADMAP success criteria volledig en machinaal verifieerbaar:

- **SC-1 (seeder demo-data)**: live `migrate:fresh --seed` produceert exact de verwachte rows (Consumer naschool, Account school1, lege connections).
- **SC-2 (Sanctum-PAT op /v1/ping)**: E2E smoke via tinker-kernel-call bevestigt HTTP 200 + correcte JSON-body met slug en abilities; route is goed gewired (`api` middleware-group + `Authenticate:sanctum`).
- **SC-4 (cross-consumer scoping)**: ROADMAP staat expliciet "route- OF query-level" toe — query-level is volledig bewezen (4/4 tests + live tinker leak-prevention check). Route-level scoping volgt in Phase 5b zoals gepland.
- **SC-5 (twee credential-shapes)**: Snelstart-shape en Mollie-shape worden beide door factory-states geproduceerd en valideren tegen de Connection-tabel zoals gespecifieerd.

Het enige gat zit op SC-3: de spec ("Connection met test-credentials toont nooit raw waardes in `->toArray()` zonder expliciete decrypt-call") wordt in de praktijk afhankelijk gemaakt van twee bewijzen: (a) `#[Hidden]` hidet alle 4 secret-velden uit `toArray()` — **volledig bewezen** via `test_to_array_hides_all_credential_fields` en live tinker; (b) encryption-at-rest zorgt dat raw waardes ook niet via DB-dump lekken — **bewezen voor 3 van de 4 velden**. `refresh_token` heeft de `encrypted` cast in `Connection::casts()` maar mist een raw-DB-bypass test à la `test_mollie_refresh_token_is_encrypted_at_rest()`.

Dit overlapt 1-op-1 met REVIEW BL-01. Voor strikt-letterlijke SC-3-interpretatie ("nooit raw waardes" omvat ook at-rest) is dit een blocker. Voor de pragma "toArray-pad afgedicht + cast aanwezig + 3/4 velden actief bewezen" is dit een warning. Conservatieve verifier-stance: **gaps_found**, één gerichte test-toevoeging volstaat om SC-3 dicht te spijkeren.

Geen route-level SC-4 bewijs vereist — ROADMAP geeft expliciet "route- OF query-level" optie en query-level is dekkend bewezen.

---

_Verified: 2026-05-14T14:44:48Z_
_Verifier: Claude (gsd-verifier)_
