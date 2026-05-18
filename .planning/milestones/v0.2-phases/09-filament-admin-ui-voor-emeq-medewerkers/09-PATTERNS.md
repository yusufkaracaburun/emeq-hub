---
phase: 9
slug: filament-admin-ui-voor-emeq-medewerkers
mapped: 2026-05-15
files_analyzed: 18
analogs_found: 16
---

# Phase 9: Filament admin-UI — Pattern Map

> Concrete analog-references per nieuw/gewijzigd bestand. PLAN.md `<read_first>`
> verwijzingen kunnen rechtstreeks naar deze regelnummers wijzen.
> Filament-resources hebben **geen** bestaande analog in deze codebase
> (Filament komt voor het eerst de stack binnen via Plan 09-02). Voor
> resource-class shape moet de planner naar Filament v4-docs verwijzen via
> `mcp__plugin_context7_context7__query-docs`. Wat wél analog mapt is alles
> rond de resources: Eloquent-model conventies, `#[Fillable]`/`#[Hidden]`
> attributen, encrypted casts, factories, migratie-stijl, config-array shapes,
> seeder-pattern, test-conventies (Bearer-flow + cross-Consumer-isolation +
> no-secret-leak), provider-switching in `Connection::fingerprint()`,
> `TokenAbilities`-discovery-contract en de OAuthFlow-registry.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Filament/Resources/ConsumerResource.php` | filament-resource (CRUD) | request-response + form | `app/Models/Consumer.php` (model-shape) + `app/Console/Commands/HubConsumerCreate.php` (PAT-issue-flow) | role-mismatch (no Filament yet) — copy model attrs + PAT-flow logic |
| `app/Filament/Resources/ConnectionResource.php` | filament-resource (read + custom action) | request-response | `app/Models/Connection.php` + `app/OAuth/Contracts/OAuthFlow.php` | role-mismatch — copy fingerprint-accessor + revoke-contract usage |
| `app/Filament/Resources/AccountResource.php` | filament-resource (read-only) | request-response | `app/Models/Account.php` | role-mismatch — read-only mapt 1:1 op model |
| `app/Filament/Resources/WebhookCallResource.php` | filament-resource (read-only viewer) | request-response | `app/Models/PassThroughCall.php` (vergelijkbare audit-shape) | role-mismatch — copy column-shape post 09-01 migratie |
| `app/Filament/Resources/AccountSubscriptionResource.php` | filament-resource (read + state-flip action) | request-response + state-machine | `app/Models/AccountSubscription.php` + `app/Billing/Account/AccountSubscriptionManager.php` (action-target) | role-mismatch — manager is single-entry-point voor state-flips |
| `app/Filament/Resources/CashierSubscriptionResource.php` | filament-resource (read-only, derived status) | request-response | `app/Models/Consumer.php` (Billable trait) + `database/factories/ConsumerFactory.php::withActiveSubscription` | partial — Cashier-vendor model, derived state |
| `app/Filament/Resources/UserResource.php` | filament-resource (gated, super-admin only) | request-response | `app/Models/User.php` + gate-pattern uit `app/Providers/AppServiceProvider.php::boot()` | role-mismatch — copy Gate::define-pattern |
| `database/migrations/2026_xx_xx_add_audit_columns_to_webhook_calls_table.php` | migration (add columns) | DDL | `database/migrations/2026_05_17_000002_add_inbound_columns_to_pass_through_calls_table.php` | **exact** |
| `app/Support/ProviderCredentialDescriptor.php` | value-object (final class + static discovery) | utility | `app/Sanctum/TokenAbilities.php` (final class + `::all()`) | **exact pattern match** |
| `config/hub-providers.php` | config (array map) | config-load | `config/billing-plans.php` (slug-keyed associative array) | **exact** |
| `app/Models/User.php` | model (add trait + interface) | identity | bestaande `app/Models/User.php` + `app/Models/Consumer.php` (trait-stacking voorbeeld: `Billable, HasApiTokens, HasFactory`) | self + role-match |
| `database/seeders/EmeqStaffSeeder.php` | seeder (env-driven idempotent insert) | DB-setup | `database/seeders/DatabaseSeeder.php` (firstOrCreate-pattern) | role-match |
| `app/Providers/Filament/AdminPanelProvider.php` | service-provider | bootstrap | `app/Providers/AppServiceProvider.php` (register + boot pattern) | role-match (Filament-generated, niet handmatig) |
| `app/Models/Connection.php` | model (descriptor-aware fingerprint) | utility | self — bestaande `Connection::fingerprint()` lines 46-55 | self (kleine wijziging) |
| `tests/Feature/Admin/PanelAccessTest.php` | test (auth-flow) | feature | `tests/Feature/Api/V1/AccountSubscriptions/ListAccountSubscriptionsTest.php` (Bearer + RefreshDatabase) | role-match |
| `tests/Feature/Admin/ConnectionFingerprintTest.php` | test (no-secret-leak) | feature | `tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php` (kolom-scan na actie) | **exact pattern match** |
| `tests/Feature/Admin/PatAbilityPresetsTest.php` | test (discovery-contract) | feature | `tests/Feature/Api/V1/Billing/BillingAbilityGateTest.php` (uses `TokenAbilities::all()`) | **exact pattern match** |
| `tests/Feature/Admin/ProviderDescriptorTest.php` | test (config-driven discovery) | feature | `tests/Feature/Api/V1/Billing/BillingAbilityGateTest.php` + `app/Billing/PlanResolver.php` test-style | role-match |

## Pattern Assignments

### `app/Filament/Resources/ConsumerResource.php` (CRUD)

**Model-shape analog:** `app/Models/Consumer.php` lines 13-33

```php
#[Fillable(['name', 'slug', 'webhook_callback_url', 'webhook_callback_secret'])]
class Consumer extends Authenticatable
{
    use Billable, HasApiTokens, HasFactory;

