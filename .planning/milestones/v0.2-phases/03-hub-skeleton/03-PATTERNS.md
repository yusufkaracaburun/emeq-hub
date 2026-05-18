# Phase 3: Hub-skeleton — Pattern Map

**Mapped:** 2026-05-14
**Files analyzed:** 19 new/modified files
**Analogs found:** 14 with strong match / 19 total (5 hebben geen directe analog in deze repo, zie "No Analog Found")

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/Models/Consumer.php` | model | CRUD | `app/Models/User.php` | exact (Authenticatable + Sanctum-ready) |
| `app/Models/Account.php` | model | CRUD | `app/Models/User.php` | role-match (geen Authenticatable) |
| `app/Models/Connection.php` | model | CRUD + encryption | `app/Models/User.php` (casts-style) | partial (geen bestaand encrypted-cast model) |
| `app/Http/Controllers/Api/V1/PingController.php` | controller | request-response | `routes/web.php` `/up` closure | partial (geen bestaande controller-class met body) |
| `app/Console/Commands/HubConsumerCreate.php` | console-command | CLI batch | `routes/console.php` (`inspire`) | weak (alleen closure-command bestaat) |
| `app/Sanctum/TokenAbilities.php` | utility | constants | — | geen analog |
| `bootstrap/app.php` (extend) | config | request-response | `bootstrap/app.php` (huidige `withRouting` + middleware-append) | exact |
| `config/auth.php` (extend) | config | — | `config/auth.php` (huidige `guards`/`providers`-blok) | exact |
| `database/migrations/2026_*_create_consumers_table.php` | migration | DDL | `database/migrations/0001_01_01_000000_create_users_table.php` | exact |
| `database/migrations/2026_*_create_accounts_table.php` | migration | DDL | `database/migrations/0001_01_01_000000_create_users_table.php` | exact |
| `database/migrations/2026_*_create_connections_table.php` | migration | DDL + encrypted | `0001_01_01_000000_create_users_table.php` + `2026_05_13_223626_create_personal_access_tokens_table.php` | role-match |
| `database/factories/ConsumerFactory.php` | factory | test-data | `database/factories/UserFactory.php` | exact |
| `database/factories/AccountFactory.php` | factory | test-data | `database/factories/UserFactory.php` | role-match |
| `database/factories/ConnectionFactory.php` | factory | test-data + states | `database/factories/UserFactory.php` (state-method `unverified()`) | role-match |
| `database/seeders/DatabaseSeeder.php` (extend) | seeder | bulk-insert | `database/seeders/DatabaseSeeder.php` (huidige) | exact |
| `routes/api.php` (new) | route | request-response | `routes/web.php` | role-match |
| `tests/Feature/Api/PingTest.php` | test | request-response | `tests/Feature/NoIndexHeaderTest.php` | exact |
| `tests/Feature/Api/SanctumAbilityTest.php` | test | request-response | `tests/Feature/NoIndexHeaderTest.php` | role-match |
| `tests/Feature/ConnectionEncryptionTest.php` | test | DB-assertion | `tests/Feature/NoIndexHeaderTest.php` | partial (geen bestaand DB-assertion-test) |

## Pattern Assignments

### `app/Models/Consumer.php` (model, CRUD + Authenticatable + HasApiTokens)

**Analog:** `app/Models/User.php` — enige bestaande Eloquent-model, gebruikt PHP 8.4 attributes (`#[Fillable]`, `#[Hidden]`), `casts()`-methode i.p.v. property, en `extends Authenticatable`. Consumer is óók `Authenticatable` want Sanctum's `auth:sanctum`-guard verwacht het.

**Imports + class-skeleton** (`app/Models/User.php:1-19`):
```php
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;
```

**Copy-pattern voor `Consumer`:**
- Use `#[Fillable(['name', 'slug'])]` (PHP 8.4 attribute-style, niet `protected $fillable = []`)
- Use `#[Hidden([...])]` indien nodig (Consumer heeft geen secrets — overslaan)
- `extends Authenticatable` (zelfde import)
- `use HasFactory, Notifiable;` — plus toevoegen: `Laravel\Sanctum\HasApiTokens` (trait via `use HasApiTokens;`)
- `/** @use HasFactory<ConsumerFactory> */` PHPDoc above use-statement

