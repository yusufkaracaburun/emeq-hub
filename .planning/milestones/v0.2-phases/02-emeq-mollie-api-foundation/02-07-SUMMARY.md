---
phase: 02-emeq-mollie-api-foundation
plan: 07
subsystem: sdk-error-mapping-and-wiring
tags: [mollie, pest, fake-mechanism, validation-exception, bearer-auth, multi-tenant, reflection, php8.3]

# Dependency graph
requires:
  - 02-01 (vendor/mollie/mollie-api-php — Fake/, ApiKeyAuthenticator, ValidationException)
  - 02-04 (Mollie::client() factory met setApiKey-pad — SUT B-5)
  - 02-05 (TestCase + Pest bootstrap + FakeMollieCredentialResolver)
  - 02-06 (Pest-baseline staat op 31 tests; cache-collision vermeden door volgorde)
provides:
  - "tests/Unit/ErrorMappingTest.php — 2 Pest-tests die de allerlaatste twee outer-layer contracten dekken: (1) ValidationException-mapping via Mollie's eigen fake-pipeline en (2) Bearer-header-wiring via ONZE app(Mollie::class)->client() factory met authenticator-introspect"
  - ".gitignore-pattern *.notes.md voor de SDK-repo zodat lokale FQCN-inspectie-bestanden (plan-execution-werk) niet in de SDK-repo lekken"
  - "Vendor-geverifieerde FQCN-notes (lokaal werk-artefact, gegitignored): Mollie\\Api\\MollieApiClient::fake() is STATIC en retourneert Mollie\\Api\\Fake\\MockMollieClient — beslissend voor B-5 strategie (fallback pad B: authenticator-introspect ipv assertSent op een aparte mock-client die niet door onze factory loopt)"
affects:
  - 02-08-PLAN (README + Hub-integration step — kan de definitieve Pest-suite stats noemen: 33 tests / 86 assertions / <1s / 100% groen op auth + resolver + multi-tenant + env-guard + idempotency + error-mapping + bearer-wiring)
  - "ROADMAP Phase 2 success criterion 4: drempel >=10 Pest-tests groen op auth/resolver/error-mapping is RUIM gehaald (33 tests / 86 assertions)"
  - "ROADMAP Phase 2 success criterion 5: 'geen raw API keys in logs/exceptions, fingerprint-only' blijft gerespecteerd — geen nieuwe code-paden die rauwe credentials in exception-messages of logs zetten; de tests asserteren juist via Reflection (geen toString/getMessage call op authenticator-state) zodat ook test-output schoon blijft"

# Tech tracking
tech-stack:
  added: []  # geen nieuwe composer-deps
  patterns:
    - "Fake-mechanisme keyed-op-request-class-FQCN: MollieApiClient::fake([\\Mollie\\Api\\Http\\Requests\\CreatePaymentRequest::class => MockResponse::unprocessableEntity('detail', 'field')]). Geen losse addResponse() methode — alle expected responses moeten in de ctor-array."
    - "MockResponse::unprocessableEntity(string $description, string $field): self — geeft een complete 422 response-body mee inclusief de 'field'-key die ValidationException::getField() oppikt"
    - "ConvertResponseToException middleware (vendor) mapt 422 -> ValidationException::fromResponse — geen extra wrapping nodig in onze SDK"
    - "B-5 fallback (pad B) — authenticator-introspect: omdat MollieApiClient::fake() STATIC is en een aparte MockMollieClient teruggeeft, kan een assertSent op een echte client uit onze factory niet werken. In plaats daarvan asserteren we op ApiKeyAuthenticator-instance + isTestToken() + Reflection op private BearerTokenAuthenticator::$token om te bewijzen dat de exact-resolved apiKey in de authenticator zit. Samen met Mollie's vendor-geteste authenticate()-pad sluit dit het outgoing Authorization-header-contract af zonder netwerk-call."
    - "Reflection-pattern voor private inherited props: ReflectionClass(BearerTokenAuthenticator::class) ipv ReflectionObject van het ApiKeyAuthenticator-subclass — de $token prop leeft op de base class, niet op de subclass"
    - "Vendor-FQCN-inspectie als read-only Task 1 vóór tests-schrijven (B-4) — voorkomt fictieve namespaces zoals Mollie\\Api\\Testing\\ door grep over vendor/ te draaien en de bevindingen in een .notes.md werk-artefact te pinnen"
    - "Test-fixture key-padding op '\\w{30,}' regex: Mollie's TokenValidator::API_KEY_PATTERN is /^(live|test)_\\w{30,}$/ — vereist 30 word-chars NA het 'test_'-prefix (niet 30 chars totaal). Onze fixture-key 'test_bearer_check_AAAAAAAAAAAAAAAAAAAA' heeft 33 chars na het prefix."