    protected function casts(): array
    {
        return [
            'webhook_callback_secret' => 'encrypted',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
```

→ **Filament form-fields** mappen 1:1 op deze 4 `#[Fillable]`-velden. `webhook_callback_secret` is encrypted (cast `'encrypted'`) — moet als `TextInput::make('webhook_callback_secret')->password()->revealable(false)` en in tabel-view alléén masked/empty tonen (zelfde invariant als ConnectionResource — D-04).

**PAT-issue-action analog:** `app/Console/Commands/HubConsumerCreate.php` lines 32-36

```php
$invalid = array_values(array_diff($abilities, TokenAbilities::all()));
// ...
$this->line('Geldige abilities: '.implode(', ', TokenAbilities::all()));
```

→ **Filament `Action::make('issuePat')`** moet abilities valideren tegen `TokenAbilities::all()` voordat het `$consumer->createToken($name, $abilities)` aanroept. Plain-text token uit `->plainTextToken` (zie `BillingAbilityGateTest.php` lines 19-22 / `ListAccountSubscriptionsTest.php` line 42) wordt via `Filament\Notifications\Notification::send()` éénmalig getoond (D-03).

**Preset-discovery contract:** Zie ProviderCredentialDescriptor-pattern hieronder. Presets-array gedefinieerd als constant op `ConsumerResource`-class, met test (`PatAbilityPresetsTest`) die `TokenAbilities::all()` ⊆ `union(presets) ∪ explicit-custom-only-set` asserteert.

---

### `app/Filament/Resources/ConnectionResource.php` (read + revoke)

**Fingerprint-accessor — bestaat al:** `app/Models/Connection.php` lines 46-55

```php
public function fingerprint(): ?string
{
    $secret = match ($this->provider) {
        'snelstart' => $this->client_key,
        'mollie' => $this->access_token,
        default => null,
    };

    return $secret ? substr(hash('sha256', $secret), 0, 12) : null;
}
```

→ **Niet reinventen.** ConnectionResource-tabel kolom `fingerprint` roept gewoon `$record->fingerprint()` aan. D-04 refactor: `match`-arm wordt vervangen door descriptor-lookup (`ProviderCredentialDescriptor::for($this->provider)->primaryEncryptedField()`) — zie volgend file.

**Hidden-fields-pattern (raw token nooit gerendered):** `app/Models/Connection.php` line 30

```php
#[Hidden(['access_token', 'refresh_token', 'client_key', 'subscription_key'])]
```

→ Eloquent `toArray()` redacts deze al (zie test `ConnectionEncryptionTest::test_to_array_hides_all_credential_fields` lines 71-84). Filament leest deze velden via Eloquent en respecteert `$hidden` zolang Resource niet expliciet `->columns(['access_token'])` doet. **Feature-test `ConnectionFingerprintTest` moet asserteren dat geen plain raw waarde in HTML voorkomt** — pattern uit `tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php`.

**Revoke-action target:** `app/OAuth/Contracts/OAuthFlow.php` lines 29-32

```php
/**
 * Trek de koppeling in bij de partner én zet status='revoked' lokaal.
 */
public function revoke(Connection $connection): void;
```

→ **Filament `Action::make('revoke')`** resolvet `app(OAuthFlowRegistry::class)->for($record->provider)->revoke($record)`. Geen direct `$record->update(['revoked_at' => now()])` — laat de flow zelf de status flippen.

**Registry-resolution-pattern:** `app/OAuth/OAuthFlowRegistry.php` lines 24-33

```php
public function for(string $provider): OAuthFlow
{
    if (! isset($this->providers[$provider])) {
        throw new InvalidArgumentException(
            "Geen OAuthFlow geregistreerd voor provider '{$provider}'."
        );
    }

    return $this->container->make($this->providers[$provider]);
}
```

→ Revoke-action moet alleen visible zijn als provider OAuth gebruikt (Snelstart heeft géén OAuthFlow geregistreerd — `revoke` voor Snelstart-Connections is een puur lokale status-flip via descriptor). De `?callable $authorizeFlow` op `ProviderCredentialDescriptor` (D-04) vertelt dit.

---

### `app/Filament/Resources/AccountResource.php` (read-only)

**Model-shape analog:** `app/Models/Account.php` lines 12-32

```php
#[Fillable(['consumer_id', 'external_id', 'display_name'])]
class Account extends Model
{
    use HasFactory;

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    public function accountSubscriptions(): HasMany
    {
        return $this->hasMany(AccountSubscription::class);
    }
}
```

→ **Filament-tabel** kolommen: `consumer.slug` (via `belongsTo`), `external_id`, `display_name`, `connections_count` (via Eloquent `->withCount('connections')`), `created_at`. Filter: `consumer_id` (Select-filter met relation-options). Geen form (read-only — alle Resource-actions `->disabled()`).

---

### `app/Filament/Resources/WebhookCallResource.php` (read-only viewer)

**Pre-req: 09-01 migratie** (zie volgende). Daarná: WebhookCall-model heeft `direction`, `provider`, `consumer_id`, `status` kolommen.

**Audit-shape parallel:** `app/Models/PassThroughCall.php` — vergelijkbare audit-rij structuur (direction/provider/event_id/timestamps). Read het model voor relation-en-cast-conventies vóór WebhookCall-model wordt toegevoegd (Spatie's webhook-server levert een eigen model, mag extended worden).

**Filament-detail-view (JSON-payload):** patroon voor collapsible JSON komt uit Filament v4-docs (`TextEntry::make('payload')->json()` of `JsonEntry`). Geen codebase-analog — verifieer via `mcp__plugin_context7_context7__query-docs`.

---

### `app/Filament/Resources/AccountSubscriptionResource.php` (read + state-flip)

**Model + state-machine analog:** `app/Models/AccountSubscription.php` lines 12-66 + `app/Billing/Account/SubscriptionStatus.php`

```php
// SubscriptionStatus.php — 6 states
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Canceled = 'canceled';
    case Completed = 'completed';
    case Unknown = 'unknown';
}
```

→ **Filament-status-kolom**: `BadgeColumn::make('status')` met kleuren-map per case. Filter: `SelectFilter::make('status')->options(SubscriptionStatus::class)`. Action-target: `app/Billing/Account/AccountSubscriptionManager.php` is single-entry-point — Pause/Resume/Cancel-actions roepen `$manager->pause($sub)` / `->resume($sub)` / `->cancel($sub)` aan. NOOIT direct `$sub->update(['status' => 'paused'])` (T-07-03-03 invariant — `AccountSubscriptionManager.php` line 26).

**Hub-side cast-pattern:** lines 51-65 — `status` cast naar enum + datetime casts voor 4 timestamps + `metadata` als array. Filament reads dit automatisch.

---

### `app/Filament/Resources/CashierSubscriptionResource.php` (read-only)

**Owner-relation analog:** `app/Models/Consumer.php` line 17 (`use Billable, HasApiTokens, HasFactory`) + `database/factories/ConsumerFactory.php` lines 45-60

```php
public function withActiveSubscription(string $planSlug = 'naschool-license', string $subscriptionName = 'main'): static
{
    return $this->afterCreating(function (Consumer $consumer) use ($planSlug, $subscriptionName): void {
        DB::table('subscriptions')->insert([
            'name' => $subscriptionName,
            'plan' => $planSlug,
            'owner_id' => $consumer->id,
            'owner_type' => Consumer::class,
            // ...
        ]);
    });
}
```

→ `Cashier\Subscription`-rij heeft `owner_id` / `owner_type` morphTo richting `Consumer`. Filament Resource kolom `owner.slug` werkt via polymorphic-relation. **Derived status** komt uit Cashier's eigen methods (`->active()`, `->cancelled()`, `->ended()`, `->onTrial()`, `->onGracePeriod()`) — geen DB-kolom, dus `->state(fn ($record) => $record->active() ? 'active' : ...)`.

---

### `app/Filament/Resources/UserResource.php` (super-admin only)

**Gate-pattern analog:** `app/Providers/AppServiceProvider.php` lines 45-53

```php
Gate::define('viewApiDocs', function (?User $user): bool {
    $token = config('scramble.access_token');

    if (! $token) {
        return false;
    }

    return hash_equals($token, (string) request()->query('token', ''));
});
```

→ **Plan 09-03/09-10 pattern:**

```php
Gate::define('manage-staff', fn (User $user): bool => $user->hasRole('super-admin'));
```

UserResource: `public static function canAccess(): bool => Gate::allows('manage-staff')`. Niet alleen voor toegang — ook voor `static::shouldRegisterNavigation()` zodat de sidebar-link verdwijnt voor non-super-admins.

---

### `database/migrations/2026_xx_xx_add_audit_columns_to_webhook_calls_table.php`

**Exacte pattern-match:** `database/migrations/2026_05_17_000002_add_inbound_columns_to_pass_through_calls_table.php`

```php
return new class extends Migration
{
    public function up(): void
    {
        // 1. Nullable-make van tenant-FK
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->foreignId('consumer_id')->nullable()->change();
            $table->foreignId('account_id')->nullable()->change();
        });

        // 2. Direction + event_id toevoegen
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->string('direction', 10)->default('outbound')->after('id');
            $table->string('event_id')->nullable()->after('request_fingerprint');
            $table->index(['direction', 'created_at']);
        });

        // 3. Unique constraint
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->unique(['provider', 'event_id'], 'pass_through_calls_provider_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->dropUnique('pass_through_calls_provider_event_unique');
            $table->dropIndex(['direction', 'created_at']);
            $table->dropColumn(['direction', 'event_id']);
        });
        // consumer_id/account_id revert weg gelaten (forward-only-prod-policy).
    }
};
```

→ **Plan 09-01 copy-pattern**: meerdere `Schema::table()` blocks voor verschillende soorten DDL (cast/add/index/constraint), expliciete `->after()` voor kolom-volgorde, `down()` laat optioneel constraints staan met inline-comment over forward-only-policy. Voor `webhook_calls`-tabel: ook `name` van constraint expliciet meegeven (Postgres + SQLite portable). Bestaande tabel-create-script staat in `database/migrations/2026_05_13_223628_create_webhook_calls_table.php`.

**Note: Spatie-default-shape:** zie create-migratie van webhook_calls. Spatie schrijft via `WebhookCall::create([...])` rauwe payloads; nieuwe kolommen `direction` / `provider` / `consumer_id` / `status` moeten gevuld worden door Hub's eigen webhook-dispatcher (zie `app/Mollie/...` of `app/Snelstart/...` webhook-routes). CONTEXT.md D-02 stelt expliciet: laat bestaande rijen NULL.

---

### `app/Support/ProviderCredentialDescriptor.php` (D-04)

**Exact pattern-match:** `app/Sanctum/TokenAbilities.php`

```php
namespace App\Sanctum;

final class TokenAbilities
{
    public const SNELSTART_READ = 'snelstart:read';
    public const SNELSTART_WRITE = 'snelstart:write';
    public const MOLLIE_READ = 'mollie:read';
    public const MOLLIE_WRITE = 'mollie:write';
    public const CONSUMER_MANAGE_ACCOUNTS = 'consumer:manage-accounts';
    public const BILLING_READ = 'billing:read';
    public const BILLING_WRITE = 'billing:write';
    public const ADMIN = '*';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SNELSTART_READ,
            self::SNELSTART_WRITE,
            // ...
            self::ADMIN,
        ];
    }
}
```

→ **Plan 09-11 pattern** (twee opties — discussion-decision in 09-CONTEXT.md heeft beide open gelaten):

**Optie A — final class + `::all()`:**

```php
namespace App\Support;

final class ProviderCredentialDescriptor
{
    public function __construct(
        public readonly string $key,
        /** @var list<string> */
        public readonly array $encryptedFields,
        public readonly string $primaryFingerprintLabel,
        public readonly ?string $oauthFlowKey,  // null = no OAuthFlowRegistry-binding
    ) {}

    public static function for(string $provider): self
    {
        $cfg = config("hub-providers.{$provider}");

        if (! is_array($cfg)) {
            throw new \InvalidArgumentException("Onbekende provider: {$provider}");
        }

        return new self(
            key: $provider,
            encryptedFields: $cfg['encrypted_fields'],
            primaryFingerprintLabel: $cfg['primary_label'],
            oauthFlowKey: $cfg['oauth_flow_key'] ?? null,
        );
    }

    /** @return list<self> */
    public static function all(): array
    {
        return array_map(
            fn (string $key) => self::for($key),
            array_keys(config('hub-providers', []))
        );
    }
}
```

→ Discovery-contract = `::all()`-method (zelfde shape als `TokenAbilities::all()`). Feature-test `ProviderDescriptorTest` asserteert dat een **theoretische** "moneybird"-rij in `config/hub-providers.php` automatisch verschijnt in `ProviderCredentialDescriptor::all()` zónder Filament-code te wijzigen (D-04 success-criterium 10).

**`Connection::fingerprint()` refactor (kleine wijziging):** `app/Models/Connection.php` lines 46-55 — vervang `match`-arm door:

```php
public function fingerprint(): ?string
{
    $descriptor = ProviderCredentialDescriptor::for($this->provider);
    $primaryField = $descriptor->encryptedFields[0] ?? null;

    if (! $primaryField) {
        return null;
    }

    $secret = $this->{$primaryField};

    return $secret ? substr(hash('sha256', $secret), 0, 12) : null;
}
```

Bestaande test `ConnectionEncryptionTest::test_fingerprint_returns_truncated_sha256_for_*` (lines 86-118) **moet groen blijven** — descriptor-rewrite mag geen gedrag wijzigen. Test op `unknown provider` → null blijft geldig (descriptor.gooit dan `InvalidArgumentException`, tenzij we `tryFor()` introduceren — implementatie-keuze voor planner).

---

### `config/hub-providers.php` (D-04)

**Exact pattern-match:** `config/billing-plans.php`

```php
return [
    'naschool-license' => [
        'amount' => [
            'value' => '0.00',
            'currency' => 'EUR',
        ],
        'interval' => '1 month',
        'description' => 'Naschool SaaS license — Emeq Hub access',
    ],
    'planny-license' => [
        // ...
    ],
];
```

→ **Plan 09-11 config-shape:**

```php
<?php

declare(strict_types=1);

/*
 * D-04: ProviderCredentialDescriptor declarations. Eén rij per provider.
 * Filament's ConnectionResource leest dit voor per-provider conditional
 * form-sections, fingerprint-resolution en revoke-action visibility.
 */
return [
    'mollie' => [
        'encrypted_fields' => ['access_token', 'refresh_token'],
        'primary_label' => 'OAuth token',
        'oauth_flow_key' => 'mollie',  // matches OAuthFlowRegistry::register('mollie', ...)
    ],
    'snelstart' => [
        'encrypted_fields' => ['client_key', 'subscription_key'],
        'primary_label' => 'Client key',
        'oauth_flow_key' => null,  // Snelstart heeft géén OAuth-flow
    ],
];
```

Empty allowlist / unknown-provider invariant zoals `config/billing.php` lines 11-15 (default-deny). Comment-style: top-of-file `/*` block met decision-tag (`D-04`) — bestaande conventie.

---

### `app/Models/User.php` (D-05 — modificatie)

**Self + trait-stack analog:** bestaand bestand + `app/Models/Consumer.php` line 17 (multi-trait stacking)

```php
// HUIDIG
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected function casts(): array { ... }
}

// VERWACHTE WIJZIGING (D-05)
class User extends Authenticatable implements FilamentUser
{
    use HasFactory, HasRoles, Notifiable;  // HasRoles uit Spatie\Permission\Traits

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->hasAnyRole(['super-admin', 'staff']);
    }

    protected function casts(): array { ... }
}
```

Pattern-imports verifieren via `mcp__plugin_context7_context7__query-docs`: `Filament\Models\Contracts\FilamentUser` + `Filament\Panel` + `Spatie\Permission\Traits\HasRoles` zijn correct in Filament v4 / Spatie permission v6.

**Bestaande `#[Fillable]`/`#[Hidden]`-attributen blijven** — Spatie's `HasRoles` voegt geen fillable-velden toe; rol-assignments gaan via `->assignRole()`.

---

### `database/seeders/EmeqStaffSeeder.php`

**Idempotent-firstOrCreate analog:** `database/seeders/DatabaseSeeder.php` lines 17-39

```php
public function run(): void
{
    if (app()->isProduction()) {
        return;
    }

    if (! User::where('email', 'test@example.com')->exists()) {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

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

→ **Plan 09-03 EmeqStaffSeeder-pattern:**

```php
public function run(): void
{
    $email = env('EMEQ_STAFF_SEED_EMAIL');
    $password = env('EMEQ_STAFF_SEED_PASSWORD');

    if (! $email || ! $password) {
        return;  // Env-driven: zonder beide vars geen-op (production-safe).
    }

    // 1. Roles + permissions (idempotent)
    $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
    $staff = Role::firstOrCreate(['name' => 'staff']);

    foreach (['manage-consumers', 'manage-connections', 'view-webhooks',
              'view-account-subscriptions', 'view-billing'] as $perm) {
        $p = Permission::firstOrCreate(['name' => $perm]);
        $superAdmin->givePermissionTo($p);
        $staff->givePermissionTo($p);
    }

    $managePerm = Permission::firstOrCreate(['name' => 'manage-staff']);
    $superAdmin->givePermissionTo($managePerm);  // staff krijgt deze NIET

    // 2. Bootstrap super-admin user
    $user = User::firstOrCreate(
        ['email' => $email],
        ['name' => 'Emeq Super Admin', 'password' => Hash::make($password)],
    );
    $user->assignRole($superAdmin);
}
```

**Geen `app()->isProduction()` guard** — deze seeder moet juist in productie 1× kunnen draaien voor bootstrap; env-vars zijn de productie-safe-knop. `DatabaseSeeder.php` zelf draait nooit in productie (line 19), dus EmeqStaffSeeder wordt geforceerd via `php artisan db:seed --class=EmeqStaffSeeder`.

---

### `app/Providers/Filament/AdminPanelProvider.php`

**Gegenereerd door `php artisan filament:install --panels`.** Niet handmatig schrijven.

**Boot/register-style analog:** `app/Providers/AppServiceProvider.php`

```php
public function register(): void
{
    $this->app->scoped(MollieConnectionContext::class);

    $this->app->singleton(OAuthFlowRegistry::class, function (Application $app): OAuthFlowRegistry {
        $registry = new OAuthFlowRegistry($app);
        $registry->register('mollie', MollieConnectOAuthFlow::class);
        return $registry;
    });
    // ...
}
```

→ AdminPanelProvider zal `panel(Panel $panel)` overriden met `->path('admin')`, `->login()`, `->authGuard('web')`, `->discoverResources(...)`. Geen analog in deze codebase; Filament-docs zijn authoritative.

**`bootstrap/providers.php` registratie:** `bootstrap/providers.php` (5 regels)

```php
<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
];
```

→ Plan 09-02: add `App\Providers\Filament\AdminPanelProvider::class,` aan de return-array (Filament's installer doet dit normaal automatisch — verifieer post-install).

---

### `tests/Feature/Admin/PanelAccessTest.php` (auth-flow + 403)

**Bearer + RefreshDatabase + role-assertion analog:** `tests/Feature/Api/V1/AccountSubscriptions/ListAccountSubscriptionsTest.php` lines 1-30

```php
namespace Tests\Feature\Api\V1\AccountSubscriptions;

use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListAccountSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_with_account_external_id_returns_only_own_account_subs(): void
    {
        $consumer = Consumer::factory()->create();
        // ... setup ...
        $token = $consumer->createToken('test', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/account-subscriptions?account_external_id=school-a');

        $response->assertOk()->assertJsonCount(1, 'data');
    }
}
```

→ **Plan 09-04+ test-pattern** voor Filament:

```php
namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_any_role_cannot_access_admin_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();  // canAccessPanel() returns false
    }

    public function test_staff_user_can_access_admin_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();
    }
}
```

Note: voor Filament-Resource-actions kun je Livewire-testing-pattern gebruiken (`Livewire::test(...)`), maar plain HTTP-asserts dekken canAccessPanel/Gate-checks goed.

---

### `tests/Feature/Admin/ConnectionFingerprintTest.php` (no-secret-leak)

**Exacte pattern-match:** `tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php` lines 30-60

```php
private const RAW_CLIENT_KEY = 'CK-test-rawkey-DO-NOT-LEAK';
private const RAW_SUBSCRIPTION_KEY = 'SK-test-rawsubkey-DO-NOT-LEAK';

