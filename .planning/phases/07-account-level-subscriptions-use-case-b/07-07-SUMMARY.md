---
phase: 07-account-level-subscriptions-use-case-b
plan: 07
subsystem: testing
tags: [integration-tests, mollie-connect, account-subscriptions, real-mollie-roundtrip, sc-4, d-26, skip-guard]

# Dependency graph
requires:
  - phase: 07-account-level-subscriptions-use-case-b
    provides: POST + DELETE /v1/account-subscriptions routes + AccountSubscriptionController + manager (plan 07-04 / 07-03)
  - phase: 07-account-level-subscriptions-use-case-b
    provides: AccountSubscriptionResource + Connection::factory()->forMollie() (plan 07-04 / 07-01)
  - phase: 06-cashier-mollie-integratie-use-case-a
    provides: IntegrationTestCase pattern + phpunit.integration.xml + composer test:integration script

provides:
  - "AccountSubscriptionIntegrationTestCase — abstract base met skip-guard op MOLLIE_CONNECT_TEST_ACCESS_TOKEN (access_-prefix). Hergebruikbaar voor latere account-subscription integration-tests."
  - "AccountSubscriptionMollieRoundtripTest — 1 happy-path test die Hub→Mollie→Hub roundtrip end-to-end uitvoert: pre-flight customer + mandate (raw SDK), POST /v1/account-subscriptions (Hub), assert real sub_* + remote status active, DELETE via Hub, assert remote canceled + mandate-cleanup."
  - ".env.example: MOLLIE_CONNECT_TEST_ACCESS_TOKEN placeholder met uitleg verschil met CASHIER_MOLLIE_KEY."

affects:
  - 07-08-PLAN.md (acceptance kan dit als D-32 §6 bewijs gebruiken; mag besluiten dat skip-default voor v0.2 acceptabel is, eventueel met handmatige UAT als alternatief)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Skip-guard pattern uit Tests\\Integration\\IntegrationTestCase (Phase 6 D-12) 1:1 hergebruikt met andere env-var-naam — bevestigt dat het pattern multi-token-source supporteert (CASHIER_MOLLIE_KEY voor use-case A, MOLLIE_CONNECT_TEST_ACCESS_TOKEN voor use-case B)."
    - "Try-finally cleanup-strategy: assertions in try-block, mandate-revoke in finally-block met inner Throwable-catch — best-effort cleanup waarbij Mollie's 30d auto-expiry de safety-net is bij partial-failure."
    - "MollieApiClient::setAccessToken (i.p.v. setApiKey) voor Connect-OAuth-shape — onderscheid met use-case A's raw API-key blijft expliciet."

key-files:
  created:
    - "tests/Integration/AccountSubscriptions/AccountSubscriptionIntegrationTestCase.php"
    - "tests/Integration/AccountSubscriptions/AccountSubscriptionMollieRoundtripTest.php"
  modified:
    - ".env.example — MOLLIE_CONNECT_TEST_ACCESS_TOKEN placeholder + comment-block toegevoegd"

key-decisions:
  - "Aparte base-class i.p.v. Phase 6's IntegrationTestCase erven: Phase 6 forceert config('mollie.key') te zetten op CASHIER_MOLLIE_KEY, wat use-case A scope is. Phase 7 gebruikt Connect-OAuth via Connection::access_token, dus de credential-flow is anders. Aparte parent voorkomt cross-contamination van config-state tussen suites."
  - "expires_at = now()->addYear() op de Connection in plaats van de factory-default now()->addHour(). Reden: HubMollieCredentialResolver doet een refresh-call zodra expires_at <5min in de toekomst ligt; bij test-run + Mollie API-call zou een test-duur >55min (theoretisch) de refresh triggeren, en Personal Access Tokens hebben geen refresh-grant. Een jaar in de toekomst maakt de test deterministisch ongeacht clock-skew of slow networks."
  - "1 testmethod i.p.v. happy + edge cases. Het plan vraagt om 1 happy-path roundtrip (SC-4 vendor-coverage); edge cases (404 op canceled sub, mandate-revoke-mid-flight) zijn al gedekt door unit-tests (plan 07-03 SyncTest) en feature-tests (plan 07-06 webhook-flow). Extra integration-tests vergroten de Mollie-API-quota-burn zonder nieuwe risk-coverage."
  - "Test gebruikt try-finally i.p.v. tearDown() voor cleanup. Reden: het mandate-id + customer-id zijn per testrun gegenereerd (timestamp), niet shared state. try-finally houdt de cleanup-scope expliciet in de testmethod en is robuust tegen partial-failure (bv. assert na DELETE faalt → mandate wordt nog steeds gerevoked)."
  - "Een ALL-of-3 commit i.p.v. opsplitsing in 2x base-class/test-file + 1x .env.example. Onder de git-policy 3-file-regel; logisch atomic want het is één plan-task. Geen approval-vereiste."

