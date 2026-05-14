---
phase: 260514-qxk-quick
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php
  - app/Models/PassThroughCall.php
  - app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php
  - tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php
  - tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php
autonomous: true
requirements:
  - HUB-05
  - PHASE-05b-REVIEW-CR-01
  - PHASE-05b-REVIEW-CR-02
  - PHASE-05b-REVIEW-CR-03
must_haves:
  truths:
    - "POST/PATCH op /v1/snelstart/* met een niet-JSON Content-Type krijgt 415 unsupported_content_type vóórdat de SDK-call gefired wordt"
    - "pass_through_calls.path bevat alleen het endpoint-pad, nooit een raw query-string (geen e-mails, filters of select-waarden)"
    - "pass_through_calls.query_keys bevat alleen de query-parameter-sleutelnamen (csv) of NULL — nooit waarden"
    - "Een lege POST/PATCH body produceert request_fingerprint=NULL i.p.v. de constante sha256('[]')-prefix"
    - "Bestaande happy-path tests (echo ping, OData $top=5, $filter+select, audit-no-secrets) blijven groen na de fixes"
  artifacts:
    - path: "database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php"
      provides: "Nieuwe nullable string kolom query_keys op pass_through_calls"
      contains: "Schema::table"
    - path: "app/Models/PassThroughCall.php"
      provides: "query_keys in Fillable-attribuut"
      contains: "query_keys"
    - path: "app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php"
      provides: "415-guard, query-key-extractie, conditional fingerprint"
      contains: "HTTP_UNSUPPORTED_MEDIA_TYPE"
  key_links:
    - from: "PassThroughController::__invoke"
      to: "PassThroughCall::create"
      via: "path zonder query-string + query_keys-csv + null-fingerprint voor lege body"
      pattern: "query_keys.*=>"
    - from: "PassThroughController::__invoke (POST/PATCH-tak)"
      to: "415-response"
      via: "Content-Type-header check vóór SDK-call"
      pattern: "application/json"
---

<objective>
Sluit de drie BLOCKER-findings uit `.planning/phases/05b-snelstart-pass-through-api/05b-REVIEW.md` (CR-01, CR-02, CR-03) zodat Phase 5b mag mergen.

Purpose: Vóór merge moet de pass-through-API (1) niet stilzwijgend non-JSON-bodies droppen, (2) geen PII uit OData-querystrings in `pass_through_calls.path` lekken, en (3) niet voor elke lege POST/PATCH dezelfde fingerprint produceren. Alle drie zijn één-controller-incident + één-migration + test-updates.

Output:
- Migration die `query_keys` (nullable string) toevoegt aan `pass_through_calls`
- `PassThroughCall` model met `query_keys` in `#[Fillable]`
- `PassThroughController` met (a) 415-guard voor non-JSON POST/PATCH, (b) endpoint-only `path` + aparte `query_keys`-csv, (c) NULL-fingerprint voor lege body
- Test-updates: bestaande OData-test mag niet meer `top=5` in `row->path` asserten + nieuwe coverage voor 415-pad, query_keys-audit en empty-body-fingerprint
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.planning/phases/05b-snelstart-pass-through-api/05b-REVIEW.md
@app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php
@app/Models/PassThroughCall.php
@database/migrations/2026_05_15_000001_create_pass_through_calls_table.php
@database/factories/PassThroughCallFactory.php
@tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php
@tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php
@tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php

<interfaces>
<!-- Huidige controller-shape (regels die wijzigen) -->

PassThroughController::__invoke regel 51 (CR-01):
```php
$body = in_array($method, ['POST', 'PATCH'], true) ? ($request->json()->all() ?: []) : null;
```

PassThroughController::__invoke regel 105 (CR-02):
```php
'path' => $endpoint.($request->getQueryString() !== null ? '?'.$request->getQueryString() : ''),
```

PassThroughController::__invoke regel 108-110 (CR-03):
```php
'request_fingerprint' => $body === null
    ? null
    : substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12),
```

PassThroughCall model heeft `#[Fillable([...])]` boven de class; `query_keys` moet daar bij.

Migration-pattern in deze repo: dedicated `Schema::table()`-migration per kolom-toevoeging (forward-only — invariant uit CLAUDE.md "Migrations zijn forward-only in prod"). Filename met datum-prefix `2026_05_15_000002_*`.