public function test_audit_row_after_successful_passthrough_contains_no_raw_client_key(): void
{
    $this->doPassThroughCallWithRawSecrets();

    $row = (array) DB::table('pass_through_calls')->latest('id')->first();

    foreach ($row as $col => $val) {
        if (is_string($val)) {
            $this->assertStringNotContainsString(
                self::RAW_CLIENT_KEY,
                $val,
                "Audit-kolom {$col} bevat raw clientKey.",
            );
        }
    }
}
```

→ **Plan 09-05/09-07 test-pattern voor Filament Livewire-HTML:**

```php
namespace Tests\Feature\Admin;

use App\Filament\Resources\ConnectionResource\Pages\ListConnections;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConnectionFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_ACCESS_TOKEN = 'access_test-DO-NOT-LEAK';
    private const RAW_CLIENT_KEY = 'CK-test-DO-NOT-LEAK';

    public function test_connection_list_html_contains_no_raw_secrets(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        Connection::factory()->forMollie()->active()->create([
            'access_token' => self::RAW_ACCESS_TOKEN,
        ]);
        Connection::factory()->forSnelstart()->create([
            'client_key' => self::RAW_CLIENT_KEY,
        ]);

        $this->actingAs($admin);

        $html = Livewire::test(ListConnections::class)->render()->html();

        $this->assertStringNotContainsString(self::RAW_ACCESS_TOKEN, $html);
        $this->assertStringNotContainsString(self::RAW_CLIENT_KEY, $html);
    }
}
```

Variant: ook GET op `/admin/connections` HTTP-response checken:

```php
$response = $this->actingAs($admin)->get('/admin/connections');
$response->assertDontSee(self::RAW_ACCESS_TOKEN);
$response->assertDontSee(self::RAW_CLIENT_KEY);
```

---

### `tests/Feature/Admin/PatAbilityPresetsTest.php` (discovery-contract)

**Exacte pattern-match:** `tests/Feature/Api/V1/Billing/BillingAbilityGateTest.php` lines 16-22

```php
public function test_billing_read_constant_exists(): void
{
    $this->assertSame('billing:read', TokenAbilities::BILLING_READ);
    $this->assertSame('billing:write', TokenAbilities::BILLING_WRITE);
    $this->assertContains(TokenAbilities::BILLING_READ, TokenAbilities::all());
    $this->assertContains(TokenAbilities::BILLING_WRITE, TokenAbilities::all());
}
```

→ **Plan 09-04 test-pattern:**

```php
namespace Tests\Feature\Admin;

