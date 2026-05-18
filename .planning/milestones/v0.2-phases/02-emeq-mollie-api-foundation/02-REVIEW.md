---
phase: 02-emeq-mollie-api-foundation
reviewer: gsd-code-reviewer
created: 2026-05-14
status: issues
findings_count:
  critical: 0
  high: 2
  medium: 4
  low: 5
---

# Code Review — Phase 02: emeq/mollie-api foundation

## Samenvatting

Goed onderhouden skeleton-package met sterke aandacht voor security (fingerprint-only logging, geen raw secrets in exception-messages), nette PHP 8.4-idiomen (readonly classes, `match`, constructor property promotion), en complete test-suite (33 tests / 86 assertions). Het outer-layer pattern spiegelt `emeq/snelstart-api` zoals afgesproken.

Twee HIGH-findings: (1) `Mollie::class` is gebonden als **singleton** waardoor host-apps die `MollieCredentialResolver::class` per-job rebinden in queue workers de stale eerste resolver krijgen — dat ondermijnt het multi-tenant verkoopverhaal van Phase 2. (2) `config('mollie.http.timeout')` en `config('mollie.http.guzzle_options')` worden gepubliceerd, gedocumenteerd ("wordt gebruikt om een custom Guzzle-client te bouwen"), en getest op publish-waarde — maar nergens geconsumeerd door `Mollie::client()`. Dead config + misleidende documentatie.

Geen CRITICAL findings: token security is consistent (fingerprint-only, geen raw secrets in messages of `__toString`), multi-tenant isolatie werkt voor de gangbare use-case (resolver returnt verschillende credentials), en de Mollie-API-surface (`setApiKey`, `setAccessToken`, `setIdempotencyKeyGenerator`, `getAuthenticator`) matcht de werkelijke `mollie/mollie-api-php` v3.11 vendor-code.

## HIGH Findings

### H-1 — `Mollie::class` singleton-binding lekt eerste resolver in queue workers

**File:** `packages/mollie-api/src/MollieServiceProvider.php:29-39`

**Probleem:** `Mollie::class` is gebonden via `$this->app->singleton(...)`. De factory captured de **eerste resolved** `MollieCredentialResolver` via `$app->make(MollieCredentialResolver::class)`. Een Hub queue worker die per job de `MollieCredentialResolver::class`-binding opnieuw zet (gebruikelijk multi-tenant pattern: scoped binding per `Connection`) krijgt voor de tweede en volgende jobs **dezelfde resolver-instantie** terug — niet de actuele binding.

Bewijs: in `tests/Unit/MollieTest.php:49` en `:67` (en alle volgende tests) wordt expliciet `$this->app->forgetInstance(Mollie::class)` aangeroepen vóór elke rebind. Die noodgreep impliceert dat de auteur al weet dat de eerste binding sticky is — maar host-apps gaan dat niet doen. De docblock op `Mollie::client()` regel 50-53 belooft "Returns a NEW instance on every call — never cache or share across tenants" en regel 41-42 "Subsequent calls re-invoke the resolver — useful when the host app switches tenants mid-request". Beide claims kloppen alleen als de oorspronkelijke resolver zelf intern de juiste tenant kan vinden (e.g. `tenant()->settings()`-lookup). Voor host-apps die per-job container-scoping of `$app->forgetInstance` doen, lekt de eerste resolver naar alle volgende jobs.

Dit raakt direct ROADMAP Phase 2 success criterion 2 ("MollieCredentialResolver runtime-swap zonder cross-tenant lekkage") — de test `02-06 MollieTest` op regel 37-68 demonstreert isolatie alleen omdat de test eerst `forgetInstance` doet en omdat de Fake-resolver via interne `sequence`-index per `resolve()`-aanroep een ander credential teruggeeft. Een externe binding-switch zou níet werken.

**Fix:** Twee opties, te kiezen door de auteur:

(a) Maak `Mollie::class` non-singleton (`$this->app->bind(...)` ipv `singleton(...)`). Dit is goedkoop omdat `Mollie::class` zelf stateless is. Elke resolve trekt de actuele `MollieCredentialResolver`-binding op:

```php
$this->app->bind(Mollie::class, function (Application $app): Mollie {
    if (! $app->bound(MollieCredentialResolver::class)) {
        throw MissingCredentialResolverException::notBound();
    }

    return new Mollie(
        resolver: $app->make(MollieCredentialResolver::class),
        config: $app->make('config'),
        container: $app,
    );
});
```

En de test `MollieServiceProviderTest::it('binds Mollie::class as a singleton')` op regel 10-20 moet meebewegen — die test cementeert nu het bug-gedrag.

(b) Houd singleton, maar laat `Mollie::client()` de resolver opnieuw uit de container halen op elke aanroep:

```php
public function client(): MollieApiClient
{
    $resolver = $this->container->make(MollieCredentialResolver::class);
    $creds = $resolver->resolve();
    ...
}
```

Optie (a) is conceptueel schoner; optie (b) houdt de huidige singleton-shape. Beide vergen een nieuwe test die expliciet rebindt zonder `forgetInstance` en bewijst dat de tweede `client()`-call de nieuwe resolver gebruikt.

---

### H-2 — `config('mollie.http.timeout')` + `guzzle_options` gepubliceerd maar nergens geconsumeerd

**File:** `packages/mollie-api/config/mollie.php:17-31` (gepubliceerd) + `packages/mollie-api/src/Mollie.php:54-70` (zou consumer moeten zijn) + `packages/mollie-api/tests/PackageSmokeTest.php:22` (asserts publish-waarde)

**Probleem:** De config-key `mollie.http.timeout` belooft via inline comment "Wordt gebruikt om een custom Guzzle-client te bouwen die aan MollieApiClient wordt doorgegeven" en `mollie.http.guzzle_options` belooft "Extra Guzzle-options merged op de default". Beide zijn dode config. `Mollie::client()` instantieert `new MollieApiClient()` zonder enige Guzzle-customization (`setHttpClient()` of equivalent wordt nergens aangeroepen). `grep -rn "http.timeout\|guzzle_options" src/` levert nul hits in productiecode op.

De publish-test (`PackageSmokeTest.php:22`) checkt alleen dat de **default-waarde 30** is — niet dat het ook bij Mollie aankomt. Een gebruiker die `MOLLIE_HTTP_TIMEOUT=60` in `.env` zet en vervolgens een trage Mollie-call doet, krijgt nog steeds de Guzzle-default timeout (geen timeout) of de Mollie-library-default — niet 60s.

Dit is een misleidende API-belofte naar de host-app, en raakt v0.1 success-criteria voor "klopt de documentatie" uit de docs-sync skill.

**Fix:** Kies één van twee:

(a) **Implementeer de config-consumptie** in `Mollie::client()` voordat de `MollieApiClient` wordt aangemaakt:

```php
public function client(): MollieApiClient
{
    $creds = $this->credentials();
    $this->guardEnvironment($creds);

    $httpClient = new \GuzzleHttp\Client(array_merge(
        ['timeout' => (int) $this->config->get('mollie.http.timeout', 30)],
        (array) $this->config->get('mollie.http.guzzle_options', []),
    ));

    $client = new MollieApiClient(httpClient: $httpClient);
    // (controleer in Mollie's MollieApiClient-ctor hoe Guzzle-injectie heet; v3.11 verwacht
    //  een PSR-18 ClientInterface via setHttpClient() of via de constructor)
    ...
}
```

Voeg dan een test toe die bewijst dat `MOLLIE_HTTP_TIMEOUT=5` daadwerkelijk een Guzzle-client met `timeout=5` aan Mollie geeft.

(b) **Verwijder de dode config** + de inline-comments die de feature beloven, en defer Guzzle-customization tot een latere phase waar het echt nodig is. Dit is veiliger gegeven dat Phase 2 expliciet "dunne, skeleton" is.

Aanbevolen: optie (b) — verwijder de dode keys nu, ga niet meer beloven dan je levert.

## MEDIUM Findings

### M-1 — `match (true)` in `Mollie::client()` heeft geen `default`-arm; nieuwe subclass leidt tot `UnhandledMatchError`

**File:** `packages/mollie-api/src/Mollie.php:62-65`

**Probleem:** De match-expressie:

```php
match (true) {
    $creds instanceof MollieApiKeyCredentials => $client->setApiKey($creds->apiKey),
    $creds instanceof MollieOAuthCredentials  => $client->setAccessToken($creds->accessToken),
};
```

heeft geen `default`. Als ooit een derde `MollieCredentials`-subclass wordt toegevoegd (en de host-app vergeet de Mollie-package te updaten, of doet een verkeerde resolver-return), valt het door naar PHP's `UnhandledMatchError` — een vendor-laag-exception die niet door de `MollieException`-base loopt. Host-apps die op `catch (MollieException $e)` rekenen vangen 'm niet.

`MollieCredentials` is abstract maar niet final/sealed. Een derde subclass is toekomst-mogelijk (e.g. een PAT/PSP-implementatie voor witteboorden-rekeningen).

**Fix:** Voeg een `default`-arm toe die `MollieException` gooit met type-info (geen secret-material):

