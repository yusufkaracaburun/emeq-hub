---
phase: 03-hub-skeleton
reviewed: 2026-05-14T14:38:42Z
depth: standard
files_reviewed: 21
files_reviewed_list:
  - database/migrations/2026_05_14_000001_create_consumers_table.php
  - database/migrations/2026_05_14_000002_create_accounts_table.php
  - database/migrations/2026_05_14_000003_create_connections_table.php
  - app/Models/Consumer.php
  - app/Models/Account.php
  - app/Models/Connection.php
  - database/factories/ConsumerFactory.php
  - database/factories/AccountFactory.php
  - database/factories/ConnectionFactory.php
  - app/Sanctum/TokenAbilities.php
  - routes/api.php
  - config/auth.php
  - bootstrap/app.php
  - app/Http/Controllers/Api/V1/PingController.php
  - tests/Feature/Api/PingTest.php
  - tests/Feature/Api/SanctumAbilityTest.php
  - tests/Feature/ConnectionEncryptionTest.php
  - tests/Feature/ConsumerAccountScopingTest.php
  - app/Console/Commands/HubConsumerCreate.php
  - tests/Feature/Console/HubConsumerCreateTest.php
  - database/seeders/DatabaseSeeder.php
findings:
  blocker: 2
  warning: 11
  total: 13
status: issues_found
---

# Phase 03: Code Review Report

**Reviewed:** 2026-05-14T14:38:42Z
**Depth:** standard
**Files Reviewed:** 21
**Status:** issues_found

## Summary

Het Hub-skeleton legt de juiste fundamenten: domeinmodel (`Consumer → Account → Connection`), encrypted casts op alle secret-velden, Sanctum-PAT-auth met `consumers`-provider en een CLI om Consumers + tokens te bootstrappen. De encryption-tests bewijzen at-rest-encryptie via een raw `DB::table()`-query (precies zoals de spec eist), en het scoping-tests-bestand demonstreert dat dezelfde `external_id` per Consumer mag, maar niet leakt. Goed startpunt.

De review vindt echter twee BLOCKERs die direct het security-contract uit `CLAUDE.md` en `.ai/rules/global.md` raken:

1. `refresh_token` mist test-coverage voor encryption-at-rest, terwijl het wel `encrypted`-gecast is. De pillar "tokens encrypted at rest" wordt gedeeltelijk geclaimd in `03-CONTEXT.md` maar niet bewezen voor de hoogste-impact-token (OAuth-refresh).
2. `HubConsumerCreate::resolveAbilities()` accepteert willekeurige strings als ability zonder validatie tegen `TokenAbilities::all()`. Een typo (`snelstart:reed`) of bewuste injectie (`*-stale`) levert een geldig PAT met onbekende-maar-aanwezige ability — straks bij een `->middleware('ability:...')`-route kan dit silent-failen of bypassen.

Daarnaast: rate-limiting ontbreekt op `/v1/*`, de "admin wildcard"-test bewijst niet wat de naam claimt, en er is geen unique-constraint op `(account_id, provider)` waardoor dubbele actieve Connections per provider mogelijk zijn (data-modelling gap).

## Blocker Issues

### BL-01: Encryption-at-rest niet getest voor `refresh_token`

**File:** `tests/Feature/ConnectionEncryptionTest.php:43-55`
**Issue:** `Connection::$casts` markeert `refresh_token` als `encrypted` (zie `app/Models/Connection.php:55`), maar de test-suite verifieert alleen `client_key`, `subscription_key` en `access_token` via raw-DB-query. Voor OAuth-providers (Mollie, toekomstige Exact/Ibanity) is `refresh_token` juist de hoogste-impact-credential — diefstal levert permanent toegang totdat de partner 'm intrekt. De pillar "tokens encrypted at rest" uit `CLAUDE.md` is dus claimed-but-not-proven. Een toekomstige refactor die per ongeluk de cast removed of een nieuwe migration die `refresh_token` van `text` naar een ander type wijzigt, zou onopgemerkt blijven.
**Fix:**
```php
public function test_mollie_refresh_token_is_encrypted_at_rest(): void
{
    $connection = Connection::factory()
        ->forMollie()
        ->create(['refresh_token' => 'refresh_secret-xyz']);

    $rawAtRest = DB::table('connections')
        ->where('id', $connection->id)
        ->value('refresh_token');

    $this->assertNotSame('refresh_secret-xyz', $rawAtRest);
    $this->assertNotEmpty($rawAtRest);
    $this->assertSame('refresh_secret-xyz', $connection->fresh()->refresh_token);
}
```