use App\Filament\Resources\ConsumerResource;  // of waar de presets-map staat
use App\Sanctum\TokenAbilities;
use Tests\TestCase;

class PatAbilityPresetsTest extends TestCase
{
    public function test_every_token_ability_is_covered_by_a_preset_or_custom_only_list(): void
    {
        $covered = collect(ConsumerResource::PAT_PRESETS)
            ->flatten()
            ->unique()
            ->merge(ConsumerResource::PAT_CUSTOM_ONLY)
            ->all();

        foreach (TokenAbilities::all() as $ability) {
            $this->assertContains(
                $ability,
                $covered,
                "Ability '{$ability}' moet in een preset of in CUSTOM_ONLY staan."
            );
        }
    }
}
```

Geen DB-state nodig (geen `RefreshDatabase`). Test draait op constants alleen — uitsluitend regression-vangnet voor nieuwe `TokenAbilities`-toevoegingen.

---

### `tests/Feature/Admin/ProviderDescriptorTest.php` (config-driven discovery)

**Config-driven analog:** `app/Billing/PlanResolver.php` + `BillingAbilityGateTest.php`

```php
// PlanResolver.php — config-load pattern
public function find(string $slug): array
{
    $plan = config("billing-plans.{$slug}");

    if (! is_array($plan)) {
        throw UnknownPlanException::forSlug($slug);
    }

    return $plan;
}
```

→ **Plan 09-11 test-pattern:**

```php
namespace Tests\Feature\Admin;

