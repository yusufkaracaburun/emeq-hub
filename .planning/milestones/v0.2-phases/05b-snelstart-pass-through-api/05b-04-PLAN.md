---
phase: 05b-snelstart-pass-through-api
plan: 04
type: execute
wave: 2
depends_on: [05b-01]
files_modified:
  - app/Http/Controllers/Api/V1/AccountController.php
  - app/Http/Controllers/Api/V1/ConnectionController.php
  - app/Http/Requests/Api/V1/StoreAccountRequest.php
  - app/Http/Requests/Api/V1/StoreConnectionRequest.php
  - app/Http/Resources/Api/V1/AccountResource.php
  - app/Http/Resources/Api/V1/ConnectionResource.php
  - routes/api.php
  - tests/Feature/Api/V1/StoreAccountTest.php
  - tests/Feature/Api/V1/StoreConnectionTest.php
  - tests/Feature/Api/V1/ShowConnectionTest.php
  - tests/Feature/Api/V1/DestroyConnectionTest.php
autonomous: true
requirements: [HUB-05]
tags:
  - laravel
  - api
  - sanctum
  - form-requests
  - api-resources
  - phpunit

must_haves:
  truths:
    - "Een Consumer met `snelstart:write`-PAT kan een Account aanmaken via `POST /v1/accounts` (HUB-05 SC-1)"
    - "Een Consumer kan voor een eigen Account een Snelstart-Connection aanmaken via `POST /v1/connections`; raw credentials staan nooit in de response, alleen fingerprint (HUB-05 SC-2)"
    - "Cross-Consumer scoping: A's PAT → B's Account/Connection → 404 (HUB-05 SC-5)"
    - "Duplicate `(consumer_id, external_id)` op POST /v1/accounts → 409"
    - "Duplicate actieve Connection op `(account_id, provider)` → 409 (gebruikt bestaande Phase-3 unique partial-index)"
    - "`DELETE /v1/connections/{id}` zet `revoked_at = now()` en retourneert 204; revoked Connection-id retourneert 404 op vervolg-DELETE"
    - "Validation 422 met `errors`-object voor missende/incorrecte body-velden (Laravel default Form-Request gedrag)"
  artifacts:
    - path: "app/Http/Controllers/Api/V1/AccountController.php"
      provides: "POST /v1/accounts"
      exports: ["store"]
    - path: "app/Http/Controllers/Api/V1/ConnectionController.php"
      provides: "POST/GET/DELETE /v1/connections"
      exports: ["store", "show", "destroy"]
    - path: "app/Http/Requests/Api/V1/StoreAccountRequest.php"
      provides: "Form-Request voor POST /v1/accounts met `external_id` + `display_name` validatie"
      contains: "extends FormRequest"
    - path: "app/Http/Requests/Api/V1/StoreConnectionRequest.php"
      provides: "Form-Request voor POST /v1/connections (provider/credentials per-shape validatie)"
      contains: "extends FormRequest"
    - path: "app/Http/Resources/Api/V1/AccountResource.php"
      provides: "JSON-shape voor Account-response (id, external_id, display_name, created_at)"
      contains: "extends JsonResource"
    - path: "app/Http/Resources/Api/V1/ConnectionResource.php"
      provides: "JSON-shape voor Connection-response (id, account_id, provider, status, fingerprint, created_at) — GEEN raw credential-velden"
      contains: "extends JsonResource"
    - path: "routes/api.php"
      provides: "4 nieuwe routes onder auth:sanctum"
      contains: "Route::apiResource"
  key_links:
    - from: "POST /v1/accounts"
      to: "consumer_id van current Consumer (request->user())"
      via: "controller schrijft consumer_id niet vanuit request-body — alleen vanuit auth-context"
      pattern: "->user\\(\\)->accounts\\(\\)->create"
    - from: "POST /v1/connections"
      to: "Account scoped op current Consumer"
      via: "Account::where('consumer_id', $consumer->id)->where('id'|'external_id', ...)->firstOrFail() — 404 bij cross-Consumer"
      pattern: "(where|firstOrFail)"
    - from: "GET/DELETE /v1/connections/{id}"
      to: "Connection scoped op current Consumer via account-relatie"
      via: "Connection::whereHas('account', fn ($q) => $q->where('consumer_id', $consumer->id))"
      pattern: "whereHas"
---

<objective>
De drie provisioning-endpoints uit HUB-05 (POST /v1/accounts, POST /v1/connections, GET/DELETE /v1/connections/{id}) + één show-route, plus alle bijbehorende Form-Requests, API-Resources, routes en feature-tests.

Purpose: HUB-05 success criteria 1, 2, 5 — Consumers kunnen via API een Account aanmaken, een Snelstart-Connection vastleggen (encrypted, fingerprint-only response), en eigen Connections lezen + revoken. Cross-Consumer scoping consistent (404, niet 403).

Output: 2 controllers + 2 form-requests + 2 resources + routes-uitbreiding + 4 dedicated feature-tests (totaal ~25 test-cases).