**Casts-pattern** (`app/Models/User.php:20-31`):
```php
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
```

**Copy-pattern voor `Consumer`:** `casts()`-methode, niet `$casts`-property. Voor Consumer: `return []` of weglaten (geen casts nodig). Voor `Account`: idem leeg. Voor `Connection`: dit is dé plek waar `'encrypted'` + `'array'` + `'datetime'` casts landen — zie hieronder.

**Sanctum HasApiTokens-trait** (`vendor/laravel/sanctum/src/HasApiTokens.php:11-72`):
```php
trait HasApiTokens
{
    public function tokens()
    {
        return $this->morphMany(Sanctum::$personalAccessTokenModel, 'tokenable');
    }

    public function tokenCan(string $ability)
    {
        return $this->accessToken && $this->accessToken->can($ability);
    }

    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null)
    {
        $plainTextToken = $this->generateTokenString();

        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }
```

**Copy-pattern:** `use Laravel\Sanctum\HasApiTokens;` toevoegen in trait-list — alle `createToken`/`tokens`/`tokenCan` zijn dan beschikbaar zonder eigen wiring. Geen `$guard_name`-property nodig: Sanctum gebruikt de `sanctum`-guard automatisch via `auth:sanctum`-middleware.

---

### `app/Models/Account.php` (model, CRUD)

**Analog:** `app/Models/User.php` — zelfde class-shape, maar zonder Authenticatable.

**Copy-pattern:**
```php
<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['consumer_id', 'external_id', 'display_name'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }
}
```

**Belangrijk:** `extends Model` (niet Authenticatable). Geen `casts()` nodig (alleen string-velden). Relations met expliciete return-type hints (`BelongsTo`, `HasMany`, `HasOne`) — PHP-rule uit `.ai/rules/php`: "Use explicit return type declarations and type hints for all method parameters".

---

### `app/Models/Connection.php` (model, CRUD + encryption)

**Analog:** `app/Models/User.php` voor class-skeleton + casts-method-style. Encryption-cast bestaat nog niet in deze repo — pattern komt van Laravel docs / Eloquent built-in casts.

**Imports + attributes:**
```php
<?php

namespace App\Models;

use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_id', 'provider', 'status',
    'access_token', 'refresh_token', 'expires_at', 'scopes',
    'client_key', 'subscription_key', 'subscription_id',
    'metadata', 'revoked_at',
])]
#[Hidden(['access_token', 'refresh_token', 'client_key', 'subscription_key'])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;
```

**Casts-methode (kopiëer naar Connection — kritisch voor encryption):**
```php
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'client_key' => 'encrypted',
            'subscription_key' => 'encrypted',
            'scopes' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
```

**Rationale:** `User.php:25-31` toont dat deze codebase de `casts()`-methode-vorm gebruikt (PHP 8 attribute-conventie van Laravel 13.9), niet de oude `protected $casts = []` property. Houd dat patroon aan.

**Fingerprint accessor** (komt uit Snelstart-SDK `vendor/emeq/snelstart-api/src/Data/SnelstartCredentials.php:55-58`):
```php
public function fingerprint(): string
{
    return hash('sha256', $this->clientKey);
}
```

**Copy-pattern voor Connection** (Hub-variant retourneert `?string` en kort de hash af op 12 chars, zie CONTEXT.md "Fingerprint accessor pattern"):
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

**Snelstart-credential-shape** (`vendor/emeq/snelstart-api/src/Data/SnelstartCredentials.php:23-37`):
```php
final readonly class SnelstartCredentials
{
    public function __construct(
        public string $clientKey,
        public string $subscriptionKey,
        public ?string $subscriptionId = null,
    ) {
        if ('' === mb_trim($this->clientKey)) {
            throw new InvalidArgumentException('SnelstartCredentials: clientKey may not be empty.');
        }

        if ('' === mb_trim($this->subscriptionKey)) {
            throw new InvalidArgumentException('SnelstartCredentials: subscriptionKey may not be empty.');
        }
    }
```

