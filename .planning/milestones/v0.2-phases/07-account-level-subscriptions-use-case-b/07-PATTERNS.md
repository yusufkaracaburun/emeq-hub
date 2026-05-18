# Phase 7: Account-level subscriptions (use-case B) — Pattern Map

**Mapped:** 2026-05-15
**Files analyzed:** 28 nieuwe/aangepaste bestanden
**Analogs found:** 25 / 28 (3 from-scratch zonder directe analog)

## Doel

Voor elk nieuw bestand in Phase 7 is hieronder de closest existing analog in de Hub-codebase geïdentificeerd, inclusief concrete code-excerpts die de planner 1:1 in `<reference_pattern>`-blokken van PLAN.md kan citeren. Analog-paden zijn absoluut en regelnummers verwijzen naar de versie die op `master` staat (commit `e42ee64`).

---

## File Classification

| Nieuw/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `database/migrations/<datum>_create_account_subscriptions_table.php` | migration | DDL | `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php` | role-match |
| `app/Models/AccountSubscription.php` | model | CRUD | `app/Models/PassThroughCall.php` + `app/Models/Connection.php` | exact (PassThroughCall) + role-match (Connection voor casts) |
| `database/factories/AccountSubscriptionFactory.php` | factory | CRUD | `database/factories/PassThroughCallFactory.php` + `database/factories/ConnectionFactory.php` | exact |
| `app/Models/Account.php` (modified — add `hasMany`) | model | CRUD | `app/Models/Account.php` (zelf — bestaande `connections()`-relatie) | exact |
| `app/Models/Connection.php` (modified — add `hasMany`) | model | CRUD | `app/Models/Account.php` (`connections()`-relatie) | exact |
| `app/Billing/Account/SubscriptionStatus.php` | enum/status | n/a | **GEEN ANALOG** (eerste PHP-enum in repo) | from-scratch |
| `app/Billing/Account/StateTransitions.php` | utility | n/a | **GEEN ANALOG** (eerste state-machine in repo) | from-scratch |
| `app/Billing/Account/Exceptions/InvalidStateTransitionException.php` | exception | n/a | `app/Billing/Exceptions/UnknownPlanException.php` | exact |
| `app/Billing/Account/AccountSubscriptionManager.php` | service | request-response + Mollie I/O | `app/Billing/PlanResolver.php` (DI-shape) + `app/Http/Controllers/Webhooks/MollieWebhookController.php` (Mollie-call + context-set) | partial (custom combinatie) |
| `app/Http/Controllers/Api/V1/AccountSubscriptions/AccountSubscriptionController.php` | controller (resource) | request-response | `app/Http/Controllers/Api/V1/ConnectionController.php` | exact |
| `app/Http/Controllers/Api/V1/AccountSubscriptions/PauseController.php` | controller (single-action) | request-response | `app/Http/Controllers/Api/V1/OAuth/InitController.php` | exact |
| `app/Http/Controllers/Api/V1/AccountSubscriptions/ResumeController.php` | controller (single-action) | request-response | `app/Http/Controllers/Api/V1/OAuth/InitController.php` | exact |
| `app/Http/Requests/Api/V1/AccountSubscriptions/CreateAccountSubscriptionRequest.php` | form-request | validation | `app/Http/Requests/Api/V1/Mollie/CreateSubscriptionRequest.php` | exact |
| `app/Http/Resources/Api/V1/AccountSubscriptionResource.php` | resource | response-format | `app/Http/Resources/Api/V1/ConnectionResource.php` | exact |
| `app/Webhooks/Mollie/WebhookPayloadRouter.php` | dispatcher | event-driven | **GEEN DIRECTE ANALOG** (extracted uit `MollieWebhookController`) | from-scratch (extract) |
| `app/Webhooks/Mollie/SubscriptionWebhookHandler.php` | handler | event-driven | `app/Http/Controllers/Webhooks/MollieWebhookController.php` (anti-spoof stap 4) | partial |
| `app/Webhooks/Mollie/PaymentWebhookHandler.php` | handler | event-driven | `app/Http/Controllers/Webhooks/MollieWebhookController.php` (huidige Payment-pad) | exact |
| `app/Http/Controllers/Webhooks/MollieWebhookController.php` (refactor) | controller (webhook ingress) | event-driven | `app/Http/Controllers/Webhooks/MollieWebhookController.php` (self) | exact |
| `routes/api.php` (additie) | routes | n/a | `routes/api.php` (Mollie pass-through-blok) | exact |
| `tests/Unit/Billing/Account/StateTransitionsTest.php` | unit-test | n/a | `tests/Unit/Billing/PlanResolverTest.php` | exact |
| `tests/Unit/Billing/Account/AccountSubscriptionManagerCreateTest.php` | unit-test | Mollie I/O stub | `tests/Feature/Api/V1/Mollie/SubscriptionsTest.php` (StubsMollieClient gebruik) | role-match |
| `tests/Unit/Billing/Account/AccountSubscriptionManagerSyncTest.php` | unit-test | Mollie I/O stub | `tests/Feature/Api/V1/Mollie/SubscriptionsTest.php` | role-match |
| `tests/Unit/Billing/Account/SubscriptionWebhookHandlerTest.php` | unit-test | event-driven | `tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php` | role-match |
| `tests/Feature/Api/V1/AccountSubscriptions/CreateAccountSubscriptionTest.php` | feature-test | request-response | `tests/Feature/Api/V1/Mollie/SubscriptionsTest.php` + `tests/Feature/Api/V1/StoreAccountTest.php` | exact |
| `tests/Feature/Api/V1/AccountSubscriptions/CancelAccountSubscriptionTest.php` | feature-test | request-response | `tests/Feature/Api/V1/Mollie/SubscriptionsTest.php` | exact |
| `tests/Feature/Api/V1/AccountSubscriptions/PauseResumeAccountSubscriptionTest.php` | feature-test | request-response | `tests/Feature/Api/V1/StoreAccountTest.php` (ability-gating-shape) | role-match |
| `tests/Feature/Api/V1/AccountSubscriptions/ListAccountSubscriptionsTest.php` | feature-test | request-response | `tests/Feature/Api/V1/Mollie/SubscriptionsTest.php` (index) | exact |
| `tests/Feature/Api/V1/AccountSubscriptions/AccountSubscriptionWebhookFlowTest.php` | feature-test | event-driven | `tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php` | exact |
| `tests/Feature/Api/V1/AccountSubscriptions/MollieAndAccountSubscriptionsCoexistenceTest.php` | feature-test | event-driven + request-response | `tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php` + `SubscriptionsTest.php` | role-match |
| `tests/Integration/AccountSubscriptions/AccountSubscriptionMollieRoundtripTest.php` | integration-test | Mollie real I/O | `tests/Integration/Billing/CashierMollieSubscriptionFlowTest.php` | exact |