**Niet** in dit plan: pass-through-route `/v1/snelstart/{path}`, middleware `ResolveSnelstartAccount`, audit-tabel-writes, `HubSnelstartCredentialResolver` binding, Scramble-route-discovery-test, SanctumAbilityTest-completion (alles in Plan 05).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md
@CLAUDE.md
@app/Http/Controllers/Api/V1/PingController.php
@app/Models/Consumer.php
@app/Models/Account.php
@app/Models/Connection.php
@app/Sanctum/TokenAbilities.php
@routes/api.php
@database/factories/ConnectionFactory.php
@database/factories/AccountFactory.php
@tests/Feature/Api/PingTest.php
@tests/Feature/Api/SanctumAbilityTest.php

<interfaces>
<!-- Bestaande Phase-3 contracten waar dit plan op leunt -->

Models (Phase 3):
```php
// app/Models/Consumer.php
class Consumer extends Authenticatable {
    use HasApiTokens;
    public function accounts(): HasMany; // hasMany(Account::class)
}

// app/Models/Account.php
#[Fillable(['consumer_id', 'external_id', 'display_name'])]
class Account extends Model {
    public function consumer(): BelongsTo;
    public function connections(): HasMany;
}

// app/Models/Connection.php
#[Fillable(['account_id', 'provider', 'status', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'client_key', 'subscription_key', 'subscription_id', 'metadata', 'revoked_at'])]
#[Hidden(['access_token', 'refresh_token', 'client_key', 'subscription_key'])]
class Connection extends Model {
    public function account(): BelongsTo;
    public function fingerprint(): ?string; // sha256(client_key|access_token)[0..12]
    // casts(): client_key, subscription_key, access_token, refresh_token zijn 'encrypted'
}
```

Abilities (uit Phase 3, geen wijziging in dit plan):
```php
final class TokenAbilities {
    public const SNELSTART_READ = 'snelstart:read';
    public const SNELSTART_WRITE = 'snelstart:write';
    public const MOLLIE_READ = 'mollie:read';
    public const MOLLIE_WRITE = 'mollie:write';
    public const CONSUMER_MANAGE_ACCOUNTS = 'consumer:manage-accounts';
    public const ADMIN = '*';
}
```

DB-constraints (Phase 3):
- `accounts (consumer_id, external_id)` is uniek (zie `2026_05_14_000002_create_accounts_table.php`; check tijdens read_first)
- `connections` heeft partial unique index op `(account_id, provider) WHERE revoked_at IS NULL` (zie `2026_05_14_151327_add_active_unique_to_connections.php`)

Ability-policy (CONTEXT.md `### PAT-ability-policy`):
| Endpoint | Required abilities (any of) |
|---|---|
| POST /v1/accounts | `snelstart:write`, `mollie:write`, `consumer:manage-accounts`, `*` |
| POST /v1/connections | `consumer:manage-accounts`, `<provider>:write` (bv. `snelstart:write`), `*` |
| GET /v1/connections/{id} | `snelstart:read`, `snelstart:write`, `consumer:manage-accounts`, `*` |
| DELETE /v1/connections/{id} | `consumer:manage-accounts`, `<provider>:write`, `*` |