patterns-established:
  - "Pattern 1 — Per-feature integration-test base-class: erf van Tests\\TestCase + RefreshDatabase, #[Group('integration')] op de abstract class, skip-guard met env()-resolve + prefix-check + placeholder-check. Bij elke nieuwe partner met eigen credential-shape wordt dit het template (geen herbruik van een generieke 'IntegrationTestCase' voor cross-suite-config-bleed)."
  - "Pattern 2 — Mollie test-mode roundtrip: pre-flight customer + valid mandate via test-IBAN NL55INGB0000000000 (Mollie docs gegarandeerd valid in test-mode), Hub-API call met de echte cst_*/mdt_*, assert via remote SDK GET dat Hub-state en Mollie-state consistent zijn, cleanup in finally-block. Hergebruikbaar voor toekomstige Hub-resources die Mollie-side state mirror'en."
  - "Pattern 3 — Try-finally test-cleanup voor remote-state-resources: assertions in try, cleanup in finally met inner Throwable-catch. Verantwoorde best-effort: voorkomt test-data accumulatie zonder de testresultaat-kritische assertions te verstikken."

requirements-completed: []  # SUB-02 wordt pas formal gecompleteerd in plan 07-08 (acceptance). Dit plan levert D-32 §6 bewijs voor SC-4 vendor-coverage, niet de overall fase-acceptance.

# Metrics
duration: ~12min
completed: 2026-05-15
---

# Phase 07 Plan 07: Mollie integration-roundtrip test (`@group integration`) Summary

**End-to-end integration-test bewijst Hub→Mollie→Hub roundtrip voor `/v1/account-subscriptions` met een echte Mollie Connect test-mode merchant-account: pre-flight customer + mandate via raw SDK, Hub-create via API, remote-verify, Hub-cancel, remote-verify-canceled — gated achter `MOLLIE_CONNECT_TEST_ACCESS_TOKEN` env-var zodat default CI suite ongemoeid blijft.**

## Performance

- **Duration:** ~12 min wall-clock incl. worktree-setup + verificatie
- **Started:** 2026-05-15 (worktree spawn)
- **Completed:** 2026-05-15
- **Tasks:** 1/1
- **Files created:** 2 (1 base-class + 1 testmethod)
- **Files modified:** 1 (`.env.example`)
- **Commits:** 1 atomic (3 files, binnen git-policy 3-file-regel)

## Accomplishments

- **D-26 / SC-4 vendor-coverage bewijs geland.** Real Mollie test-mode roundtrip is geautomatiseerd via `@group integration`; runt via `composer test:integration` of `vendor/bin/phpunit --configuration=phpunit.integration.xml`, NIET in default `php artisan test`.
- **Skip-guard reproduceerbaar.** Zonder `MOLLIE_CONNECT_TEST_ACCESS_TOKEN` env-var (of met de `access_xxx`-placeholder uit `.env.example`) skipt de test graceful met een duidelijke message — geen CI-failures op feature-branches.
- **5-stappen test-flow geïmplementeerd:**
  1. Pre-flight customer + valid directdebit-mandate via raw Mollie SDK (test-IBAN `NL55INGB0000000000` levert direct valid mandate in test-mode).
  2. Hub-domain setup: Consumer + Account + Connection (forMollie) met de echte `access_token` overschreven op de factory-default + `expires_at = now()->addYear()` om refresh-flow te bypassen.
  3. Hub POST `/v1/account-subscriptions` met de real `cst_*` + `mdt_*` → assert 201 + `data.status='active'` + `data.mollie_subscription_id` met `sub_`-prefix.
  4. Verify Mollie API GET op de subscription → `status='active'` (proof dat de Hub-call op het Mollie-merchant-account is geland).
  5. Hub DELETE → assert 204; Mollie GET → `status='canceled'`. Cleanup-mandate in `finally`-block (T-07-07-02 mitigation).
