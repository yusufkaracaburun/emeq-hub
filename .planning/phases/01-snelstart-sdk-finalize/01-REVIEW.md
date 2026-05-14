---
phase: 01-snelstart-sdk-finalize
reviewed: 2026-05-14T00:00:00Z
depth: standard
files_reviewed: 1
files_reviewed_list:
  - packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php
findings:
  critical: 0
  warning: 4
  info: 7
  total: 11
status: issues_found
---

# Phase 01: Code Review Report — SnelstartConnectorTest

**Reviewed:** 2026-05-14
**Depth:** standard
**Files Reviewed:** 1
**Status:** issues_found

## Summary

De rewrite van `SnelstartConnectorTest.php` (12 `it()`-blokken, 22 cases met datasets) dekt de happy paths van `getRequestException()` en `handleRetry()` netjes af. Geen BLOCKER-issues: geen lekkende credentials, geen risky-test triggers (alle cases hebben minstens één `expect()`), geen netwerkverkeer (alles gemockt via PHPUnit `createMock`), geen `eval`/`exec`/debug-artefacten.

De review levert wel een aantal echte gaten op in **branche-dekking** van de getestte methods, een aantal **mock-fidelity hazards** die stilzwijgend kunnen breken bij Saloon v4 upgrades, en een **scope-mismatch** (één test hoort niet in deze file thuis). Geen daarvan is een ship-blocker, maar ze zijn allemaal binnen de doelstelling van "echte SDK-validation" die de phase oplevert.

## Warnings

### WR-01: Coverage gap — `default => null`-branche van `getRequestException()` wordt niet bewezen voor unmapped 4xx/5xx

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:162-169`
**Issue:** De test `'returns null for unmapped 2xx and 3xx statuses'` exercises status 204 en 301. In de productie-flow roept Saloon `getRequestException()` echter alleen aan voor failed responses (4xx/5xx); 204/301 raken dit pad in praktijk nooit. De echte risico-statuses zijn unmapped 4xx-codes die Snelstart kán teruggeven en die nu stilletjes `null` retourneren — bijvoorbeeld:
- `405 Method Not Allowed` (verkeerde HTTP-verb op een endpoint)
- `409 Conflict` (concurrent edits, Snelstart documenteert dit op sommige resources)
- `422 Unprocessable Entity` (Azure APIM kan dit teruggeven bij content-type mismatch)
- `451` of een onbekend 4xx code dat Snelstart later toevoegt

Op deze statuses retourneert de connector nu `null` en valt Saloon terug op de generieke `RequestException` — wat de SDK-belofte ("alle fouten als SnelstartException-subclass") **stilzwijgend breekt**. De test claimt impliciet dat dit gedrag bedoeld is, maar bewijst het niet voor het juiste statusbereik.

**Fix:** Vervang of vul aan met een dataset over realistische unmapped statuses:

```php
it('returns null for unmapped 4xx statuses (caller gets default RequestException)', function (int $status): void {
    $connector = makeSnelstartConnector();
    $response  = fakeSnelstartResponse($status, 'unmapped');

    expect($connector->getRequestException($response, null))->toBeNull();
})->with([405, 409, 422, 451]);
```

Overweeg parallel of het `default => null`-gedrag wel het juiste contract is — beter zou een catch-all `SnelstartException::unmapped($status, $body)` zijn zodat de SDK-grens dichter wordt.

### WR-02: `parseRetryAfter()` HTTP-date-pad niet getest — Azure APIM kan een datum teruggeven

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:130-150`
**Issue:** RFC 7231 (en de Azure APIM-laag waar Snelstart achter zit) staat zowel `Retry-After: 42` (seconds) als `Retry-After: Wed, 21 Oct 2015 07:28:00 GMT` (HTTP-date) toe. `SnelstartConnector::parseRetryAfter()` checkt `is_numeric($value)` en retourneert `null` voor HTTP-date. Dat null-pad voor *non-numeric maar non-empty* header-waarde wordt niet getest — de bestaande "null-Retry-After"-case test alleen de afwezigheid van de header (callback retourneert `null`), niet de "header aanwezig, niet-numeriek" branch.

**Fix:** Voeg een case toe:

```php
it('returns RateLimitException with null retryAfter when Retry-After is an HTTP-date', function (): void {
    $connector = makeSnelstartConnector();
    $response  = fakeSnelstartResponse(429, 'throttled', retryAfter: 'Wed, 21 Oct 2015 07:28:00 GMT');

    $exception = $connector->getRequestException($response, null);

    expect($exception)->toBeInstanceOf(RateLimitException::class)
        ->and($exception->retryAfterSeconds)->toBeNull();
});
```

Optioneel: als de SDK eigenaar wil dat HTTP-date wél geparsed wordt, is dat een aparte feature in `parseRetryAfter()` — flag voor productowner.

### WR-03: `handleRetry()` test bewijst non-retryable-pad voor slechts 3 statuses — 403/422/409 ontbreken

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:198-207`
**Issue:** De dataset `[400, 401, 404]` is symbolisch maar incompleet. De productie-retry-lijst is `[429, 500, 502, 503, 504]`. Alle andere statuses *moeten* false retourneren — inclusief 403 (auth-fail, mag niet retryen want is permanent), 422 en 501. De test naam zegt "non-retryable 4xx statuses" maar bewijst maar drie ervan. Een bug die per ongeluk 403 aan de retry-lijst toevoegt (bv. `[429, 403, 500, 502, 503, 504]`) wordt niet gevangen.

**Fix:** Breid de dataset uit zodat alle realistische non-retryable statuses gedekt zijn:

```php
->with([400, 401, 403, 404, 405, 409, 422, 451, 501])
```

### WR-04: Mock-fidelity hazard — `Response::header()` callback heeft verkeerde arity

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:46-48`
**Issue:** Saloon's `Response::header()` heeft de signature `header(string $header, mixed $default = null): mixed`. De mock-callback:

```php
static fn (string $name) => 'Retry-After' === $name ? $retryAfter : null,
```

accepteert maar **één** parameter. Zolang de connector-code `$response->header('Retry-After')` (één arg) blijft aanroepen, werkt het. Maar zodra iemand `header('Retry-After', '0')` met een default-arg gebruikt — of de Saloon v4-upgrade de signature aanpast — gooit deze closure een `ArgumentCountError`. Met `failOnWarning=true` slaat dat hard tegen de muur.

**Fix:** Maak de mock tolerant voor extra args:

```php
$response->method('header')->willReturnCallback(
    static fn (string $name, mixed $default = null) => 'Retry-After' === $name ? $retryAfter : $default,
);
```

Dit matcht ook semantisch beter: een echte `Response` retourneert de default als de header ontbreekt, niet hardcoded `null`.

## Info

### IN-01: Scope-mismatch — `'invokes the authenticator factory'` hoort niet in `SnelstartConnectorTest`

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:70-77`
**Issue:** Deze test exerciseert `snelstart.authenticator-factory` container-binding, niet de `SnelstartConnector`. Filename en testdoel mismatchen. Bij `failOnRisky=true` is dit niet kapot (de test heeft een expectation), maar het is een maintenance-smell: lezers verwachten in `SnelstartConnectorTest` alleen connector-gedrag.

**Fix:** Verplaats naar `tests/Unit/SnelstartServiceProviderTest.php` (of vergelijkbaar). Houd `SnelstartConnectorTest` strikt voor `getRequestException` / `handleRetry` / connector-config.

### IN-02: `makeSnelstartConnector()` doet meer dan nodig voor pure mapping-tests

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:54-64`
**Issue:** Elke test boot Testbench + service provider + container-binding + `ClientKeyAuthenticator` op, terwijl `getRequestException` en `handleRetry` de authenticator niet aanroepen. De `beforeEach(Cache::flush())` in `tests/Pest.php` is óók niet nodig hier. Voor een unit test op een puur synchrone mapping-method is dit te zwaar — het verbergt waar de test-afhankelijkheden zitten en vertraagt de suite.

**Fix:** Voor pure mapping-tests:

```php
function makeSnelstartConnector(): SnelstartConnector
{
    return new SnelstartConnector(
        baseUrl: 'https://snelstart.test/v2',
        authenticator: test()->createStub(ClientKeyAuthenticator::class),
    );
}
```

Aparte ServiceProviderTest voor de container-binding (zie IN-01).