key-files:
  created:
    - "packages/mollie-api/tests/Unit/ErrorMappingTest.php"
    - "packages/mollie-api/.mollie-fake-fqcn-notes.md (lokaal werk-artefact — gitignored, niet in repo)"
  modified:
    - "packages/mollie-api/.gitignore (+ *.notes.md + .mollie-fake-fqcn-notes.md patterns)"

key-decisions:
  - "B-5 strategie B gekozen op basis van Task 1's vendor-inspection: MollieApiClient::fake() is STATIC en retourneert een aparte MockMollieClient. Strategie A (assertSent op onze client) is daarom onmogelijk; strategie B (authenticator-introspect + Reflection) is principieel sound omdat onze factory's enige verantwoordelijkheid setApiKey() is — de feitelijke header-emit is vendor-domein."
  - "Test-payload moet client-side geldig zijn (amount.currency + amount.value) zodat de mocked 422-response wordt gehit — Mollie's CreatePaymentRequestFactory valideert payload-shape vóór de HTTP-call (MoneyFactory throws InvalidArgumentException). Een payload zonder 'value' faalt al lokaal en raakt de mock-adapter nooit. De 'field' in de mock-response ('amount.value') is server-side semantiek — onafhankelijk van de werkelijke payload-inhoud."
  - "Geen `Mollie\\Api\\Fake\\MockMollieClient` import in de testfile zelf — alleen `MockResponse` is publiek nodig. MollieApiClient::fake() retourneert het mock-type via inference; PHP en de IDE weten via de return-type al wat er teruggegeven wordt."
  - "Reflection op BearerTokenAuthenticator (de base class) ipv ApiKeyAuthenticator (de subclass) — het private $token-property leeft op de base. ReflectionObject($auth)->getProperty('token') zou ook werken, maar via base-class ReflectionClass is het expliciet welke laag de prop owns."
  - "Notes-file `.mollie-fake-fqcn-notes.md` als gegitignored werk-artefact. Het bestand bevat de geverifieerde FQCN's + auth-pipeline-analyse die Task 2 voedt. Niet in de SDK-repo omdat het geen runtime-asset is en bij volgende vendor-upgrades irrelevant wordt — dan opnieuw inspecteren ipv outdated notes onderhouden."

patterns-established:
  - "Vendor-inspection-as-task: een aparte Task 1 die READ-ONLY in vendor/ rondloopt en de exacte FQCN's + method-signatures in een .notes.md schrijft, voorkomt fictieve namespaces in test-imports. Toekomstige Emeq-SDKs (Moneybird/Ibanity/Exact) kunnen dit pattern reproduceren door grep over hun vendor/ te draaien voor mock/fake-classes vóór ze error-mapping tests schrijven."
  - "Authenticator-introspect-pattern voor outer-layer wiring-verificatie: in plaats van een full HTTP-roundtrip te mocken om de Bearer-header te checken, asserteren we direct op de authenticator-state (instanceof + getter + Reflection op private token). Dit isoleert wat WIJ verantwoordelijk voor zijn (de juiste authenticator installeren) van wat de vendor-lib doet (de Bearer-header emitten)."
  - "Plan-verify substring-matching discipline: plan-verify clauses (grep -q 'test_bearer_check') vereisen dat fixture-strings de exacte substring bevatten. De ≥30-char regex-validatie van Mollie wordt apart bevredigd via padding — beide checks gecombineerd in één key-string."

requirements-completed: []  # MOLL-01 was reeds gemarkeerd in 02-04; deze plan-run is coverage-completion

# Metrics
duration: 8min
completed: 2026-05-14
---

# Phase 02 Plan 07: Error-mapping Tests + Bearer-wiring Verification Summary

**Sluit-fase voor de SDK-foundation Pest-laag: 2 nieuwe tests die de allerlaatste outer-layer contracten dekken — Mollie's ValidationException-mapping via 422 fake-response, en de Bearer-Authorization wiring via ONZE `app(Mollie::class)->client()` factory (B-5). Vendor-FQCN's vooraf in een Task 1 vendor-inspection vastgepind (B-4) om fictieve namespaces uit te sluiten. Pest-suite staat nu op 33 tests / 86 assertions / 0.63s — ROADMAP Phase 2 success criteria 4 + 5 gesloten.**

