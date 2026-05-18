# Plan 05a-03 Pre-flight verifie (B3 — SC-5 hard gate)

**Datum:** 2026-05-15
**Doel:** bevestig dat consumer-Idempotency-Key forward + dedup-emulation technisch werken op vendor `mollie/mollie-api-php@3.10.0` voordat Tasks 1-3 erop bouwen.

## V1 — `MollieApiClient` constructor + idempotency-generator-injectie + runtime-setter

**Bron:** `vendor/mollie/mollie-api-php/src/MollieApiClient.php` regels 153-166 +
`vendor/mollie/mollie-api-php/src/Traits/HandlesIdempotency.php` regels 18-39.

**Constructor-signature (verbatim, regel 153-157):**

```php
public function __construct(
    $client = null,
    ?MollieHttpAdapterPickerContract $adapterPicker = null,
    ?IdempotencyKeyGeneratorContract $idempotencyKeyGenerator = null
) {
```

**Runtime-setters bestaan en zijn publiek** (`HandlesIdempotency`-trait):

| Method | Signature | Effect |
|---|---|---|
| `setIdempotencyKey($key): self` | regel 24 | zet één-shot `idempotency-key` voor de NEXT request; reset na elke request automatisch. Supersedes de generator als beide gezet zijn. |
| `setIdempotencyKeyGenerator(IdempotencyKeyGeneratorContract): self` | regel 72 | overschrijft de generator runtime. |
| `getIdempotencyKey(): ?string` | regel 41 | read-back voor test-assertions. |
| `getIdempotencyKeyGenerator(): ?IdempotencyKeyGeneratorContract` | regel 47 | read-back voor test-assertions. |
| `resetIdempotencyKey(): self` | regel 55 | wis huidige key. |
| `clearIdempotencyKeyGenerator(): self` | regel 80 | wis huidige generator. |

**Auth-setters werken post-construction zonder ServiceProvider-tussenkomst** (`HandlesAuthentication`-trait regels 11-44):
`setApiKey(string)` en `setAccessToken(string)` instantiëren `ApiKeyAuthenticator` resp. `AccessTokenAuthenticator` en zijn safe op een al-gebouwde client.

**Pad voor consumer-key-forward (gekozen):** **(b) runtime-setter via `MollieApiClient::setIdempotencyKey($consumerKey)`**.