use App\Support\ProviderCredentialDescriptor;
use Tests\TestCase;

class ProviderDescriptorTest extends TestCase
{
    public function test_mollie_descriptor_resolves_from_config(): void
    {
        $d = ProviderCredentialDescriptor::for('mollie');

        $this->assertSame(['access_token', 'refresh_token'], $d->encryptedFields);
        $this->assertSame('mollie', $d->oauthFlowKey);
    }

    public function test_adding_theoretical_provider_appears_in_all(): void
    {
        // D-04 success-criterium 10: een nieuwe provider toevoegen vereist
        // alleen een rij in config/hub-providers.php — geen Filament-code-wijziging.
        config(['hub-providers.moneybird' => [
            'encrypted_fields' => ['access_token', 'refresh_token'],
            'primary_label' => 'OAuth token',
            'oauth_flow_key' => 'moneybird',
        ]]);

        $keys = array_map(fn ($d) => $d->key, ProviderCredentialDescriptor::all());

        $this->assertContains('moneybird', $keys);
    }
}
```

Bestaande `BillingAbilityGateTest::test_billing_read_constant_exists` is de constants-asserter-mal. Test op `config()`-runtime-override (laravel pattern, ook gebruikt in `BillingAbilityGateTest::test_admin_subscription_endpoints_require_admin_allowlist` line 58: `config(['billing.admin_allowlist' => []])`).

---

## Shared Patterns

### Filament-Resource-class shape

**Source:** Filament v4-docs (geen codebase-analog — Filament is nieuw in deze stack na Plan 09-02).

→ **Apply to:** alle 7 resources. Planner moet hier `mcp__plugin_context7_context7__query-docs` aanroepen voor:
- `Filament\Resources\Resource` base-class
- `Filament\Forms\Components\*` (TextInput, Select, Section)
- `Filament\Tables\Columns\*` (TextColumn, BadgeColumn)
- `Filament\Tables\Filters\*` (SelectFilter)
- `Filament\Tables\Actions\*` (Action, BulkAction)
- `Filament\Notifications\Notification`
- `Filament\Models\Contracts\FilamentUser`

### Encrypted-at-rest invariant

**Source:** `app/Models/Connection.php` lines 60-72 + `app/Models/Consumer.php` lines 22-27

```php
protected function casts(): array
{
    return [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'client_key' => 'encrypted',
        'subscription_key' => 'encrypted',
        // ...
    ];
}
```

→ **Apply to:** alle 7 resources. Filament's auto-form renders een TextInput voor encrypted-velden. Voor `ConnectionResource` MOET dit `->disabled()` + `->dehydrated(false)` zijn zodat raw value nooit in form-state komt. Voor `ConsumerResource.webhook_callback_secret`: `->password()->revealable(false)` + niet in tabel-view tonen.

### `#[Hidden]`-attribute schermt toArray() af