**Belangrijk voor `Connection`-kolommen:** drie velden `client_key` + `subscription_key` + `subscription_id` matchen exact de SDK-DTO (Phase 5b's `HubSnelstartCredentialResolver` bouwt straks deze DTO uit een `Connection`-row). Geen rename, geen abbreviation.

---

### `app/Http/Controllers/Api/V1/PingController.php` (controller, request-response)

**Analog:** Bestaat (nog) geen controller met body in deze repo (`app/Http/Controllers/Controller.php:1-8` is een lege abstract base). Dichtsbijzijnde request-response-handler-pattern: de closure in `routes/web.php`.

**Reference-closure** (`routes/web.php:5-9`):
```php
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'version' => '0.1.0-dev',
    'status' => 'ok',
]));
```

**Copy-pattern voor `PingController` (single-action `__invoke`, retourneert JSON-array — Laravel cast't automatisch):**
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PingController extends Controller
{
    public function __invoke(Request $request): array
    {
        $consumer = $request->user();

        return [
            'pong' => true,
            'consumer' => $consumer->slug,
            'abilities' => $consumer->currentAccessToken()->abilities ?? [],
        ];
    }
}
```

**Belangrijk:** `extends App\Http\Controllers\Controller` (de lege abstract base bestaat al, `app/Http/Controllers/Controller.php:5`). Single-action `__invoke` voor één-route-controller is consistent met de bestaande `routes/web.php` closure-stijl (één expressive handler per route). Geen Eloquent API Resource nodig voor `/ping` — gewoon array → JSON.

---

### `app/Console/Commands/HubConsumerCreate.php` (console-command, CLI batch)

**Analog:** Alleen `routes/console.php:6-8` (`inspire`-closure-command) bestaat — geen `app/Console/Commands/`-directory. De directory zelf moet worden aangemaakt.

**Reference (closure-style):**
```php
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
```

**Copy-pattern voor `HubConsumerCreate` (class-based command via `php artisan make:command HubConsumerCreate --no-interaction`):**
```php
<?php

namespace App\Console\Commands;

use App\Models\Consumer;
use Illuminate\Console\Command;

class HubConsumerCreate extends Command
{
    protected $signature = 'hub:consumer:create
                            {--slug= : Unieke slug (kebab-case)}
                            {--name= : Vrije weergave-naam}
                            {--abilities=* : CSV of meermaals: snelstart:read,mollie:write}
                            {--token-name=cli-default : Naam van het PAT-record}';

    protected $description = 'Maak een Consumer + Personal Access Token aan vanaf CLI';

    public function handle(): int
    {
        $slug = (string) $this->option('slug');
        $name = (string) $this->option('name');

        if ('' === $slug || '' === $name) {
            $this->error('--slug en --name zijn verplicht.');

            return self::INVALID;
        }

        $consumer = Consumer::create(['slug' => $slug, 'name' => $name]);

        $abilities = $this->resolveAbilities();
        $token = $consumer->createToken((string) $this->option('token-name'), $abilities);

        $this->info("Consumer created: id={$consumer->id}, slug={$consumer->slug}");
        $this->info("Token name: {$this->option('token-name')}");
        $this->warn("Plain-text token (toon eenmalig): {$token->plainTextToken}");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function resolveAbilities(): array
    {
        $raw = (array) $this->option('abilities');

        if ([] === $raw) {
            return ['*'];
        }

        return collect($raw)
            ->flatMap(fn (string $item) => explode(',', $item))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }
}
```

**Belangrijk volgens `.ai/rules/global.md` (Nederlands voor user-facing text, Engels voor identifiers):** `$description` en error/info-messages in Nederlands, code en option-names in Engels. Geen rauw token in logs — alleen interactieve CLI-output (`$this->warn(...)`). Snelstart-SDK `SnelstartCredentials::fingerprint()` is referentie voor hoe nooit raw tokens te tonen elders.

---

### `app/Sanctum/TokenAbilities.php` (utility, constants)

**Analog:** Geen bestaande constants-class in repo. Nieuw namespace `App\Sanctum`.

**Copy-pattern (final class met `const` — geen instances):**
```php
<?php

namespace App\Sanctum;

final class TokenAbilities
{
    public const SNELSTART_READ = 'snelstart:read';
    public const SNELSTART_WRITE = 'snelstart:write';
    public const MOLLIE_READ = 'mollie:read';
    public const MOLLIE_WRITE = 'mollie:write';
    public const CONSUMER_MANAGE_ACCOUNTS = 'consumer:manage-accounts';
    public const ADMIN = '*';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SNELSTART_READ,
            self::SNELSTART_WRITE,
            self::MOLLIE_READ,
            self::MOLLIE_WRITE,
            self::CONSUMER_MANAGE_ACCOUNTS,
            self::ADMIN,
        ];
    }
}
```

**Rationale:** `.ai/rules/php` zegt "TitleCase for Enum keys" — maar dit is geen `enum`, omdat Sanctum-abilities ruwe strings vergelijkt (`tokenCan('snelstart:read')`). PHP-string-enums werken óók, alternatief is `enum TokenAbility: string` — beslissing aan planner/Claude's Discretion in CONTEXT.md. Constants-class is simpelst en matched de bestaande conventie (geen enums in repo nog).

---

### `bootstrap/app.php` (config, request-response — uitbreiden)

**Analog (current):** zichzelf (`bootstrap/app.php:1-19`).

**Huidige inhoud:**
```php
<?php

use App\Http\Middleware\SetNoIndexHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SetNoIndexHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

**Copy-pattern voor uitbreiding:** voeg `api: __DIR__.'/../routes/api.php'` + `apiPrefix: 'v1'` toe in `withRouting()`. `SetNoIndexHeaders::append()` blijft staan. Sanctum's stateful-middleware niet toevoegen — we gebruiken alleen Bearer-PAT, geen SPA-cookies (rationale uit CONTEXT.md "auth:sanctum-middleware voor de meeste `/v1/*`-routes").

**Voorgesteld diff:**
```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',     // ← toevoegen
    apiPrefix: 'v1',                        // ← toevoegen
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

Geen middleware-changes nodig: `routes/api.php` krijgt automatisch de `api`-group met `throttle:api` (Laravel 11+ default), en `auth:sanctum` plakken we per route in `routes/api.php` zelf.

---

### `config/auth.php` (config — uitbreiden)

**Analog (current):** zichzelf (`config/auth.php:40-74`).

**Huidige guards + providers:**
```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => env('AUTH_MODEL', User::class),
    ],
],
```

**Copy-pattern voor uitbreiding:**
```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'sanctum' => [
        'driver' => 'sanctum',
        'provider' => 'consumers',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => env('AUTH_MODEL', User::class),
    ],
    'consumers' => [
        'driver' => 'eloquent',
        'model' => App\Models\Consumer::class,
    ],
],
```

**Belangrijk:** `use App\Models\Consumer;` toevoegen aan de top van `config/auth.php` (line 3, naast `use App\Models\User;`), of inline `App\Models\Consumer::class` schrijven. `User` blijft (niet verwijderen) — die hoort bij Filament-admin in Phase 9 per CONTEXT.md "Claude's Discretion".

---

### `database/migrations/*_create_consumers_table.php` (migration, DDL)

**Analog:** `database/migrations/0001_01_01_000000_create_users_table.php` (lines 1-49).

**Class-skeleton + up()** (`database/migrations/0001_01_01_000000_create_users_table.php:1-22`):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
```

**Copy-pattern voor `consumers`:**
```php
public function up(): void
{
    Schema::create('consumers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('consumers');
}
```

**Conventie:** anonymous-class-return-pattern (`return new class extends Migration { ... }`) — niet de oudere `class CreateConsumersTable`-stijl. `down()` mag bestaan voor `migrate:fresh` in dev/test, conform CONTEXT.md "Migration-policy".

---

### `database/migrations/*_create_accounts_table.php` (migration, DDL)

**Analog:** zelfde als consumers — `0001_01_01_000000_create_users_table.php` voor anonymous-class-shape, plus `2026_05_13_223626_create_personal_access_tokens_table.php:14-23` voor `morphs()`-vs-`foreignId`-pattern.

**Reference voor foreign-key in personal_access_tokens** (`database/migrations/2026_05_13_223626_create_personal_access_tokens_table.php:14-23`):
```php
Schema::create('personal_access_tokens', function (Blueprint $table) {
    $table->id();
    $table->morphs('tokenable');
    $table->text('name');
    $table->string('token', 64)->unique();
    $table->text('abilities')->nullable();
    $table->timestamp('last_used_at')->nullable();
    $table->timestamp('expires_at')->nullable()->index();
    $table->timestamps();
});
```

**Copy-pattern voor `accounts`:**
```php
public function up(): void
{
    Schema::create('accounts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('consumer_id')
            ->constrained('consumers')
            ->cascadeOnDelete();
        $table->string('external_id');
        $table->string('display_name')->nullable();
        $table->timestamps();

        $table->unique(['consumer_id', 'external_id']);
        $table->index('consumer_id');
    });
}

public function down(): void
{
    Schema::dropIfExists('accounts');
}
```

---

### `database/migrations/*_create_connections_table.php` (migration, DDL + encrypted text)

**Analog:** consumers-/accounts-migration voor shape, geen bestaand voorbeeld van `text()->nullable()`-velden voor encrypted-payload, maar de pattern is plain `$table->text('col')->nullable()` (encryptie gebeurt op model-laag via `'encrypted'`-cast — niet op DB-laag).

**Copy-pattern:**
```php
public function up(): void
{
    Schema::create('connections', function (Blueprint $table) {
        $table->id();
        $table->foreignId('account_id')
            ->constrained('accounts')
            ->cascadeOnDelete();
        $table->string('provider');
        $table->string('status')->default('active');

        // OAuth-shape (Mollie, future Exact/Ibanity)
        $table->text('access_token')->nullable();
        $table->text('refresh_token')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->json('scopes')->nullable();

        // Key-based-shape (Snelstart)
        $table->text('client_key')->nullable();
        $table->text('subscription_key')->nullable();
        $table->string('subscription_id')->nullable();

        // Provider-specifieke overflow
        $table->json('metadata')->nullable();

        // Audit
        $table->timestamp('revoked_at')->nullable();
        $table->timestamps();

        $table->index(['account_id', 'provider']);
    });
}

public function down(): void
{
    Schema::dropIfExists('connections');
}
```

**Belangrijk:** `text()` (niet `string()`) voor encrypted velden — Laravel's `encrypted`-cast produceert een base64-payload die forever > 255 chars wordt. `subscription_id` is `string()` want geen secret (CONTEXT.md "Claude's Discretion").

---

### `database/factories/ConsumerFactory.php` (factory, test-data)

**Analog:** `database/factories/UserFactory.php` (lines 1-45).

**Imports + class-skeleton + definition()** (`database/factories/UserFactory.php:1-34`):
```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
```

**Copy-pattern voor `ConsumerFactory`:**
```php
<?php

namespace Database\Factories;

use App\Models\Consumer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Consumer>
 */
class ConsumerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
        ];
    }
}
```

**Belangrijk:** `fake()`-helper (niet `$this->faker`) — `UserFactory.php:28-32` gebruikt `fake()`, dus dat is de project-conventie. PHPDoc `/** @extends Factory<Consumer> */`. Geen state-methodes nodig — kunnen toegevoegd worden ad-hoc.

---

### `database/factories/AccountFactory.php` (factory, test-data + relations)

**Analog:** `database/factories/UserFactory.php` voor shape. Geen bestaande factory met `belongsTo`-FK-resolution, maar standard Laravel-pattern is `Consumer::factory()` als FK-value.

**Copy-pattern:**
```php
<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Consumer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consumer_id' => Consumer::factory(),
            'external_id' => 'ext-'.fake()->unique()->numerify('######'),
            'display_name' => fake()->company(),
        ];
    }
}
```

---

### `database/factories/ConnectionFactory.php` (factory, test-data + states)

**Analog:** `database/factories/UserFactory.php:36-44` — state-method `unverified()`-pattern.

**Reference state-pattern** (`database/factories/UserFactory.php:36-44`):
```php
/**
 * Indicate that the model's email address should be unverified.
 */
public function unverified(): static
{
    return $this->state(fn (array $attributes) => [
        'email_verified_at' => null,
    ]);
}
```

**Copy-pattern voor `ConnectionFactory` met `forSnelstart()` + `forMollie()`-states:**
```php
<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'provider' => 'snelstart',
            'status' => 'active',
        ];
    }

    public function forSnelstart(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'snelstart',
            'client_key' => 'CK-'.Str::random(40),
            'subscription_key' => 'SK-'.Str::random(40),
            'subscription_id' => (string) Str::uuid(),
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'scopes' => null,
        ]);
    }

    public function forMollie(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'mollie',
            'access_token' => 'access_'.Str::random(40),
            'refresh_token' => 'refresh_'.Str::random(40),
            'expires_at' => now()->addHour(),
            'scopes' => ['payments.read', 'payments.write'],
            'client_key' => null,
            'subscription_key' => null,
            'subscription_id' => null,
        ]);
    }
}
```

**Belangrijk:** state-methodes returnen `static` (niet `self` of `$this`), conform `UserFactory::unverified():static`. State-callback signature is `fn (array $attributes) => [...]`, exact zoals het bestaande analog.

---

### `database/seeders/DatabaseSeeder.php` (seeder, bulk-insert — uitbreiden)

**Analog (current):** zichzelf (`database/seeders/DatabaseSeeder.php:1-25`).

**Huidige inhoud:**
```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
```

**Copy-pattern voor uitbreiding (Consumer + Account; production-guard):**
```php
public function run(): void
{
    if (app()->isProduction()) {
        return;
    }

    User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    $consumer = Consumer::firstOrCreate(
        ['slug' => 'naschool'],
        ['name' => 'Naschool'],
    );

    $consumer->accounts()->firstOrCreate(
        ['external_id' => 'school1'],
        ['display_name' => 'Demo School 1'],
    );
}
```

**Imports toevoegen** (top van file, naast `use App\Models\User;`):
```php
use App\Models\Consumer;
```

**Belangrijk:** `use WithoutModelEvents;` blijft. `app()->isProduction()` als early-return — conform CONTEXT.md "Niet seeden in production".

---

### `routes/api.php` (route, request-response — nieuw)

**Analog:** `routes/web.php:1-17` voor route-style en `routes/console.php:6-8` voor closure-naming-style.

**Reference** (`routes/web.php:1-9`):
```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'version' => '0.1.0-dev',
    'status' => 'ok',
]));
```

**Copy-pattern voor `routes/api.php`:**
```php
<?php