Reden: simpeler dan een verse client bouwen (de SDK's `Mollie::client()` regelt al credentials, env-guard en optionele config-generator). Een one-shot setter past 1-op-1 in de bestaande controller-flow zonder dat we de SDK omzeilen.

**Implicatie Task 2 `PaymentsController::buildClient()`:**

```php
private function buildClient(Request $request): MollieApiClient
{
    $client = Mollie::client();

    $consumerKey = $request->header('Idempotency-Key');
    if (is_string($consumerKey) && $consumerKey !== '') {
        $client->setIdempotencyKey($consumerKey);
    }

    return $client;
}
```

Geen reflection nodig, geen verse `new MollieApiClient()`. De SDK-default-generator (uit `config('mollie.idempotency.generator')`) blijft actief wanneer Consumer geen header meestuurt; `setIdempotencyKey()` overschrijft de generator-output voor één request en wist zichzelf daarna (regel `idempotencyKey resets to null after each request`).

## V2 — `IdempotencyKeyGeneratorContract` method-naam

**Bron:** `vendor/mollie/mollie-api-php/src/Contracts/IdempotencyKeyGeneratorContract.php` regel 5-8.

```php
interface IdempotencyKeyGeneratorContract
{
    public function generate(): string;
}
```

**Method:** `generate(): string` (zonder argumenten, return-type `string`).

**Implicatie Task 1 `ConsumerIdempotencyKeyGenerator`:** implementeert `generate(): string` exact. Niet `generateKey()`, niet `__invoke()`. Onze SDK's `UuidV7IdempotencyKeyGenerator` volgt al deze signature (`packages/mollie-api/src/Idempotency/UuidV7IdempotencyKeyGenerator.php` regel 22).

Met V1-uitkomst (runtime-setter `setIdempotencyKey`) is `ConsumerIdempotencyKeyGenerator` strikt genomen **niet nodig** — we hoeven geen generator-instantie te bouwen voor één-shot key-forwarding. Plan-stap die de class voorschrijft blijft echter aangehouden voor:
1. Plan-acceptance-criteria (Task 1 verlangt `app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php` op disk).
2. Toekomstige use-case waarin een generator wèl meegegeven moet worden (bv. tests die `setIdempotencyKeyGenerator()` gebruiken om een vaste key te forceren).

## V3 — `MollieApiClient::fake()` + dedup-emulation

**Bron:** `vendor/mollie/mollie-api-php/src/MollieApiClient.php` regel 186-189 +
`vendor/mollie/mollie-api-php/src/Fake/MockMollieClient.php` regels 1-33 +
`vendor/mollie/mollie-api-php/src/Fake/MockResponse.php` regels 21-90.

**Fake-helper bestaat als publieke statische factory:**

```php
public static function fake(array $expectedResponses = [], bool $retainRequests = false): MockMollieClient
{
    return new MockMollieClient($expectedResponses, $retainRequests);
}
```

`MockMollieClient` is een subclass van `MollieApiClient` met een `MockMollieHttpAdapter`. `assertSent($callback)` en `assertSentCount(int)` zijn beschikbaar voor test-assertions.

**Dedup-emulation-pad voor SC-5 W7-test (2× POST → same Payment-id):**
We hoeven Mollie's server-side dedup-window NIET in de fake te emuleren. Voor SC-5 is het bewijs:

1. Hub forward't de Consumer's `Idempotency-Key`-header verbatim naar Mollie (V1).
2. Twee opeenvolgende `payments->create($payload)`-calls met dezelfde key zouden bij echte Mollie dezelfde payment-id retourneren (Mollie's 24h-dedup-venster).
3. In een mock-context emuleren we dat door **dezelfde Payment-resource-response** te returnen voor beide calls — assert dat:
   - Beide responses dezelfde `id`-waarde hebben.
   - De Hub heeft de header VERBATIM naar Mollie verstuurd (capture'd via een mock die de `getIdempotencyKey()`-waarde inspecteert vóór de call).

**Strategie (concreet):** **bind de `\Emeq\MollieApi\Mollie`-wrapper via een PHPUnit mock op de container**, vergelijkbaar met `tests/Feature/Webhooks/ThrowingMollieApiClient.php` uit Plan 05a-02. De `client()`-method returnt een test-double `MollieApiClient`-subclass die:

- `__get('payments')` retourneert een test-only payment-endpoint-stub.
- De stub's `create($data)`-method capture't de `$this->client->getIdempotencyKey()` net vóór de "call" en returnt een vooraf-geconfigureerde Payment-array.

**Test-skelet-snippet (W7 — SC-5 dedup):**

```php
public function test_two_post_with_same_idempotency_key_returns_same_mollie_payment_id(): void
{
    $capturedKeys = [];
    $capturedPayloads = [];

    // Bind een fake Emeq\MollieApi\Mollie waarvan client() altijd een StubMollieApiClient retourneert.
    [$consumer, $token, $account, $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

    $this->bindFakeMollieClient(function (Request $request) use (&$capturedKeys, &$capturedPayloads, $connection) {
        // Captured runtime-idempotency-key + payload — assert later
    }, paymentToReturn: ['id' => 'tr_dedup_xyz', 'status' => 'open', '_links' => ['checkout' => ['href' => 'https://mollie.test/checkout']]]);

    $payload = ['description' => 'Test', 'amount' => ['currency' => 'EUR', 'value' => '12.34']];
    $headers = ['Authorization' => "Bearer {$token}", 'X-Account-Id' => 'school-A', 'Idempotency-Key' => 'idem-test-001'];

    $resp1 = $this->withHeaders($headers)->postJson('/v1/mollie/payments', $payload);
    $resp2 = $this->withHeaders($headers)->postJson('/v1/mollie/payments', $payload);

    $resp1->assertCreated()->assertJsonPath('id', 'tr_dedup_xyz');
    $resp2->assertCreated()->assertJsonPath('id', 'tr_dedup_xyz');
    $this->assertSame('idem-test-001', $capturedKeys[0]);
    $this->assertSame('idem-test-001', $capturedKeys[1]);
}
```

**Mock-strategie (final):** geen `MollieApiClient::fake()` direct, omdat `MockMollieClient` HTTP-level mockt (response-bodies via `MockResponse`) en in onze controller-flow gaan we via `$client->payments->create()` waar de hydrated-resource al teruggegeven is. Voor unit-stijl assertions op `getIdempotencyKey()` is een eenvoudigere container-bind met een test-only `StubMollieApiClient` precieser. Patroon overgenomen van Plan 05a-02's `ThrowingMollieApiClient`-stub.

Voor de happy-path-tests (PaymentsTest) gebruiken we hetzelfde stub-pad met een capturing-closure: één punt van capture (payload + headers), één return-array per scenario. Geen echte HTTP-call.

## Conclusie

Alle drie verifie-punten groen. Geen blokker:
- V1: runtime-setter `setIdempotencyKey()` is het preferred pad — eenvoudiger dan constructor-injection.
- V2: `generate(): string` is bevestigd.
- V3: `MollieApiClient::fake()` bestaat; we kiezen het stub-client-pad voor precieze key-capture (matches Plan 05a-02-pattern).

Tasks 1-3 kunnen starten met deze padzekerheid.