### BL-02: `hub:consumer:create` valideert abilities niet tegen `TokenAbilities`

**File:** `app/Console/Commands/HubConsumerCreate.php:54-69`
**Issue:** `resolveAbilities()` neemt het CLI-input `--abilities=...`, splitst op komma, trim't en stuurt het rechtstreeks naar `createToken()`. Er is geen check tegen `TokenAbilities::all()`. Gevolg:
- Typo's (`snelstart:reed` in plaats van `snelstart:read`) creëren een geldig PAT zonder bruikbare abilities — silent failure bij toekomstige `->middleware('ability:snelstart:read')`.
- Bewuste rare strings (`*-something`, spaces, junk) belanden in de `personal_access_tokens.abilities`-kolom. Sanctum's `tokenCan()` check op `*` is een exact-match — wildcard-pollution is mogelijk afhankelijk van hoe abilities worden gechecked.
- De whole reden om `TokenAbilities` als final class met constants te hebben is type-safety; die wordt nergens gehandhaafd.

Dit overtreedt CLAUDE.md's "Consumer ↔ Account ↔ Connection chain is strict" — abilities zijn het authorization-stuk dat straks per-provider-toegang gatet, en moeten strict zijn.
**Fix:**
```php
private function resolveAbilities(): array
{
    /** @var list<string> $raw */
    $raw = (array) $this->option('abilities');

    if ($raw === []) {
        return [TokenAbilities::ADMIN];
    }

    $abilities = collect($raw)
        ->flatMap(fn (string $item): array => explode(',', $item))
        ->map(fn (string $item): string => trim($item))
        ->filter()
        ->values()
        ->all();

    $known = TokenAbilities::all();
    $unknown = array_diff($abilities, $known);

    if ($unknown !== []) {
        throw new InvalidArgumentException(
            'Unknown abilities: '.implode(', ', $unknown).
            '. Allowed: '.implode(', ', $known)
        );
    }

    return $abilities;
}
```
Plus een matchende negative-test in `HubConsumerCreateTest`.

## Warnings

### WR-01: Geen rate-limiting op `/v1/*`

**File:** `routes/api.php:14`
**Issue:** De route-group heeft alleen `auth:sanctum`. Er is geen `throttle:api` (of strenger) middleware. Een gelekt PAT kan ongelimiteerd `/v1/ping` of straks `/v1/snelstart/*` aanroepen tot Snelstart's rate-limit hit. Bovendien geen brute-force-mitigatie op auth-failures (Sanctum returnt 401 zonder throttling op deze route).
**Fix:**
```php
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('/ping', PingController::class)->name('api.ping');
});
```
Overweeg een aparte rate-limiter `RateLimiter::for('api', ...)` in `AppServiceProvider::boot()` die op consumer-ID scopet i.p.v. IP.

### WR-02: `test_admin_wildcard_grants_access_to_any_route` bewijst niet wat de naam claimt

**File:** `tests/Feature/Api/SanctumAbilityTest.php:14-22`
**Issue:** De test maakt een token met `*` en hit `/v1/ping`. Maar `/v1/ping` heeft geen ability-check (`auth:sanctum` only, zie WR-04). Een token met *elke* ability — inclusief `snelstart:read` — zou hier ook 200 returnen. De test bewijst dus alleen "een geldig PAT met `*` is een geldig PAT". Misleidende naam, geen extra coverage.
**Fix:** Hernoem naar `test_token_with_admin_ability_is_accepted_by_sanctum_guard`, of voeg een tweede route toe met `->middleware('ability:snelstart:read')` en assert dat `*`-token doorkomt waar `mollie:read`-token een 403 krijgt.