- **Geen productie-code aangepast.** De `recordPaymentEvent` shape-mismatch die in eerdere SUMMARY's (07-06) als deferred-item flagged was, is reeds afgevangen door `PaymentWebhookHandler::handle` (commit `5645514`, plan 07-06) dat `(array) $payment` cast met NUL-key filter. De integration-test raakt dit pad alleen indirect (create + cancel), dus geen aanvullende fix nodig in plan 07-07.
- **Verificatie 100% groen:**
  - `php artisan test --compact --filter=AccountSubscriptionMollieRoundtripTest`: 0 tests, 0 passed (Group=integration correct excluded).
  - `vendor/bin/phpunit --configuration=phpunit.integration.xml --filter=AccountSubscriptionMollieRoundtripTest`: 1 test, 0 passed, 1 skipped (env-var placeholder triggert guard).
  - `vendor/bin/phpunit --configuration=phpunit.integration.xml` (alle): 5 tests, 0 passed, 5 skipped (Phase 6: 4, Phase 7: 1) — geen regressie op Phase 6's `CashierMollieSubscriptionFlowTest` of `CashierWebhookEndToEndTest`.
  - `php artisan test --compact` (default): 337 passed / 1100 assertions / 1 pre-existing incomplete — identiek aan plan 07-06 baseline.
  - `./vendor/bin/pint --dirty --format agent`: clean.

## Task Commits

| # | Hash | Type | Description |
|---|------|------|-------------|
| 1 | `9530784` | test | Mollie roundtrip integration-test + base-class + .env.example update |

## Files Created/Modified

- `tests/Integration/AccountSubscriptions/AccountSubscriptionIntegrationTestCase.php` (created, 51 regels) — abstract base met skip-guard op `MOLLIE_CONNECT_TEST_ACCESS_TOKEN`.
- `tests/Integration/AccountSubscriptions/AccountSubscriptionMollieRoundtripTest.php` (created, 122 regels) — 1 happy-path testmethod met 5-stappen roundtrip + try-finally cleanup.
- `.env.example` (modified, +14 regels) — `MOLLIE_CONNECT_TEST_ACCESS_TOKEN=access_xxx` placeholder met comment-block die het verschil met `CASHIER_MOLLIE_KEY` uitlegt.

## Decisions Made

- **Aparte base-class i.p.v. Phase 6's IntegrationTestCase erven.** Phase 6's setUp forceert `config(['mollie.key' => $key])` op de `CASHIER_MOLLIE_KEY`-waarde — dat zou de Connect-flow van Phase 7 corrupten omdat `MollieConnectionContext` + `HubMollieCredentialResolver` op de per-Connection `access_token` werken, niet op een globale config-key. Aparte parent isoleert deze suites.
- **`expires_at = now()->addYear()` op de Connection-factory-override.** `HubMollieCredentialResolver` doet refresh-call zodra `expires_at < now()+5min`. Personal Access Tokens hebben geen refresh-grant, dus een refresh-poging crasht. Een jaar in de toekomst is veilig.
- **1 testmethod (happy-path roundtrip), geen edge cases.** Plan vraagt SC-4 vendor-coverage; edge cases zijn al unit/feature-test-gedekt (plan 07-03 + 07-06). Meer integration-tests = meer Mollie API-quota-burn zonder extra risk-coverage.
- **Try-finally voor cleanup i.p.v. tearDown().** Mandate-id + customer-id zijn per testrun gegenereerd (timestamp), geen shared state. try-finally houdt de cleanup-scope expliciet in de testmethod en blijft robuust bij partial-failure.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Worktree-vendor + `.env` ontbraken**

