---
phase: 05b-snelstart-pass-through-api
reviewed: 2026-05-14T20:00:00Z
depth: standard
files_reviewed: 30
files_reviewed_list:
  - app/Http/Controllers/Api/V1/AccountController.php
  - app/Http/Controllers/Api/V1/ConnectionController.php
  - app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php
  - app/Http/Middleware/ResolveSnelstartAccount.php
  - app/Http/Requests/Api/V1/StoreAccountRequest.php
  - app/Http/Requests/Api/V1/StoreConnectionRequest.php
  - app/Http/Resources/Api/V1/AccountResource.php
  - app/Http/Resources/Api/V1/ConnectionResource.php
  - app/Models/PassThroughCall.php
  - app/Services/Snelstart/HubSnelstartCredentialResolver.php
  - app/Support/Snelstart/HeaderForwarder.php
  - app/Support/Snelstart/UpstreamErrorMapper.php
  - database/factories/PassThroughCallFactory.php
  - database/migrations/2026_05_15_000001_create_pass_through_calls_table.php
  - routes/api.php
  - tests/Concerns/PrimesSnelstartTokenCache.php
  - tests/Feature/Api/V1/DestroyConnectionTest.php
  - tests/Feature/Api/V1/ShowConnectionTest.php
  - tests/Feature/Api/V1/Snelstart/HeaderForwardingTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughErrorMappingTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughResolutionTest.php
  - tests/Feature/Api/V1/StoreAccountTest.php
  - tests/Feature/Api/V1/StoreConnectionTest.php
  - tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php
  - tests/Feature/PassThroughCallModelTest.php
  - tests/Feature/Services/HubSnelstartCredentialResolverTest.php
  - tests/Unit/Support/Snelstart/HeaderForwarderTest.php
  - tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php
findings:
  critical: 3
  warning: 7
  info: 4
  total: 14
status: issues_found
---

# Phase 05b: Code Review Report

**Reviewed:** 2026-05-14T20:00:00Z
**Depth:** standard
**Files Reviewed:** 30
**Status:** issues_found

## Samenvatting

De Phase 5b Snelstart-pass-through-laag ziet er over het algemeen solide uit:
de Bearer → Consumer → Account → Connection-chain wordt gehandhaafd via
`ResolveSnelstartAccount`-middleware met `X-Account-Id`-header (geen
query-string-leakage), credentials worden encrypted-cast opgeslagen, en
`HeaderForwarder` doet whitelist-filtering. `UpstreamErrorMapper` mapt SDK-
exceptions netjes naar Hub-responses inclusief 401→502-cloaking. Test-coverage
op de feature-laag is hoog en raakt de echte DB.

**Maar er zijn drie BLOCKER-issues** die voor merge moeten worden opgelost:

1. **Body-forwarding is stilzwijgend kapot voor niet-JSON content-types** — een
   consumer die `application/x-www-form-urlencoded` of XML stuurt naar de
   pass-through krijgt een lege body upstream. Snelstart v2 is wel JSON-only,
   maar de Hub zou dat moeten enforce'n in plaats van silently te corrupten.
2. **Raw query-string (incl. PII zoals e-mails uit OData `$filter`-clauses) wordt
   in `pass_through_calls.path` weggeschreven** — dat overtreedt het "fingerprint-only"-
   contract uit CLAUDE.md / `.ai/rules/global.md` op een lekkende manier.
3. **`request_fingerprint` is een constante hash voor lege POST/PATCH-bodies**
   (`sha256('[]')` truncated) — geeft misleidende audit-data en kan twee
   onafhankelijke "wel-een-call"-events niet meer onderscheiden van "leeg-body"-
   herhalingen.

Verder een aantal Warnings rond defensive coding (null-derefs op
middleware-set attributes), test-couplings aan SDK-interne validatie, en
naming/consistency-issues.

---

## Critical Issues

### CR-01: Body-forwarding is stilzwijgend kapot voor niet-JSON content-types

**File:** `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:51`

**Issue:**
```php
$body = in_array($method, ['POST', 'PATCH'], true) ? ($request->json()->all() ?: []) : null;
```

`$request->json()->all()` retourneert alleen iets zinnigs wanneer Content-Type
`application/json` is. Voor `application/x-www-form-urlencoded`, XML, of een
willekeurig binair payload wordt `$body` stilzwijgend `[]`. De upstream
Snelstart-call krijgt dan een lege JSON-body, terwijl de consumer dacht een
echte payload mee te sturen. Snelstart v2 verwacht weliswaar JSON, maar de
Hub:

1. Logt geen waarschuwing.
2. Stuurt geen 415 terug aan de consumer.
3. Heeft geen test die dit pad afdekt.

Resultaat: consumer ziet een 4xx van Snelstart ("relatie kan niet leeg zijn")
terwijl het werkelijke probleem aan Hub-zijde zit. Debugging-nachtmerrie en
data-integriteitsrisico (er kunnen lege POSTs binnenkomen die toch geaccepteerd
worden door Snelstart als alle velden optioneel zijn).

**Fix:** Reject expliciet niet-JSON Content-Type voor POST/PATCH, of forward
de raw body byte-perfect.

```php
if (in_array($method, ['POST', 'PATCH'], true)) {
    $contentType = $request->header('Content-Type', '');
    if (! str_starts_with(strtolower($contentType), 'application/json')) {
        return response()->json([
            'error' => 'unsupported_content_type',
            'message' => 'Pass-through accepteert alleen application/json voor POST/PATCH.',
        ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }
    $body = $request->json()->all();
} else {
    $body = null;
}
```

---

### CR-02: Audit-log lekt raw query-string (incl. PII) in `pass_through_calls.path`

**File:** `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:105`

**Issue:**
```php
'path' => $endpoint.($request->getQueryString() !== null ? '?'.$request->getQueryString() : ''),
```

De query-string wordt verbatim in de DB-kolom `path` gezet. Bewijs dat dit PII
bevat: `tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php:85`
test al een query met `$filter=Email eq 'a@b.nl'` — die volledige expression
inclusief e-mailadres komt in `pass_through_calls.path` terecht.

Dit overtreedt twee project-regels:

- `.ai/rules/global.md` § Security: "Raw secrets verschijnen nooit in logs … Gebruik fingerprints." Hoewel het hier PII en geen secrets betreft, hanteert het project een fingerprint-only-discipline (`request_fingerprint`-kolom is daar het bewijs van).
- CLAUDE.md § Constraints: "Tokens encrypted at rest: gevoelige credentials … nooit raw in DB of logs. Fingerprint-only voor debugging."

Verder is `$request->getQueryString()` URL-decoded niet — de string blijft
percent-encoded, maar dat is geen mitigatie tegen DB-lekkage; grep op
`a%40b.nl` werkt prima.

Snelstart-OData-querystrings kunnen e-mails, namen en interne IDs bevatten —
exact het soort data dat AVG/GDPR onder logging-minimalisatie schaart.

**Fix:** Strip query-string uit het audit-veld, of behoud alleen niet-PII-
deel (operation-keys zonder waarden). Voorstel: alleen `$endpoint` opslaan,
optioneel een aparte `query_keys`-kolom met sleutelnamen (zonder waarden):

```php
'path' => $endpoint,
'query_keys' => $request->query() !== []
    ? implode(',', array_keys($request->query()))
    : null,
```

Migration toevoegen voor nieuwe `query_keys`-kolom. Update
`PassThroughOdataRelatiesTest::test_get_relaties_with_top_5_query_string_is_proxied_verbatim_to_sdk`
(regel 67) zodat het niet langer asserts dat `top=5` in `row->path` zit.

---

### CR-03: `request_fingerprint` is een constante hash voor lege POST/PATCH-bodies

**File:** `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:108-110`

**Issue:**
```php
$body = in_array($method, ['POST', 'PATCH'], true) ? ($request->json()->all() ?: []) : null;
// …
'request_fingerprint' => $body === null
    ? null
    : substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12),
```

Voor elke POST/PATCH zonder body (of met niet-JSON content-type — zie CR-01)
wordt `$body = []`, en `json_encode([])` is `"[]"`. De fingerprint wordt
dan `substr(sha256('[]'), 0, 12) = 'd751713988f7'` — dezelfde 12 chars voor
elke lege/niet-JSON POST in de hele audit-tabel.

Gevolg:

- Audit-trail kan twee onafhankelijke calls niet meer onderscheiden.
- Geeft false-positive matches bij forensics ("hé, deze fingerprint zien we
  100 keer — replay attack?" terwijl het 100 onafhankelijke lege POSTs zijn).
- Maakt de fingerprint-kolom als debugging-tool nutteloos voor de meest
  voorkomende edge-case.

**Fix:** Geen fingerprint zetten voor lege/missing bodies.

```php
$hasBody = is_array($body) && $body !== [];
'request_fingerprint' => $hasBody
    ? substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12)
    : null,
```

Test toevoegen in `PassThroughAuditNoSecretsTest`:

```php
public function test_empty_post_body_yields_null_fingerprint(): void
{
    // … setup …
    $this->postJson('/v1/snelstart/relaties', [])->assertCreated();
    $row = (array) DB::table('pass_through_calls')->latest('id')->first();
    $this->assertNull($row['request_fingerprint']);
}
```

---

## Warnings

### WR-01: Middleware-attributes worden zonder null-guard gedereferenceerd

**File:** `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:94-102`

**Issue:**
```php
/** @var Account $account */
$account = $request->attributes->get('snelstart_account');
/** @var Connection $connection */
$connection = $request->attributes->get('snelstart_connection');

PassThroughCall::create([
    // …
    'account_id' => $account->getKey(),
    'connection_id' => $connection->getKey(),
```

De `@var`-PHPDoc is een hint, geen runtime-protectie. Als de
`resolve.snelstart.account`-middleware ooit per ongeluk wordt verwijderd uit
de route-definitie, of als de middleware-binding faalt in een toekomstige
refactor, retourneert `attributes->get()` `null` en crasht de controller met
een fatal "Call to a member function getKey() on null" — *na* de SDK-call,
dus na een eventuele upstream side-effect. Audit-row wordt dan niet meer
geschreven.

**Fix:** Defense-in-depth guard met early-return:

```php
$account = $request->attributes->get('snelstart_account');
$connection = $request->attributes->get('snelstart_connection');

if (! $account instanceof Account || ! $connection instanceof Connection) {
    return response()->json([
        'error' => 'resolution_failed',
        'message' => 'Pass-through-context niet correct opgezet door middleware.',
    ], Response::HTTP_INTERNAL_SERVER_ERROR);
}
```

Of doe de attribute-resolutie vóór de SDK-call zodat een config-fout
nooit een upstream-call triggert.

---

### WR-02: Audit-row wordt overgeslagen wanneer SDK-mock een non-Throwable raised

**File:** `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:60-92`

**Issue:** De try/catch vangt alleen `Throwable`. Als een toekomstige
SDK-versie een fatal-error (memory limit, type error binnen Saloon) gooit
*vóór* de try-block (bv. tijdens `app(Snelstart::class)` resolution), of
als `JSON_THROW_ON_ERROR` in regel 88 zelf throws (bij een
`JsonException` op `$mapped['body']`-encoding), dan wordt:

- Geen audit-rij weggeschreven.
- Consumer krijgt een 500 zonder Hub-context.

Specifiek: regel 88 doet `json_encode($mapped['body'], JSON_THROW_ON_ERROR)`
binnen de catch-block. Als die zelf gooit, valt de exception out van de
catch en gaat de hele audit-write op regel 99 verloren. Audit-write moet
idempotent en best-effort zijn — anders is "audit-failures-zwart-gat" een
hidden state.

**Fix:** Wrap audit-write in een eigen try/catch (log-only failure-modus) en
zet `$responseBody` op een safe-fallback wanneer `json_encode` faalt:

```php
} catch (Throwable $e) {
    $mapped = UpstreamErrorMapper::mapException($e);
    $status = $mapped['status'];
    try {
        $responseBody = json_encode($mapped['body'], JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        $responseBody = '{"error":"upstream_error","message":"Internal mapping failed"}';
    }
    // …
}

// audit-write defensief
try {
    PassThroughCall::create([…]);
} catch (Throwable $auditException) {
    Log::warning('pass_through_audit_write_failed', ['exception' => $auditException]);
}
```

---

### WR-03: 401-cloaking-policy preserveert `upstream_status: 401` in de body

**File:** `app/Support/Snelstart/UpstreamErrorMapper.php:30-42`

**Issue:** De PHPDoc claimt: "401/403 worden bewust naar 502 gemapt om de
Snelstart-auth-state niet te onthullen (zie threat T-05b-10)." Maar de body
bevat letterlijk:

```php
'upstream_status' => 401,
'upstream_detail' => 'authentication_failed',
```

Een consumer die de body inspecteert ziet alsnog dat het om een 401 ging.
Cloaking via status-code is dan kosmetiek — de echte info zit één laag dieper.
Als de intentie is "verberg dat het credential-rot is", moet ook de body
opgekuist worden:

```php
'upstream_status' => 0,
'upstream_detail' => 'auth_state_unknown',
```

Of de PHPDoc moet eerlijker zijn: "401 wordt op de status-code naar 502
gemapt zodat HTTP-client-libraries niet auto-refresh-retry doen; het detail
blijft beschikbaar in de body voor diagnose." Kies een richting; nu spreekt
de implementatie de doc-comment tegen.

**Fix:** Kies expliciet (en update threat T-05b-10 in CONTEXT.md):
verbergen → body opkuisen; diagnostisch open → doc-comment aanpassen.

---

### WR-04: `extractStatusFromMessage` regex matched ook generieke "HTTP 200"-substrings

**File:** `app/Support/Snelstart/UpstreamErrorMapper.php:135-142`

**Issue:**
```php
if (preg_match('/HTTP\s+(\d{3})/', $message, $matches) === 1) {
    return (int) $matches[1];
}
```

Als de SDK ooit een ServerException construeert met een message als
`"Snelstart API returned HTTP 503. Body: 'See HTTP 404 for legacy path'"`,
matched de regex op `HTTP 503` (eerste match). Goed. Maar als de message
ooit zou luiden `"Backend HTTP 200 OK but partial fail"`, matched het op
200 → leuke audit-data (`upstream_status: 200` voor een ServerException).

Coupling aan SDK-message-formaat zonder versie-pin is fragiel. Beter:

**Fix:** Voeg een dedicated getter op `ServerException` toe (`statusCode()`),
of als die niet binnen scope is, gebruik een striktere regex:
`/^Snelstart API returned HTTP (\d{3})/` (anchored).

```php
if (preg_match('/^Snelstart API returned HTTP (\d{3})/', $message, $matches) === 1) {
    return (int) $matches[1];
}
```

---

### WR-05: Test koppelt aan SDK-interne `InvalidArgumentException` zonder explicit contract

**File:** `tests/Feature/Services/HubSnelstartCredentialResolverTest.php:55-63`

**Issue:**
```php
public function test_resolve_throws_when_connection_has_no_snelstart_credentials(): void
{
    $mollieConnection = Connection::factory()->forMollie()->create();
    $resolver = new HubSnelstartCredentialResolver($mollieConnection);
    $this->expectException(InvalidArgumentException::class);
    $resolver->resolve();
}
```

Het Hub-resolver-contract zelf gooit nooit; de exception komt uit
`SnelstartCredentials::__construct()` in de SDK. Dit is een impliciete
afhankelijkheid: als de SDK z'n DTO-validatie wijzigt naar een eigen
exception-class, breekt de test. Ook waar de Hub *eerst* zou moeten
detecteren ("dit is geen Snelstart-Connection") komt nu de SDK over heen.

**Fix:** Doe een early provider-check in `HubSnelstartCredentialResolver`:

```php
public function __construct(private Connection $connection)
{
    if ($connection->provider !== 'snelstart') {
        throw new \DomainException(
            "HubSnelstartCredentialResolver: connection.provider moet 'snelstart' zijn, kreeg '{$connection->provider}'.",
        );
    }
}
```

Test wordt dan: `expectException(DomainException::class)` — Hub-eigen
contract.

---

### WR-06: `external_id` wordt niet getrimd vóór persistence

**File:** `app/Http/Controllers/Api/V1/AccountController.php:27`,
`app/Http/Requests/Api/V1/StoreAccountRequest.php:20`

**Issue:** Een consumer kan `'external_id' => ' school-007 '` (met
whitespace) sturen. De validatie laat dat door (`required|string|min:1|max:255`),
de controller doet `$request->string('external_id')->toString()` — geen trim.
DB krijgt `' school-007 '`. Vervolgens probeert dezelfde consumer
`'school-007'` (zonder spaces) — andere rij. Maar de **middleware** doet later
`->where('external_id', $accountHeader)` — exact match. Mismatch.

Dit is een silent-bug-pattern: data wordt ingestort die later niet meer
exact gematched kan worden uit een header (HTTP headers worden door
proxies vaak getrimd).

**Fix:** Trim in de FormRequest met `prepareForValidation`:

```php
protected function prepareForValidation(): void
{
    if ($this->has('external_id')) {
        $this->merge(['external_id' => trim((string) $this->input('external_id'))]);
    }
}
```

Of voeg een Validation-rule `regex:/^\S+(?:\s+\S+)*$/` toe om leading/trailing
whitespace te rejecten. Beide acceptabel; trim is meer "be liberal in what
you accept".

---

### WR-07: `Route::any('/snelstart/{path}')` accepteert alle HTTP-methodes; controller filtert pas in body

**File:** `routes/api.php:26-29`, `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php:23-32`

**Issue:** De catch-all route accepteert *elke* HTTP-method (HEAD, OPTIONS,
TRACE, CONNECT, …) en delegeert filtering naar de controller. Dit betekent:

1. `ResolveSnelstartAccount`-middleware runt voor TRACE/CONNECT — DB-queries
   worden gefired voor methods die we toch gaan 405'en.
2. Sanctum-auth-check eveneens — extra werk.
3. OPTIONS-preflight wordt niet correct als preflight afgehandeld; consumer
   krijgt 405 in plaats van 200 zonder body (zie test `test_options_method_returns_405_with_method_not_allowed`,
   wat lijkt op een doelbewuste keuze, maar CORS-preflight zal nu falen).

**Fix:** Beperk de route in de definitie zelf:

```php
Route::match(['GET', 'POST', 'PATCH', 'DELETE'], '/snelstart/{path}', PassThroughController::class)
    ->where('path', '.*')
    ->middleware('resolve.snelstart.account')
    ->name('api.snelstart.passthrough');
```

Dan kan de `ALLOWED_METHODS`-check uit de controller verdwijnen en is de
defensieve check overbodig. Bespaart middleware-overhead en kort de
controller in.

---

## Info

### IN-01: `PassThroughCall` model gebruikt `$timestamps = false` maar `created_at` zit wel in `$fillable`

**File:** `app/Models/PassThroughCall.php:11-30`

**Issue:**
```php
#[Fillable([
    // …
    'created_at',
])]
class PassThroughCall extends Model
{
    public $timestamps = false;
```

Mengt twee paradigma's: timestamps uit, maar `created_at` mass-assignable.
De controller doet `'created_at' => now()` in de create-call (regel 113 in
`PassThroughController`). Dat werkt, maar:

- De migration heeft `useCurrent()` op de kolom, dus DB zou het invullen
  als de controller `created_at` zou weglaten.
- Cast voor `created_at => 'datetime'` werkt prima.

Niet incorrect, maar inconsistent. Kies één: óf `$timestamps = true` met
alleen `created_at` (vereist `const UPDATED_AT = null;`), óf laat
`'created_at'` uit `$fillable` en laat DB `useCurrent()` doen.

**Fix:**
```php
public $timestamps = false;
const UPDATED_AT = null;
const CREATED_AT = 'created_at';
// of: $fillable zonder 'created_at', controller laat 't ook weg.
```

---

### IN-02: `PrimesSnelstartTokenCache` trait dupliceert credential-bouw uit `HubSnelstartCredentialResolver`

**File:** `tests/Concerns/PrimesSnelstartTokenCache.php:25-38`

**Issue:** De trait bouwt zelf een `SnelstartCredentials`-DTO uit
Connection-velden. Dezelfde logica zit in `HubSnelstartCredentialResolver`.
Als de DTO-shape verandert, moet je op twee plekken updaten.

**Fix:** Roep de resolver aan:

```php
$credentials = (new HubSnelstartCredentialResolver($connection))->resolve();
```

---

### IN-03: `ConnectionController::findOwnedConnection` doet een implicit join-query zonder eager-load

**File:** `app/Http/Controllers/Api/V1/ConnectionController.php:97-104`

**Issue:**
```php
return Connection::query()
    ->whereHas('account', fn ($q) => $q->where('consumer_id', $consumerId))
    ->find($connectionId);
```

`whereHas` triggert een subquery + de `find()` doet 1 query. Voor `show` is
het 2 queries voor wat ook 1 join-query had kunnen zijn:

```php
return Connection::query()
    ->whereExists(fn ($q) => $q->from('accounts')
        ->whereColumn('accounts.id', 'connections.account_id')
        ->where('accounts.consumer_id', $consumerId))
    ->find($connectionId);
```

Of nog cleaner via een Account-scoped lookup. Performance is out-of-v1-scope,
dus alleen flagged voor consistency: andere controllers gebruiken
`whereHas` ook, dus dit is geen blocker.

---

### IN-04: `ScrambleRouteDiscoveryTest` skipt stilletjes wanneer de catch-all niet gerendered wordt

**File:** `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php:69-74`

**Issue:** De test `test_openapi_spec_contains_snelstart_passthrough_catchall`
gebruikt `markTestSkipped` als de catch-all niet in de spec verschijnt. Dat
betekent SC-8 ("Scramble bevat alle Phase 5b routes") is feitelijk niet
gegarandeerd — een test die skipped passeert telt als pass.

**Fix:** Ofwel hard-fail wanneer Scramble dit pad niet kan renderen (en los
het op via een per-resource route naast de catch-all), ofwel verwijder de
test en track via een aparte issue/ADR. `markTestSkipped` met "TODO follow-up
ADR"-comment is een typische "groen-makende"-anti-pattern.

---

_Reviewed: 2026-05-14T20:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