```php
match (true) {
    $creds instanceof MollieApiKeyCredentials => $client->setApiKey($creds->apiKey),
    $creds instanceof MollieOAuthCredentials  => $client->setAccessToken($creds->accessToken),
    default => throw new MollieException(sprintf(
        'Unsupported MollieCredentials subclass: %s. Expected MollieApiKeyCredentials or MollieOAuthCredentials.',
        $creds::class,
    )),
};
```

Voeg een test toe in `MollieTest` die een anoniem `MollieCredentials`-subclass via een custom resolver pusht en op deze fout asserts.

---

### M-2 — `Mollie::client()` test in `MollieTest.php` bewijst niet dat de OAuth-token correct in de authenticator wordt gezet

**File:** `packages/mollie-api/tests/Unit/MollieTest.php:25-35`

**Probleem:** De test "builds a MollieApiClient with an authenticator when an OAuth resolver is bound" assert alleen dat `getAuthenticator()` niet null is — geen check dat de **specifieke access-token** in de authenticator zit. Vergelijk met de overeenkomstige B-5 test in `ErrorMappingTest.php:58-91` die met reflection bewijst dat `$apiKey` daadwerkelijk in `BearerTokenAuthenticator::$token` zit voor de API-key-flow.

Voor OAuth ontbreekt een gelijkwaardige assertion. Een regressie waarin per ongeluk `setApiKey($creds->accessToken)` zou worden aangeroepen (typo, copy-paste) wordt door de huidige test niet betrapt — `getAuthenticator()` zou nog steeds non-null zijn (gewoon een verkeerd type).

**Fix:** Voeg een reflection-assertion toe parallel aan B-5 voor OAuth-credentials:

```php
$reflection = new ReflectionClass(BearerTokenAuthenticator::class);
$tokenProp = $reflection->getProperty('token');
$tokenProp->setAccessible(true);

expect($tokenProp->getValue($authenticator))->toBe($accessToken);
```

(Verifieer dat Mollie voor OAuth ook `BearerTokenAuthenticator` gebruikt of een aparte `OAuthAuthenticator` — de check moet matchen met wat `setAccessToken()` daadwerkelijk installeert.)

---

### M-3 — `MollieApiClient::fake()` in eerste ErrorMappingTest omzeilt onze hele SDK-wiring

**File:** `packages/mollie-api/tests/Unit/ErrorMappingTest.php:28-56`

**Probleem:** De test "surfaces ValidationException with usable getField() on a 422 response via Mollie::fake()" gebruikt `MollieApiClient::fake([...])` direct — niet via `app(Mollie::class)->client()`. De test bewijst feitelijk alleen dat Mollie's eigen library een 422 in een `ValidationException` met `getField()` omzet — wat een vendor-eigenschap is, niet iets dat onze SDK aanlevert. Voor 02-07 success criteria ("error mapping correct via onze package") is dit te dun bewijs.

De inline comment op regel 22-26 erkent dit (de `fake()` is statisch, dus kan niet via container vervangen worden), maar het effect is dat dit test-bedrag niet bijdraagt aan dekking van onze code. De tweede test in dezelfde file (B-5 op regel 58-91) doet wel het juiste — die zou voldoende zijn als enige error-mapping-test, mits we de ValidationException-coverage elders aantonen.

**Fix:** Twee opties:

(a) Verwijder de eerste test of stem het claim-doel af: documenteer in de testtitel dat het puur het Mollie-vendor-contract bewijst (e.g. "Mollie vendor surfaces ValidationException::getField() — vendor contract baseline"), zodat het niet ten onrechte als onze coverage telt.

(b) Bouw een full-integration variant die WEL via onze factory loopt: gebruik `Saloon`'s `MockClient` of Mollie's `PendingRequest`-injectie zodat de mock op de uitkomst van `app(Mollie::class)->client()` werkt. Vereist meer reverse-engineering van Mollie v3.11's testing-pad.

(c) Accepteer (a) — Phase 2 doet alleen wiring, niet HTTP-mapping. Stel echte error-mapping uit naar de fase waar resources écht worden geraakt (Phase 5 / MOLL-03).

---

### M-4 — `FakeMollieCredentialResolver::sequence()` faalt stil bij verkeerde input-shape

**File:** `packages/mollie-api/tests/Support/FakeMollieCredentialResolver.php:68-97`

**Probleem:** De sequence-shortcut accepteert `[FQCN => list<string>]` (regel 74-87) of `list<MollieCredentials>` (regel 91-93). Als een gebruiker per ongeluk een tussenliggende vorm pasees, bijv. `[MollieApiKeyCredentials::class => 'test_a']` (string ipv list<string>) of `[0 => 'test_a']` (string ipv MollieCredentials-instance), valt het door beide guards en levert het stil een lege `$instances` — waarna de constructor `InvalidArgumentException("needs at least one credential")` gooit zonder uitleg dat de input-shape verkeerd was.