**Source:** `app/Models/Connection.php` line 30

```php
#[Hidden(['access_token', 'refresh_token', 'client_key', 'subscription_key'])]
```

→ **Apply to:** geen wijziging nodig. Filament gebruikt model-`toArray()` voor JSON-export-acties. Test `ConnectionEncryptionTest::test_to_array_hides_all_credential_fields` (lines 71-84) bewijst dit gedrag.

### Multi-tenant cross-Consumer-isolation in tests

**Source:** `tests/Feature/Api/V1/AccountSubscriptions/ListAccountSubscriptionsTest.php` lines 66-83

```php
public function test_list_with_other_consumer_account_external_id_returns_empty_list(): void
{
    $consumerA = Consumer::factory()->create();
    $consumerB = Consumer::factory()->create();
    $accountB = Account::factory()->for($consumerB)->create(['external_id' => 'school-secret']);
    // ...

    $tokenA = $consumerA->createToken('test', [TokenAbilities::MOLLIE_READ])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/v1/account-subscriptions?account_external_id=school-secret')
        ->assertOk()
        ->assertJsonCount(0, 'data');
}
```

→ **Apply to:** Plan 09-04 (ConsumerResource), 09-05 (ConnectionResource), 09-06 (AccountResource), 09-08 (AccountSubscriptionResource). In Filament context betekent dit: ListPage rendert geen rijen van andere Consumers, eigen Action's faalen op cross-Consumer-record-id (404 of authorization-deny).