---

## Pattern Assignments

### Database-laag

#### `database/migrations/<datum>_create_account_subscriptions_table.php` (migration, DDL)

**Analog:** `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php`

**Schema-shape pattern** (regels 8-34) — gebruik `Schema::create`, expliciete FK-rules (`cascadeOnDelete` / `nullOnDelete`), `DB::statement` voor partial indexes:
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pass_through_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consumer_id')->constrained('consumers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('connections')->nullOnDelete();
            $table->string('provider');
            // ... kolommen ...
            $table->timestamp('created_at')->useCurrent();
            $table->index(['consumer_id', 'created_at']);
            $table->index(['account_id', 'created_at']);
        });

        DB::statement(
            'CREATE INDEX pass_through_calls_status_failures '
            .'ON pass_through_calls (status) WHERE status >= 500'
        );
    }
```

**Voor D-03's partial unique index op `(connection_id, mollie_subscription_id) WHERE mollie_subscription_id IS NOT NULL`** — analog: `database/migrations/2026_05_14_151327_add_active_unique_to_connections.php` regels 8-13:
```php
DB::statement(
    'CREATE UNIQUE INDEX connections_account_id_provider_active_unique '
    .'ON connections (account_id, provider) WHERE revoked_at IS NULL'
);
```

**FK-policy uit D-03:** `account_id` = `cascadeOnDelete`, `connection_id` = `restrictOnDelete` (afwijking van pass-through-calls' `nullOnDelete`). Voor `restrict` gebruikt Laravel `restrictOnDelete()`.

---

#### `app/Models/AccountSubscription.php` (model, CRUD)

**Analog (Fillable-attribute + casts-shape):** `app/Models/PassThroughCall.php`
**Analog (factory-binding):** `app/Models/Connection.php` regels 4-32
**Analog (`hasMany`/`belongsTo`-relaties):** `app/Models/Account.php` regels 18-26

**Class-shape pattern** (PassThroughCall regels 11-31):
```php
#[Fillable([
    'consumer_id',
    'account_id',
    'connection_id',
    // ... velden uit D-03 ...
])]
class PassThroughCall extends Model
{
    /** @use HasFactory<PassThroughCallFactory> */
    use HasFactory;