### IN-03: Productie-URL `https://b2bapi.snelstart.nl/v2` als default in test helper

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:38, 61`
**Issue:** De default URL in `fakeSnelstartResponse()` en `makeSnelstartConnector()` is de **echte productie-host** van Snelstart. In de huidige test-setup gaat er niets over het netwerk (alles gemockt), dus geen acute kwetsbaarheid. Maar zodra iemand per ongeluk de mock weghaalt of een integratie-test toevoegt die de helper hergebruikt, kan live-verkeer ontstaan. Niet conform "fail loudly" — een `.test`-style hostname zou direct fout falen.

**Fix:**

```php
function fakeSnelstartResponse(
    int $status,
    string $body = '{}',
    ?string $retryAfter = null,
    string $url = 'https://snelstart.test/v2/relaties',
): Response { /* ... */ }
```

Idem voor `baseUrl` in `makeSnelstartConnector()`.

### IN-04: Magic strings koppelen test rigide aan exception-message-format

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:101, 102, 112, 127, 138, 149, 159`
**Issue:** Asserties zoals `->toContain('HTTP 401')`, `->toContain('fp:')`, `->toContain('retry after 42s')` koppelen de test letterlijk aan de message-formats in `AuthenticationException`, `RateLimitException`, etc. Zodra iemand de message rephrast (bv. `'rate-limited, wait 42s'`), gaan deze tests rood zonder echte regressie. Geen acute fout, wel drift-hazard.

**Fix:** Test de gestructureerde data (status-property, errorCodes-array, retryAfterSeconds) waar mogelijk; behoud een lichte smoke-check op de message:

```php
expect($exception)->toBeInstanceOf(RateLimitException::class)
    ->and($exception->retryAfterSeconds)->toBe(42);

// optioneel: één algemene message-sanity check, niet exact format
expect($exception->getMessage())->toMatch('/429|rate.?limit/i');
```

### IN-05: `RuntimeException` en `Saloon\Http\Request` gebruikt zonder `use` statement

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:179, 182, 193, 203`
**Issue:** Het bestand heeft expliciete `use`-statements voor alle Saloon-classes (lines 13-16) behalve `Saloon\Http\Request`, dat consequent inline FQN wordt gebruikt (`test()->createMock(Saloon\Http\Request::class)`). Idem `RuntimeException` (line 179) zonder leading backslash en zonder import. In een file zonder namespace werkt het toevallig (global namespace lookup), maar het is inconsistent met de rest van de imports.

**Fix:**

```php
use RuntimeException;
use Saloon\Http\Request as SaloonRequest;
```

en dan `test()->createMock(SaloonRequest::class)`. Of alleen `use Saloon\Http\Request;` als er geen naam-clash is.

### IN-06: `parseRetryAfter()` met empty-string-header niet expliciet getest

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:141-150`
**Issue:** De source heeft drie null-paden in `parseRetryAfter`: (a) header absent (`null`), (b) header is `''`, (c) header is niet-numeriek. De test "no Retry-After header" dekt (a) impliciet via de callback. Pad (b) "header aanwezig met lege string" en (c) (zie WR-02) zijn niet bewezen. Op zich edge-cases, maar `'' === $value` staat letterlijk in de source — als die check ooit weggehaald wordt zonder dat een test breekt, regressie-risico.

**Fix:**

```php
it('returns null retryAfter when Retry-After header is empty string', function (): void {
    $connector = makeSnelstartConnector();
    $response  = fakeSnelstartResponse(429, 'throttled', retryAfter: '');

    expect($connector->getRequestException($response, null)->retryAfterSeconds)->toBeNull();
});
```

### IN-07: PendingRequest mock altijd gebouwd, ook voor paden die `getPendingRequest()` niet aanroepen

**File:** `packages/snelstart-api/tests/Unit/Http/SnelstartConnectorTest.php:40-41, 49`
**Issue:** `fakeSnelstartResponse()` bouwt voor élke status een `PendingRequest`-mock, terwijl alleen het 404-pad `$response->getPendingRequest()->getUrl()` aanroept. Geen functionele fout (PHPUnit's `createMock` flagt unused method stubs niet als risky bij `expects` ontbreekt) maar onnodige setup-cost en signaal-ruis.

**Fix:** Maak de PendingRequest-mock optioneel of bouw 'm alleen wanneer een non-default `url` wordt meegegeven. Klein detail; alleen oppakken bij een bredere test-cleanup.

---

_Reviewed: 2026-05-14_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