Het `abilities:`-middleware (Sanctum) accepteert meerdere als AND; voor "any of" gebruiken we Laravel's eigen `tokenCan()`-check in een tiny custom middleware OF expliciete check binnen controllers. **Beslis bij plan-execute: gebruik per-controller `$request->user()->tokenCan(...)` met `abort_if`** — past bij `<deep_work_rules>`'s "concrete values" en is leesbaar in de controller. Een dedicated `AbilityAnyMiddleware` is overkill voor 4 endpoints; introduceer 'm alleen als Plan 05 (pass-through, 1 route) er ook bij gebaat is — anders inline.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Form-Requests + Resources voor Accounts en Connections</name>
  <files>
    app/Http/Requests/Api/V1/StoreAccountRequest.php,
    app/Http/Requests/Api/V1/StoreConnectionRequest.php,
    app/Http/Resources/Api/V1/AccountResource.php,
    app/Http/Resources/Api/V1/ConnectionResource.php
  </files>
  <behavior>
    - `StoreAccountRequest::rules()` valideert `external_id` (required, string, max 255, regex of niet — voor 5b: `['required', 'string', 'min:1', 'max:255']`) en `display_name` (nullable, string, max 255)
    - `StoreConnectionRequest::rules()` valideert `account_id` (required, integer, exists `accounts,id` GESCOPED op consumer — gebruik `Rule::exists('accounts', 'id')->where('consumer_id', $this->user()->id)`), `provider` (required, in `['snelstart']` — alleen snelstart in deze fase; Mollie 5a breidt later uit), en `credentials` (required, array). Onder `credentials.*`:
      - `credentials.client_key` (required_if provider snelstart, string, min 10)
      - `credentials.subscription_key` (required_if provider snelstart, string, min 10)
      - `credentials.subscription_id` (nullable, string)
    - `authorize()` retourneert `true` voor beide Form-Requests (ability-check zit in controller via `tokenCan` — Form-Requests pakken Laravel's validation-pad, ability is een aparte zorg)
    - `AccountResource::toArray()` returnt `['id', 'external_id', 'display_name', 'created_at']`
    - `ConnectionResource::toArray()` returnt `['id', 'account_id', 'provider', 'status', 'fingerprint', 'revoked_at', 'created_at']` — GEEN `client_key`, `subscription_key`, `access_token`, `refresh_token`, `subscription_id`. `fingerprint` gebruikt de bestaande accessor `$this->fingerprint()`.
  </behavior>
  <read_first>
    - app/Http/Controllers/Api/V1/PingController.php (namespace-pattern voor `App\Http\Controllers\Api\V1\*`)
    - app/Models/Connection.php (`#[Fillable]` + `#[Hidden]` + `fingerprint()`-accessor)
    - app/Models/Account.php (`#[Fillable]`)
    - database/migrations/2026_05_14_000002_create_accounts_table.php (unique constraint shape — `unique('consumer_id', 'external_id')`)
    - database/migrations/2026_05_14_151327_add_active_unique_to_connections.php (partial unique index — beheerst de 409 op POST /v1/connections)
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md `### Provisioning-endpoints` + `### Error-response format`
  </read_first>
  <action>
    Genereer scaffolding:
    ```
    php artisan make:request Api/V1/StoreAccountRequest --no-interaction
    php artisan make:request Api/V1/StoreConnectionRequest --no-interaction
    php artisan make:resource Api/V1/AccountResource --no-interaction
    php artisan make:resource Api/V1/ConnectionResource --no-interaction
    ```

    Pas `StoreAccountRequest` aan tot:

    ```php
    <?php

    namespace App\Http\Requests\Api\V1;

    use Illuminate\Foundation\Http\FormRequest;

    class StoreAccountRequest extends FormRequest
    {
        public function authorize(): bool
        {
            return true; // ability-check in controller
        }

        /**
         * @return array<string, mixed>
         */
        public function rules(): array
        {
            return [
                'external_id'  => ['required', 'string', 'min:1', 'max:255'],
                'display_name' => ['nullable', 'string', 'max:255'],
            ];
        }
    }
    ```

    Pas `StoreConnectionRequest` aan tot:

    ```php
    <?php

    namespace App\Http\Requests\Api\V1;

    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Validation\Rule;

    class StoreConnectionRequest extends FormRequest
    {
        public function authorize(): bool
        {
            return true; // ability-check in controller
        }

        /**
         * @return array<string, mixed>
         */
        public function rules(): array
        {
            $consumerId = (int) $this->user()?->getKey();

            return [
                'account_id' => [
                    'required',
                    'integer',
                    Rule::exists('accounts', 'id')->where('consumer_id', $consumerId),
                ],
                'provider' => ['required', 'string', Rule::in(['snelstart'])],
                'credentials' => ['required', 'array'],
                'credentials.client_key' => ['required_if:provider,snelstart', 'string', 'min:10'],
                'credentials.subscription_key' => ['required_if:provider,snelstart', 'string', 'min:10'],
                'credentials.subscription_id' => ['nullable', 'string', 'max:255'],
            ];
        }
    }
    ```

    Pas `AccountResource` aan tot:

    ```php
    <?php

    namespace App\Http\Resources\Api\V1;

    use Illuminate\Http\Request;
    use Illuminate\Http\Resources\Json\JsonResource;

    /**
     * @mixin \App\Models\Account
     */
    class AccountResource extends JsonResource
    {
        /**
         * @return array<string, mixed>
         */
        public function toArray(Request $request): array
        {
            return [
                'id'           => $this->id,
                'external_id'  => $this->external_id,
                'display_name' => $this->display_name,
                'created_at'   => $this->created_at?->toIso8601String(),
            ];
        }
    }
    ```

    Pas `ConnectionResource` aan tot:

    ```php
    <?php

    namespace App\Http\Resources\Api\V1;

    use Illuminate\Http\Request;
    use Illuminate\Http\Resources\Json\JsonResource;

    /**
     * @mixin \App\Models\Connection
     */
    class ConnectionResource extends JsonResource
    {
        /**
         * @return array<string, mixed>
         */
        public function toArray(Request $request): array
        {
            return [
                'id'          => $this->id,
                'account_id'  => $this->account_id,
                'provider'    => $this->provider,
                'status'      => $this->status,
                'fingerprint' => $this->fingerprint(),
                'revoked_at'  => $this->revoked_at?->toIso8601String(),
                'created_at'  => $this->created_at?->toIso8601String(),
            ];
        }
    }
    ```

    Run pint: `vendor/bin/pint --dirty --format agent`.

    **Geen test in deze task** — Form-Requests en Resources worden indirect getest door de controller-feature-tests in Task 3.
  </action>
  <verify>
    <automated>php artisan route:list 2>&1 >/dev/null && grep -c "class StoreAccountRequest extends FormRequest" app/Http/Requests/Api/V1/StoreAccountRequest.php</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "class StoreAccountRequest extends FormRequest" app/Http/Requests/Api/V1/StoreAccountRequest.php` == 1
    - `grep -c "class StoreConnectionRequest extends FormRequest" app/Http/Requests/Api/V1/StoreConnectionRequest.php` == 1
    - `grep -c "class AccountResource extends JsonResource" app/Http/Resources/Api/V1/AccountResource.php` == 1
    - `grep -c "class ConnectionResource extends JsonResource" app/Http/Resources/Api/V1/ConnectionResource.php` == 1
    - `grep -c "fingerprint" app/Http/Resources/Api/V1/ConnectionResource.php` == 1
    - `grep -ciE "(client_key|subscription_key|access_token|refresh_token)" app/Http/Resources/Api/V1/ConnectionResource.php` == 0
    - `grep -c "Rule::exists" app/Http/Requests/Api/V1/StoreConnectionRequest.php` == 1
    - `php -l app/Http/Requests/Api/V1/StoreAccountRequest.php app/Http/Requests/Api/V1/StoreConnectionRequest.php app/Http/Resources/Api/V1/AccountResource.php app/Http/Resources/Api/V1/ConnectionResource.php` exit 0
    - `vendor/bin/pint --test --dirty` exit 0
  </acceptance_criteria>
  <done>4 files staan op exact deze pad-en-naam-conventie, validatie-rules + resource-shapes matchen CONTEXT.md, geen raw-credential-velden in ConnectionResource.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: AccountController + ConnectionController + routes</name>
  <files>
    app/Http/Controllers/Api/V1/AccountController.php,
    app/Http/Controllers/Api/V1/ConnectionController.php,
    routes/api.php
  </files>
  <behavior>
    - `AccountController::store(StoreAccountRequest)` maakt een Account via `$request->user()->accounts()->create([...])`, returnt `AccountResource` met 201. Cross-Consumer onmogelijk: `consumer_id` komt ALLEEN uit auth-context, niet uit request-body. Ability-check vooraf: `abort_unless($request->user()->tokenCan('snelstart:write') || tokenCan('mollie:write') || tokenCan('consumer:manage-accounts') || tokenCan('*'), 403, 'insufficient_ability')`.
    - Duplicate `(consumer_id, external_id)` → vang Postgres `UniqueConstraintViolationException` (of `QueryException` met sqlstate 23505) en return 409 met `{error:'account_exists', message:'Account met deze external_id bestaat al voor deze Consumer'}`. **Of** check eerst met een `where('external_id')->exists()` — beide acceptabel; race-condition-veiligheid via try/catch is robuuster.
    - `ConnectionController::store(StoreConnectionRequest)`: ability-check `consumer:manage-accounts || snelstart:write || *`. Account is al gescoped via Form-Request's `Rule::exists`. Maakt Connection via `$account->connections()->create(['provider' => $request->provider, 'status' => 'active', 'client_key' => $request->input('credentials.client_key'), 'subscription_key' => $request->input('credentials.subscription_key'), 'subscription_id' => $request->input('credentials.subscription_id'), 'metadata' => null])`. Encrypted-casts handelen de encryptie af. Duplicate active op `(account_id, provider)` (partial unique index uit Phase 3) → 409 met `{error:'connection_exists', message:'Een actieve Snelstart-Connection bestaat al voor dit Account'}`. Returnt `ConnectionResource` met 201.
    - `ConnectionController::show(int $id)`: ability `snelstart:read || snelstart:write || consumer:manage-accounts || *`. Find Connection scoped via `Connection::whereHas('account', fn ($q) => $q->where('consumer_id', $request->user()->id))->find($id)`. Geen match → 404 met `{error:'connection_not_found'}`. Match → 200 met `ConnectionResource`.
    - `ConnectionController::destroy(int $id)`: ability `consumer:manage-accounts || snelstart:write || *`. Find met dezelfde scoping. Geen match → 404. Reeds revoked (`revoked_at !== null`) → 404 (idempotency-keuze: één-shot revoke; **document deze keuze in plan-action**: revoked Connection blijft leesbaar via GET maar staat geen tweede DELETE meer toe). Match → `$connection->update(['revoked_at' => now()])` + return `response()->noContent()` (204).
    - Routes registreren in `routes/api.php` onder de bestaande `Route::middleware('auth:sanctum')->group(...)`:
      ```php
      Route::post('/accounts', [AccountController::class, 'store'])->name('api.accounts.store');
      Route::post('/connections', [ConnectionController::class, 'store'])->name('api.connections.store');
      Route::get('/connections/{connection}', [ConnectionController::class, 'show'])->name('api.connections.show');
      Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy'])->name('api.connections.destroy');
      ```
      **Niet** `Route::apiResource` gebruiken: te veel onnodige routes (index/update/edit/create) die we niet implementeren.
      **Niet** route-model-binding op `{connection}` zonder eigen scoping — gebruik primitive int en doe scoping in controller (cross-Consumer = 404 zonder dat 403-info-disclosure mogelijk wordt).
  </behavior>
  <read_first>
    - app/Http/Controllers/Api/V1/PingController.php (single-action `__invoke` pattern; deze controllers worden multi-action dus `class extends Controller` met named methods)
    - app/Http/Controllers/Controller.php (lege abstract base class — check dat deze bestaat)
    - routes/api.php (bestaande structuur — moet binnen `auth:sanctum`-group)
    - app/Models/Connection.php (`#[Fillable]` bevat al `client_key`, `subscription_key`, `subscription_id` — `create()` werkt out-of-the-box)
    - bootstrap/app.php (`apiPrefix: 'v1'` — routes worden automatisch `/v1/*` zonder expliciete prefix in routes/api.php)
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md `### PAT-ability-policy` (ability-tabel)
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md `### Error-response format` (JSON-envelope: `{error: snake_case, message: NL, details?: {}}`)
  </read_first>
  <action>
    Genereer scaffolding:
    ```
    php artisan make:controller Api/V1/AccountController --no-interaction
    php artisan make:controller Api/V1/ConnectionController --no-interaction
    ```

    **`AccountController.php`**:

    ```php
    <?php

    namespace App\Http\Controllers\Api\V1;

    use App\Http\Controllers\Controller;
    use App\Http\Requests\Api\V1\StoreAccountRequest;
    use App\Http\Resources\Api\V1\AccountResource;
    use App\Sanctum\TokenAbilities;
    use Illuminate\Database\UniqueConstraintViolationException;
    use Illuminate\Http\JsonResponse;
    use Symfony\Component\HttpFoundation\Response;

    class AccountController extends Controller
    {
        public function store(StoreAccountRequest $request): JsonResponse|AccountResource
        {
            $this->guardAbility($request, [
                TokenAbilities::SNELSTART_WRITE,
                TokenAbilities::MOLLIE_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
                TokenAbilities::ADMIN,
            ]);

            try {
                $account = $request->user()->accounts()->create([
                    'external_id'  => $request->string('external_id')->toString(),
                    'display_name' => $request->input('display_name'),
                ]);
            } catch (UniqueConstraintViolationException) {
                return response()->json([
                    'error'   => 'account_exists',
                    'message' => 'Account met deze external_id bestaat al voor deze Consumer.',
                ], Response::HTTP_CONFLICT);
            }

            return (new AccountResource($account))->response()->setStatusCode(Response::HTTP_CREATED);
        }

        /**
         * @param  list<string>  $allowed
         */
        private function guardAbility($request, array $allowed): void
        {
            $token = $request->user()?->currentAccessToken();
            $has   = $token && collect($allowed)->contains(fn (string $ability) => $token->can($ability));
            abort_unless($has, Response::HTTP_FORBIDDEN, 'insufficient_ability');
        }
    }
    ```

    **`ConnectionController.php`** volgt hetzelfde pattern. Belangrijke punten:
    - In `store()`: `Account::where('consumer_id', $request->user()->id)->findOrFail($request->integer('account_id'))` — extra defense-in-depth bovenop `Rule::exists` in de Form-Request
    - In `store()`: catch `UniqueConstraintViolationException` voor de active-Connection-409
    - In `show()`/`destroy()`: gebruik `Connection::whereHas('account', fn ($q) => $q->where('consumer_id', $consumerId))->find($id)`; null → 404 `{error:'connection_not_found'}`
    - In `destroy()`: na `find` ook check `$connection->revoked_at === null` — anders 404 (revoked is "weg" voor DELETE-doeleinden, zelfde info-disclosure-policy)
    - `revoked_at` update via `$connection->update(['revoked_at' => now()])`; return `response()->noContent()` (204)
    - `findOrFail` op een non-existing id zou een ModelNotFoundException gooien → standaard Laravel-404; om consistent JSON-envelope te geven, gebruik expliciete `find()` + manuele JSON-response

    **`routes/api.php`** uitbreiden (binnen bestaande `Route::middleware('auth:sanctum')->group(...)`):

    ```php
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/ping', PingController::class)->name('api.ping');

        Route::post('/accounts', [AccountController::class, 'store'])->name('api.accounts.store');

        Route::post('/connections', [ConnectionController::class, 'store'])->name('api.connections.store');
        Route::get('/connections/{connection}', [ConnectionController::class, 'show'])->name('api.connections.show');
        Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy'])->name('api.connections.destroy');
    });
    ```

    Imports bovenaan `routes/api.php` toevoegen:
    ```php
    use App\Http\Controllers\Api\V1\AccountController;
    use App\Http\Controllers\Api\V1\ConnectionController;
    ```

    Run pint + route-list-smoke:
    ```
    vendor/bin/pint --dirty --format agent
    php artisan route:list --path=v1 --except-vendor
    ```
  </action>
  <verify>
    <automated>php artisan route:list --path=v1 --except-vendor 2>&1 | grep -cE "(POST.*v1/accounts|POST.*v1/connections|GET.*v1/connections|DELETE.*v1/connections)"</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "class AccountController extends Controller" app/Http/Controllers/Api/V1/AccountController.php` == 1
    - `grep -c "class ConnectionController extends Controller" app/Http/Controllers/Api/V1/ConnectionController.php` == 1
    - `grep -c "TokenAbilities::" app/Http/Controllers/Api/V1/AccountController.php` >= 3
    - `grep -c "TokenAbilities::" app/Http/Controllers/Api/V1/ConnectionController.php` >= 3
    - `grep -cE "(account_exists|connection_exists|connection_not_found)" app/Http/Controllers/Api/V1/AccountController.php app/Http/Controllers/Api/V1/ConnectionController.php` >= 3
    - `grep -c "UniqueConstraintViolationException" app/Http/Controllers/Api/V1/AccountController.php app/Http/Controllers/Api/V1/ConnectionController.php` >= 2
    - `grep -c "whereHas\\|->user()->accounts()" app/Http/Controllers/Api/V1/ConnectionController.php` >= 1
    - `php artisan route:list --path=v1 --except-vendor` output bevat exact 5 v1-routes (ping + 4 nieuwe)
    - `php -l app/Http/Controllers/Api/V1/AccountController.php app/Http/Controllers/Api/V1/ConnectionController.php` exit 0
  </acceptance_criteria>
  <done>4 nieuwe routes staan in `route:list`, controllers compileren, ability-check + cross-Consumer scoping inline aanwezig.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Feature-tests voor alle 4 provisioning-endpoints</name>
  <files>
    tests/Feature/Api/V1/StoreAccountTest.php,
    tests/Feature/Api/V1/StoreConnectionTest.php,
    tests/Feature/Api/V1/ShowConnectionTest.php,
    tests/Feature/Api/V1/DestroyConnectionTest.php
  </files>
  <behavior>
    Pattern voor alle 4 tests: PHPUnit `class XxxTest extends TestCase` met `use RefreshDatabase;` + namespace `Tests\Feature\Api\V1`. Authenticatie via `withHeader('Authorization', 'Bearer '.$token)` waarbij `$token = $consumer->createToken('test', [<abilities>])->plainTextToken`. Géén `Sanctum::actingAs` (geeft niet-realistische token-shape).

    **`StoreAccountTest`** (~6 cases):
    1. `test_creates_account_with_snelstart_write_ability_returns_201_and_resource_shape` — consumer + PAT met `snelstart:write`; POST `/v1/accounts` met `{external_id:'school-007', display_name:'School 7'}`; assert 201, JSON `data.external_id === 'school-007'`, `data.display_name === 'School 7'`, `data.id` is integer, `data.created_at` is ISO-8601
    2. `test_consumer_manage_accounts_ability_can_also_create_account` — PAT met alleen `consumer:manage-accounts`; assert 201
    3. `test_mollie_write_ability_can_also_create_account` — assert 201 (Account is provider-agnostisch)
    4. `test_token_without_required_ability_returns_403` — PAT met alleen `mollie:read`; assert 403
    5. `test_validation_error_returns_422_with_errors_object` — POST zonder body; assert 422 + key `errors.external_id`
    6. `test_duplicate_external_id_for_same_consumer_returns_409_with_account_exists` — eerste POST: 201; tweede POST met identieke external_id: 409, `error === 'account_exists'`
    7. `test_unauthenticated_request_returns_401`

    **`StoreConnectionTest`** (~8 cases):
    1. `test_creates_snelstart_connection_with_encrypted_credentials_and_returns_fingerprint_only` — POST met `provider:'snelstart'`, credentials. Assert 201; response keys: `id, account_id, provider, status, fingerprint, revoked_at, created_at` (geen `client_key`, `subscription_key`, `subscription_id`). Assert `fingerprint` is een 12-char hex-string. Assert DB-row: `DB::table('connections')->find($id)->client_key` is NIET de plain string (encryption-at-rest sanity-check, hergebruik Phase 3 pattern).
    2. `test_response_never_contains_raw_credentials` — explicit grep: `assertDontSeeText('CK-test-clientkey-1234567890')` op de response-body
    3. `test_consumer_manage_accounts_ability_can_create_connection`
    4. `test_token_without_required_ability_returns_403` — PAT alleen `snelstart:read`; assert 403
    5. `test_cross_consumer_account_id_returns_422_via_rule_exists` — Consumer A maakt Account; Consumer B doet POST `/v1/connections` met dat `account_id`; assert 422 (Rule::exists fail) `errors.account_id`. **Niet** 404 hier omdat Rule::exists 422-validation-fail produceert; cross-Consumer-scope op de Account-route zit in Form-Request — semantically dezelfde "info-disclosure-veilig" (de Consumer leert niet of het Account bestaat-maar-niet-van-mij, alleen "account_id is invalid").
    6. `test_duplicate_active_snelstart_connection_for_same_account_returns_409` — maak één Connection; tweede POST met zelfde `(account, provider)`; assert 409 `error === 'connection_exists'`
    7. `test_revoked_connection_does_not_block_new_connection_creation` — maak Connection, zet `revoked_at = now()`, POST nieuwe Connection met zelfde `(account, snelstart)`; assert 201 (partial unique index laat revoked rijen toe)
    8. `test_validation_error_for_missing_credentials_returns_422` — POST zonder `credentials.client_key`; assert 422 + `errors.credentials.client_key`

    **`ShowConnectionTest`** (~4 cases):
    1. `test_consumer_can_read_own_connection_returns_200_with_fingerprint`
    2. `test_other_consumers_connection_returns_404_with_connection_not_found` — Consumer A's Connection met Consumer B's PAT → 404, `error === 'connection_not_found'`
    3. `test_token_without_required_ability_returns_403`
    4. `test_revoked_connection_is_still_returnable_via_show` — als CONTEXT.md `### Provisioning-endpoints` zegt: *"Toekomstige Phase 4: trigger upstream-revoke … `DELETE` zet `revoked_at`"*. Show mag revoked rijen blootleggen (audit-transparantie); destroy niet meer toestaan op revoked. Assert 200 + `data.revoked_at !== null`.

    **`DestroyConnectionTest`** (~5 cases):
    1. `test_consumer_can_revoke_own_connection_returns_204_and_sets_revoked_at`
    2. `test_other_consumers_connection_returns_404_on_delete`
    3. `test_already_revoked_connection_returns_404_on_second_delete` (idempotency-keuze gedocumenteerd in CONTEXT.md `<deferred>`-stijl: revoked = "weg" voor DELETE)
    4. `test_token_without_required_ability_returns_403`
    5. `test_revoked_at_persists_after_delete_call` — assert via DB-query dat `revoked_at` niet null is na DELETE
  </behavior>
  <read_first>
    - tests/Feature/Api/PingTest.php (pattern voor `Bearer`-token-aanmaak via `createToken`)
    - tests/Feature/Api/SanctumAbilityTest.php (pattern voor ability-specific PAT-aanmaak)
    - database/factories/ConnectionFactory.php (`forSnelstart()` state — gebruik om bestaande Connections op te zetten in show/destroy-tests)
    - database/factories/AccountFactory.php
    - app/Models/Connection.php (`fingerprint()`-method: assert het via response-body)
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md `### Provisioning-endpoints` + `### PAT-ability-policy`
  </read_first>
  <action>
    Genereer met `php artisan make:test --phpunit Api/V1/StoreAccountTest --no-interaction` (en analoog voor de andere 3). Pas namespace aan naar `Tests\Feature\Api\V1`.

    Schrijf voor elke test-class de cases per behavior-sectie hierboven. Stub-helper-methods toegestaan (bv. `private function consumerWithToken(array $abilities): array { ... }` voor DRY).

    Encryption-at-rest assertion voor StoreConnectionTest case 1 hergebruikt het Phase-3 pattern uit `ConnectionEncryptionTest`:
    ```php
    $raw = DB::table('connections')->where('id', $id)->value('client_key');
    $this->assertNotSame('CK-test-clientkey-1234567890', $raw);
    $this->assertNotEmpty($raw);
    ```

    `assertDontSeeText` werkt op response-content; gebruik tegen de plain-text credential-waarde.

    Run pint + alle 4 tests:
    ```
    vendor/bin/pint --dirty --format agent
    php artisan test --compact --filter='StoreAccountTest|StoreConnectionTest|ShowConnectionTest|DestroyConnectionTest'
    ```
  </action>
  <verify>
    <automated>php artisan test --compact --filter='StoreAccountTest|StoreConnectionTest|ShowConnectionTest|DestroyConnectionTest'</automated>
  </verify>
  <acceptance_criteria>
    - `grep -cE "public function test_" tests/Feature/Api/V1/StoreAccountTest.php` >= 6
    - `grep -cE "public function test_" tests/Feature/Api/V1/StoreConnectionTest.php` >= 8
    - `grep -cE "public function test_" tests/Feature/Api/V1/ShowConnectionTest.php` >= 4
    - `grep -cE "public function test_" tests/Feature/Api/V1/DestroyConnectionTest.php` >= 5
    - Totaal >= 23 nieuwe test-cases in deze plan
    - `php artisan test --compact --filter='StoreAccountTest|StoreConnectionTest|ShowConnectionTest|DestroyConnectionTest'` exit 0
    - Volledige Hub-suite blijft groen na deze plan (Phase 3 niet brekend): `php artisan test --compact` exit 0
    - `grep -ciE "(CK-test|SK-test|access_token_.{5})" tests/Feature/Api/V1/StoreConnectionTest.php` >= 1 (tests gebruiken plain test-credentials zodat we tegen de raw-strings kunnen asserteren — én het test-bestand bevat dus de plain strings; dat is OK want het is een test-fixture, geen prod-secret)
  </acceptance_criteria>
  <done>23+ test-cases groen, alle 4 success criteria afgedekt voor provisioning (HUB-05 SC-1, SC-2, SC-5 deels), encryption-at-rest in de POST-flow opnieuw bewezen, cross-Consumer 404 voor show/destroy en 422 voor store, idempotente revoke gedocumenteerd via test.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Bearer PAT → Consumer-identity (auth) | Sanctum's resolveToken — al gevalideerd in Phase 3 |
| Consumer-identity → Account/Connection-scoping | Deze fase bewijst routes-laag scoping (Phase 3-04 deed alleen Eloquent-laag) |
| Request-body credentials → DB-encrypted-rij | Eloquent encrypted-casts (Phase 3) doen het werk; deze fase bewijst end-to-end via test |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05b-12 | Spoofing | Consumer A POST'/connections met `account_id` van Consumer B | mitigate | `StoreConnectionRequest::rules()` gebruikt `Rule::exists('accounts','id')->where('consumer_id', $this->user()->id)` → 422 op cross-Consumer. Defense-in-depth in controller: `Account::where('consumer_id', $userId)->findOrFail($id)`. Test 5 in StoreConnectionTest valideert. |
| T-05b-13 | Information disclosure | Cross-Consumer GET/DELETE returnt 403 i.p.v. 404 (lekt existence) | mitigate | Controllers retourneren 404 met `connection_not_found` voor cross-Consumer-Connection-id's (CONTEXT.md beslissing). Test 2 in ShowConnectionTest + DestroyConnectionTest valideert. |
| T-05b-14 | Information disclosure | Raw `client_key` of `subscription_key` in response van POST /v1/connections | mitigate | `ConnectionResource::toArray()` whitelist alleen `id, account_id, provider, status, fingerprint, revoked_at, created_at`. Test 1+2 van StoreConnectionTest grep-test op response-body voor afwezigheid van plain credential-string. |
| T-05b-15 | Tampering | Consumer schrijft `consumer_id` in request-body POST /v1/accounts | mitigate | `StoreAccountRequest::rules()` accepteert geen `consumer_id`; Controller schrijft `$request->user()->accounts()->create(['external_id'=>..., 'display_name'=>...])` — `consumer_id` komt uit auth-context, nooit uit body. Mass-assignment-protection via Account `#[Fillable]` excludeert `consumer_id` impliciet (alleen `external_id, display_name`). |
| T-05b-16 | Elevation of privilege | PAT met alleen `snelstart:read` doet POST /v1/connections | mitigate | Inline `tokenCan()`-check in controller; test 4 van StoreConnectionTest valideert 403. |
| T-05b-17 | Repudiation | Geen audit-trail voor revoke | accept | `revoked_at`-timestamp staat op de Connection-rij zelf; Phase 9 admin-UI kan ernaar tonen. Geen aparte audit-tabel voor revoke-events in 5b (overlapping met `pass_through_calls` — DELETE /v1/connections is geen pass-through dus geen rij in die tabel). |
</threat_model>

<verification>
- 23+ feature-tests groen
- `php artisan route:list --path=v1 --except-vendor` toont 5 v1-routes (ping + 4 nieuwe)
- Phase 3-tests blijven groen
- Pint clean
- Geen wijziging onder `packages/snelstart-api/`
</verification>

<success_criteria>
HUB-05 success criteria gedekt door dit plan:
- SC-1: ✅ POST /v1/accounts werkt met snelstart:write-PAT (StoreAccountTest case 1)
- SC-2: ✅ POST /v1/connections slaat encrypted op, returnt fingerprint (StoreConnectionTest case 1 + 2)
- SC-5: partial ✅ — provisioning-deel afgedekt; pass-through-deel komt in Plan 05
- SC-8: voorbereid — routes staan in `route:list`, Scramble-discovery-test komt in Plan 05

Plan 05 kan starten met:
- `/v1/accounts` en `/v1/connections` routes bestaan
- `ResolveSnelstartAccount`-middleware kan een Connection.id krijgen via `X-Account-Id` → Account → Connection
- Tests-suite is groen als baseline
</success_criteria>

<output>
Na completion: `.planning/phases/05b-snelstart-pass-through-api/05b-04-SUMMARY.md` per template. Notitie naar Plan 05:
- routes/api.php wordt opnieuw aangeraakt (catch-all pass-through-route toevoegen + middleware-alias). Plan 04 gemerget vóórdat Plan 05 begint.
- Pattern voor ability-guard inline in controller (zie `AccountController::guardAbility()`) — Plan 05 hergebruikt het in `PassThroughController` óf extract het naar een trait/helper als duplicatie meer dan 3× wordt.
</output>