    public function consumer(): BelongsTo { return $this->belongsTo(Consumer::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function connection(): BelongsTo { return $this->belongsTo(Connection::class); }
}
```

**Casts-pattern** (PassThroughCall regels 48-59) — gebruik `protected function casts(): array`, geen `protected $casts`-array (Laravel 11+ convention):
```php
protected function casts(): array
{
    return [
        'status' => 'integer',
        'duration_ms' => 'integer',
        'response_size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];
}
```

**Voor AccountSubscription specifiek (D-03 velden):** casts moeten minimaal bevatten:
- `'metadata' => 'array'` (jsonb)
- `'starts_at' => 'datetime'`
- `'paused_at' => 'datetime'`
- `'canceled_at' => 'datetime'`
- `'completed_at' => 'datetime'`
- `'last_webhook_event_at' => 'datetime'`
- `'start_date' => 'date'`
- `'status' => SubscriptionStatus::class` (PHP-enum-cast)
- `'times' => 'integer'`

**Geen `encrypted` casts nodig** (D-02 invariant — `mollie_*`-id's zijn opaque references, geen secrets).

---

#### `database/factories/AccountSubscriptionFactory.php` (factory)

**Analog:** `database/factories/PassThroughCallFactory.php` (foreign-key-chain) + `database/factories/ConnectionFactory.php` (state-methods).

**FK-chain pattern** (PassThroughCallFactory regels 19-37):
```php
public function definition(): array
{
    $consumer = Consumer::factory();
    $account = Account::factory()->for($consumer);

    return [
        'consumer_id' => $consumer,
        'account_id' => $account,
        'connection_id' => Connection::factory()->forSnelstart()->for($account),
        // ... defaults ...
    ];
}
```

**State-pattern** (ConnectionFactory regels 29-88) — gebruik `forConnection(Connection $c)`, `pending()`, `active()`, `paused()`, `canceled()` states:
```php
public function forSnelstart(): static
{
    return $this->state(fn (array $attributes) => [
        'provider' => 'snelstart',
        'client_key' => 'CK-'.Str::random(40),
        // ...
    ]);
}

public function pending(): static
{
    return $this->state(fn (array $attributes) => [
        'status' => 'pending',
        // ...
    ]);
}

public function active(): static
{
    return $this->state(fn (array $attributes) => [
        'status' => 'active',
        // ...
    ]);
}
```

**Voor AccountSubscription:** Default state = `pending` (D-04); helper `forConnection(Connection $c)` moet `connection_id`, `account_id` (via `$c->account`) en `consumer_id` (via `$c->account->consumer`) in één call zetten — match het `for($account)`-pattern uit PassThroughCallFactory.

---

#### `app/Models/Account.php` + `app/Models/Connection.php` (modified — add `hasMany`)

**Analog:** `app/Models/Account.php` regels 18-26 (zelf):
```php
public function consumer(): BelongsTo
{
    return $this->belongsTo(Consumer::class);
}

public function connections(): HasMany
{
    return $this->hasMany(Connection::class);
}
```

**Toevoeging Phase 7:**
- In `Account.php`: `public function accountSubscriptions(): HasMany { return $this->hasMany(AccountSubscription::class); }`
- In `Connection.php`: `public function accountSubscriptions(): HasMany { return $this->hasMany(AccountSubscription::class); }`

**Chirurgisch wijzigen** (`.ai/rules/engineering.md`): alleen 1 method toevoegen per file, geen andere edits.

---

### Service-laag

#### `app/Billing/Account/SubscriptionStatus.php` (PHP-enum)

**GEEN ANALOG** in de codebase. Repo gebruikt momenteel `final class` met `public const` (zie `app/Sanctum/TokenAbilities.php`) i.p.v. enums — bewust voor Sanctum's string-vergelijking (STATE.md, decision 03-02).

**Voor Phase 7 wel een echte enum nodig** (D-04 spreekt over `App\Billing\Account\SubscriptionStatus`-enum; cast op model gebruikt `'status' => SubscriptionStatus::class`). Planner moet from-scratch een backed `enum` met `: string` schrijven.

**Stijl-referentie voor identifier-casing** — `app/Sanctum/TokenAbilities.php` regels 5-22:
```php
final class TokenAbilities
{
    public const SNELSTART_READ = 'snelstart:read';
    public const MOLLIE_READ = 'mollie:read';
    public const MOLLIE_WRITE = 'mollie:write';
    // ...
}
```

**Phase 7 schrijft:**
```php
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

(TitleCase voor enum-keys volgens Laravel Boost PHP-rule "Use TitleCase for Enum keys".)

---

#### `app/Billing/Account/StateTransitions.php` (utility)

**GEEN ANALOG** — eerste state-machine in de repo. From-scratch.

**Stijl-referenties:**
- `final class` + `static`-helpers (zoals `App\Support\Mollie\MollieUpstreamErrorMapper`, regel 23): `final class MollieUpstreamErrorMapper { public static function mapException(Throwable $exception): array { ... } }`
- Test-matrix-spec: zie CONTEXT.md §<specifics> "State-machine test-matrix" voor legal/illegal-paren

**Verwacht API (D-04):**
```php
final class StateTransitions
{
    public static function assertTransition(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        // throws InvalidStateTransitionException als overgang niet in legal-tabel staat
    }
}
```

---

#### `app/Billing/Account/Exceptions/InvalidStateTransitionException.php` (exception)

**Analog:** `app/Billing/Exceptions/UnknownPlanException.php`

**Full file pattern** (regels 1-19):
```php
<?php

declare(strict_types=1);

namespace App\Billing\Exceptions;

use RuntimeException;

final class UnknownPlanException extends RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf(
            'Onbekende plan-slug: "%s". Definieer in config/billing-plans.php.',
            $slug,
        ));
    }
}
```

**Voor InvalidStateTransitionException** (D-04 vraagt `from`/`to`-properties voor inspectie):
```php
final class InvalidStateTransitionException extends RuntimeException
{
    public function __construct(
        public readonly SubscriptionStatus $from,
        public readonly SubscriptionStatus $to,
    ) {
        parent::__construct(sprintf(
            'Ongeldige state-transition: %s → %s.',
            $from->value,
            $to->value,
        ));
    }

    public static function for(SubscriptionStatus $from, SubscriptionStatus $to): self
    {
        return new self($from, $to);
    }
}
```

**NL-message-conventie** uit UnknownPlanException — message in NL, identifiers in EN.

---

#### `app/Billing/Account/AccountSubscriptionManager.php` (service)

**Analogs (gecombineerd):**
- `app/Billing/PlanResolver.php` (DI + `final class` + method-shape)
- `app/Http/Controllers/Webhooks/MollieWebhookController.php` (regels 84-86) voor Mollie-context + SDK-call-pattern
- `app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php` (regels 49-61) voor `createForId`-call
- `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` (regels 196-206) voor Idempotency-Key forward

**DI-shape** (PlanResolver regels 19-49):
```php
final class PlanResolver
{
    public function find(string $slug): array
    {
        $plan = config("billing-plans.{$slug}");
        if (! is_array($plan)) {
            throw UnknownPlanException::forSlug($slug);
        }
        return $plan;
    }
}
```

**Mollie-context-set + SDK-call** (MollieWebhookController regels 84-90):
```php
app(MollieConnectionContext::class)->set($connection);
try {
    Mollie::client()->payments->get($payload['id']);
} catch (Throwable $e) {
    // ... error-handling ...
}
```

**Subscriptions create via SDK** (SubscriptionsController regels 49-61):
```php
try {
    $subscription = $this->buildClient($r)->subscriptions->createForId($customer_id, $r->validated());
} catch (MollieApiException $e) {
    throw MollieExceptionMapper::map($e);
}
```

**Idempotency-Key forward-pattern** (AbstractMolliePassThroughController regels 196-206):
```php
protected function buildClient(Request $request): MollieApiClient
{
    $client = Mollie::client();
    $consumerKey = $request->header('Idempotency-Key');
    if (is_string($consumerKey) && $consumerKey !== '') {
        $client->setIdempotencyKey($consumerKey);
    }
    return $client;
}
```

**Error-mapping consumeren** (`MollieUpstreamErrorMapper::mapException`) — manager mag exceptions doorgooien; de controller-laag mapt ze met deze mapper. Voor `NotFoundException` in `syncFromMollie()` (D-17) wel inline catchen → state `unknown`:
```php
try {
    $remote = Mollie::client()->subscriptions->getForId($sub->mollie_customer_id, $sub->mollie_subscription_id);
} catch (NotFoundException) {
    $this->transitionTo($sub, SubscriptionStatus::Unknown);
    return;
}
```

**State-transition logging** (D-22 — log-only, geen audit-tabel):
```php
Log::info('account_subscription.transition', [
    'subscription_id' => $sub->id,
    'from' => $from->value,
    'to' => $to->value,
    'reason' => $reason,
]);
```

---

### API-laag (Controllers + Form Requests + Resources)

#### `app/Http/Controllers/Api/V1/AccountSubscriptions/AccountSubscriptionController.php` (resource controller)

**Analog:** `app/Http/Controllers/Api/V1/ConnectionController.php`

**Full controller-shape pattern** (regels 18-95) — `store`/`show`/`destroy` met inline ability-guard + cross-Consumer-scope (D-12 invariant):
```php
class ConnectionController extends Controller
{
    public function store(StoreConnectionRequest $request): JsonResponse|ConnectionResource
    {
        $this->guardAbility($request, [
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::SNELSTART_WRITE,
            TokenAbilities::ADMIN,
        ]);

        $consumerId = (int) $request->user()->getKey();

        try {
            /** @var Account $account */
            $account = Account::query()
                ->where('consumer_id', $consumerId)
                ->findOrFail($request->integer('account_id'));
        } catch (ModelNotFoundException) {
            return $this->notFound('account_not_found', 'Account niet gevonden voor deze Consumer.');
        }

        // ... create ...

        return (new ConnectionResource($connection))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, int $connection): JsonResponse|ConnectionResource
    {
        $this->guardAbility($request, [...]);
        $model = $this->findOwnedConnection($request, $connection);
        if ($model === null) {
            return $this->notFound('connection_not_found', 'Connection niet gevonden.');
        }
        return new ConnectionResource($model);
    }
```

**Cross-Consumer-scope helper** (regels 97-104):
```php
private function findOwnedConnection(Request $request, int $connectionId): ?Connection
{
    $consumerId = (int) $request->user()->getKey();

    return Connection::query()
        ->whereHas('account', fn ($q) => $q->where('consumer_id', $consumerId))
        ->find($connectionId);
}
```

**404-helper + guardAbility-helper** (regels 106-123) — copy 1:1 (D-12 patroon `404 not 403` om info-disclosure te voorkomen):
```php
private function notFound(string $error, string $message): JsonResponse
{
    return response()->json([
        'error' => $error,
        'message' => $message,
    ], Response::HTTP_NOT_FOUND);
}

private function guardAbility(Request $request, array $allowed): void
{
    $token = $request->user()?->currentAccessToken();
    $has = $token && collect($allowed)->contains(fn (string $ability) => $token->can($ability));
    abort_unless($has, Response::HTTP_FORBIDDEN, 'insufficient_ability');
}
```

**Voor AccountSubscriptionController-`index`** (`GET /v1/account-subscriptions?account_id={external_id}`):
- Filter via `whereHas('account', fn ($q) => $q->where('consumer_id', $consumerId)->where('external_id', $externalId))`
- Return `AccountSubscriptionResource::collection($subs)`

**Voor `destroy` met D-08 semantiek** (Mollie-cancel + Hub-state):
- Call `$manager->cancel($sub)` — manager doet Mollie-cancel + state-transition
- Return `response()->noContent()`

---

#### `app/Http/Controllers/Api/V1/AccountSubscriptions/PauseController.php` + `ResumeController.php` (single-action)

**Analog:** `app/Http/Controllers/Api/V1/OAuth/InitController.php`

**Full single-action shape** (regels 12-50):
```php
class InitController extends Controller
{
    public function __construct(private readonly OAuthFlowRegistry $registry) {}

    /**
     * @return array<string, string>
     */
    public function __invoke(Request $request): array
    {
        $validated = $request->validate([
            'account_external_id' => ['required', 'string'],
        ]);

        /** @var Consumer $consumer */
        $consumer = $request->user();

        $account = $consumer->accounts()
            ->where('external_id', $validated['account_external_id'])
            ->firstOrFail();

        // ... business logic ...

        return [
            'connection_id' => (string) $connection->id,
            'redirect_url' => $redirectUrl,
        ];
    }
}
```

**Voor Pause/ResumeController:**
- Constructor-inject `AccountSubscriptionManager`
- `__invoke(Request $request, int $id)` resolve `AccountSubscription` via cross-Consumer-scope, call `$manager->pause($sub, $request->input('reason'))` of `$manager->resume($sub)`
- State-transition failure → `InvalidStateTransitionException` → 409 (D-23)
- Return JSON met nieuwe state (via `AccountSubscriptionResource`)

---

#### `app/Http/Requests/Api/V1/AccountSubscriptions/CreateAccountSubscriptionRequest.php` (form-request)

**Analog:** `app/Http/Requests/Api/V1/Mollie/CreateSubscriptionRequest.php`

**Full validation-shape** (regels 24-52):
```php
class CreateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth gebeurt op middleware-niveau (auth:sanctum + resolve.mollie.account
        // + ability-guard in AbstractMolliePassThroughController).
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'array'],
            'amount.currency' => ['required', 'string', 'size:3'],
            'amount.value' => ['required', 'string', 'regex:/^\d+\.\d{2,}$/'],
            'interval' => ['required', 'string', 'regex:/^\d+\s+(day|days|week|weeks|month|months)$/'],
            'description' => ['required', 'string', 'max:255'],
            'startDate' => ['nullable', 'date'],
            'mandateId' => ['nullable', 'string'],
            'metadata' => ['nullable'],
            'times' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
```

**Voor CreateAccountSubscriptionRequest specifiek (D-09 body-shape):** voeg toe:
- `account_external_id` => `['required', 'string']`
- `mollie_customer_id` => `['required', 'string', 'starts_with:cst_']`
- `mollie_mandate_id` => `['nullable', 'string', 'starts_with:mdt_']`
- `amount.currency` => `['required', 'string', 'in:EUR']` (D-strikt EUR-only)

**Validation-Rule met cross-Consumer-check op `account_external_id`** — analog: `StoreConnectionRequest` regels 20-26:
```php
public function rules(): array
{
    $consumerId = (int) $this->user()?->getKey();

    return [
        'account_id' => [
            'required',
            'integer',
            Rule::exists('accounts', 'id')->where('consumer_id', $consumerId),
        ],
        // ...
    ];
}
```

Voor Phase 7: `Rule::exists('accounts', 'external_id')->where('consumer_id', $consumerId)` op `account_external_id`.

---

#### `app/Http/Resources/Api/V1/AccountSubscriptionResource.php` (resource)

**Analog:** `app/Http/Resources/Api/V1/ConnectionResource.php`

**Full resource-shape** (regels 1-29):
```php
namespace App\Http\Resources\Api\V1;

use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Connection
 */
class ConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'provider' => $this->provider,
            'status' => $this->status,
            'fingerprint' => $this->fingerprint(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

**Voor AccountSubscriptionResource** — velden uit D-03 (geen `mollie_customer_id` lekken in v0.2 is niet vereist; Mollie-id's zijn opaque references per D-02):
```php
return [
    'id' => $this->id,
    'status' => $this->status->value,                            // enum-cast → string
    'mollie_customer_id' => $this->mollie_customer_id,
    'mollie_subscription_id' => $this->mollie_subscription_id,
    'mollie_mandate_id' => $this->mollie_mandate_id,
    'amount' => ['currency' => $this->amount_currency, 'value' => $this->amount_value],
    'interval' => $this->interval,
    'description' => $this->description,
    'times' => $this->times,
    'start_date' => $this->start_date?->toDateString(),
    'starts_at' => $this->starts_at?->toIso8601String(),
    'paused_at' => $this->paused_at?->toIso8601String(),
    'canceled_at' => $this->canceled_at?->toIso8601String(),
    'completed_at' => $this->completed_at?->toIso8601String(),
    'last_payment_status' => $this->last_payment_status,
    'last_webhook_event_at' => $this->last_webhook_event_at?->toIso8601String(),
    'metadata' => $this->metadata,
    'created_at' => $this->created_at?->toIso8601String(),
];
```

---

### Webhook-laag

#### `app/Webhooks/Mollie/WebhookPayloadRouter.php` (dispatcher)

**GEEN DIRECTE ANALOG** — extracted uit `MollieWebhookController`. Eerste dispatcher-pattern in de repo (D-15).

**Verwacht API** (uit CONTEXT D-15 prefix-tabel):
```php
final class WebhookPayloadRouter
{
    public function __construct(
        private readonly SubscriptionWebhookHandler $subscriptionHandler,
        private readonly PaymentWebhookHandler $paymentHandler,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function routeFor(string $id, array $payload, Connection $connection): WebhookHandlerResult
    {
        return match (true) {
            str_starts_with($id, 'sub_') => $this->subscriptionHandler->handle($id, $payload, $connection),
            str_starts_with($id, 'tr_')  => $this->paymentHandler->handle($id, $payload, $connection),
            str_starts_with($id, 'mdt_') => WebhookHandlerResult::skip('mandate_events_not_implemented'),
            default                       => $this->paymentHandler->handle($id, $payload, $connection),
        };
    }
}
```

`WebhookHandlerResult` is een eenvoudige DTO/record (id-prefix, antispoof-resource, status, audit-extra).

---

#### `app/Webhooks/Mollie/SubscriptionWebhookHandler.php` + `PaymentWebhookHandler.php` (handlers)

**Analog (PaymentWebhookHandler):** `app/Http/Controllers/Webhooks/MollieWebhookController.php` regels 83-91 (huidige anti-spoof-fetch):
```php
// 4. Anti-spoofing: bind context + fetch resource
app(MollieConnectionContext::class)->set($connection);
try {
    Mollie::client()->payments->get($payload['id']);
} catch (Throwable $e) {
    $this->auditFailedWebhook($request, 'spoof_check_failed: '.$e->getMessage());
    return response()->json(['error' => 'resource_ownership_failed'], 400);
}
```

**Voor SubscriptionWebhookHandler** (D-15 + D-18 — Mollie GET vervangt anti-spoof-fetch + triggert state-sync):
```php
public function handle(string $id, array $payload, Connection $connection): WebhookHandlerResult
{
    app(MollieConnectionContext::class)->set($connection);

    $sub = AccountSubscription::query()
        ->where('connection_id', $connection->id)
        ->where('mollie_subscription_id', $id)
        ->first();

    if ($sub === null) {
        return WebhookHandlerResult::skip('unknown_subscription');
    }

    $this->manager->syncFromMollie($sub);    // doet Mollie GET + state-update; vangt 404 → unknown

    return WebhookHandlerResult::ok($sub);
}
```

**Voor PaymentWebhookHandler** (D-15 — Payment-event met `subscriptionId` → `recordPaymentEvent`):
```php
public function handle(string $id, array $payload, Connection $connection): WebhookHandlerResult
{
    app(MollieConnectionContext::class)->set($connection);

    try {
        $payment = Mollie::client()->payments->get($id);
    } catch (Throwable $e) {
        return WebhookHandlerResult::antiSpoofFailed($e->getMessage());
    }

    $subscriptionId = $payment->subscriptionId ?? null;
    if ($subscriptionId !== null) {
        $sub = AccountSubscription::query()
            ->where('connection_id', $connection->id)
            ->where('mollie_subscription_id', $subscriptionId)
            ->first();

        if ($sub !== null) {
            $this->manager->recordPaymentEvent($sub, $payment->toArray());
            // recordPaymentEvent inspecteert payment.status + details.failureReason
            // mandate_invalid → state active → paused (D-16)
        }
    }

    return WebhookHandlerResult::ok();
}
```

---

#### `app/Http/Controllers/Webhooks/MollieWebhookController.php` (refactor)

**Analog:** Het bestand zelf (regels 1-118). Refactor target:
- Stap 0-3 (regels 41-81) blijven identiek (hard-fail guard, signature-verify, Connection-lookup, payload-id-check)
- Stap 4 (regels 83-91) wordt gedelegeerd naar `WebhookPayloadRouter::routeFor()`
- Stap 5-7 (regels 93-105) blijven identiek, maar de Spatie `webhook_calls`-create gebeurt **na** route + state-update, en alleen als handler-result niet `skipped` is

**D-31 invariant:** default-pad (`tr_*` zonder subscriptionId) moet exact dezelfde flow houden — bestaande `MollieWebhookIngressTest`/`MollieWebhookAntiSpoofingTest` blijven groen zonder wijziging.

**Refactor-volgorde uit D-18:**
```
1. Hard-fail guard         (lines 41-46 unchanged)
2. Signature-verify        (lines 49-60 unchanged)
3. Connection-lookup       (lines 63-73 unchanged)
4. Payload-id-check        (lines 76-81 unchanged)
5. WebhookPayloadRouter::routeFor()                              ← NEW (Phase 7 D-15)
6. WebhookCall::create()   (audit; alleen bij OK / antispoof-fail) (lines 94-99 — bestaande logica)
7. ForwardMollieWebhookToConsumer::dispatch()                     (line 102 unchanged)
8. 202 Accepted            (line 105 unchanged)
```

---

#### `routes/api.php` (additie)

**Analog:** `routes/api.php` regels 60-87 (Mollie pass-through-blok):
```php
Route::prefix('mollie')->middleware('resolve.mollie.account')->group(function (): void {
    Route::post('/payments', [PaymentsController::class, 'store'])->name('api.mollie.payments.store');
    Route::get('/payments/{id}', [PaymentsController::class, 'show'])->name('api.mollie.payments.show');
    Route::delete('/payments/{id}', [PaymentsController::class, 'destroy'])->name('api.mollie.payments.destroy');

    Route::get('/customers/{id}/subscriptions', [SubscriptionsController::class, 'index'])->name('api.mollie.customers.subscriptions.index');
    Route::post('/customers/{id}/subscriptions', [SubscriptionsController::class, 'store'])->name('api.mollie.customers.subscriptions.store');
    // ...
});
```

**Voor Phase 7** — D-12 zegt **geen** `resolve.mollie.account`-middleware (Account+Connection-resolutie in controller, niet pre-route). Ability-gating per route via `middleware('ability:...')`-groep:
```php
Route::middleware('auth:sanctum')->group(function (): void {
    // ... bestaande blokken ...

    Route::prefix('account-subscriptions')->group(function (): void {
        Route::middleware('ability:mollie:write,*')->group(function (): void {
            Route::post('/', [AccountSubscriptionController::class, 'store'])->name('api.account-subscriptions.store');
            Route::delete('/{id}', [AccountSubscriptionController::class, 'destroy'])->name('api.account-subscriptions.destroy');
            Route::post('/{id}/pause', PauseController::class)->name('api.account-subscriptions.pause');
            Route::post('/{id}/resume', ResumeController::class)->name('api.account-subscriptions.resume');
        });

        Route::middleware('ability:mollie:read,mollie:write,*')->group(function (): void {
            Route::get('/', [AccountSubscriptionController::class, 'index'])->name('api.account-subscriptions.index');
            Route::get('/{id}', [AccountSubscriptionController::class, 'show'])->name('api.account-subscriptions.show');
        });
    });
});
```

**Locatie:** Plaats het blok **na** de Mollie pass-through-blok (regel 87) en **vóór** de publieke `/oauth/mollie/callback`-route (regel 91).

---

### Test-laag

#### `tests/Unit/Billing/Account/StateTransitionsTest.php`

**Analog:** `tests/Unit/Billing/PlanResolverTest.php`

**Full test-shape** (regels 1-87):
```php
namespace Tests\Unit\Billing;

use App\Billing\Exceptions\UnknownPlanException;
use App\Billing\PlanResolver;
use Tests\TestCase;

class PlanResolverTest extends TestCase
{
    private PlanResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PlanResolver;
    }

    public function test_find_returns_plan_array_for_known_slug(): void
    {
        $plan = $this->resolver->find('naschool-license');
        $this->assertArrayHasKey('amount', $plan);
    }

    public function test_find_throws_unknown_plan_exception_for_unknown_slug(): void
    {
        $this->expectException(UnknownPlanException::class);
        $this->expectExceptionMessageMatches('/does-not-exist/');
        $this->resolver->find('does-not-exist');
    }
}
```

**Voor StateTransitionsTest** — data-provider-pattern voor de transition-matrix (CONTEXT §<specifics> "State-machine test-matrix"):
```php
public function test_legal_transition_does_not_throw(): void
{
    StateTransitions::assertTransition(SubscriptionStatus::Pending, SubscriptionStatus::Active);
    $this->assertTrue(true);
}

public function test_illegal_transition_throws_with_from_to_properties(): void
{
    try {
        StateTransitions::assertTransition(SubscriptionStatus::Canceled, SubscriptionStatus::Active);
        $this->fail('Should have thrown');
    } catch (InvalidStateTransitionException $e) {
        $this->assertSame(SubscriptionStatus::Canceled, $e->from);
        $this->assertSame(SubscriptionStatus::Active, $e->to);
    }
}
```

---

#### `tests/Unit/Billing/Account/AccountSubscriptionManagerCreateTest.php` + `SyncTest.php`

**Analog:** `tests/Feature/Api/V1/Mollie/SubscriptionsTest.php` (StubsMollieClient gebruik) + `tests/Concerns/StubsMollieClient.php`.

**Test-setup-pattern** (SubscriptionsTest regels 29-57):
```php
class SubscriptionsTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_get_customer_subscriptions_lists_via_sdk(): void
    {
        [, $token, , $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);

        $this->bindMollieStubs([
            'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscriptionCollection([
                ['id' => 'sub_a', 'status' => 'active', 'customerId' => $arg['customer_id']],
            ]),
        ]);
        // ... assertions ...
    }
}
```

**Voor AccountSubscriptionManagerCreateTest:** Niet via HTTP — direct `$manager->create($account, $connection, $dto)` aanroepen na `MollieConnectionContext::set($connection)` (manager doet dat zelf intern). De `subscriptions`-stub asserteert payload + return-value:
```php
$this->bindMollieStubs([
    'subscriptions' => function (string $op, mixed $arg) {
        if ($op === 'createForId') {
            $this->assertSame('cst_abc', $arg['customer_id']);
            return $this->makeSubscription([
                'id' => 'sub_new',
                'status' => 'active',
                'customerId' => $arg['customer_id'],
            ]);
        }
    },
]);

