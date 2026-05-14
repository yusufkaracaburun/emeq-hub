---
phase: 05b-snelstart-pass-through-api
plan: 03
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Support/Snelstart/UpstreamErrorMapper.php
  - app/Support/Snelstart/HeaderForwarder.php
  - tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php
  - tests/Unit/Support/Snelstart/HeaderForwarderTest.php
  - .docs/decisions/upstream-error-mapping.md
autonomous: true
requirements: [HUB-05]
tags:
  - laravel
  - snelstart
  - error-mapping
  - security
  - phpunit

must_haves:
  truths:
    - "Een Snelstart-zijdige fout (401/403/5xx) wordt op de Hub omgezet naar 502 met een veilig JSON-envelope dat geen info over de auth-state lekt"
    - "Een user-input-fout (400/404/422) wordt 1-op-1 doorgegeven aan de Consumer"
    - "Een Snelstart-429 wordt passthrough met `Retry-After`-header doorgegeven"
    - "Network-errors (timeout / connection refused) worden 504 met short-code `snelstart_timeout`"
    - "De header-forwarder zet ALLEEN whitelisted headers door naar Snelstart (`Accept`, `Content-Type`, `If-Match`, `If-None-Match`) — alle andere headers worden gestript"
  artifacts:
    - path: "app/Support/Snelstart/UpstreamErrorMapper.php"
      provides: "Pure functie die een Saloon-exception/Response → `array{status:int, body:array, headers:array, short_code:?string}` mapped"
      contains: "class UpstreamErrorMapper"
    - path: "app/Support/Snelstart/HeaderForwarder.php"
      provides: "Pure functie die `Illuminate\\Http\\Request`-headers filtert naar whitelist voor SDK-call"
      contains: "class HeaderForwarder"
  key_links:
    - from: "UpstreamErrorMapper::mapException()"
      to: "Snelstart SDK exception subclasses (AuthenticationException, ServerException, ValidationException, NotFoundException, RateLimitException) + Saloon FatalRequestException"
      via: "match-statement op exception-class"
      pattern: "match \\("
    - from: "HeaderForwarder::forward()"
      to: "config of class-constant met whitelist"
      via: "array-filter intersect"
      pattern: "(Accept|Content-Type|If-Match|If-None-Match)"
---

<objective>
Twee thin support-classes die in Plan 05's `PassThroughController` ingewikkeld werk wegnemen:
1. `UpstreamErrorMapper` — maakt Snelstart-SDK-exceptions / -responses → Hub-HTTP-response (status + JSON-body + headers + short-code voor audit)
2. `HeaderForwarder` — filtert inkomende Hub-headers naar de whitelist voordat ze de SDK in gaan

Purpose: HUB-05 success criteria 6 (correcte error-mapping) + 7 (audit met short-code) + threat-model T-05b-09 (header-leak via `Authorization` / `Cookie` naar Snelstart) + T-05b-10 (info-disclosure over Snelstart-auth-state).

Output: 2 PHP-classes onder `app/Support/Snelstart/` + 2 dedicated test-files + 1 ADR voor de error-mapping policy. Géén controller/routes/middleware in dit plan.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md
@CLAUDE.md
@packages/snelstart-api/src/Exceptions/SnelstartException.php
@packages/snelstart-api/src/Exceptions/AuthenticationException.php
@packages/snelstart-api/src/Exceptions/ServerException.php
@packages/snelstart-api/src/Exceptions/ValidationException.php
@packages/snelstart-api/src/Exceptions/NotFoundException.php
@packages/snelstart-api/src/Exceptions/RateLimitException.php

<interfaces>
<!-- Exception-tree die we gaan mappen. NIET wijzigen — alleen consumeren. -->

