# Coding Conventions

**Analysis Date:** 2026-05-15

## Taalbeleid (uit `.ai/rules/global.md`)

- **Code, identifiers, technische comments:** Engels (volg OSS- en API-vocabulair).
- **Commit-messages, PR-beschrijvingen, planning-docs, conversatie:** Nederlands.
- **Partner-domeintermen volgen de partner-API:**
  - Snelstart spreekt Nederlands — `Relaties`, `Verkoopfacturen`, `clientKey`, `subscriptionKey` blijven Nederlands.
  - Mollie spreekt Engels — `Payments`, `Customers`, `Mandates`, `Subscriptions` blijven Engels.
  - Niet vertalen, niet mixen.

## Naming Patterns

**Files (`app/`):**
- `PascalCase.php` matching de FQCN (`App\Models\Connection` → `app/Models/Connection.php`).
- Eén class per file (PSR-4 autoload via `composer.json`).
- Controllers eindigen op `Controller` (`PaymentsController.php`, `ConnectionController.php`).
- Form-Requests eindigen op `Request` (`StoreConnectionRequest.php`, `CreatePaymentRequest.php`).
- API-Resources eindigen op `Resource` (`ConnectionResource.php`, `AccountResource.php`).
- Middleware verb-prefix + noun (`ResolveMollieAccount.php`, `RequireCashierWebhookSecret.php`, `EnsureEmeqAdminToken.php`).
- Console-commands matchen de signature in artisan-case (`PruneOAuthPendingConnections.php` → `oauth:prune-pending`, `HubConsumerCreate.php` → `hub:consumer:create`).
- Test-traits leven onder `tests/Concerns/` en starten met een werkwoord (`PrimesSnelstartTokenCache.php`, `StubsMollieClient.php`, `BindsMollieConnectionContext.php`).

**Classes & Namespaces:**
- Namespaces volgen de provider-laag: `App\Mollie\…`, `App\Support\Mollie\…`, `App\Support\Snelstart\…`, `App\OAuth\Mollie\…`, `App\OAuth\Contracts\…`.
- Interfaces leven onder `Contracts/` (`App\OAuth\Contracts\OAuthFlow`).
- Test-doubles & fakes leven onder `Testing/` binnen de feature-namespace (`App\OAuth\Testing\FakeOAuthFlow`).

**Methods & Variables:**
- `camelCase` voor methods en lokale variabelen (`getAuthorizationUrl`, `exchangeCode`, `refreshToken`, `findOwnedConnection`).
- Booleans als predicates: `isXxx`, `hasXxx`, `canXxx`. Geen `flag`/`status` als boolean-naam.
- Eloquent-attributes blijven `snake_case` matching de DB-kolom (`access_token`, `client_key`, `oauth_state_expires_at`, `connection_id`).
- Sanctum-ability-constants in `SCREAMING_SNAKE_CASE` met `:`-namespace in de string-waarde (`SNELSTART_READ = 'snelstart:read'`, `MOLLIE_WRITE = 'mollie:write'`, `ADMIN = '*'`). Zie `app/Sanctum/TokenAbilities.php`.

**Test methods:**
- Snake-case `test_xxx_yyy` met beschrijvende zin: `test_snelstart_client_key_is_encrypted_at_rest`, `test_tampered_signature_returns_400_and_no_dispatch`, `test_get_relaties_with_top_5_query_string_is_proxied_verbatim_to_sdk`. Zie `tests/Feature/ConnectionEncryptionTest.php`.
- Geen `@test`-annotatie nodig — de `test_`-prefix triggert PHPUnit 12 al.
- Voor Pest-tests in SDK-packages: `it('does X', …)` / `it('throws Y when Z', …)`. Zie `packages/mollie-api/tests/PackageSmokeTest.php`.

**Enums:**
- TitleCase keys (Laravel-Boost-conventie): `FavoritePerson`, `Monthly`. Geen enums actief in deze repo per 2026-05-15 — pattern volgen zodra ze landen.

## Code Style

**Formatter:** Laravel Pint v1.27 (default preset, geen `pint.json` aanwezig — pure PSR-12 + Laravel overrides).

```bash
vendor/bin/pint --dirty --format agent   # vóór commit; alleen gewijzigde files
```

- **NIET** `vendor/bin/pint --test` draaien — gewoon `pint --format agent` om fixes meteen toe te passen.
- `--dirty` scope't naar `git status`-changed files; faster en safer dan full-repo.