$sub = $manager->create($account, $connection, new CreateAccountSubscriptionDto(/* ... */));
$this->assertSame('sub_new', $sub->mollie_subscription_id);
$this->assertSame(SubscriptionStatus::Active, $sub->status);
```

**Voor SyncTest** — 404-pad voor `unknown`-state (D-17):
```php
$this->bindMollieStubs([
    'subscriptions' => fn () => new NotFoundException('subscription not found'),
]);

$manager->syncFromMollie($sub);

$sub->refresh();
$this->assertSame(SubscriptionStatus::Unknown, $sub->status);
```

---

#### `tests/Unit/Billing/Account/SubscriptionWebhookHandlerTest.php`

**Analog:** `tests/Feature/Webhooks/MollieWebhookAntiSpoofingTest.php` (regels 19-118)

**Test-shape pattern** (regels 31-56):
```php
public function test_webhook_for_id_that_returns_404_from_mollie_returns_400_resource_ownership_failed(): void
{
    Bus::fake();
    $this->bindMollieClientThatThrows(new NotFoundException('payment tr_spoof_1 not found for this connection'));

    $connection = $this->makeMollieConnection();
    $payload = json_encode(['id' => 'tr_spoof_1']);
    $signature = MollieWebhookSignature::sign($payload, $this->secret);

    $response = $this->call(
        'POST',
        "/webhooks/mollie/{$connection->id}",
        [], [], [],
        ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    $response->assertStatus(400);
    Bus::assertNotDispatched(ForwardMollieWebhookToConsumer::class);
}
```

**Voor SubscriptionWebhookHandlerTest** — direct handler-aanroep (geen HTTP):
- Setup `AccountSubscription` met `status=Active`, `mollie_subscription_id='sub_x'`
- Stub Mollie GET retourneert payment met `details.failureReason='mandate_invalid'`
- Call `$handler->handle('tr_failed', $payload, $connection)`
- Assert `$sub->refresh()->status === SubscriptionStatus::Paused`
- Assert `$sub->paused_at !== null`

---

#### `tests/Feature/Api/V1/AccountSubscriptions/*Test.php` (5 feature-tests)

**Analog (HTTP-setup):** `tests/Feature/Api/V1/Mollie/SubscriptionsTest.php` (uses `StubsMollieClient::setupMollieConsumer()` + `callMollie()`)
**Analog (ability-gating):** `tests/Feature/Api/V1/StoreAccountTest.php` regels 14-110

**StoreAccountTest pattern** (regels 14-110):
```php
class StoreAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_account_with_snelstart_write_ability_returns_201_and_resource_shape(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_WRITE]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounts', [
                'external_id' => 'school-007',
                'display_name' => 'School 7',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.external_id', 'school-007');
    }

    public function test_token_without_required_ability_returns_403(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::MOLLIE_READ]);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounts', [/* ... */])
            ->assertForbidden();
    }
}
```

**Voor `CreateAccountSubscriptionTest`** — combineer StubsMollieClient + ability-matrix:
- Happy: PAT met `mollie:write` + valid body → 201 + AccountSubscription-row + Mollie subscription_id gevuld
- Ability-fail: PAT met `mollie:read` → 403
- Cross-Consumer: Consumer B's `account_external_id` → 422 (Rule::exists faalt)
- Validation: missing `amount.value` → 422
- Idempotency: 2x dezelfde `Idempotency-Key` → één Mollie-call

**Voor `AccountSubscriptionWebhookFlowTest`** (SC-2) — analog MollieWebhookAntiSpoofingTest:
```php
public function test_payment_failed_with_mandate_invalid_transitions_subscription_to_paused(): void
{
    [, , , $connection] = $this->setupMollieConsumer();
    $sub = AccountSubscription::factory()->forConnection($connection)->active()->create([
        'mollie_subscription_id' => 'sub_123',
    ]);

    $this->bindMollieStubs([
        'payments' => fn () => $this->makePayment([
            'id' => 'tr_failed',
            'status' => 'failed',
            'subscriptionId' => 'sub_123',
            'details' => ['failureReason' => 'mandate_invalid'],
        ]),
    ]);

    $payload = json_encode(['id' => 'tr_failed']);
    $signature = MollieWebhookSignature::sign($payload, config('services.mollie.webhook_secret'));

    $this->call('POST', "/webhooks/mollie/{$connection->id}", [], [], [],
        ['HTTP_X_MOLLIE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    )->assertStatus(202);

    $sub->refresh();
    $this->assertSame(SubscriptionStatus::Paused, $sub->status);
    $this->assertNotNull($sub->paused_at);
}
```

---

#### `tests/Integration/AccountSubscriptions/AccountSubscriptionMollieRoundtripTest.php` (@group integration)

**Analog:** `tests/Integration/Billing/CashierMollieSubscriptionFlowTest.php`

**Integration-test-shape** (regels 1-60):
```php
namespace Tests\Integration\Billing;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Support\Facades\DB;
use Mollie\Api\MollieApiClient;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\IntegrationTestCase;

#[Group('integration')]
class CashierMollieSubscriptionFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['billing-plans' => [/* ... */]]);
    }

    public function test_admin_can_create_subscription_with_first_payment_redirect_url(): void
    {
        $admin = Consumer::factory()->create();
        config(['billing.admin_allowlist' => [$admin->id]]);
        $token = $admin->createToken('admin', [TokenAbilities::BILLING_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/admin/billing/subscriptions', [/* ... */]);

        $response->assertStatus(202);
    }
}
```

**Skip-guard** (IntegrationTestCase regels 28-46):
```php
protected function setUp(): void
{
    parent::setUp();

    $key = env('CASHIER_MOLLIE_KEY') ?: env('MOLLIE_KEY');
    if (! is_string($key) || $key === '' || ! str_starts_with($key, 'test_') || $key === 'test_xxx') {
        $this->markTestSkipped(
            'Integration tests require CASHIER_MOLLIE_KEY (test_-prefix). '
            .'Run `composer test:integration` apart.'
        );
    }

    config(['cashier.key' => $key, 'mollie.key' => $key]);
}
```

**Voor Phase 7** — Phase 7 gebruikt Connect-`access_token`, niet `CASHIER_MOLLIE_KEY`. Optie: aparte env-key `MOLLIE_CONNECT_TEST_ACCESS_TOKEN` of hergebruik dezelfde guard met andere env-naam.

**Real Mollie API call** (regels 66-77):
```php
$mollieClient = new MollieApiClient;
$mollieClient->setApiKey(env('CASHIER_MOLLIE_KEY'));

$mollieCustomer = $mollieClient->customers->create([
    'name' => 'Integration Test Consumer',
    'email' => 'integration+'.now()->timestamp.'@emeq.test',
]);

$mollieMandate = $mollieClient->mandates->createForId($mollieCustomer->id, [
    'method' => 'directdebit',
    'consumerName' => 'Test Account Holder',
    'consumerAccount' => 'NL55INGB0000000000',
]);

$this->assertSame('valid', $mollieMandate->status);
```

---

## Shared Patterns

### Cross-Consumer-scope (D-12 invariant)

**Source:** `app/Http/Controllers/Api/V1/ConnectionController.php` regels 97-104
**Apply to:** Alle 3 AccountSubscriptions-controllers (show/destroy/pause/resume)

```php
private function findOwnedSubscription(Request $request, int $id): ?AccountSubscription
{
    $consumerId = (int) $request->user()->getKey();

    return AccountSubscription::query()
        ->whereHas('account', fn ($q) => $q->where('consumer_id', $consumerId))
        ->find($id);
}
```

**Belangrijk:** Bij niet-gevonden → 404 (`not 403`), zelfde error-shape als ConnectionController (D-12 voorkomt info-disclosure).

---

### Inline ability-guard

**Source:** `app/Http/Controllers/Api/V1/ConnectionController.php` regels 117-124
**Apply to:** Alle controllers die niet via een route-level `ability:`-middleware-groep gaan (single-actions die meerdere abilities accepteren)

```php
private function guardAbility(Request $request, array $allowed): void
{
    $token = $request->user()?->currentAccessToken();
    $has = $token && collect($allowed)->contains(fn (string $ability) => $token->can($ability));
    abort_unless($has, Response::HTTP_FORBIDDEN, 'insufficient_ability');
}
```

**D-10 mapping voor Phase 7:**
- create/destroy/pause/resume: `[MOLLIE_WRITE, ADMIN]`
- index/show: `[MOLLIE_READ, MOLLIE_WRITE, ADMIN]`

Beide kunnen ook puur route-level via `middleware('ability:mollie:write,*')` (zoals in routes/api.php voor `oauth/mollie/init`). Voorkeur: route-level voor consistentie, inline alleen als routegroep niet werkt voor multi-ability-OR.

---

### Mollie SDK-call met error-mapping

**Source:** `app/Http/Controllers/Api/V1/Mollie/SubscriptionsController.php` regels 49-61 + `app/Support/Mollie/MollieUpstreamErrorMapper.php` regels 23-118
**Apply to:** `AccountSubscriptionManager` (alle Mollie-call-sites)

```php
try {
    $subscription = $this->buildClient($r)->subscriptions->createForId($customer_id, $r->validated());
} catch (MollieApiException $e) {
    throw MollieExceptionMapper::map($e);    // SDK's eigen mapper — gooit Emeq-typed exception
}
```

Controller-laag (Phase 7's AccountSubscriptionController) vangt de Emeq-typed exceptions en mapt via `MollieUpstreamErrorMapper::mapException()` naar Hub-HTTP-response. **Manager mag exceptions doorgooien** — controller is verantwoordelijk voor HTTP-vertaling. **Uitzondering:** `NotFoundException` in `syncFromMollie()` → inline catch → state `unknown` (D-17).

---

### State-transition logging (geen audit-tabel)

**Source:** **GEEN ANALOG** — D-22 vraagt log-only, niet DB-rij
**Apply to:** Elke state-transition in AccountSubscriptionManager

```php
Log::info('account_subscription.transition', [
    'subscription_id' => $sub->id,
    'from' => $from->value,
    'to' => $to->value,
    'reason' => $reason ?? 'manual',
    'mollie_subscription_id' => $sub->mollie_subscription_id,
]);
```

Structured logging-key `account_subscription.transition` zodat productie-log-aggregators erop kunnen filteren. Geen raw credentials in log-context (fingerprint-only per `.ai/rules/global.md`).

---

### MollieConnectionContext-binding vóór SDK-call

**Source:** `app/Http/Controllers/Webhooks/MollieWebhookController.php` regel 84 + `app/Http/Middleware/ResolveMollieAccount.php` regel 62
**Apply to:** `AccountSubscriptionManager` + `SubscriptionWebhookHandler` + `PaymentWebhookHandler`

```php
app(MollieConnectionContext::class)->set($connection);
// nu pas Mollie::client()->... aanroepen — HubMollieCredentialResolver leest deze context
```

**Belangrijk:** `MollieConnectionContext` is `scoped`-bound in `AppServiceProvider` (per request). In een job (`syncFromMollie`-batch in v0.3+) moet de context expliciet per call gezet worden.

---

### Sanctum-auth + Account-resolutie inline (D-12)

**Source:** `app/Http/Controllers/Api/V1/OAuth/InitController.php` regels 19-31
**Apply to:** AccountSubscriptionController's `store`

```php
$validated = $request->validate([
    'account_external_id' => ['required', 'string'],
]);

/** @var Consumer $consumer */
$consumer = $request->user();

$account = $consumer->accounts()
    ->where('external_id', $validated['account_external_id'])
    ->firstOrFail();
```

In `CreateAccountSubscriptionRequest` zit Rule::exists al — de `firstOrFail()` is een runtime-safety-net.

---

### Test-double via StubsMollieClient-trait

**Source:** `tests/Concerns/StubsMollieClient.php` regels 112-152 + `setupMollieConsumer()` regels 676-684
**Apply to:** Alle Unit- en Feature-tests die Mollie-API hitten

```php
[$consumer, $token, $account, $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

$this->bindMollieStubs([
    'subscriptions' => fn (string $op, mixed $arg) => $this->makeSubscription([
        'id' => 'sub_test_1',
        'status' => 'active',
        'customerId' => $arg['customer_id'],
    ]),
]);
```

**Helper-methods uit trait:** `makeSubscription()`, `makeSubscriptionCollection()`, `makePayment()`, `setupMollieConsumer()`, `callMollie()` — direct hergebruikbaar.

**N.B.** Phase 7 tests roepen niet via `/v1/mollie/customers/{id}/subscriptions` aan (= Phase 5a route), maar via `/v1/account-subscriptions` (= Phase 7 nieuwe route). De stub-binding zelf is identiek — Mollie's `subscriptions->createForId()` wordt door de manager aangeroepen.

---

## No Analog Found

Files met geen close match in de bestaande codebase. Planner gebruikt CONTEXT.md decisions als bron:

| File | Role | Reden |
|---|---|---|
| `app/Billing/Account/SubscriptionStatus.php` | enum | Repo gebruikt momenteel `final class + public const` i.p.v. PHP-enums (per decision 03-02 voor TokenAbilities). Phase 7 is de eerste echte backed enum. Stijl-referentie: Laravel Boost PHP-rule "TitleCase voor Enum keys". |
| `app/Billing/Account/StateTransitions.php` | state-machine helper | Eerste state-machine in de Hub. Cashier-Mollie heeft een eigen interne machine maar die zit in vendor-code, niet als Hub-class. Stijl-referentie: `MollieUpstreamErrorMapper` (`final class` + static helpers). |
| `app/Webhooks/Mollie/WebhookPayloadRouter.php` | dispatcher | Eerste prefix-based-router in de Hub (D-15). Extracted uit `MollieWebhookController`, dus partial-analog is dat bestand zelf. |

---

## Metadata

**Analog-search scope:**
- `app/Models/` (5 modellen)
- `app/Http/Controllers/Api/V1/` (incl. Mollie/, OAuth/, Snelstart/, Billing/, Admin/)
- `app/Http/Controllers/Webhooks/`
- `app/Http/Requests/Api/V1/` (incl. Mollie/)
- `app/Http/Resources/Api/V1/`
- `app/Http/Middleware/`
- `app/Mollie/`
- `app/Support/` (Mollie + Snelstart)
- `app/Sanctum/`
- `app/Billing/` (incl. Exceptions/)
- `app/Jobs/`
- `database/factories/` (5 factories)
- `database/migrations/` (alle relevante 2026_05_14 + 2026_05_15)
- `tests/Concerns/`
- `tests/Feature/Api/V1/Mollie/` + `Webhooks/`
- `tests/Unit/Billing/`
- `tests/Integration/Billing/`

**Files scanned:** ~45
**Pattern extraction date:** 2026-05-15

**Key invariants ge-extracted:**
1. Hub-laag = business-logic (state-machine, manager, controllers) — SDK blijft thin (memory: `feedback_pass_through_sdk_pattern.md`).
2. Cross-Consumer-scope via `whereHas('account', fn ($q) => $q->where('consumer_id', ...))` op alle owned-resource-queries; 404 op miss (`not 403`).
3. `MollieConnectionContext::set()` vóór elke `Mollie::client()`-call (anders gooit context een `RuntimeException`).
4. Inline ability-guard helper-pattern (matches ConnectionController) wanneer route-level middleware niet de juiste multi-ability-OR levert.
5. `MollieUpstreamErrorMapper::mapException()` voor Hub-HTTP-response; `MollieExceptionMapper::map()` (SDK) voor exception-type-mapping in catch-blocks.
6. State-transitions log-only (D-22), geen DB-history-tabel in v0.2.
7. Migration-pattern: `Schema::create` + `DB::statement` voor partial-indexes (regel-voor-regel mirror van `2026_05_15_000001_create_pass_through_calls_table.php` + `2026_05_14_151327_add_active_unique_to_connections.php`).
8. Factory state-pattern: `forConnection()` / `pending()` / `active()` / `paused()` / `canceled()` als ConnectionFactory-states.
9. Integration-tests `@group integration` + skip-guard op env-key (analog: `IntegrationTestCase`).
10. PHPUnit (niet Pest) in de Hub; Pest blijft in SDK-packages.

---

*Phase: 07-account-level-subscriptions-use-case-b*
*Pattern mapping: 2026-05-15 — gebaseerd op CONTEXT D-01 t/m D-32 + bestaande Hub-code op master @ e42ee64*