From packages/snelstart-api/src/Exceptions/*:
```php
abstract class SnelstartException extends \RuntimeException {}

final class AuthenticationException extends SnelstartException {
    public static function tokenFetchFailed(int $status, string $body, string $fp): self;
}
final class ServerException extends SnelstartException {
    public static function fromResponse(int $status, string $body): self;
}
final class ValidationException extends SnelstartException {
    public readonly array $errorCodes;
    public readonly string $rawBody;
}
final class NotFoundException extends SnelstartException {
    public static function forUrl(string $url): self;
}
final class RateLimitException extends SnelstartException {
    public readonly ?int $retryAfterSeconds;
}
```

Saloon-laag:
```php
// Saloon\Exceptions\Request\FatalRequestException — netwerk-fout (timeout, DNS, refused)
// Saloon\Exceptions\Request\RequestException — HTTP-status-fout zonder SDK-mapping (zou niet voor moeten komen bij Snelstart-connector — alle 4xx/5xx zijn gedekt — maar mapper handelt 'm voor de zekerheid af)
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: `UpstreamErrorMapper` + tests</name>
  <files>app/Support/Snelstart/UpstreamErrorMapper.php, tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php</files>
  <behavior>
    Static method `UpstreamErrorMapper::mapException(\Throwable $exception): array` returnt:
    ```php
    array{
        status: int,           // HTTP-status voor Hub-response (200..504)
        body: array,           // JSON-body voor Hub-response
        headers: array,        // Extra headers (bv. Retry-After)
        short_code: ?string,   // Voor pass_through_calls.upstream_error (null bij 4xx user-input)
    }
    ```

    Mapping (uit CONTEXT.md `<decisions> ### Upstream-error mapping`-tabel):

    | Input | Output status | Output body | short_code |
    |---|---|---|---|
    | `AuthenticationException` (Snelstart 401/403 of token-fetch-failed) | **502** | `{error:'upstream_error', message:'Upstream auth failed', upstream_status:401, upstream_detail:'authentication_failed'}` | `snelstart_auth` |
    | `ServerException` (Snelstart 5xx) | **502** | `{error:'upstream_error', message:'Upstream returned server error', upstream_status:<int>, upstream_detail:'server_error'}` | `snelstart_5xx` |
    | `ValidationException` (Snelstart 400) | **400** | `{error:'upstream_validation', message:<sdk-message>, upstream_status:400, error_codes:[...]}` (passthrough — info-disclosure-veilig) | `null` (4xx user-input → null in audit) |
    | `NotFoundException` (Snelstart 404) | **404** | `{error:'upstream_not_found', message:<sdk-message>, upstream_status:404}` | `null` |
    | `RateLimitException` (Snelstart 429) | **429** | `{error:'upstream_rate_limited', message:<sdk-message>, upstream_status:429}` + header `Retry-After: <int>` (alleen indien `retryAfterSeconds` niet null) | `null` |
    | `Saloon\Exceptions\Request\FatalRequestException` (netwerk) | **504** | `{error:'upstream_timeout', message:'Upstream did not respond in time', upstream_status:0}` | `snelstart_timeout` |
    | Andere `\Throwable` | **502** | `{error:'upstream_error', message:'Unexpected upstream failure', upstream_status:0, upstream_detail:'unknown'}` | `snelstart_unknown` |

    **Belangrijk** (CONTEXT.md `### Upstream-error mapping` + threat T-05b-10):
    - 401/403 mappen naar **502 niet 401/403** — voorkomt info-disclosure of het de Consumer's PAT óf de stored client_key was die ongeldig is
    - Geen Snelstart-response-body letterlijk doorgeven in 502/504-bodies; alleen short-code + message-string die de SDK al heeft samengevat (en die zelf al fingerprints + truncation toepast — zie SDK Exceptions)

    Class-skeleton:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Support\Snelstart;

    use Emeq\SnelstartApi\Exceptions\AuthenticationException;
    use Emeq\SnelstartApi\Exceptions\NotFoundException;
    use Emeq\SnelstartApi\Exceptions\RateLimitException;
    use Emeq\SnelstartApi\Exceptions\ServerException;
    use Emeq\SnelstartApi\Exceptions\ValidationException;
    use Saloon\Exceptions\Request\FatalRequestException;
    use Throwable;

    final class UpstreamErrorMapper
    {
        /**
         * @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string}
         */
        public static function mapException(Throwable $exception): array
        {
            return match (true) {
                $exception instanceof AuthenticationException => [
                    'status' => 502,
                    'body' => [
                        'error' => 'upstream_error',
                        'message' => 'Upstream auth failed',
                        'upstream_status' => 401,
                        'upstream_detail' => 'authentication_failed',
                    ],
                    'headers' => [],
                    'short_code' => 'snelstart_auth',
                ],
                // ... overige cases per tabel hierboven
            };
        }
    }
    ```

    **Tests** `tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php` (unit-test, geen DB):
    1. `test_authentication_exception_maps_to_502_with_snelstart_auth_short_code`
    2. `test_server_exception_maps_to_502_with_snelstart_5xx_short_code` (verifieer dat status 503 in `ServerException::fromResponse(503, ...)` als `upstream_status: 503` in body terechtkomt)
    3. `test_validation_exception_passes_through_as_400_with_error_codes` (gebruik `ValidationException::fromBody('{"errorCode":"ALG-0100"}')`; assert `error_codes` array bevat 'ALG-0100', en `short_code` === null)
    4. `test_not_found_exception_passes_through_as_404`
    5. `test_rate_limit_exception_passes_through_with_retry_after_header` (gebruik `RateLimitException::fromBody('{}', 30)`; assert headers `['Retry-After' => '30']`)
    6. `test_rate_limit_exception_without_retry_after_omits_header`
    7. `test_fatal_request_exception_maps_to_504_with_snelstart_timeout` (instantieer `FatalRequestException` met een gemockt `Saloon\Http\PendingRequest` of een minimal stub — als FatalRequestException's constructor lastig is, fallback: gebruik een echte SocketException of mock met `$this->createMock(FatalRequestException::class)`)
    8. `test_unknown_throwable_maps_to_502_with_unknown_short_code` (gooi een `\RuntimeException("anders")`; assert status 502, short_code `snelstart_unknown`)

    Run pint en test.
  </behavior>
  <read_first>
    - packages/snelstart-api/src/Exceptions/AuthenticationException.php (constructor / factory `tokenFetchFailed`)
    - packages/snelstart-api/src/Exceptions/ServerException.php (factory `fromResponse(int $status, string $body)`)
    - packages/snelstart-api/src/Exceptions/ValidationException.php (`errorCodes` property + `fromBody` factory)
    - packages/snelstart-api/src/Exceptions/RateLimitException.php (`retryAfterSeconds` property + `fromBody` factory)
    - packages/snelstart-api/src/Exceptions/NotFoundException.php (`forUrl` factory)
    - vendor/saloonphp/saloon/src/Exceptions/Request/FatalRequestException.php (constructor-signature voor test-instantiatie)
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md `### Upstream-error mapping`
  </read_first>
  <action>
    Maak `app/Support/Snelstart/UpstreamErrorMapper.php` per skeleton boven; vul alle 8 cases in volgens de tabel. Maak de directory aan indien niet bestaand (`mkdir -p app/Support/Snelstart`).

    Maak `tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php` met namespace `Tests\Unit\Support\Snelstart`. Géén `RefreshDatabase` — pure unit-test.

    Run `vendor/bin/pint --dirty --format agent`.
    Run `php artisan test --compact --filter=UpstreamErrorMapperTest`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=UpstreamErrorMapperTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "final class UpstreamErrorMapper" app/Support/Snelstart/UpstreamErrorMapper.php` == 1
    - `grep -c "public static function mapException" app/Support/Snelstart/UpstreamErrorMapper.php` == 1
    - `grep -cE "(snelstart_auth|snelstart_5xx|snelstart_timeout|snelstart_unknown)" app/Support/Snelstart/UpstreamErrorMapper.php` >= 4
    - `grep -c "instanceof AuthenticationException" app/Support/Snelstart/UpstreamErrorMapper.php` == 1
    - `grep -c "instanceof FatalRequestException" app/Support/Snelstart/UpstreamErrorMapper.php` == 1
    - `grep -cE "public function test_" tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php` >= 8
    - `php artisan test --compact --filter=UpstreamErrorMapperTest` exit 0, alle 8 tests passed
  </acceptance_criteria>
  <done>Mapper-class bestaat met alle 7 cases (+ fallback), 8 tests groen, geen wijzigingen onder `packages/snelstart-api/`.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: `HeaderForwarder` + tests</name>
  <files>app/Support/Snelstart/HeaderForwarder.php, tests/Unit/Support/Snelstart/HeaderForwarderTest.php</files>
  <behavior>
    Static method `HeaderForwarder::forward(\Illuminate\Http\Request $request): array<string,string>` returnt een associative array van `header-name => header-value` waarbij ALLEEN deze 4 headers behouden blijven (case-insensitive bij vergelijking, output preserveert de **canonieke casing**):
    - `Accept`
    - `Content-Type`
    - `If-Match`
    - `If-None-Match`

    Alle andere headers (`Authorization`, `X-Account-Id`, `Cookie`, `User-Agent`, `X-Forwarded-For`, custom `X-*`-headers) worden weggegooid.

    Lege headers (Laravel kan voor `Content-Type` op een GET-request `null` of `""` teruggeven) worden ook weggegooid — geen `Content-Type: ''` doorzetten naar Snelstart.

    Class-skeleton:

    ```php
    <?php

    declare(strict_types=1);

    namespace App\Support\Snelstart;

    use Illuminate\Http\Request;

    final class HeaderForwarder
    {
        /**
         * Headers die de Hub doorzet naar Snelstart bij pass-through.
         * Whitelist > blacklist — voorkomt automatische lekkage van toekomstige
         * Hub-headers (zie CONTEXT.md §<decisions> ### Header forwarding).
         */
        private const ALLOWED = ['Accept', 'Content-Type', 'If-Match', 'If-None-Match'];

        /**
         * @return array<string, string>
         */
        public static function forward(Request $request): array
        {
            $out = [];
            foreach (self::ALLOWED as $name) {
                $value = $request->header($name);
                if (is_string($value) && '' !== $value) {
                    $out[$name] = $value;
                }
            }

            return $out;
        }
    }
    ```

    **Tests** `tests/Unit/Support/Snelstart/HeaderForwarderTest.php` (pure unit, instantieer `Request::create()`):
    1. `test_forwards_only_whitelisted_headers` — request met `Accept`, `Content-Type`, `If-Match`, `If-None-Match` + `Authorization`, `Cookie`, `User-Agent`, `X-Account-Id`, `X-Custom-Header`; assert output bevat exact de 4 whitelisted en GEEN van de andere 5
    2. `test_strips_authorization_header_explicitly` — bouw `Request::create()` met `HTTP_AUTHORIZATION: Bearer xyz`; assert `Authorization` niet in output
    3. `test_strips_cookie_header_explicitly` — bouw request met `HTTP_COOKIE`; assert niet in output
    4. `test_strips_x_account_id_header_explicitly` — voorkomt doorlekken van Hub's eigen routing-header naar Snelstart
    5. `test_omits_empty_content_type` — request zonder body; assert geen `Content-Type` in output OF lege `Content-Type` wordt niet meegegeven
    6. `test_case_insensitive_header_matching` — request met `accept: application/json` (lowercase); assert output bevat `Accept` met de juiste value (Laravel's `$request->header()` is case-insensitive)
  </behavior>
  <read_first>
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md `<decisions> ### Header forwarding — Whitelist`
    - vendor/symfony/http-foundation/HeaderBag.php OR vendor/laravel/framework/src/Illuminate/Http/Request.php (gedrag van `$request->header(string $name): ?string`)
  </read_first>
  <action>
    Maak de class en test per skeletons hierboven.

    `php artisan make:test --phpunit Support/Snelstart/HeaderForwarderTest --no-interaction` als startpunt (genereert tests/Feature; verplaats naar `tests/Unit/Support/Snelstart/` of pas namespace aan).

    Run pint en test: `php artisan test --compact --filter=HeaderForwarderTest`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=HeaderForwarderTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "final class HeaderForwarder" app/Support/Snelstart/HeaderForwarder.php` == 1
    - `grep -c "private const ALLOWED" app/Support/Snelstart/HeaderForwarder.php` == 1
    - `grep -cE "'Accept'.*'Content-Type'.*'If-Match'.*'If-None-Match'" app/Support/Snelstart/HeaderForwarder.php` == 1
    - `grep -ciE "(authorization|cookie|x-account-id)" app/Support/Snelstart/HeaderForwarder.php` == 0 (whitelist mag deze namen NIET noemen — geen blacklist-anti-pattern)
    - `grep -cE "public function test_" tests/Unit/Support/Snelstart/HeaderForwarderTest.php` >= 6
    - `php artisan test --compact --filter=HeaderForwarderTest` exit 0, alle 6 tests passed
  </acceptance_criteria>
  <done>HeaderForwarder met whitelist-pattern bestaat; 6 tests bewijzen dat Authorization/Cookie/X-Account-Id niet doorgaan; Phase 5a kan dezelfde pattern hergebruiken (voor Mollie, met andere whitelist).</done>
</task>

<task type="auto">
  <name>Task 3: ADR — `upstream-error-mapping`</name>
  <files>.docs/decisions/upstream-error-mapping.md</files>
  <read_first>
    - .docs/decisions/pass-through-calls-table.md (zelfde ADR-stijl uit Plan 01 dat in deze fase ook geschreven wordt — niet vereist dat 01 al gemerged is, alleen dat de stijl bekend is)
    - .docs/decisions/mollie-passthrough-api.md (pass-through-ADR-template)
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md `<decisions> ### Upstream-error mapping`
  </read_first>
  <action>
    ADR (Nederlands proza, Engelse identifiers). Sections:

    1. `# Upstream-error mapping — Snelstart-pass-through`
    2. `## Status` — *"Geaccepteerd 2026-05-14 — Phase 5b mapt Snelstart-zijdige fouten naar Hub-HTTP-responses volgens de tabel hieronder."*
    3. `## Keuze` — kopieer de mapping-tabel uit CONTEXT.md `### Upstream-error mapping` letterlijk
    4. `## Context` — waarom **502 voor Snelstart 401/403** (niet 401/403 passthrough):
       - Voorkomt dat een Consumer kan onderscheiden of zijn PAT ongeldig is (401 op Hub-zijde) versus de opgeslagen `client_key` (401 op Snelstart-zijde) — beide zouden zonder remap als 401 terugkomen
       - Reduceert info-disclosure-surface; auth-status van een Connection blijft black-box voor de Consumer
       - 502 is semantisch correct: "upstream-onderdeel rejecteert mij" — de Hub is een gateway
       - 504 onderscheidt netwerk-timeout (`FatalRequestException`) van 502 server-error → maakt monitoring scherper
    5. `## Consequenties` —
       - `pass_through_calls.upstream_error` krijgt short-codes (`snelstart_auth`, `snelstart_5xx`, `snelstart_timeout`, `snelstart_unknown`) zodat dashboards op causes kunnen aggregeren zonder body-parsing (covered in Plan 05)
       - Mollie 5a kan een eigen mapping-ADR maken (Connect-flow heeft afwijkende auth-foutcodes)
       - 502-responses bevatten **géén** Snelstart response-body — alleen short-code + summary-message uit de SDK-exception (die zelf al body-truncation toepast)
       - Phase 9 admin-UI (Filament) kan op `upstream_error`-kolom filteren om auth-failures te zien zonder body-parsing

    Eind-regel: *"Bron: `.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md` §`<decisions> ### Upstream-error mapping`. Implementatie: `app/Support/Snelstart/UpstreamErrorMapper.php`."*

    Géén heredoc — Write-tool. Anti-cliché-regels uit `.ai/rules/global.md`.
  </action>
  <verify>
    <automated>test -f .docs/decisions/upstream-error-mapping.md && grep -cE "^## (Status|Keuze|Context|Consequenties)" .docs/decisions/upstream-error-mapping.md</automated>
  </verify>
  <acceptance_criteria>
    - `test -f .docs/decisions/upstream-error-mapping.md` exit 0
    - `grep -c "^## Status$" .docs/decisions/upstream-error-mapping.md` == 1
    - `grep -c "^## Keuze$" .docs/decisions/upstream-error-mapping.md` == 1
    - `grep -c "^## Context$" .docs/decisions/upstream-error-mapping.md` == 1
    - `grep -c "^## Consequenties$" .docs/decisions/upstream-error-mapping.md` == 1
    - `grep -cE "(snelstart_auth|snelstart_5xx|snelstart_timeout)" .docs/decisions/upstream-error-mapping.md` >= 3
    - `grep -c "502" .docs/decisions/upstream-error-mapping.md` >= 2
    - **Trigger `docs-sync` skill** in execute-sessie als follow-up (nieuwe ADR → `.docs/README.md` index)
  </acceptance_criteria>
  <done>ADR bestaat, 4 secties, mapping-tabel + 502-rationale gedocumenteerd, docs-sync getriggerd.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| SDK-exception (cleartext message met truncated body) → Hub-response (Consumer ziet dit) | De mapper moet voorkomen dat upstream auth-state of credentials in een Consumer-zichtbaar response-veld terechtkomt |