### WR-03: Geen unique constraint op `(account_id, provider)`

**File:** `database/migrations/2026_05_14_000003_create_connections_table.php:38`
**Issue:** Alleen een non-unique index op `(account_id, provider)`. Niets in DB of model voorkomt dat één Account twee actieve Snelstart-Connections heeft. Bij OAuth-re-consent zou dit een doublet kunnen produceren; bij webhook-routing weet de Hub niet welke Connection bij een binnenkomende callback hoort.

Note: er kan een legitiem use-case zijn (oude + nieuwe Connection naast elkaar tijdens migratie), in welk geval een partial unique index op `WHERE revoked_at IS NULL` op zijn plaats is.
**Fix:** Discussie + nieuwe migration:
```php
// Optie A: harde unique
$table->unique(['account_id', 'provider']);

// Optie B (Postgres): partial unique op actieve rows
DB::statement('CREATE UNIQUE INDEX connections_account_provider_active_idx
               ON connections (account_id, provider)
               WHERE revoked_at IS NULL');
```

### WR-04: `auth:sanctum`-only op `/v1/ping` — geen ability-enforcement

**File:** `routes/api.php:15`
**Issue:** De comment in `SanctumAbilityTest.php:26-29` erkent al dat ability-middleware ontbreekt in Phase 3. Dat is begrijpelijk omdat er nog geen provider-routes zijn, maar voor de placeholder `/v1/ping` is `auth:sanctum` zonder ability-eis dubieus: een token met enkel `mollie:read` mag praten met een ping die metadata over een Consumer teruggeeft (zie WR-08).
**Fix:** Of definieer expliciet een lichte ability (bijv. `ping`/`probe`), of accepteer dit als phase-scope en zorg dat `WR-08` (consumer-ID exposure) gefixed wordt.

### WR-05: `HubConsumerCreate` valideert slug-format niet

**File:** `app/Console/Commands/HubConsumerCreate.php:22-29`
**Issue:** `$slug` wordt geaccepteerd zoals aangeleverd. `Consumer::create(['slug' => 'Naschool Test'])` (met spatie, hoofdletters) gaat door tot de DB. De `unique` constraint vangt duplicaten, maar niet format-issues. `ConsumerFactory` produceert keurige kebab-case slugs; de CLI niet.
**Fix:**
```php
if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
    $this->error('--slug moet kebab-case zijn (lowercase, cijfers, hyphens).');
    return self::INVALID;
}
```

### WR-06: `HubConsumerCreate` echo't raw `QueryException`-message naar CLI

**File:** `app/Console/Commands/HubConsumerCreate.php:33-37`
**Issue:** `{$e->getMessage()}` van een `QueryException` bevat het volledige SQL-statement + parameter-binding details. Op CLI in productie (`php artisan hub:consumer:create` via SSH) ge-logged of in een CI-log gestreamd, lekt dit DB-schema-info. Voor een interactieve developer-tool acceptabel; voor CI-automation niet.
**Fix:**
```php
} catch (QueryException $e) {
    if ($e->errorInfo[1] ?? null === 1062 /* MySQL */
        || str_contains($e->getMessage(), 'consumers_slug_unique')) {
        $this->error("Slug '{$slug}' bestaat al.");
    } else {
        $this->error('Aanmaken Consumer mislukt.');
        report($e);
    }
    return self::FAILURE;
}
```

### WR-07: `Connection::fingerprint()` is hardcoded per provider — silent null voor nieuwe