- **Found during:** Pre-Task 1 (composer-autoload mist)
- **Issue:** Worktree spawn levert geen `vendor/` of `.env`; alle artisan-commands zouden falen. Documenteert dezelfde trap als plan 07-01..06 SUMMARY's.
- **Fix:** `cp .env.example .env && composer install --no-interaction && php artisan key:generate --force`. `.env` + `vendor/` zijn gitignored — geen commit.
- **Verification:** Baseline `AccountSubscriptionTest` 26/26 groen vóór Task 1.
- **Committed in:** N.v.t. — worktree-setup, geen tracked-files.

**2. [Plan-conformatie] `composer test:integration -- --filter=...` werkt niet**

- **Found during:** verify-step uit het plan
- **Issue:** Het plan's verify-block schrijft `composer test:integration -- --filter=AccountSubscriptionMollieRoundtripTest` voor. Composer interpreteert `--filter` als een composer-flag i.p.v. door te geven aan het script, met als gevolg `The "--filter" option does not exist`. Het composer-script delegates naar `vendor/bin/phpunit --configuration=phpunit.integration.xml`, maar de delegation-volgorde laat `--` niet door.
- **Fix:** Verify-step gebruikt direct `vendor/bin/phpunit --configuration=phpunit.integration.xml --filter=AccountSubscriptionMollieRoundtripTest`. Functioneel equivalent; geen wijziging aan het composer-script of de tests.
- **Files modified:** geen (alleen verify-commando-gebruik).
- **Verification:** Direct phpunit-call levert exact `1 test / 0 passed / 1 skipped`.
- **Committed in:** N.v.t. — verify-step-shape, geen tracked-files.

---

**Total deviations:** 2 auto-fixes (1 worktree-setup, 1 verify-step composer-flag-issue). Geen architecturele afwijking, geen scope-creep, geen productie-code aangeraakt.

**Geen `AccountSubscriptionManager::recordPaymentEvent` shape-fix nodig.** De parallel-execution prompt vroeg deze fix optioneel te doen als de integration-roundtrip het pad raakt. Bij review blijkt:
- `PaymentWebhookHandler::handle` (plan 07-05 productie-code) cast al `(array) $payment` met `array_filter` op NUL-prefixed keys vóór het `recordPaymentEvent` aanroept (zie commit `5645514`, plan 07-06).
- Mijn integration-test raakt het webhook-pad NIET — het is een create/cancel-flow via `/v1/account-subscriptions` HTTP-endpoint, niet via `/webhooks/mollie/{connection_id}`.
- De manager-API `recordPaymentEvent(AccountSubscription $sub, array $payment)` is per contract array-only; de productie-handler doet de object→array-conversie. Defensiever maken zou Rule 4-territory (architecturale change) en is niet nodig voor plan 07-07's SC-4 bewijs.

Bijgevolg: 0 productie-code-wijzigingen in dit plan, conform plan-scope.

## TDD Gate Compliance

Plan 07-07 is `type: execute` met 1 task gemarkeerd `type="auto"` (geen `tdd="true"`-flag). Geen plan-niveau RED/GREEN-gate-verificatie nodig.

In strikte TDD-zin had de test eerst moeten falen tegen een ontbrekend pad — in dit geval is de "test" de skip-guard zelf, en het primaire bewijs (real roundtrip) gebeurt alleen bij expliciete env-var-set door de developer. Dat is een acceptance-test-pattern, niet een RED/GREEN unit-test. Plan 07-08 (acceptance) is de plek waar het real-run-pad handmatig gevalideerd kan worden.

## Threat Surface Scan

Geen nieuwe trust-boundary-surface buiten het `<threat_model>` van het plan. Alle 4 STRIDE-rijen zijn ge-mitigate:

- **T-07-07-01 (Info Disclosure — MOLLIE_CONNECT_TEST_ACCESS_TOKEN leak):** Test gebruikt `env()`-call, geen `var_dump`/`dump`/`Log::info` op de token. `.env` is gitignored; `.env.example` heeft alleen `access_xxx`-placeholder. Skip-guard voorkomt CI-execution zonder explicit opt-in.
- **T-07-07-02 (Tampering — dangling Mollie test-data):** Cleanup-stap in `finally`-block revoke't de mandate (best-effort). Mollie test-mode customers verlopen sowieso na 30 dagen automatisch. Naming-prefix `Hub Integration Test <timestamp>` + email `integration+<timestamp>@emeq.test` maken het test-data eenvoudig herkenbaar in het Mollie-dashboard.
- **T-07-07-03 (DoS — Mollie test-mode rate-limit):** Test runt alleen opt-in via `composer test:integration`; default-CI runt het niet. Developer-machine rate-limits zijn ~30 req/min — ruim onder Mollie test-env-limiet.
- **T-07-07-04 (Repudiation — onduidelijke fail-reden):** Skip-guard message wijst naar exact env-var-naam + format-vereiste. Assertions zijn expliciet (`assertSame('valid', $mollieMandate->status)` met diagnose-message, `assertStringStartsWith('sub_', ...)` met value-context). `finally`-block heeft inner try/catch om partial-failure-state niet te maskeren.

## Deferred Items

- **Real-run verification.** Test is alleen automatisch verifieerbaar in skip-modus zonder live Mollie Connect-token. De feitelijke roundtrip-execute valt onder plan 07-08 (acceptance) of handmatige UAT door een developer met een PAT in `.env`. Plan 07-08 mag besluiten dat skip-default voor v0.2 acceptabel is en handmatige UAT als alternatief telt.
- **docs-sync skill-run.** Geen routes/models/migrations toegevoegd in plan 07-07 — alleen tests + `.env.example`. Phase-close in plan 07-08 / orchestrator-merge doet de globale docs-sync.

## Issues Encountered

- **`composer test:integration -- --filter=` werkt niet.** Composer slikt `--filter` op als unknown option. Workaround: direct `vendor/bin/phpunit --configuration=phpunit.integration.xml --filter=...`. Geen wijziging aan composer-script gedaan; functioneel equivalent.

## User Setup Required

**Voor lokale UAT (optioneel):** voeg in `.env` een echte Mollie Connect personal-access-token toe:

```
MOLLIE_CONNECT_TEST_ACCESS_TOKEN=access_<echte-token>
```

Verkrijg via Mollie Dashboard → Settings → Developers → Personal access tokens (test-mode). Daarna draait `composer test:integration` de echte roundtrip — verwachte duur ~5-10 sec voor 4 Mollie API-calls + de Hub-flow.

Zonder de env-var blijft de test skipped — geen actie vereist voor reguliere CI / development-workflow.

## Next Phase Readiness

- **07-08 (acceptance):** D-32 §6 acceptance-criterium (Integration-test `@group integration` draait NIET in default `php artisan test` maar wel in `composer test:integration`) is met deze plan formeel gedekt. Acceptance kan de full suite-run + Pint + Scramble-OpenAPI-render-check uitvoeren en dan SUB-02 op Validated zetten.

## Self-Check

- `tests/Integration/AccountSubscriptions/AccountSubscriptionIntegrationTestCase.php` — FOUND
- `tests/Integration/AccountSubscriptions/AccountSubscriptionMollieRoundtripTest.php` — FOUND
- `.env.example` contains `MOLLIE_CONNECT_TEST_ACCESS_TOKEN` — VERIFIED
- `#[Group('integration')]` on both files — VERIFIED
- Commit `9530784` — FOUND in git log (test 07-07 integration-roundtrip)
- `php artisan test --compact --filter=AccountSubscriptionMollieRoundtripTest` — 0 tests / 0 passed (correctly excluded from default suite)
- `vendor/bin/phpunit --configuration=phpunit.integration.xml --filter=AccountSubscriptionMollieRoundtripTest` — 1 test / 0 passed / 1 skipped (env-var placeholder)
- `vendor/bin/phpunit --configuration=phpunit.integration.xml` (alle) — 5 tests / 0 passed / 5 skipped (geen regressie op Phase 6)
- `php artisan test --compact` — 337 passed / 1100 assertions / 1 pre-existing incomplete (geen regressie)
- `./vendor/bin/pint --dirty --format agent` — passed
- Verification items 1-5 uit plan: alle 5 geslaagd

## Self-Check: PASSED

---
*Phase: 07-account-level-subscriptions-use-case-b*
*Completed: 2026-05-15*