**EditorConfig (`.editorconfig`):**
- 4 spaces indent, LF line-endings, UTF-8, trim trailing whitespace.
- 2 spaces voor `*.{yml,yaml}`.
- `*.md` behoudt trailing whitespace (markdown hard-breaks).

**Linting:** geen ESLint/Larastan in de Hub (`composer.json` heeft géén `larastan` dependency). SDK-packages (`packages/mollie-api/`, `packages/snelstart-api/`) hebben elk een `phpstan.neon.dist` — daar wel statisch checken.

## `declare(strict_types=1)` policy

**Niet-uniform door de repo** — twee patronen naast elkaar (eerlijk benoemd, niet uitmiddelen per `.ai/rules/engineering.md`):

- **Wel `declare(strict_types=1);`** in nieuwer geschreven lagen: alle `app/Billing/`, `app/Support/Snelstart/`, `app/Support/Mollie/`, `app/Http/Controllers/Api/V1/Mollie/`, `app/Http/Controllers/Api/V1/Billing/`. Bijv. `app/Support/Snelstart/UpstreamErrorMapper.php:3`.
- **Geen `declare`** in oudere lagen: `app/Models/*.php` (Connection, Consumer, Account, PassThroughCall, User), `app/Http/Middleware/ResolveMollieAccount.php`, `app/OAuth/OAuthFlowRegistry.php`, `app/Console/Commands/PruneOAuthPendingConnections.php`.

**Regel voor nieuwe files:** `declare(strict_types=1);` toevoegen op regel 3 (na `<?php`, vóór `namespace`). Bestaande files niet retroactief converteren in een feature-PR (chirurgisch wijzigen, geen drive-by-refactor).

In `tests/` geldt hetzelfde — ~41% van de testfiles heeft `declare(strict_types=1);` (27/66), recente Mollie- en Billing-tests wél, oudere Snelstart-feature-tests niet.

## PHP 8.4 Patterns (Laravel-Boost-foundation)

- **Constructor property promotion** voor alle DI: `public function __construct(private readonly HttpFactory $http, private readonly ConfigRepository $config) {}`. Zie `app/OAuth/Mollie/MollieConnectOAuthFlow.php:14-17`.
- **`readonly`** op DI-properties (geen herbinden in service-lifetime). Required op data-DTOs.
- **Explicit return types** verplicht op alle public/protected methods: `public function fingerprint(): ?string`, `public function getAuthorizationUrl(Account $account, array $scopes, string $state): string`. Geen impliciete `mixed`.
- **Typed parameters** verplicht — nullable met `?Type`, union waar nodig (`JsonResponse|ConnectionResource`, `Subscription|SubscriptionCollection|Throwable`).
- **`final` op classes** die niet bedoeld zijn voor extension: `final class MollieConnectOAuthFlow`, `final class OAuthFlowRegistry`, `final class PlanResolver`, `final class TokenAbilities`, `final class UpstreamErrorMapper`, `final class UnknownPlanException`. Models, Controllers, Tests blijven non-final (Laravel-conventie).
- **`match`-expressions** boven `switch` voor mapping: `match ($this->provider) { 'snelstart' => $this->client_key, 'mollie' => $this->access_token, default => null }`. Zie `app/Models/Connection.php:41-45`.
- **First-class callable syntax** + arrow functions voor closures: `fn (string $ability) => $token->can($ability)`.
- **Curly braces verplicht** voor alle control structures, óók single-line bodies (Laravel-Boost PHP-rule).

## PHPDoc & Array Shapes

- **Geen PHPDoc voor wat de signature al zegt** (zie memory `feedback_minimal_comments.md` + `.ai/rules/global.md`). Geen `@param string $name The name` als de type-hint hetzelfde zegt.
- **Wel PHPDoc voor array shapes** — verplicht waar PHP's type-system tekortschiet:
  ```php
  /**
   * @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string}
   */
  public static function mapException(Throwable $exception): array
  ```
  Zie `app/Support/Snelstart/UpstreamErrorMapper.php:27` en `app/Billing/PlanResolver.php:23`.
- **Wel PHPDoc voor generics**:
  - `@extends Factory<Connection>` op factory-classes.
  - `@use HasFactory<ConnectionFactory>` op models.
  - `@return list<string>` voor numerieke-key arrays, `@return array<string, mixed>` voor associative.