### Comment-style voor decision-tags

**Source:** `config/billing-plans.php` lines 5-19, `config/billing.php` lines 5-9, `app/Billing/Account/SubscriptionStatus.php` (geen comment maar config in `billing-plans.php` toont stijl)

```php
/*
 * D-05 / D-06: plan-definities voor Cashier-Mollie use-case A
 * (Emeq factureert aan Consumers). Schema matched
 * `mollie/laravel-cashier-mollie ^2.20`'s plan-shape:
 *   - amount.value: string, 2 decimals (Mollie-validatie-vereiste)
 */
```

→ **Apply to:** `config/hub-providers.php` top, `app/Support/ProviderCredentialDescriptor.php` PHPDoc, migraties die D-tags refereren. Tag-stijl: `D-04: ...`, `Plan 09-XX: ...`, link naar 09-CONTEXT.md decision-sectie.

### `declare(strict_types=1)` + `final class`

**Source:** `app/Sanctum/TokenAbilities.php` line 1-5, `app/Support/Mollie/MollieHeaderForwarder.php` lines 1-5, `app/Billing/PlanResolver.php` lines 1-19

→ **Apply to:** alle nieuwe support/value-object files. Niet alle bestaande Hub-files hebben dit (Models gebruiken het niet — Laravel-conventie), maar Support en Sanctum wel. Volg de role-conventie.

