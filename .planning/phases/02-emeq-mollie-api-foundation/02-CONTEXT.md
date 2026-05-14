# Phase 2: emeq/mollie-api foundation — Context

**Gathered:** 2026-05-14
**Status:** Ready for planning
**Source:** Synthesized from `.claude/plans/fancy-honking-spring.md` (Plan-mode review 2026-05-14) + ROADMAP.md Phase 2 details + REQUIREMENTS.md MOLL-01 + prior session research

<domain>
## Phase Boundary

Een dunne, multi-tenant, dual-credential Laravel-package (`emeq/mollie-api`) rond `mollie/mollie-api-php` (^3.11, BSD-2-Clause) waarop alle Hub-fasen (Phase 3-8) kunnen leunen. Outer-layer pattern spiegelt `emeq/snelstart-api`: ServiceProvider + CredentialResolver + Facade + multi-tenant container-bindings. Inner HTTP-stack ligt bij `mollie/mollie-api-php` (geen eigen Saloon-wrapper).

**Levert MOLL-01:**
- `emeq/mollie-api` skeleton + `MollieServiceProvider` (Spatie package-tools DSL)
- `MollieCredentialResolver`-contract — host-app bindt zelf
- **Dual credentials van dag 1:** `MollieApiKeyCredentials` (`test_|live_`-prefix validatie) + `MollieOAuthCredentials` (`access_`-prefix validatie)
- `Mollie::class` facade-target met `::client(): MollieApiClient` factory
- Exception-layer: `MollieException` (package-base) + `MissingCredentialResolverException`
- Pest-suite ≥10 groen op auth/resolver/error-mapping

**Niet in Phase 2** (volgt in latere fasen):
- Mollie Connect OAuth-broker logic (Phase 4 — MOLL-02 + HUB-02)
- Resource-wrapping (Payments/Customers/etc) — host-apps roepen `Mollie::client()->payments->create(...)` direct aan op de onderliggende lib (Phase 5 — MOLL-03)
- Webhook-verifier (Phase 5 — MOLL-04)
- Hub-integratie (Phase 3+)

</domain>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plan-source (autoritief voor scope + architectuur)
- `.claude/plans/fancy-honking-spring.md` — plan-mode review 2026-05-14, sectie "Oorspronkelijk Phase 2-plan" heeft volledige bestandstree + bindings + tests
- `.planning/REQUIREMENTS.md` — MOLL-01 requirement-tekst (regels 13)
- `.planning/ROADMAP.md` — Phase 2 details (sectie "Phase 2: emeq/mollie-api foundation")

### Snelstart-SDK referentie-pattern (read-only, 1-op-1 voor outer-layer)
- `packages/snelstart-api/composer.json` — composer.json shape, scripts, autoload, extra.laravel.providers
- `packages/snelstart-api/src/SnelstartServiceProvider.php` — Spatie package-tools DSL + binding-pattern
- `packages/snelstart-api/src/Snelstart.php` — facade-target shape (resolver→authenticator→connector flow)
- `packages/snelstart-api/src/Facades/Snelstart.php` — facade boilerplate
- `packages/snelstart-api/src/Contracts/SnelstartCredentialResolver.php` — contract docblock-stijl
- `packages/snelstart-api/src/Data/SnelstartCredentials.php` — readonly final + validate-in-ctor + fingerprint pattern
- `packages/snelstart-api/src/Exceptions/SnelstartException.php` — base exception pattern
- `packages/snelstart-api/src/Exceptions/MissingCredentialResolverException.php` — static `::notBound()` factory
- `packages/snelstart-api/tests/TestCase.php` — Testbench setup
- `packages/snelstart-api/tests/Pest.php` — Pest hooks
- `packages/snelstart-api/tests/Support/FakeCredentialResolver.php` — test-double pattern