use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/ping', PingController::class)->name('api.ping');
});
```

**Belangrijk:** geen `prefix('v1')` nodig — `bootstrap/app.php` regelt `apiPrefix: 'v1'` voor de hele file. Controller-FQN-import + invokable-route (`PingController::class`) is leesbaarder dan `[PingController::class, '__invoke']`.

---

### `tests/Feature/Api/PingTest.php` (test, request-response)

**Analog:** `tests/Feature/NoIndexHeaderTest.php` (lines 1-31) — enige feature-test met HTTP-assertions.

**Imports + class-skeleton + test-method** (`tests/Feature/NoIndexHeaderTest.php:1-13`):
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class NoIndexHeaderTest extends TestCase
{
    public function test_up_endpoint_has_x_robots_tag_header(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }
```

**Copy-pattern voor `PingTest`:**
```php
<?php

namespace Tests\Feature\Api;

use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_consumer_receives_pong(): void
    {
        $consumer = Consumer::factory()->create(['slug' => 'naschool']);
        $token = $consumer->createToken('test', ['snelstart:read'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/ping')
            ->assertOk()
            ->assertJson([
                'pong' => true,
                'consumer' => 'naschool',
            ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/v1/ping')->assertUnauthorized();
    }
}
```

**Belangrijk:**
- `namespace Tests\Feature\Api` — sub-namespace voor api-tests, conform Laravel-conventie en PSR-4-resolution (PHPUnit pickt automatisch op via `phpunit.xml` `<directory>tests/Feature</directory>`).
- `use RefreshDatabase;` — nodig want we maken Consumer-records. `phpunit.xml` heeft `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:`, dus `RefreshDatabase` is goedkoop.
- Test-methods naming-convention: `test_<scenario>_<expected>` (snake_case na `test_`), conform `NoIndexHeaderTest::test_up_endpoint_has_x_robots_tag_header`.
- PHPUnit (niet Pest) — conform CLAUDE.md `phpunit/core` rules.
- Test command na change: `php artisan test --compact --filter=PingTest` (conform CLAUDE.md `phpunit/core` rules).