### Migration forward-only down() comment

**Source:** `database/migrations/2026_05_17_000002_add_inbound_columns_to_pass_through_calls_table.php` lines 43-45

```php
public function down(): void
{
    Schema::table('pass_through_calls', function (Blueprint $table): void {
        $table->dropUnique('pass_through_calls_provider_event_unique');
        // ...
    });
    // consumer_id/account_id revert naar non-null laten we expliciet weg:
    // forward-only-prod-policy (CLAUDE.md — Migrations zijn forward-only in prod).
}
```

→ **Apply to:** Plan 09-01 migratie. CLAUDE.md invariant: "Migrations zijn forward-only in prod. Geen `down()` aanroepen na merge". `down()` blijft technisch werken voor lokale `migrate:fresh`, maar tagged-met-comment dat productie-gedrag forward-only is.

## No Analog Found

Filament-Resource classes hebben **geen** existing analog in deze codebase. De planner moet voor de resource-shape per file uitsluitend leunen op Filament v4-docs via `mcp__plugin_context7_context7__query-docs`. Wat **wel** uit deze codebase gehaald wordt:

| File | Reason geen-analog | Compenseert met |
|------|---------------------|-----------------|
| 7× `app/Filament/Resources/*Resource.php` | Filament wordt voor het eerst geïnstalleerd via Plan 09-02 | Domain-model + business-logic uit `app/Models/*`, `app/OAuth/*`, `app/Billing/Account/AccountSubscriptionManager.php`, `app/Sanctum/TokenAbilities.php` |
| `app/Providers/Filament/AdminPanelProvider.php` | Gegenereerd door `php artisan filament:install --panels` | Niet handmatig schrijven; verifieer post-install via `bootstrap/providers.php` |
| `app/Filament/Resources/*Resource/Pages/*.php` (Filament-page-classes) | Generated by Filament's resource-make-command | `php artisan make:filament-resource ...` |

## Metadata

**Analog search scope:** `app/Models/`, `app/Support/`, `app/Sanctum/`, `app/Billing/`, `app/OAuth/`, `app/Providers/`, `database/migrations/`, `database/seeders/`, `database/factories/`, `config/`, `tests/Feature/Api/V1/AccountSubscriptions/`, `tests/Feature/Api/V1/Billing/`, `tests/Feature/Api/V1/Snelstart/`, `tests/Feature/ConnectionEncryptionTest.php`, `bootstrap/`.

**Files scanned:** 30+ files read directly; 18 analogs assigned.

**Pattern extraction date:** 2026-05-15

**Key invariants surfaced:**
1. `Connection::fingerprint()` heeft al de provider-switch — `ProviderCredentialDescriptor` moet de bestaande accessor wrappen, niet vervangen (encryption-tests blijven groen).
2. `TokenAbilities` (`final class` + `::all()`) is dé discovery-contract-mal voor ProviderCredentialDescriptor.
3. `#[Hidden]`-attribute op `Connection` schermt al `toArray()` af — Filament's JSON-export-acties zijn safe by default.
4. `AccountSubscriptionManager` is single-entry-point voor state-flips — Filament-actions roepen manager-methoden, niet `$sub->update(...)`.
5. `OAuthFlowRegistry::for('snelstart')` throwt `InvalidArgumentException` — revoke-action op Snelstart-Connections moet conditioneel zijn op `descriptor->oauthFlowKey !== null`.
6. Migration-down comment-stijl + forward-only-tag is een vaste conventie (zie `2026_05_17_000002_add_inbound_columns_to_pass_through_calls_table.php`).
7. Cross-Consumer-isolation tests gebruiken `Consumer::factory()->create()` × 2 + `getJson()` met token A → assert lege list / 404 op resource van B (`ListAccountSubscriptionsTest` line 66-83).
8. No-secret-leak tests scannen kolommen/HTML met `assertStringNotContainsString` + constant `RAW_SECRET` (`PassThroughAuditNoSecretsTest` lines 26-30).