### Architectuur-invariants (uit CLAUDE.md / `.ai/rules/`)
- `.ai/rules/global.md` — taal, security, OAuth-policy, multi-tenant scope
- `.ai/rules/engineering.md` — chirurgisch wijzigen, conflicten oppervlakken, lezen vóór schrijven
- `.ai/packages` rules — `packages/` is gitignored sub-repos
- `CLAUDE.md` — domeinmodel-invariants (tokens encrypted at rest, geen Hub-modellen in SDK)

### Mollie-research (uit plan-mode-sessie)
- `mollie/mollie-api-php` ^3.11 GitHub (BSD-2-Clause): `setApiKey()` + `setAccessToken()` runtime-swap, `MollieApiClient::fake()` voor tests
- Plan-agent bevinding: `MollieApiClient` heeft `getAuthenticator()` (geen `getApiKey()` getter), `ApiException::getField()` alleen op subclass `ValidationException`, `setProfileId()` bestaat NIET (profile via payload of OAuth-token-context)
- `mollie/laravel-mollie` issue #245 / PR #246 (jul 2024) afgewezen — laravel-mollie blijft single-tenant

### Repo
- `yusufkaracaburun/emeq-mollie-api` — bestaat op GitHub sinds 2026-05-13 (publiek, leeg, geen default branch). Description stale ("Saloon v3") — bij eerste push bijwerken naar wrap-mollie-api-php beschrijving.

</canonical_refs>

<decisions>
## Implementation Decisions