Niet een productie-pad maar test-DX matters wanneer je later extra resolvers schrijft.

**Fix:** Voeg een expliciete fallback toe die de gebruiker informeert:

```php
foreach ($credentials as $key => $value) {
    if (is_string($key) && is_array($value)) {
        // ... existing FQCN-map handling
        continue;
    }

    if ($value instanceof MollieCredentials) {
        $instances[] = $value;
        continue;
    }

    throw new InvalidArgumentException(sprintf(
        'FakeMollieCredentialResolver::sequence() got unsupported entry [%s => %s]. ' .
        'Expected list<MollieCredentials> or [FQCN => list<string>].',
        var_export($key, true),
        is_object($value) ? $value::class : gettype($value),
    ));
}
```

## LOW Findings

### L-1 — `MissingCredentialResolverException::notBound()` toont FQCN twee keer in plaats van class-shortname

**File:** `packages/mollie-api/src/Exceptions/MissingCredentialResolverException.php:22-30`

De message-template:

```
No Emeq\MollieApi\Contracts\MollieCredentialResolver binding found in the container.
Bind your resolver in a ServiceProvider, e.g.:
$this->app->bind(Emeq\MollieApi\Contracts\MollieCredentialResolver::class, YourTenantResolver::class);
```

is correct maar wordt bij de tweede vermelding nogal lang. Stylistisch: gebruik de class-shortname in de hint en de FQCN alleen één keer voor disambiguatie. Geen functionele fix nodig.

---

### L-2 — Config-comment-stijl mengt `/* */` met inline `|` pipe-art (Laravel-stijl)

**File:** `packages/mollie-api/config/mollie.php:33-48`

De `'idempotency'`-sectie gebruikt PSR-stijl `|`-prefix-comments (Laravel-config-stijl) terwijl andere secties (`'enforce_environment'`, `'http'`) gewone `/* */` blocks gebruiken. Kies één stijl voor consistentie binnen het bestand.

---

### L-3 — `Mollie::credentials()` retourneert nieuwe `MollieCredentials` per call, maar dat is niet expliciet getest

**File:** `packages/mollie-api/src/Mollie.php:44-47` + `tests/Unit/MollieTest.php`

De docblock belooft "Subsequent calls re-invoke the resolver". Geen directe test in `MollieTest` die `credentials()` twee keer aanroept en bewijst dat de tweede call de resolver opnieuw aanroept (anders dan via `client()` waar het indirect uitkomt via "fresh per call"-assertions). Niet kritiek, want `client()` doet `$this->credentials()` intern, maar een directe assertie maakt het contract toetsbaar als de implementatie later wordt opgesplitst.

---

### L-4 — Inconsistente spatie in `! str_starts_with` vs idiomatische `!str_starts_with`

**File:** `packages/mollie-api/src/Data/MollieApiKeyCredentials.php:33`, `MollieOAuthCredentials.php:33`, `MollieServiceProvider.php:30`, `Mollie.php:95`

De codebase gebruikt `! str_starts_with(...)` met een spatie tussen `!` en het predicaat. Dat is Pint's `unary_operator_spaces`-rule met `spaces_around=true`, prima — maar PSR-12 en de meeste Laravel-codebases gebruiken `!str_starts_with(...)`. Niet fout, maar gemarkeerd als style-keuze die afwijkt van de PSR-12 preset die in `pint.json` staat. Vermoedelijk geconfigureerd via override (`pint.json` heeft `mb_str_functions` disabled, mogelijk ook deze rule). Geen actie nodig tenzij je Pint-output dit als drift markeert.

---

### L-5 — Test-fixture `apiKey` strings padding-A's voldoen aan Mollie's `TokenValidator::isApiKey()` ≥30-chars maar zijn opaque

**File:** `packages/mollie-api/tests/Unit/MollieTest.php:16, 28, 44-47, 79, 93, 103, 113` + `ErrorMappingTest.php:61`

Strings als `'test_alphaAAAAAAAAAAAAAAAAAAAAAAAAA'` zijn ondoorzichtig — een lezer ziet niet meteen waarom dat padding nodig is. De comment in `MollieTest.php:38-40` legt dit één keer uit (TokenValidator ≥30 chars), maar herhalingen in andere files herhalen dat niet. Stylistisch: maak een test-helper-constant:

```php
// In tests/Support of Pest.php:
const TEST_MOLLIE_APIKEY_MIN = 'test_minlength_thirty_chars_xxxxx';
```

Geen functionele issue, alleen leesbaarheid bij review.

---

_Reviewed: 2026-05-14_
_Reviewer: gsd-code-reviewer_
_Depth: deep (cross-file: Mollie.php ↔ MollieServiceProvider.php ↔ vendor/mollie/mollie-api-php/src/)_