---

### `tests/Feature/Api/SanctumAbilityTest.php` (test, request-response)

**Analog:** `tests/Feature/NoIndexHeaderTest.php` voor shape; `PingTest` (zelf nieuw) voor Sanctum-token-fixture.

**Copy-pattern:**
```php
<?php

namespace Tests\Feature\Api;

use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanctumAbilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_without_required_ability_is_rejected(): void
    {
        // TODO Phase 5b: dit faalt nu nog niet want /v1/ping eist geen specifieke
        // ability. Test wordt scherper zodra een /v1/snelstart-route met
        // ->middleware('ability:snelstart:read') wordt toegevoegd.
        $this->markTestIncomplete('Wacht op /v1/snelstart/* in Phase 5b');
    }

    public function test_admin_wildcard_ability_grants_access_to_any_route(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('admin', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/ping')
            ->assertOk();
    }
}
```

**Belangrijk:** Phase 3 levert nog geen route met ability-check (alleen `auth:sanctum`), dus de "geweigerd zonder juiste ability"-test is structureel een `markTestIncomplete()` of een placeholder die in Phase 5b scherper wordt. Test bestaat al wel voor harness-completeness.

---

### `tests/Feature/ConnectionEncryptionTest.php` (test, DB-assertion)

**Analog:** `tests/Feature/NoIndexHeaderTest.php` voor class-shape. Geen bestaand DB-assertion-pattern — Laravel-standard pattern is `DB::table(...)->value(...)`.