Test-conventie: PHPUnit (geen Pest), `RefreshDatabase`-trait, `Saloon\Http\Faking\MockClient` voor SDK-mocks, `Tests\Concerns\PrimesSnelstartTokenCache` voor token-priming.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Migration + model-fillable voor query_keys-kolom</name>
  <files>database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php, app/Models/PassThroughCall.php</files>
  <behavior>
    - Na `php artisan migrate`: kolom `query_keys` bestaat op `pass_through_calls` (nullable string, geen index nodig — alleen audit)
    - `PassThroughCall::create([..., 'query_keys' => '$top,$filter'])` schrijft de waarde weg en kan via `$row->query_keys` worden gelezen
    - `PassThroughCall::create([..., 'query_keys' => null])` blijft toegestaan (default voor zero-query calls)
    - Bestaande pass_through_calls-rijen vanuit de factory blijven aanmaakbaar (factory hoeft niet aangepast — default = null is impliciet)
  </behavior>
  <action>
    1. Maak migration met `php artisan make:migration add_query_keys_to_pass_through_calls_table --table=pass_through_calls --no-interaction`. Hernoem het file zo nodig naar `2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php` zodat het sequentieel ná `2026_05_15_000001_create_pass_through_calls_table.php` draait.
    2. In `up()`: `Schema::table('pass_through_calls', fn (Blueprint $t) => $t->string('query_keys')->nullable()->after('path'));`. Geen index — column is puur audit-leesbaar. `down()` mag de kolom droppen (`Schema::table(..., fn ($t) => $t->dropColumn('query_keys'))`) maar prod is forward-only.
    3. Voeg `'query_keys'` toe aan het `#[Fillable([...])]`-attribuut op `App\Models\PassThroughCall` (logische plek: direct na `'path'`). Geen cast nodig — string of null is default Eloquent-gedrag.
    4. Niet aanraken: `PassThroughCallFactory` (default `query_keys = null` werkt prima zonder expliciete entry), `casts()`-methode, `$timestamps = false`-policy.
    5. `php artisan migrate` lokaal draaien om DB-staat synchroon te krijgen (executor doet dit voor de tests in task 2 lopen).
  </action>
  <verify>
    <automated>php artisan migrate --no-interaction && php artisan test --compact --filter=PassThroughCallModelTest</automated>
  </verify>
  <done>
    - Migration-file bestaat met juiste datum-prefix en draait `migrate` schoon
    - `query_keys` zichtbaar in `pass_through_calls` via `php artisan db:table pass_through_calls` of `database-schema`-tool
    - `PassThroughCall`-model heeft `query_keys` in `#[Fillable]`
    - PassThroughCallModelTest blijft groen (geen regressie op bestaande model-asserts)
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Controller-fixes CR-01 + CR-02 + CR-03</name>
  <files>app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php</files>
  <behavior>
    - POST `/v1/snelstart/relaties` met `Content-Type: application/x-www-form-urlencoded` of zonder Content-Type retourneert 415 met `{"error":"unsupported_content_type","message":"..."}` en schrijft GEEN audit-rij (geen SDK-call gefired)
    - POST/PATCH met `Content-Type: application/json` (of `application/json; charset=utf-8`) blijft werken zoals nu
    - GET `/v1/snelstart/relaties?$top=5&$filter=...`: audit-rij heeft `path = '/relaties'` (geen `?`-segment), `query_keys = '$top,$filter'`
    - GET zonder query: `path = '/relaties'`, `query_keys = null`
    - POST met body `{}` (lege object) en correct Content-Type: `request_fingerprint = null` (niet `d751713988f7` of welke 12-char-hash dan ook van `[]`)
    - POST met body `{"naam":"x"}`: `request_fingerprint` is 12-char sha256-prefix zoals voorheen
    - GET (zonder body) blijft `request_fingerprint = null` zoals bestaande PassThroughEchoPingTest assert
  </behavior>
  <action>
    1. **CR-01 (regel 51):** Vervang de body-resolution-regel door een expliciet contract:
       ```php
       if (in_array($method, ['POST', 'PATCH'], true)) {
           $contentType = strtolower((string) $request->header('Content-Type', ''));
           if (! str_starts_with($contentType, 'application/json')) {
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
       Plaats deze block **vóór** `microtime(true)`-start en vóór de SDK-call: een 415 mag geen audit-rij produceren (consistent met de bestaande 403/405-paden die ook zonder audit-rij retourneren — zie `PassThroughEchoPingTest::test_token_with_only_mollie_read_ability_returns_403_on_snelstart_get` regel 109).
    2. **CR-02 (regel 105):** Vervang de `path`-assignment + voeg `query_keys` toe:
       ```php
       $queryKeys = $request->query();
       'path' => $endpoint,
       'query_keys' => $queryKeys !== [] ? implode(',', array_keys($queryKeys)) : null,
       ```
       Pas op: `$query = $request->query()` bestaat al op regel 49 — hergebruik die variabele i.p.v. opnieuw aan te roepen.
    3. **CR-03 (regel 108-110):** Vervang de fingerprint-expressie zodat lege array → null:
       ```php
       'request_fingerprint' => (is_array($body) && $body !== [])
           ? substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12)
           : null,
       ```
       Dit handhaaft het bestaande contract dat GET geen fingerprint heeft (`$body === null`), en breidt uit met "lege body → null".
    4. Niet aanraken: ability-check (regel 34-46), HeaderForwarder-call, SDK-call zelf, UpstreamErrorMapper-pad, response-rendering. Scope is chirurgisch: drie regels semantiek wijzigen + één 415-guard toevoegen.
    5. `vendor/bin/pint --dirty --format agent` na de wijziging.
  </action>
  <verify>
    <automated>php artisan test --compact --filter='PassThroughController|PassThrough'</automated>
  </verify>
  <done>
    - Controller heeft 415-guard die feuert vóór de SDK-call
    - `path` bevat geen `?`-segment meer in audit-rijen
    - `query_keys` wordt csv-string van keys of null
    - Empty-body fingerprint = null
    - Bestaande tests in `tests/Feature/Api/V1/Snelstart/` blijven groen behalve de twee asserts uit `PassThroughOdataRelatiesTest` regel 67 + de "fingerprint not-null"-assert in `PassThroughAuditNoSecretsTest` regel 115 — die worden in task 3 expliciet bijgewerkt
    - Pint groen
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Test-updates + nieuwe coverage voor CR-01/CR-02/CR-03</name>
  <files>tests/Feature/Api/V1/Snelstart/PassThroughOdataRelatiesTest.php, tests/Feature/Api/V1/Snelstart/PassThroughAuditNoSecretsTest.php, tests/Feature/Api/V1/Snelstart/PassThroughEchoPingTest.php</files>
  <behavior>
    - Bestaande `test_get_relaties_with_top_5_query_string_is_proxied_verbatim_to_sdk` assertt nu dat `path = '/relaties'` (exact, geen `?top=5`) én dat `query_keys` (csv) `$top` bevat
    - Nieuwe test: `test_get_relaties_with_complex_odata_query_stores_only_query_keys_no_values` — assertt dat e-mail uit `$filter=Email eq 'a@b.nl'` NIET in een audit-kolom voorkomt, en dat `query_keys` precies `$filter,$select,$top` (of vergelijkbare set, key-set niet volgorde-afhankelijk) bevat
    - Nieuwe test: `test_post_without_json_content_type_returns_415_and_writes_no_audit_row` — POST naar `/v1/snelstart/relaties` met `Content-Type: application/x-www-form-urlencoded` retourneert 415, body `{"error":"unsupported_content_type",...}`, en `pass_through_calls` blijft leeg
    - Nieuwe test: `test_post_with_empty_json_body_yields_null_fingerprint` — POST met `{}` body en JSON content-type → audit-rij wordt geschreven, status 201 (SDK-mock), `request_fingerprint = null`
    - Bestaande `PassThroughAuditNoSecretsTest::test_audit_row_does_not_contain_request_body_for_post` (regel 115): assert `$row['request_fingerprint']` is NOT NULL blijft want body = `['naam' => 'SECRET']` is niet-leeg
    - Bestaande `PassThroughEchoPingTest::test_get_echo_ping_*` regel 59 (`assertNull($row->request_fingerprint, 'GET-requests hebben geen body...')`) blijft groen zonder wijziging
  </behavior>
  <action>
    1. **Update `PassThroughOdataRelatiesTest::test_get_relaties_with_top_5_query_string_is_proxied_verbatim_to_sdk`** (regel 64-67):
       Vervang:
       ```php
       $this->assertStringStartsWith('/relaties', $row->path);
       $this->assertStringContainsString('top=5', $row->path);
       ```
       door:
       ```php
       $this->assertSame('/relaties', $row->path);
       $this->assertNotNull($row->query_keys);
       $this->assertStringContainsString('$top', (string) $row->query_keys);
       ```
       Test-name mag blijven zoals 'is_proxied_verbatim_to_sdk' — de SDK krijgt de query nog steeds verbatim door (regel 60 captured-assert blijft); alleen de audit-laag schrijft 'm anders weg.
    2. **Voeg toe in `PassThroughOdataRelatiesTest`** (na `test_complex_odata_query_with_filter_and_select_is_proxied`):
       ```php
       public function test_complex_odata_query_stores_only_query_keys_no_values_in_audit(): void
       {
           [, $token, $account] = $this->setupSnelstartConsumer();

           MockClient::global([
               RawSnelstartRequest::class => MockResponse::make(['value' => []], 200),
           ]);

           $this->withHeader('Authorization', "Bearer {$token}")
               ->withHeader('X-Account-Id', $account->external_id)
               ->getJson('/v1/snelstart/relaties?%24filter=Email%20eq%20%27a%40b.nl%27&%24select=Id%2CNaam&%24top=10')
               ->assertOk();

           $row = (array) DB::table('pass_through_calls')->latest('id')->first();

           foreach ($row as $col => $val) {
               if (is_string($val)) {
                   $this->assertStringNotContainsString('a@b.nl', $val, "Audit-kolom {$col} lekt e-mail uit OData filter.");
                   $this->assertStringNotContainsString('Email eq', $val, "Audit-kolom {$col} lekt filter-expression.");
               }
           }

           $this->assertSame('/relaties', $row['path']);
           $this->assertNotNull($row['query_keys']);
           $keys = explode(',', (string) $row['query_keys']);
           $this->assertContains('$filter', $keys);
           $this->assertContains('$select', $keys);
           $this->assertContains('$top', $keys);
       }
       ```
       Voeg `use Illuminate\Support\Facades\DB;` toe bovenaan het file als die nog niet bestaat.
    3. **Voeg toe in `PassThroughEchoPingTest`** (of liever in `PassThroughOdataRelatiesTest` als ResponseHeaderForwardingTest-stijl van non-JSON beter bij OData past — kies `PassThroughEchoPingTest` omdat het al een eenvoudige Snelstart-route gebruikt zonder JSON-roundtrip-aannames):

       Eigenlijk hoort de 415-test thuis in een nieuwe lichte test-file of in `PassThroughEchoPingTest`. Plaats 'm in `PassThroughEchoPingTest` voor minimale file-churn:
       ```php
       public function test_post_with_non_json_content_type_returns_415_and_writes_no_audit_row(): void
       {
           [, $token, $account, $connection] = $this->setupSnelstartConsumer([TokenAbilities::SNELSTART_WRITE]);
           $this->primeSnelstartToken($connection);

           // SDK MAG NIET worden aangeroepen — als de guard correct werkt.
           MockClient::global([
               RawSnelstartRequest::class => MockResponse::make(['should' => 'not-be-called'], 500),
           ]);

           $response = $this->withHeader('Authorization', "Bearer {$token}")
               ->withHeader('X-Account-Id', $account->external_id)
               ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
               ->post('/v1/snelstart/relaties', ['naam' => 'should-not-pass']);

           $response->assertStatus(415);
           $response->assertJsonPath('error', 'unsupported_content_type');
           $this->assertSame(0, PassThroughCall::count(), '415-pad mag geen audit-rij schrijven');
       }
       ```
       Pas op: deze test post raw form-encoded data via `$this->post()` (niet `postJson()`); Laravel's testing-helper-`post()` zet default Content-Type op `application/x-www-form-urlencoded` — perfect voor dit pad. Een expliciete `withHeader('Content-Type', ...)` is overbodig maar leesbaar.
    4. **Voeg toe in `PassThroughAuditNoSecretsTest`** (na `test_audit_row_does_not_contain_request_body_for_post`):
       ```php
       public function test_empty_post_body_yields_null_fingerprint(): void
       {
           $consumer = Consumer::factory()->create();
           $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
           $connection = Connection::factory()->forSnelstart()->for($account)->create();
           $this->primeSnelstartToken($connection);

           $token = $consumer->createToken('test', [TokenAbilities::SNELSTART_WRITE])->plainTextToken;

           MockClient::global([
               RawSnelstartRequest::class => MockResponse::make(['ok' => true], 201),
           ]);

           $this->withHeader('Authorization', "Bearer {$token}")
               ->withHeader('X-Account-Id', 'school-A')
               ->postJson('/v1/snelstart/relaties', [])
               ->assertCreated();

           $row = (array) DB::table('pass_through_calls')->latest('id')->first();
           $this->assertNull($row['request_fingerprint'], 'Lege POST-body mag geen constante fingerprint produceren');
       }
       ```
    5. **Niet aanraken:** `PassThroughEchoPingTest::test_get_echo_ping_*` regel 59 (GET-pad blijft `null` zoals al expected), `PassThroughAuditNoSecretsTest::test_audit_row_does_not_contain_request_body_for_post` regel 115 (body is niet-leeg dus fingerprint blijft niet-null).
    6. `vendor/bin/pint --dirty --format agent` na de wijzigingen.
  </action>
  <verify>
    <automated>php artisan test --compact --filter='PassThroughOdataRelatiesTest|PassThroughAuditNoSecretsTest|PassThroughEchoPingTest'</automated>
  </verify>
  <done>
    - `PassThroughOdataRelatiesTest::test_get_relaties_with_top_5_query_string_is_proxied_verbatim_to_sdk` groen met nieuwe asserts (path = '/relaties' exact + query_keys bevat $top)
    - Nieuwe test `test_complex_odata_query_stores_only_query_keys_no_values_in_audit` groen — bewijst dat geen enkele kolom 'a@b.nl' of 'Email eq' bevat
    - Nieuwe test `test_post_with_non_json_content_type_returns_415_and_writes_no_audit_row` groen — 415 + 0 audit-rijen
    - Nieuwe test `test_empty_post_body_yields_null_fingerprint` groen — fingerprint = NULL
    - Bestaande tests in deze drie files allemaal groen (geen regressie)
    - Pint groen
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| consumer → Hub /v1/snelstart/* | Consumer-payload + headers; Hub mag niet stilzwijgend droppen of in audit-DB lekken |
| Hub → pass_through_calls (DB) | Audit-laag; mag geen PII/secrets/raw query-string opslaan (fingerprint-only-discipline uit `.ai/rules/global.md`) |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-qxk-01 | Information Disclosure | `pass_through_calls.path`-kolom | mitigate | Strip query-string; alleen endpoint-pad opslaan. Aparte `query_keys`-kolom met keys-only (geen values). Task 2 + 3. |
| T-qxk-02 | Tampering | `request_fingerprint` voor lege bodies | mitigate | NULL i.p.v. constante `sha256('[]')`-hash zodat audit-trail niet "100× zelfde fingerprint" als replay-signal misinterpreteert. Task 2 + 3. |
| T-qxk-03 | Denial-of-service / Data-corruption | Body-drop voor non-JSON POST/PATCH | mitigate | 415-guard vóór SDK-call; consumer krijgt expliciet contract-violation i.p.v. silent-data-corruption. Task 2 + 3. |
| T-qxk-04 | Repudiation | 415-pad zonder audit-rij | accept | Bewust geen audit-rij voor 415 (consistent met bestaande 403/405-pad); HTTP-laag (nginx/Caddy) logt status-code + path op infra-laag, voldoende voor forensics. |
</threat_model>

<verification>
1. `php artisan migrate --no-interaction` draait schoon
2. `php artisan test --compact --filter='PassThrough'` — alle Phase 5b pass-through tests groen
3. `php artisan test --compact` — volledige suite groen (geen onverwachte regressies in audit/logging-paden buiten Phase 5b)
4. `vendor/bin/pint --dirty --format agent` geen unstaged changes meer
5. Sanity grep: `grep -n "request->getQueryString" app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` retourneert leeg (CR-02 weg)
6. Sanity grep: `grep -n "HTTP_UNSUPPORTED_MEDIA_TYPE" app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` retourneert 1 match (CR-01 in)
</verification>

<success_criteria>
- CR-01 dicht: non-JSON POST/PATCH → 415 vóór SDK-call, geen audit-rij
- CR-02 dicht: `pass_through_calls.path` bevat alleen endpoint, `query_keys`-kolom heeft csv van keys (of NULL), nooit waarden
- CR-03 dicht: lege POST/PATCH-body → `request_fingerprint = NULL`
- Migration toegevoegd met datum-prefix `2026_05_15_000002_*`
- `PassThroughCall`-model `#[Fillable]` uitgebreid met `query_keys`
- 3 nieuwe tests + 1 aangepaste test, allemaal groen
- Bestaande Phase 5b test-suite blijft groen
- Pint groen
- Phase 5b kan dicht — review-status van `issues_found` (3 critical) naar `clean` op CR-as
</success_criteria>

<output>
After completion, create `.planning/quick/260514-qxk-fix-05b-critical-findings-body-forwardin/260514-qxk-SUMMARY.md` met:
- Wat is gefixt (CR-01/CR-02/CR-03 per stuk)
- Nieuwe migration-filename + welke tests zijn toegevoegd/aangepast
- Verwijzing terug naar `05b-REVIEW.md` met "CR-01/CR-02/CR-03 closed"
- Open-Warnings-status (WR-01 t/m WR-07 + IN-01 t/m IN-04 zijn buiten scope van deze quick-task — apart opvolgen)
</output>