**File:** `app/Models/Connection.php:37-46`
**Issue:** De `match`-expressie kent alleen `snelstart` en `mollie`. Voor een toekomstige `moneybird`-/`ibanity`-/`exact`-Connection returnt `fingerprint()` `null`. De caller (logging, debugging) krijgt geen warning. Wanneer Phase 4+ landt en iemand een Moneybird-Connection logt, denkt de developer dat er geen secret is, terwijl er wél een access_token in DB staat.
**Fix:** Of een `default => throw new LogicException(...)` voor onbekende providers, of een generieke fallback die `access_token ?? client_key` fingerprint. Plus aanpassing in test `test_fingerprint_returns_null_for_unknown_provider` om het nieuwe gedrag te beschrijven. Mijn voorkeur: gooi een exception (fail-loud) — dat dwingt updates bij nieuwe provider-toevoeging.

### WR-08: `PingController` lekt Consumer-slug + abilities ongefilterd

**File:** `app/Http/Controllers/Api/V1/PingController.php:19-23`
**Issue:** Met een geldig PAT krijg je de Consumer-slug + alle abilities terug. Op zich geen ramp (slug is non-secret), maar:
- Het is een information-disclosure-vector richting een gestolen-PAT-bezitter die anders niet wist tot welke Consumer het token hoort.
- De abilities-lijst maakt een aanvaller meteen duidelijk welke endpoints hij/zij wel/niet kan hitten — reconnaissance-hulp.

Niet kritiek omdat het token al gestolen is op dat punt, maar overweeg of `pong` + 200 niet genoeg is.
**Fix:** Strip naar minimum, of accepteer maar documenteer als bewust voor consumer-debugging.

### WR-09: `forMollie()` factory zet `expires_at` op `now()->addHour()` — kan test-flakiness veroorzaken

**File:** `database/factories/ConnectionFactory.php:49`
**Issue:** `now()->addHour()` is real-time. Tests die straks logica testen rond "is dit token verlopen" kunnen flakey worden afhankelijk van wall-clock. Beter `Carbon` freezen of een vaste relative datetime gebruiken.
**Fix:**
```php
'expires_at' => fake()->dateTimeBetween('+30 minutes', '+2 hours'),
```
of in tests die expiry-logica raken: `Carbon::setTestNow(...)`.

### WR-10: `Connection.provider` en `Connection.status` zijn untyped strings

**File:** `app/Models/Connection.php:39-43` en `database/migrations/2026_05_14_000003_create_connections_table.php:17-18`
**Issue:** `provider` en `status` zijn `string`-kolommen. Het hele systeem leunt op stringvergelijking (`match`-statements, `where('provider', 'snelstart')`-queries). Een typo of inconsistente casing (Snelstart vs snelstart) is een latent bug. PHP 8.4 + Spatie laravel-data conventie pleit voor backed enums hier.
**Fix:**
```php
// app/Enums/ConnectionProvider.php
enum ConnectionProvider: string
{
    case Snelstart = 'snelstart';
    case Mollie = 'mollie';
}

// app/Enums/ConnectionStatus.php
enum ConnectionStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Pending = 'pending';
}

// In Connection::casts():
'provider' => ConnectionProvider::class,
'status' => ConnectionStatus::class,
```

### WR-11: `DatabaseSeeder` seeded zowel `User` als `Consumer` — onduidelijk waarom `User` nog bestaat

**File:** `database/seeders/DatabaseSeeder.php:23-28` en `app/Models/User.php`
**Issue:** Het skeleton-`User`-model + `users`-tabel zijn nog steeds aanwezig en worden geseeded, terwijl `Consumer` de feitelijke auth-entity is voor `sanctum`-guard (`config/auth.php:48-50`). Op termijn dood gewicht; nu een leesbaarheidsprobleem (twee `Authenticatable`-modellen die elk denken auth-canonical te zijn). De `web`-guard staat nog op `users` (`config/auth.php:42-45`), maar er zijn geen `web`-auth-routes.

Niet blockend voor Phase 3 (skeleton-fase), wel een TODO voor de cleanup-PR die met v0.2-stack-finalisering gepaard gaat.
**Fix:** Of expliciet documenteren waarom `User` nog leeft (e.g. "voor admin-backend in Phase 7"), of verwijder `User` + de bijbehorende `users`-tabel-migration + `User::factory()` in seeder. Aparte ADR/clean-up-task.

---

_Reviewed: 2026-05-14T14:38:42Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