- **Wel `@var` voor narrowed types** na een `try/findOrFail`: `/** @var Account $account */`. Zie `app/Http/Controllers/Api/V1/ConnectionController.php:32`.
- **Mixen Engels + Nederlands in PHPDoc-beschrijvingen** is OK zolang technische termen Engels blijven. Beslissings-refs (`D-05`, `T-05a-06`, `CR-02`) zijn shortcodes naar `.docs/decisions/` en `.docs/plans/` — niet hernoemen.

## Eloquent Patterns

- **`#[Fillable(...)]` attribute** boven model-class (Laravel 13.9 attribute-based config) — geen `protected $fillable = [...]` property meer. Zie `app/Models/Connection.php:12-27`.
- **`#[Hidden(...)]` attribute** voor velden die nooit in `toArray()/toJson()` mogen verschijnen. Voor credentials altijd: `#[Hidden(['access_token', 'refresh_token', 'client_key', 'subscription_key'])]` (`Connection.php:28`).
- **`protected function casts(): array`** method (PHP-method-based casts) ipv `$casts` property.
- **Encrypted casts verplicht voor credentials:** `'access_token' => 'encrypted'`, `'refresh_token' => 'encrypted'`, `'client_key' => 'encrypted'`, `'subscription_key' => 'encrypted'`, `'webhook_callback_secret' => 'encrypted'`. Zie `app/Models/Connection.php:55-58` en `app/Models/Consumer.php:24-26`.
- **`public $timestamps = false;`** waar audit-tabellen alleen `created_at` hebben (`PassThroughCall.php:31`). Forward-only audit-log.
- **Relations zijn explicit-typed methods**: `public function account(): BelongsTo { return $this->belongsTo(Account::class); }`. Geen string-table-naam, altijd `::class`.

## Import Organization

Pint sorteert imports alphabetisch in één groep. Bestaande pattern bevestigd:

1. Imports alfabetisch gesorteerd binnen één blok.
2. Geen aparte groepen voor Laravel / external / app.
3. Geen `use function` of `use const` aliases zonder reden.
4. Geen partial-namespace-imports — altijd `use App\Models\Connection;` niet `use App\Models;`.

Voorbeeld correct (`app/Http/Controllers/Api/V1/ConnectionController.php:5-16`):
```php
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreConnectionRequest;
use App\Http\Resources\Api\V1\ConnectionResource;
use App\Models\Account;
use App\Models\Connection;
use App\Sanctum\TokenAbilities;
use Illuminate\Database\Eloquent\ModelNotFoundException;
…
```

## Error Handling

**Strategie:** typed exceptions worden in de Hub-laag gemapt naar Hub-HTTP-responses via een `UpstreamErrorMapper`. SDKs gooien rauwe exceptions; de Hub vangt en transformeert. Beslissingsbron: `.docs/decisions/upstream-error-mapping.md`.

**Patterns:**

- **Per-provider mapper:** `App\Support\Snelstart\UpstreamErrorMapper::mapException(Throwable)` en `App\Support\Mollie\MollieUpstreamErrorMapper::mapException(Throwable)` retourneren elk een `array{status, body, headers, short_code}`. Controller bouwt response uit dat array.
- **Auth-cloaking:** Snelstart 401/403 → Hub 502 (niet 401 lekken — threat T-05b-10). Mollie idem (threat T-05a-06). Reden: voorkomt dat een Consumer kan onderscheiden tussen "fout token" en "Hub-bug".
- **Domain exceptions met static factories:**
  ```php
  final class UnknownPlanException extends RuntimeException {
      public static function forSlug(string $slug): self {
          return new self(sprintf('Onbekende plan-slug: "%s". …', $slug));
      }
  }
  ```
  Zie `app/Billing/Exceptions/UnknownPlanException.php`. Geen rauwe `throw new RuntimeException("…")` in business-logic.
- **Form-Request-edge-validatie** vóór controller-body: `class StoreConnectionRequest extends FormRequest` met `rules()`-array. Controller assumes validated data.
- **Geen Eloquent-default-`abort(404)`:** controllers vangen `ModelNotFoundException` en bouwen consistente JSON: `{"error": "account_not_found", "message": "…"}`. Zie `ConnectionController.php:35-37`.
- **HTTP-status-constants** via Symfony: `Response::HTTP_CREATED`, `Response::HTTP_CONFLICT`, `Response::HTTP_FORBIDDEN`. Niet magic-numbers.
- **`abort_unless(…, 403, 'insufficient_ability')`** voor ability-guards in controllers (`ConnectionController.php:122`).