| Hub-request-headers (kunnen `Authorization`, `Cookie` bevatten) → SDK-call-headers (gaan naar Snelstart) | Whitelist mag NOOIT leaken; toekomstige Hub-headers moeten by default geweigerd worden |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05b-08 | Information disclosure | UpstreamErrorMapper 502-body | mitigate | Body bevat alleen `short_code` + summary-message uit SDK-exception; SDK-exceptions truncaten body en gebruiken fingerprint i.p.v. clientKey (zie `AuthenticationException::tokenFetchFailed`). Geen Snelstart response-body letterlijk gekopieerd in 502/504. |
| T-05b-09 | Information disclosure | HeaderForwarder lekt `Authorization` of `Cookie` naar Snelstart | mitigate | Whitelist-only design met `private const ALLOWED`. Geen blacklist-pattern; nieuwe Hub-headers worden by default geweigerd. Test 2/3/4 (Plan 03 Task 2) bewijst dit voor de drie hoogste-risico-headers. |
| T-05b-10 | Information disclosure | 401/403-rewrap onthult Snelstart-auth-state | mitigate | Mapper rewrapped 401/403 naar 502; Consumer kan niet onderscheiden of PAT of stored client_key faalde. Test 1 (Plan 03 Task 1) verifieert. |
| T-05b-11 | Denial of service | 429 zonder Retry-After | accept | Mapper omit de header wanneer Snelstart 'm niet stuurt; Consumer mag zelf default-backoff toepassen. Out-of-scope: Hub eigen rate-limiter naast Snelstart's. |
</threat_model>