## Performance

- **Duration:** ~8 min (1 cycle Rule-1 deviation voor client-side payload-validatie ontdekking + 1 cycle voor de `\w{30,}` regex-vs-totale-key-lengte fix)
- **Started:** 2026-05-14T12:27:41Z (Task 1 inspection)
- **Completed:** 2026-05-14
- **Tasks:** 2 atomair gecommit in sub-repo
- **Files created:** 1 (in repo) + 1 (lokaal werk-artefact, gitignored)
- **Files modified:** 1 (.gitignore)

## Accomplishments

- **Task 1 — Vendor-FQCN-inspectie:** Read-only inspectie van `vendor/mollie/mollie-api-php/src/` produceert een `.mollie-fake-fqcn-notes.md` (lokaal, gegitignored) met de exact-geverifieerde FQCN's voor `Mollie\Api\Fake\MockMollieClient`, `MockResponse`, `MockMollieHttpAdapter`, `ApiKeyAuthenticator`, `BearerTokenAuthenticator`, en `ValidationException::getField`. Belangrijke beslissende vondst: `MollieApiClient::fake()` is **static** en retourneert een aparte `MockMollieClient` — dat sluit pad A (`$client->fake([...]); $client->assertSent(...)`) op onze factory-output uit en activeert pad B (authenticator-introspect) voor de B-5 assertion.
- **Task 2 — ErrorMappingTest met 2 tests:**
  - **Test 1 (ValidationException via fake):** `MollieApiClient::fake([CreatePaymentRequest::class => MockResponse::unprocessableEntity('The amount.value field is required.', 'amount.value')])` queue't een 422. Een `$mock->payments->create([...])` met **client-side-valide payload** (amount.currency + amount.value beide aanwezig — anders faalt `MoneyFactory` lokaal vóór de HTTP-call) raakt de mock; `ConvertResponseToException` mapt naar `ValidationException`; `$caught->getField()` retourneert `'amount.value'`. Bewijst end-to-end dat Mollie's eigen 422-mapping in onze SDK werkt zonder ons tussenliggend transform-pad.
  - **Test 2 (Bearer via ONZE wiring, B-5):** Bind `FakeMollieCredentialResolver::withApiKey('test_bearer_check_AAAAAAAAAAAAAAAAAAAA')`, `forgetInstance(Mollie::class)`, `$client = app(Mollie::class)->client()`. Asserts: `$client->getAuthenticator() instanceof ApiKeyAuthenticator`, `->isTestToken() === true`, en via `ReflectionClass(BearerTokenAuthenticator::class)->getProperty('token')->getValue($authenticator)` dat de **exact-resolved apiKey** in de authenticator zit. Samen met Mollie's vendor-geteste `BearerTokenAuthenticator::authenticate()` (regel: `$request->headers()->add('Authorization', "Bearer {$this->token}");`) sluit dit het outgoing-header-contract af zonder netwerk-call.
- **ROADMAP success criterion 4 afgesloten:** Drempel ≥10 Pest-tests groen op auth/resolver/error-mapping is ruim gehaald. Suite staat nu op **33 tests / 86 assertions / 0.63s**. De 2 nieuwe tests dekken het laatste contract-gat tussen onze factory en Mollie's eigen library (error-mapping pipeline + auth-pipeline).
- **ROADMAP success criterion 5 expliciet beschermd:** Geen nieuwe code-paden die rauwe `apiKey`-strings in exception-messages of logs zetten. De Bearer-test gebruikt Reflection (geen `toString`/`getMessage`/`echo`) op de private `$token`-property, dus ook test-output (PHPUnit fail-messages bij regressies) zou geen rauwe key bevatten.
- **B-4 invariant respected:** alle imports in `ErrorMappingTest.php` zijn vendor-geverifieerd via Task 1's notes — geen fictieve `Mollie\Api\Fake\MockMollieClient` direct-import (alleen `MockResponse` is publiek nodig in de test; `MollieApiClient::fake()`'s return-type infereert het mock-type).

## Task Commits

Beide tasks atomair gecommit in de mollie-api sub-repo op branch `feat/foundation`:

1. **Task 1:** `820996c` — `chore(02-07): gitignore *.notes.md voor plan-execution FQCN-inspectie` — 4 insertions in `.gitignore`. Het `.mollie-fake-fqcn-notes.md` zelf wordt door dit pattern gecaptured en blijft lokaal-only (geen file in commit).
2. **Task 2:** `4914ca3` — `test(02-07): ErrorMappingTest — ValidationException::getField + Bearer wiring (B-4 + B-5)` — 91 insertions in `tests/Unit/ErrorMappingTest.php` (nieuwe file met 2 tests).

Geen Hub-worktree-commit van plan-artefacten in deze run — orchestrator commit SUMMARY/STATE/ROADMAP.

## Files Created/Modified

- `packages/mollie-api/tests/Unit/ErrorMappingTest.php` — 2 `it()`-blocks. Imports: `Emeq\MollieApi\{Contracts\MollieCredentialResolver, Mollie}` + `Emeq\MollieApi\Tests\Support\FakeMollieCredentialResolver` + Mollie's `{Exceptions\ValidationException, Fake\MockResponse, Http\Auth\{ApiKeyAuthenticator, BearerTokenAuthenticator}, Http\Requests\CreatePaymentRequest, MollieApiClient}`. Geen `Mollie\Api\Fake\MockMollieClient` import — return-type-inference dekt het.
- `packages/mollie-api/.gitignore` — `*.notes.md` + `.mollie-fake-fqcn-notes.md` patterns toegevoegd onder een "Inspection notes" sectie. Het `.mollie-fake-fqcn-notes.md` zelf is een lokaal werk-artefact (FQCN-inspectie-notes voor Task 2's invul-beslissingen) en blijft buiten de SDK-repo.

## Decisions Made

- **B-5 strategie B (authenticator-introspect) ipv A (assertSent op fake-client)** — Task 1's vendor-inspection wees uit dat `MollieApiClient::fake()` STATIC is en een aparte `MockMollieClient` retourneert. Strategie A werkt alleen als fake() instance-method zou zijn op een client uit onze factory. Strategie B (authenticator-state + Reflection) is principieel sound omdat de scope van onze factory eindigt bij `setApiKey()`; de header-emit is vendor-verantwoordelijkheid en is door de vendor's eigen unit-tests gedekt.
- **Reflection op BearerTokenAuthenticator (base) ipv ApiKeyAuthenticator (subclass)** — De `$token`-property leeft op de base class. ReflectionClass van de base is explicieter dan ReflectionObject van het subclass-instance, en documenteert in de testcode welke laag de prop owns.
- **Notes-file als gitignored werk-artefact** — `.mollie-fake-fqcn-notes.md` bevat vendor-specifieke discovery-data (FQCN's + signatures uit Mollie's v3.11.0). Bij volgende vendor-upgrades wordt dit obsolete; in plaats van outdated notes te onderhouden is het pattern "inspecteer opnieuw bij behoefte". Gitignore via `*.notes.md` pattern zodat ook toekomstige inspectie-files automatisch buiten de repo blijven.
- **Test-payload met `'value' => '0.00'`** — Mollie's `CreatePaymentRequestFactory` valideert client-side dat `amount.currency` + `amount.value` beide aanwezig zijn (`MoneyFactory::create` throws `InvalidArgumentException` anders). De waarde '0.00' is technisch geen geldige Mollie-payment-amount, maar dat maakt niet uit — de mocked 422-response gooit ValidationException **voordat** de waarde echt door Mollie's server gevalideerd zou worden. Wat we testen is het 422→ValidationException-mapping-pad, niet payment-amount-business-logic.
- **Geen B-5 'pad A' fallback-test geschreven** — Het plan suggereerde optioneel een aanvullende assertSent-test als bewijs van Bearer-header (op een aparte `MollieApiClient::fake()`-instance, niet door onze factory). Dat is bewust niet toegevoegd: het zou Mollie's eigen vendor-tests dupliceren (`BearerTokenAuthenticator::authenticate()` test) zonder iets toe te voegen aan ONZE wiring-bewijs. De authenticator-introspect via onze factory is het juiste isolatie-niveau.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Test-payload zonder `amount.value` faalt client-side vóór de mock-adapter wordt gehit**

- **Found during:** Task 2 eerste Pest-run (`InvalidArgumentException: Invalid Money data provided` op `vendor/.../MoneyFactory.php:12`)
- **Issue:** Plan-action-block suggereerde `'amount' => ['currency' => 'EUR'], // intentionally missing 'value'` om een 422 te triggeren. Maar Mollie's `CreatePaymentRequestFactory` valideert payload-shape **vóór** de HTTP-call: `MoneyFactory` throws `InvalidArgumentException` als currency+value niet beide aanwezig zijn. De call raakt de mock-adapter daardoor nooit, en `ConvertResponseToException` wordt niet getriggerd.
- **Fix:** Payload uitgebreid naar `['amount' => ['currency' => 'EUR', 'value' => '0.00'], ...]`. Nu komt de call door de client-side validatie heen, raakt de mock-adapter, krijgt de 422 mock-response, en de middleware mapt 'em naar ValidationException. De `field`-waarde in `getField()` komt uit de mock-response (server-side semantiek), niet uit de feitelijke payload.
- **Files modified:** `packages/mollie-api/tests/Unit/ErrorMappingTest.php` (alleen testfile — geen src/-wijziging nodig).
- **Commit:** `4914ca3` (geïntegreerd in Task 2's enige commit; geen aparte fix-commit omdat het issue tijdens dezelfde RED-GREEN-cycle is opgelost).
- **Plan-impact:** Geen — dit is een test-payload-detail, geen architectuur-issue. Plan's intent (een ValidationException via 422 triggeren) blijft volledig intact.

**2. [Rule 1 — Bug] Eerste fixture-key voldeed niet aan `\w{30,}` regex-vereiste**

- **Found during:** Task 2 tweede Pest-run na key-rename naar `test_bearer_check` (`InvalidAuthenticationException: Invalid API key. An API key must start with 'test_' or 'live_' and must be at least 30 characters long.`)
- **Issue:** Mollie's `TokenValidator::API_KEY_PATTERN = '/^(live|test)_\w{30,}$/'` vereist **30 word-chars NA het `test_`-prefix**, niet 30 chars totaal. Mijn key `test_bearer_check_AAAAAAAAAAAAAAAA` had 29 chars na de underscore (1 te kort). De totale lengte was 34, wat misleadend was tot ik de regex daadwerkelijk las.
- **Fix:** Padding uitgebreid van 16 'A's naar 20 'A's: `test_bearer_check_AAAAAAAAAAAAAAAAAAAA` (33 chars na het `test_`-prefix). Beide plan-verify-clausules nu tegelijk bevredigd (substring `test_bearer_check` aanwezig + Mollie's `isApiKey()` accepteert de key).
- **Files modified:** `packages/mollie-api/tests/Unit/ErrorMappingTest.php`.
- **Commit:** `4914ca3` (zelfde commit als Task 2's eind-state; geen aparte fix-commit).
- **Plan-impact:** Geen — plan-verify clausule `grep -q 'test_bearer_check'` is gehonoreerd via substring-match. De plan-source had de `\w{30,}` regex-eis niet expliciet vermeld; deze is meegenomen als doorgetrokken discovery uit eerdere plans (02-05, 02-06 hadden vergelijkbare key-padding-issues).

### Pint cosmetic rewrites

- Pint paste `blank_line_before_statement` toe op `tests/Unit/ErrorMappingTest.php` — voegt blank line vóór `try {` blok in (test 1)
- Pint paste `binary_operator_spaces` toe op `tests/Unit/ErrorMappingTest.php` — aligneert `=`-assignments in test 2 (`$reflection`, `$tokenProp`)
- Beide cosmetic-only, geen semantische impact

### Niet als deviation: notes-file gitignored

Het plan-frontmatter `files_modified` lijst `packages/mollie-api/.mollie-fake-fqcn-notes.md` als artefact. Dat bestand bestaat op disk maar zit niet in de repo (gitignored via Task 1's `.gitignore`-update). Plan-text-context regel 195 expliciet: "De notes-file is een eenmalig artefact dat Task 2 voedt. Op disk laten (gitignored ...)". Niet als deviation gedocumenteerd; het is het intended gedrag.

## Issues Encountered

- **Eerste Task 2 Pest-run faalde op MoneyFactory client-side validatie** — Plan's payload-shape was incomplete (intentionally om 422 te forceren, maar dat is een server-side trigger; Mollie's client-side validatie is een aparte laag). Opgelost via Rule 1 #1 hierboven.
- **Tweede Task 2 Pest-run faalde op InvalidAuthenticationException** — Mijn eerste poging met `test_bearer_check_AAAAAAAAAAAAAAAA` (34 chars totaal, 29 chars na prefix) viel te kort op de `\w{30,}` regex. Opgelost via Rule 1 #2 hierboven.
- **Shell-chaining `tail -3 | grep` faalde in mijn verify-check** — Mijn debug-attempt gebruikte `tail -3` waar de plan-verify clause `tail -10` gebruikt; daardoor zag mijn check de `passed`-regel niet. Plan-verify clause met `tail -10` werkt correct; eigen verify-script aangepast voor consistency.

## Verification Summary

Alle plan-`<verification>`-clausules + `<success_criteria>`:

- `.mollie-fake-fqcn-notes.md` bestaat met `Conclusie voor Task 2` → PASS (geverifieerd lokaal, file is gitignored maar wel op disk)
- `app(Mollie::class)->client()` hits in `tests/Unit/ErrorMappingTest.php` → PASS (2 hits, B-5)
- `forgetInstance(Mollie::class)` hits → PASS (1 hit)
- `getField` hits → PASS (3 hits)
- `ValidationException` hits → PASS (6 hits)
- Geen fictieve `Mollie\Api\Fake\` placeholder als import → PASS (Task 1 wees uit dat alleen `MockResponse` publiek nodig is; `MockMollieClient` komt via return-type-inference)
- `./vendor/bin/pest --filter=ErrorMappingTest` exit 0 → PASS (2 tests / 6 assertions / 0.31s)
- Volledige Pest-suite toont ≥25 passed → OVERSCHREDEN (33 tests / 86 assertions / 0.63s)
- `php -l` op `tests/Unit/ErrorMappingTest.php` → PASS
- Pint clean op alle modified files → PASS (`{"tool":"pint","result":"passed"}`)

ROADMAP success criteria:

- SC #4 (≥10 Pest-tests groen op auth + resolver + error-mapping) → AFGESLOTEN — 33 tests / 86 assertions
- SC #5 ("geen raw tokens in logs/exceptions — alleen fingerprint") → AFGESLOTEN — geen nieuwe code-paden die rauwe credentials zichtbaar maken; de Bearer-test gebruikt Reflection (geen toString/getMessage) op de private token-prop zodat ook test-fail-output schoon blijft.
- SC #2 ("multi-tenant runtime swap zonder cross-tenant lekkage") → reeds AFGESLOTEN in 02-06
- B-4 (geen fictieve namespaces) → AFGESLOTEN — alle imports vendor-geverifieerd
- B-5 (Bearer assertion via ONZE wiring) → AFGESLOTEN via strategie B (authenticator-introspect + Reflection)

## Next Phase Readiness

- **02-08 ready:** README + Hub-integration step. Concrete cijfers voor de README: 33 tests / 86 assertions / 0.63s suite-tijd / dual-credential (API-key + OAuth) + multi-tenant key-swap + env-guard + idempotency dual-path + error-mapping (ValidationException) + Bearer-wiring (authenticator-introspect) — een volledige outer-layer Pest-laag voor een SDK die niets meer dan setApiKey/setAccessToken op een vendor-library doet.
- **Geen blockers** voor wave 7 (plan 08).
- **Phase 2 Pest-foundation is sluit:** alle outer-layer contracten getest, geen niet-geteste auth-paden meer.

## Self-Check: PASSED

Sub-repo commits exist (verified via `git log --oneline -5` in `packages/mollie-api/`):
- `820996c chore(02-07): gitignore *.notes.md voor plan-execution FQCN-inspectie` → FOUND
- `4914ca3 test(02-07): ErrorMappingTest — ValidationException::getField + Bearer wiring (B-4 + B-5)` → FOUND

Files exist:
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/tests/Unit/ErrorMappingTest.php` → FOUND
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/.mollie-fake-fqcn-notes.md` → FOUND (lokaal, gitignored)
- `/Users/yusufkaracaburun/Sites/localhost/emeq-hub-phase2/packages/mollie-api/.gitignore` (modified) → FOUND

Pest suite confirmed green:
- Filtered (`ErrorMappingTest`) → 2 passed, 6 assertions, 0.31s
- Full suite → 33 passed, 86 assertions, 0.63s

---
*Phase: 02-emeq-mollie-api-foundation*
*Completed: 2026-05-14*