## Security & Logging

**Tokens encrypted at rest** — non-negotiable invariant (`CLAUDE.md` + `.ai/rules/global.md`):

- `access_token`, `refresh_token`, `client_key`, `subscription_key`, `webhook_callback_secret` → altijd `'encrypted'`-cast.
- Raw secrets verschijnen NIET in: logs, exception-messages, HTTP error-bodies, audit-rijen (`pass_through_calls`), API-resources.
- **Fingerprint-only** voor debugging:
  ```php
  public function fingerprint(): ?string {
      $secret = match ($this->provider) {
          'snelstart' => $this->client_key,
          'mollie' => $this->access_token,
          default => null,
      };
      return $secret ? substr(hash('sha256', $secret), 0, 12) : null;
  }
  ```
  Zie `app/Models/Connection.php:39-48` — 12 chars sha256-hex.

**Webhook-secrets per Connection** — geen één globale secret per Consumer. Mollie-webhook signature-verify gebruikt `services.mollie.webhook_secret` als platform-level secret + per-Connection lookup via `connection_id` in de URL. Zie `app/Http/Controllers/Webhooks/MollieWebhookController.php:41-46` (hard-fail als secret niet geconfigureerd).

**Geen rauwe secrets in audit:** `pass_through_calls` slaat alleen `query_keys` op (de keys van een query-string, niet de waardes). Bewezen in `tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php`.

**Header-allowlist:** alleen whitelisted headers worden naar partners doorgestuurd. `Authorization`, `Cookie`, `User-Agent`, `X-Account-Id`, custom headers worden gestript. Zie `app/Support/Snelstart/HeaderForwarder.php` en zijn unit-test.

## Anti-AI-cliché's (`.ai/rules/global.md`)

Vermijd in commits, docs en comments:
- "In this article" / "Laten we eens kijken" / "It is important to"
- "Furthermore" / "Moreover" / "Daarnaast" / "Bovendien" (mechanisch als opener)
- "Discover how" / "Transform" / "Seamless" / "Naadloos"
- "Innovative solution" / "Custom-made" / "Revolutionary" / "Game-changer"
- "Dive in" / "Explore" / "Unlock" / "Empower"

## Geen verzonnen partner-features

- Wat in code of docs over Snelstart/Mollie/Moneybird/Ibanity/Exact staat moet **exact** kloppen met hun officiële documentatie (gemirror'd in `.docs/partners/<provider>/`).
- Geen "vermoedelijke" endpoints, geen verzonnen response-velden, geen aangenomen rate-limits.
- Vendor-discoveries die afwijken van eerste-aanname worden in een testfile-PHPDoc gedocumenteerd zodat ze niet verloren gaan. Voorbeeld:
  ```
  Vendor-discovery: Mollie's SDK exposes `SubscriptionEndpointCollection`
  onder `MollieApiClient::$subscriptions` — NIET `$customerSubscriptions`
  zoals plan suggereerde.
  ```
  Zie `tests/Feature/Api/V1/Mollie/SubscriptionsTest.php:12-19`.

## Function Design

- **Kleine, single-purpose methods.** Controllers delegeren naar helper-methods (`findOwnedConnection`, `notFound`, `guardAbility` in `ConnectionController`).
- **Parameter-arrays met PHPDoc-array-shapes**, niet rauwe `array`-hints zonder context.
- **Return early** bij guards — geen diepe nesting. `if ($connection === null) { return …; }` op het pad-niveau, niet in een `else`-branch.
- **Geen optionele booleans als laatste param** voor "config-mode" — gebruik named-args of een data-object.

## Module Design

- **Geen barrel-files** (`index.php`-style re-exports). PSR-4 autoload is de export-mechanism.
- **Geen partner-business-logic in SDK-packages** — invariant uit `CLAUDE.md`. SDKs zijn dun: HTTP-laag, auth-laag, DTOs. Hub-domain leeft in `app/`.
- **Geen Hub-domeinmodellen (`Connection`, `Account`, `Consumer`) importeren in `packages/<sdk>/`** — strikt gescheiden via dep-direction Hub → SDK.

---

*Convention analysis: 2026-05-15*