### Package shape
- **Name:** `emeq/mollie-api`
- **Namespace:** `Emeq\MollieApi\`
- **Skeleton basis:** `spatie/package-skeleton-laravel` (zelfde als Snelstart-SDK)
- **License:** MIT
- **PHP:** ^8.3 (compat met emeq-hub PHP 8.4)
- **Laravel:** ^11.0||^12.0||^13.0

### Dependencies (composer.json `require`)
- `php: ^8.3`
- `illuminate/contracts: ^11.0||^12.0||^13.0`
- `mollie/mollie-api-php: ^3.11` ← **kerndep**
- `spatie/laravel-data: ^4.0`
- `spatie/laravel-package-tools: ^1.16`

**Bewust NIET in require:**
- `saloonphp/*` — geen Saloon-laag in dit pakket
- `mollie/laravel-mollie` — single-tenant, niet bruikbaar voor multi-tenant resolver
- `mollie/laravel-cashier-mollie` — leeft in Phase 6 als consumer-side dep, niet hier

### Dependencies (composer.json `require-dev`)
Mirror Snelstart-SDK: `pestphp/pest ^3||^4`, `pest-plugin-arch`, `pest-plugin-laravel`, `orchestra/testbench ^9||^10||^11`, `larastan/larastan ^3`, `laravel/pint`, `nunomaduro/collision ^8.8`, `phpstan/extension-installer ^1.4`, `phpstan/phpstan-deprecation-rules ^2`, `phpstan/phpstan-phpunit ^2`.

### Auto-discovery
- `extra.laravel.providers: [Emeq\\MollieApi\\MollieServiceProvider]`
- `extra.laravel.aliases: { Mollie: Emeq\\MollieApi\\Facades\\Mollie }`

**Facade-alias = `Mollie`** (NIET `EmeqMollie`). Reden: matcht Snelstart-pattern (`Snelstart`-alias). In Phase 6 wordt laravel-mollie pas toegevoegd als transitive dep van Cashier-Mollie — die collision wordt opgelost door explicit `use Mollie\Api\MollieApiClient;` in host-code (FQN-import), of door laravel-mollie's facade in `config/app.php` aliases te overriden. README documenteert dit.

> NB: tijdens plan-mode-review werd "EmeqMollie" eerst voorgesteld; user koos `Mollie`. Bij Phase 6 evalueren of conflict echt bestaat (waarschijnlijk niet — Cashier-Mollie zelf gebruikt geen `Mollie`-alias maar `Cashier::class`).

### Container-bindings (in `MollieServiceProvider::packageRegistered()`)

```php
// 1. MollieCredentialResolver::class — NIET gebonden door package; host-app MUST bind
//    (Snelstart-pattern: package gooit MissingCredentialResolverException als unbound)

// 2. Mollie::class — singleton, holds resolver reference
$this->app->singleton(Mollie::class, function (Application $app): Mollie {
    if (!$app->bound(MollieCredentialResolver::class)) {
        throw MissingCredentialResolverException::notBound();
    }
    return new Mollie(
        resolver: $app->make(MollieCredentialResolver::class),
        config:   $app->make('config'),
    );
});

// 3. MollieApiClient::class — BIND (per-resolve), NIET singleton
//    Reden: multi-tenant = fresh client per credentials-resolve
$this->app->bind(
    \Mollie\Api\MollieApiClient::class,
    fn (Application $app) => $app->make(Mollie::class)->client(),
);
```

### `Mollie::client()` flow

```php
public function client(): \Mollie\Api\MollieApiClient
{
    $creds = $this->resolver->resolve(); // MollieApiKeyCredentials | MollieOAuthCredentials

    // 1. Optional env-guard (config-driven)
    if ($this->config->get('mollie.enforce_environment', false)
        && app()->environment('production')
        && $creds instanceof MollieApiKeyCredentials
        && str_starts_with($creds->apiKey, 'test_')
    ) {
        throw new MollieException('Production env requires live_ API-key');
    }

    // 2. Optional custom Guzzle
    $guzzle = $this->buildGuzzle(); // returns null als geen custom config

    // 3. Instantiate fresh client
    $client = new \Mollie\Api\MollieApiClient($guzzle);

    // 4. Apply credentials per type
    match (true) {
        $creds instanceof MollieApiKeyCredentials  => $client->setApiKey($creds->apiKey),
        $creds instanceof MollieOAuthCredentials   => $client->setAccessToken($creds->accessToken),
    };

    // 5. Optional idempotency-key generator (config-driven)
    if ($gen = $this->config->get('mollie.idempotency.generator')) {
        $client->setIdempotencyKeyGenerator(app($gen));
    }

    return $client;
}
```

### Bestandstree

```
packages/mollie-api/
├── composer.json
├── phpunit.xml.dist
├── pint.json
├── phpstan.neon.dist
├── README.md
├── LICENSE.md          (MIT, mirror Snelstart)
├── CHANGELOG.md        (lege placeholder)
├── .gitignore
├── .gitattributes
├── .editorconfig
├── config/mollie.php
├── src/
│   ├── MollieServiceProvider.php
│   ├── Mollie.php
│   ├── Facades/Mollie.php
│   ├── Contracts/
│   │   └── MollieCredentialResolver.php
│   ├── Data/
│   │   ├── MollieCredentials.php              (abstract base met fingerprint())
│   │   ├── MollieApiKeyCredentials.php        (apiKey: test_|live_)
│   │   └── MollieOAuthCredentials.php         (accessToken: access_)
│   └── Exceptions/
│       ├── MollieException.php
│       └── MissingCredentialResolverException.php
└── tests/
    ├── TestCase.php
    ├── Pest.php
    ├── ArchTest.php                            (strict_types, no debug-funcs)
    ├── PackageSmokeTest.php                    (SP boots, bindings present)
    ├── Support/FakeMollieCredentialResolver.php
    └── Unit/
        ├── Data/
        │   ├── MollieApiKeyCredentialsTest.php  (3 tests)
        │   ├── MollieOAuthCredentialsTest.php   (3 tests)
        │   └── MollieCredentialsFingerprintTest.php (1 test, shared abstract)
        ├── MollieServiceProviderTest.php       (3 tests)
        ├── MollieTest.php                      (4 tests)
        └── ErrorMappingTest.php                (2 tests)
```

Total ≥17 tests (drempel ≥10).

### config/mollie.php structuur

```php
return [
    'enforce_environment' => env('MOLLIE_ENFORCE_ENVIRONMENT', false),
    'http' => [
        'timeout'        => env('MOLLIE_HTTP_TIMEOUT', 30),
        'guzzle_options' => [],
    ],
    'idempotency' => [
        'generator' => null, // class implementing IdempotencyKeyGeneratorContract
    ],
];
```

### Hub-integratie

In `composer.json` van de Hub: path-repository toevoegen voor `packages/mollie-api/` (mirror van bestaande Snelstart-entry). Géén `require: emeq/mollie-api` yet — pas wanneer Hub of host-app het actief gebruikt (Phase 3+).

### Git-policy
- Werk op feature-branch in `packages/mollie-api/` (sub-repo), niet direct main
- Eerste push: `git push -u origin main` (na approval) — creëert default branch
- Update repo description op GitHub: van "Saloon v3" naar "Laravel SDK wrap around mollie/mollie-api-php with multi-tenant credential resolver and OAuth support"

### Claude's Discretion

- Of `MollieCredentials` een abstract base wordt of een sealed-union via PHP interface — kies wat het schoonst is in tests; beide patronen geldig
- Pest-test-organisatie (in `Unit/Data/`, `Unit/`, etc.) mag schuiven zolang ≥10 tests groen
- README-inhoud (basis-usage + multi-tenant-voorbeeld + Connect-OAuth voorvertoning) — kort houden, link naar v0.2 Hub-docs
- PHPStan-level (Snelstart heeft `level: 5` of zo) — match Snelstart-SDK setting

</decisions>

<specifics>
## Specific References / Examples

- `MollieApiClient::fake()` is Mollie's eigen Saloon-like mock; gebruik voor `ErrorMappingTest` om een 422-response met `{status, title, detail, field}` te returnen en `Mollie\Api\Exceptions\ValidationException::getField()` te asserteren
- Test #5 van success criteria (Bearer header): gebruik `$client->getAuthenticator()` om `Mollie\Api\Http\Auth\ApiKeyAuthenticator` te asserteren + `->isTestToken() === true`. Aanvullende test via `MollieApiClient::fake()` met `assertSent()` om de outgoing PSR-7 request header `Authorization: Bearer test_...` te valideren
- Fingerprint-pattern: `sha256($apiKey)` met eerste 12 chars voor logs/audit (mirror SnelstartCredentials)
- Cross-tenant isolation test (success criterion 2): bind een resolver die afwisselend returnt `test_keyA` en `test_keyB`; assert dat opeenvolgende `Mollie::client()` aanroepen aparte client-instanties zijn met de juiste keys

</specifics>

<deferred>
## Deferred Ideas (uit scope Phase 2)

| Idee | Wanneer |
|---|---|
| Mollie Connect OAuth-broker (authorize/exchange/refresh endpoints) | Phase 4 (MOLL-02 + HUB-02) |
| Resources-wrapping op de facade (`Mollie::payments()`) | Phase 5 (MOLL-03) — overweegnemen als ergonomie het waard is |
| Webhook-verifier helper | Phase 5 (MOLL-04) |
| `IdempotencyKeyGenerator` custom-implementatie (job-id-gebaseerd) | Host-apps in latere fasen, niet in SDK |
| Cashier-Mollie integratie | Phase 6 (SUB-01) — let op laravel-mollie collision |
| Account-level subscription-laag | Phase 7 (SUB-02) |
| Migratie van bestaande emeq-mollie-api repo description ("Saloon v3" → wrap-mollie-api-php) | Bij eerste push in deze fase — direct doen |

</deferred>

---

*Phase: 02-emeq-mollie-api-foundation*
*Context gathered: 2026-05-14 via PRD-equivalent synthesis from .claude/plans/fancy-honking-spring.md + plan-mode review*