**Copy-pattern:**
```php
<?php

namespace Tests\Feature;

use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectionEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_key_is_encrypted_at_rest(): void
    {
        $connection = Connection::factory()
            ->forSnelstart()
            ->create(['client_key' => 'CK-secret-123']);

        $rawAtRest = DB::table('connections')
            ->where('id', $connection->id)
            ->value('client_key');

        $this->assertNotSame('CK-secret-123', $rawAtRest);
        $this->assertSame('CK-secret-123', $connection->fresh()->client_key);
    }

    public function test_to_array_hides_credential_fields(): void
    {
        $connection = Connection::factory()->forSnelstart()->create();

        $array = $connection->toArray();

        $this->assertArrayNotHasKey('client_key', $array);
        $this->assertArrayNotHasKey('subscription_key', $array);
        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
    }

    public function test_fingerprint_returns_truncated_sha256_for_snelstart(): void
    {
        $connection = Connection::factory()
            ->forSnelstart()
            ->create(['client_key' => 'CK-secret-123']);

        $expected = substr(hash('sha256', 'CK-secret-123'), 0, 12);

        $this->assertSame($expected, $connection->fingerprint());
    }
}
```

**Belangrijk:** `assertNotSame`/`assertSame` (PHPUnit) — niet `expect()->toBe()` (dat is Pest, en deze repo gebruikt PHPUnit per `phpunit/core` rules). DB-bypass via `DB::table(...)` om Eloquent-decryptie te omzeilen — toont rauwe DB-waarde.

---

## Shared Patterns

### PHP 8 / Laravel 13 model-attributes (alle Models)
**Source:** `app/Models/User.php:13-14`
**Apply to:** `Consumer`, `Account`, `Connection`
```php
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
```

Gebruik `#[Fillable]` en `#[Hidden]` attribute-syntax (niet `protected $fillable = []`). Imports:
```php
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
```

### Casts via `casts()`-methode (alle Models met casts)
**Source:** `app/Models/User.php:25-31`
**Apply to:** `Connection` (kritisch — encrypted-casts)
```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
}
```

Methode-form (`protected function casts(): array`), niet property-form. Laravel 11+/13 conventie.

### Factory PHPDoc + `fake()`-helper (alle Factories)
**Source:** `database/factories/UserFactory.php:10-12, 25-33`
**Apply to:** `ConsumerFactory`, `AccountFactory`, `ConnectionFactory`
```php
/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
```

PHPDoc `@extends Factory<Model>` voor IDE/static-analysis. `fake()`-globale helper (niet `$this->faker`). State-methodes returnen `static`.

### Migration anonymous-class-pattern (alle Migrations)
**Source:** `database/migrations/0001_01_01_000000_create_users_table.php:7-22`
**Apply to:** `*_create_consumers_table`, `*_create_accounts_table`, `*_create_connections_table`
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

Anonymous-class-return (niet `class CreateXTable`). `up(): void` + `down(): void`. Schema-closure neemt `Blueprint $table`.