<verification>
- `UpstreamErrorMapperTest` 8 tests groen
- `HeaderForwarderTest` 6 tests groen
- Phase 3-suite blijft groen (`php artisan test --compact`)
- Pint clean
- Géén wijziging onder `packages/snelstart-api/`
- 2 ADRs (deze plan + Plan 01) toegevoegd in `.docs/decisions/`; `docs-sync` skill heeft `.docs/README.md`-index bijgewerkt
</verification>

<success_criteria>
- Plan 05's `PassThroughController` kan in zijn catch-block schrijven:
  `$mapped = UpstreamErrorMapper::mapException($e); $headers = HeaderForwarder::forward($request);`
  zonder verdere infrastructuur of context
- Header-whitelist staat als `private const` in de class (niet in `config/`) zodat een verandering een code-change vereist (audit-baarheid)
- Error-mapping-policy is gedocumenteerd in ADR voor toekomstige Phase 5a-reviewers
</success_criteria>

<output>
Na completion: `.planning/phases/05b-snelstart-pass-through-api/05b-03-SUMMARY.md` per template. Notitie naar Plan 05: importeer beide classes uit `App\Support\Snelstart\`; gebruik `UpstreamErrorMapper::mapException($e)` in een catch-Throwable-block rondom de SDK-call.
</output>