### PHPUnit-feature-test-skeleton (alle Tests)
**Source:** `tests/Feature/NoIndexHeaderTest.php:1-13`
**Apply to:** `Api/PingTest`, `Api/SanctumAbilityTest`, `ConnectionEncryptionTest`
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class NoIndexHeaderTest extends TestCase
{
    public function test_up_endpoint_has_x_robots_tag_header(): void
    {
        $this->get('/up')
            ->assertOk();
    }
}
```

- `extends TestCase` (PHPUnit, niet Pest)
- Test-namen `test_<scenario>_<expected>` snake_case
- `RefreshDatabase` trait toevoegen voor tests die DB raken
- Test-commands: `php artisan test --compact --filter=<TestClass>` (CLAUDE.md `phpunit/core`)

### Security: tokens encrypted at rest + fingerprint-only (Connection-model + HubConsumerCreate)
**Source:** `vendor/emeq/snelstart-api/src/Data/SnelstartCredentials.php:55-58` (fingerprint pattern) + CONTEXT.md "Fingerprint accessor pattern"
**Apply to:** `Connection` (encrypted casts + `fingerprint()`-accessor + `#[Hidden]`), `HubConsumerCreate` (raw token alleen één keer in CLI-output)

```php
public function fingerprint(): string
{
    return hash('sha256', $this->clientKey);
}
```

Encrypted-cast op DB-laag (4 velden), `#[Hidden]` op API-laag (zelfde 4 velden), `fingerprint()`-accessor voor logs/audit. Geen raw secrets in logs, exceptions, of error responses. CLI-command toont plain-token éénmalig via `$this->warn()` — niet via `Log::info()`.

### Taal-conventie (alle nieuwe files)
**Source:** `.ai/rules/global.md` + huidige codebase
**Apply to:** alle code en docs
- Code, identifiers, type-hints, classes: **Engels** (`PingController`, `forSnelstart`, `client_key`)
- User-facing CLI-output, descriptions, errors: **Nederlands** (`$this->error('--slug is verplicht')`)
- Snelstart-domeintermen blijven Nederlands (Relaties, Verkoopfacturen — niet relevant in Phase 3)

---

## No Analog Found

Files waarvoor geen close match bestaat in deze repo — planner moet RESEARCH.md / vendor-code raadplegen, of de patterns hierboven combineren.

| File | Role | Data Flow | Reden / Alternatieve bron |
|---|---|---|---|
| `app/Sanctum/TokenAbilities.php` | utility (constants) | — | Geen utility-class in `app/`. Pattern is plain `final class` met `public const`. Geen analoge file in repo. |
| `app/Console/Commands/HubConsumerCreate.php` | console-command | CLI batch | Directory `app/Console/Commands/` bestaat niet. Closure-command in `routes/console.php` is enige analog (`inspire`). Gebruik `php artisan make:command HubConsumerCreate --no-interaction` als baseline. |
| `app/Http/Controllers/Api/V1/PingController.php` | controller | request-response | `app/Http/Controllers/Controller.php` is een lege abstract base — geen controller-met-body. Closest is `routes/web.php` closure. Gebruik single-action `__invoke`-pattern. |
| `bootstrap/app.php` (Sanctum-API-wiring) | config | request-response | Huidige `bootstrap/app.php` heeft alleen `web` + `commands` + `health` in `withRouting()` — geen `api`-key. Laravel 11+ docs: voeg `api: __DIR__.'/../routes/api.php'` toe. |
| `tests/Feature/ConnectionEncryptionTest.php` (DB-assertion) | test | DB-assertion | `tests/Feature/NoIndexHeaderTest.php` is enige feature-test, doet HTTP-assertion (geen DB). DB-bypass-pattern via `DB::table()->value()` is Laravel-standard maar bestaat niet in repo. |

---

## Metadata

**Analog search scope:** `app/`, `bootstrap/`, `config/`, `database/`, `routes/`, `tests/`, `vendor/emeq/snelstart-api/src/`, `vendor/laravel/sanctum/src/`
**Files scanned:** 19 (vs. 19 target-files)
**Files in repo at time of mapping:** 1 model (`User`), 1 controller-base (`Controller`), 1 middleware (`SetNoIndexHeaders`), 1 factory (`UserFactory`), 1 seeder (`DatabaseSeeder`), 6 migrations, 2 feature-tests, 1 unit-test, 1 service-provider (`AppServiceProvider` met Scramble), 2 routes-files (`web.php`, `console.php`)
**Pattern extraction date:** 2026-05-14
**Repo state checked against:** branch `chore/v02-roadmap-split-and-scramble` @ HEAD `ff524b7`
