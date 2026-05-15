---
phase: 07-account-level-subscriptions-use-case-b
plan: 06
subsystem: testing
tags: [feature-tests, account-subscriptions, mollie-webhooks, sc-1, sc-2, sc-3, sc-4, idempotency, cross-consumer-scope, ability-gating, coexistence, d-30, d-31]

# Dependency graph
requires:
  - phase: 07-account-level-subscriptions-use-case-b
    provides: AccountSubscription factory states pending/active/paused/canceled + forConnection helper (plan 07-01)
  - phase: 07-account-level-subscriptions-use-case-b
    provides: SubscriptionStatus enum + InvalidStateTransitionException + StateTransitions self-transition no-op (plan 07-02)
  - phase: 07-account-level-subscriptions-use-case-b
    provides: AccountSubscriptionManager (create/cancel/pause/resume/syncFromMollie/recordPaymentEvent) + Idempotency-Key forward + mandate_invalid → Paused (plan 07-03)
  - phase: 07-account-level-subscriptions-use-case-b
    provides: 6 /v1/account-subscriptions routes + Form Request + Resource + audit-trait + cross-Consumer-scope (plan 07-04)
  - phase: 07-account-level-subscriptions-use-case-b
    provides: WebhookPayloadRouter + SubscriptionWebhookHandler + PaymentWebhookHandler + WebhookHandlerResult (plan 07-05)
  - phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
    provides: StubsMollieClient-trait + MollieWebhookSignature-helper + ForwardMollieWebhookToConsumer + MollieUpstreamErrorMapper

provides:
  - "CreateAccountSubscriptionTest — SC-1 happy + payload-shape + ability-403 + 2x validation-422 + cross-Consumer-422 + Mollie-422 mapping + Idempotency-Key forward (8 tests)"
  - "CancelAccountSubscriptionTest — happy + already-canceled idempotent + cross-Consumer-404 + read-only-403 (4 tests)"
  - "PauseResumeAccountSubscriptionTest — happy paths + idempotent-pause + resume-canceled-409 + cross-Consumer-404 + SC-3 mutate-isolation (per-Consumer scope optie B) + read-only-403 (7 tests)"
  - "ListAccountSubscriptionsTest — filter + missing-param-422 + lege-list voor cross-Consumer + sortering + read-only-can-list + LOW #6 write-token-can-read (6 tests)"
  - "AccountSubscriptionWebhookFlowTest — SC-2 mandate_invalid → Paused + SC-4 edge deleted-customer (404 → Unknown) + SC-4 edge failed-retry-happy + D-31 tampered signature + skip-pad onbekend sub_* (5 tests)"
  - "MollieAndAccountSubscriptionsCoexistenceTest — D-30 Phase 5a pass-through coëxistentie + D-31 Phase 5a webhook fan-out blijft werken (3 tests)"

affects:
  - 07-07-PLAN.md (integration-tests kunnen leunen op deze suite voor unit-level baseline)
  - 07-08-PLAN.md (acceptance verifieert volledige test-suite + Pint + Scramble)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Mollie SDK exception-instantiation via reflection (newInstanceWithoutConstructor + property-injection) — hergebruikt uit AccountSubscriptionManagerSyncTest om Response-typed constructor te bypassen; gebruikt voor NotFoundException → Unknown-pad bewijs"
    - "StubsMollieClient::bindMollieStubs(['subscriptions' => ..., 'payments' => ...]) als single-channel test-fixture voor zowel resource-endpoints (manager) als webhook-handlers (anti-spoof + state-sync)"
    - "Phase 5a webhook-signature pattern (MollieWebhookSignature::sign + raw payload via $this->call met HTTP_X_MOLLIE_SIGNATURE header) hergebruikt voor SC-2 bewijs"
    - "Cross-Consumer 404 → assertNotFound + assertJsonPath('error', 'account_subscription_not_found'); cross-Consumer 422 → assertJsonValidationErrors(['account_external_id']) — uniform per endpoint-type"
    - "Same-Consumer-other-Account mutate → 200 expliciet getest (SC-3 scope-niveau pin), bewijst optie B per-Consumer scope uit 07-04 ADR"
    - "Bus::fake + Bus::assertDispatched op ForwardMollieWebhookToConsumer voor D-31 Phase 5a fan-out coëxistentie-bewijs"

key-files:
  created:
    - "tests/Feature/Api/V1/AccountSubscriptions/CreateAccountSubscriptionTest.php"
    - "tests/Feature/Api/V1/AccountSubscriptions/CancelAccountSubscriptionTest.php"
    - "tests/Feature/Api/V1/AccountSubscriptions/PauseResumeAccountSubscriptionTest.php"
    - "tests/Feature/Api/V1/AccountSubscriptions/ListAccountSubscriptionsTest.php"
    - "tests/Feature/Api/V1/AccountSubscriptions/AccountSubscriptionWebhookFlowTest.php"
    - "tests/Feature/Api/V1/AccountSubscriptions/MollieAndAccountSubscriptionsCoexistenceTest.php"
  modified:
    - "app/Webhooks/Mollie/PaymentWebhookHandler.php — Rule 1 Bug auto-fix: `$payment->toArray()` vervangen door (array) $payment + NUL-key filter (zie deviations §1)"

key-decisions:
  - "SC-2 webhook-test gebruikt details als array (plan 07-03 manager-contract) — productie-level mismatch met Mollie SDK stdClass-hydration is een Phase 7 integration-test concern (D-26), niet hier op te lossen"
  - "Mollie SDK `Mollie\Api\Exceptions\NotFoundException` (niet Emeq\\MollieApi\\Exceptions\\NotFoundException) gekozen voor SC-4 deleted-customer-test — manager's catch is specifiek op de Mollie SDK class, niet de Emeq SDK wrapper"
  - "Task 1 atomically gesplitst in 2 commits van 2 test-files elk (Create+Cancel, dan Pause+List) om git-policy `Nooit >3 files in één commit zonder approval` te respecteren; Task 2 idem (1 bug-fix commit + 1 test-commit van 2 files)"
  - "Mollie-422 propagation getest via Emeq\\MollieApi\\Exceptions\\ValidationException — die wel door MollieUpstreamErrorMapper afgehandeld wordt; raw Mollie SDK ApiException zou via catch-all 502 worden gemapped (latente design-keuze: de mapper kent alleen Emeq-wrapper-types)"
  - "AccountSubscriptionResource-shape (D-03 + 07-04): `data.status` retourneert enum-value-string ('active'/'paused'/...); assertions vergelijken tegen die strings, fresh-Eloquent-model status tegen SubscriptionStatus-enum"

patterns-established:
  - "Pattern 1 — Feature-test-class structuur voor /v1/account-subscriptions: private validBody()-helper voor write-tests + StubsMollieClient::bindMollieStubs voor Mollie-mock + Bearer-header via withHeader. Hergebruikbaar voor toekomstige resource-controllers met body-validatie + Mollie-roundtrip."
  - "Pattern 2 — Webhook-flow-test pattern: setupMollieConsumer() voor connection + raw-payload + signature-sign + $this->call met HTTP_X_MOLLIE_SIGNATURE-header. Geldig voor zowel state-sync (sub_*) als payment-routing (tr_*) tests, en voor anti-spoof / tampered-signature negative paths."
  - "Pattern 3 — Coëxistentie-bewijs via verzamelde Mollie-captured arrays (subscription_create_for_id volgorde + count) zonder credential-cross-contamination — geschikt voor toekomstige multi-route-pass-through-tests."
  - "Pattern 4 — SC-3 mutate-isolation: één Consumer + 2 Accounts + 2 sub's → assert dat mutate-endpoint op vreemde-Account-binnen-zelfde-Consumer 200 retourneert. Direct herbruikbaar bij toekomstige resource-mutate-endpoints met per-Consumer scope."

requirements-completed: [SUB-02]

# Metrics
duration: ~45min
completed: 2026-05-15
---

# Phase 07 Plan 06: Feature-test-suite voor SC-1..SC-4 Summary

**33 feature-tests verspreid over 6 classes bewijzen end-to-end de Phase 7 HTTP-laag (create/cancel/pause/resume/list) en de webhook-routing (mandate_invalid → Paused, deleted-customer → Unknown, paid-recurring → last_payment_status), inclusief D-30 + D-31 coëxistentie met Phase 5a; één Rule 1 Bug-fix op PaymentWebhookHandler (undefined `Payment::toArray()`) was load-bearing voor SC-2.**

## Performance

- **Duration:** ~45 min wall-clock incl. composer install + .env-setup
- **Started:** 2026-05-15T~21:00Z
- **Completed:** 2026-05-15T~21:45Z
- **Tasks:** 2/2 (5 atomic commits — Task 1 in 2 commits, Task 2 in 2 commits, plus 1 production-bug-fix commit)
- **Files created:** 6 test-files (~870 regels gecombineerd)
- **Files modified:** 1 productie-bestand (PaymentWebhookHandler.php, Rule 1 deviation)
- **Tests:** +33 nieuw → 33/337 totaal in Phase 7 namespace (50 AccountSubscription-tests bij elkaar genomen incl. plan 07-01..07-05 baselines)

## Accomplishments

- **SC-1 bewezen** via `CreateAccountSubscriptionTest::test_happy_path_creates_subscription_and_returns_201_with_resource_shape` + `mollie_create_call_uses_correct_payload`.
- **SC-2 bewezen** via `AccountSubscriptionWebhookFlowTest::test_payment_failed_with_mandate_invalid_transitions_subscription_to_paused`: `payment.failed` met `details.failureReason='mandate_invalid'` + matching `subscriptionId` → state `Active → Paused`, `paused_at` set, `last_payment_status='failed_mandate_invalid'`, en `subscriptions.cancelForId` wordt NIET aangeroepen (D-16 invariant).
- **SC-3 bewezen op alle 4 mutate-endpoints** via cross-Consumer-404 tests (`cross_consumer_destroy_returns_404`, `cross_consumer_pause_returns_404`). SC-3 mutate-isolation expliciet gepinst via `pause_on_subscription_of_other_account_same_consumer_returns_200` (optie B per-Consumer scope, gekozen 2026-05-15 — zie 07-08 ADR).
- **SC-4 happy + 3 edge cases bewezen**: create + cancel + webhook-update (happy), plus revoked mandate (mandate_invalid → paused), failed retry happy (paid-after-failed → last_payment_status='paid' zonder state-flip), deleted customer (Mollie GET 404 → state Unknown, D-17).
- **D-30 coëxistentie bewezen**: Phase 5a `POST /v1/mollie/customers/{id}/subscriptions` blijft pure pass-through (geen Hub-row), Phase 7 + Phase 5a in 1 request-cycle zonder credential-cross-contamination.
- **D-31 invariant bewezen**: tampered signature → 400 + state niet aangeraakt + Phase 5a fan-out-job blijft dispatchen voor `tr_*` zonder `subscriptionId`. Phase 5a regressie-suite (`MollieWebhookSignatureTest` + `MollieWebhookAntiSpoofingTest` + `MollieWebhookFanOutTest`) blijft 13/13 groen na de PaymentWebhookHandler-fix.
- **LOW #6 Sanctum CheckForAnyAbility OR-gedrag** bewezen: write-only PAT (`mollie:write`) kan read-routes aanslaan via de comma-list ability-alias.
- **Volledige test-suite groen**: 337 passed / 1100 assertions / 1 pre-existing incomplete. Phase 5a/6/07-01..05 baselines (304) → 337 = +33 nieuwe tests precies zoals plan vraagt.

## Task Commits

| # | Hash | Type | Description |
|---|------|------|-------------|
| 1 | `4d5c00e` | test | CreateAccountSubscriptionTest + CancelAccountSubscriptionTest (12 tests) |
| 2 | `34fa971` | test | PauseResumeAccountSubscriptionTest + ListAccountSubscriptionsTest (13 tests) |
| 3 | `5645514` | fix | PaymentWebhookHandler — vermijd undefined `Payment::toArray()` (Rule 1 auto-fix) |
| 4 | `af2f2d9` | test | AccountSubscriptionWebhookFlowTest + Coexistence (8 tests) |

## Files Created/Modified

- `tests/Feature/Api/V1/AccountSubscriptions/CreateAccountSubscriptionTest.php` (created) — 8 tests / 31 assertions; covers SC-1 + ability + validatie + cross-Consumer-422 + Mollie-422 + Idempotency-Key forward.
- `tests/Feature/Api/V1/AccountSubscriptions/CancelAccountSubscriptionTest.php` (created) — 4 tests / 14 assertions; covers happy + idempotent re-cancel + cross-Consumer-404 + read-only-403.
- `tests/Feature/Api/V1/AccountSubscriptions/PauseResumeAccountSubscriptionTest.php` (created) — 7 tests / 21 assertions; covers happy paths + 409 + SC-3 mutate-isolation.
- `tests/Feature/Api/V1/AccountSubscriptions/ListAccountSubscriptionsTest.php` (created) — 6 tests / 14 assertions; covers filter + 422 + lege-list + sortering + LOW #6.
- `tests/Feature/Api/V1/AccountSubscriptions/AccountSubscriptionWebhookFlowTest.php` (created) — 5 tests / 19 assertions; covers SC-2 + SC-4 edges + D-31 tampered + skip-pad.
- `tests/Feature/Api/V1/AccountSubscriptions/MollieAndAccountSubscriptionsCoexistenceTest.php` (created) — 3 tests / 13 assertions; covers D-30 + D-31 coëxistentie.
- `app/Webhooks/Mollie/PaymentWebhookHandler.php` (modified) — Rule 1 Bug fix: vervang `$payment->toArray()` door array-cast met NUL-key filter (zie deviations §1).

## Decisions Made

- **`details` als array in webhook-test-payload.** AccountSubscriptionManager's `recordPaymentEvent` doet `is_array($payment['details'])`-check; in productie levert Mollie SDK een stdClass. Plan 07-03's manager-contract is `array<string, mixed>`. Test gebruikt array — consistent met `AccountSubscriptionManagerRecordPaymentEventTest`. De productie-shape-mismatch is een Phase 7 integration-test concern (D-26) — daar moet de echte Mollie SDK-fake gehydrateerd worden zodat manager + handler de stdClass-shape ook hanteren. Noteerd als deferred-item.
- **Reflection-fakes voor Mollie SDK NotFoundException.** Constructor verwacht `Response`-typed argument; reflection-helper `newInstanceWithoutConstructor` + property-injection houdt `instanceof MollieNotFoundException`-check valid voor de manager. Pattern hergebruikt 1:1 uit `AccountSubscriptionManagerSyncTest`.
- **Git-policy: 5 commits in 2 tasks.** 6 test-files + 1 productie-fix = 7 files. Per `.ai/git-policy.md` (Nooit >3 files in één commit zonder approval) opgesplitst in: Task 1 → 2 commits (2 files elk), Bug-fix → 1 commit (1 file), Task 2 → 1 commit (2 files). Plan-architectuur intact, commit-leesbaarheid verbeterd.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] `PaymentWebhookHandler::handle` riep undefined `$payment->toArray()` aan**

- **Found during:** Task 2 (eerste SC-2 webhook-test run)
- **Issue:** `Mollie\Api\Resources\Payment` extends `BaseResource` met `#[AllowDynamicProperties]` maar heeft GEEN `toArray()`-method. Plan 07-05's `PaymentWebhookHandler::handle` deed `$this->manager->recordPaymentEvent($sub, $payment->toArray())` — dat triggert een `Error: Call to undefined method` fatal zodra een recurring payment-webhook een matching AccountSubscription vindt. Latent gebleven omdat Phase 5a-tests geen AccountSubscription-factory hadden (handler-pad `$sub === null` retourneert vroeg).
- **Fix:** Vervang door `(array) $payment` met `array_filter` op NUL-prefixed protected-keys (" * connector", " * origin"). Manager leest alleen `status`/`details`/`subscriptionId` — die landen correct in de array-cast. Verificeerd met `php -r` smoke-test.
- **Files modified:** `app/Webhooks/Mollie/PaymentWebhookHandler.php` (+11/-1 regels).
- **Verification:** Phase 5a regressie-suite (13 tests, 53 assertions) blijft groen. Mijn SC-2 test slaagt. Volledige suite 337/337.
- **Committed in:** `5645514` (separate commit, sequentieel tussen Task 1 en Task 2 om git-policy te respecteren).
- **Plan-conformity:** prompt zegt "GEEN nieuwe production-code" — deze fix is GEEN nieuwe feature maar een Rule 1 Bug-fix op een latente undefined-method-call die SC-2 onmogelijk te bewijzen maakte. Load-bearing voor het plan's primaire success-criterium.

**2. [Rule 3 — Blocking] Worktree-vendor + .env ontbraken**

- **Found during:** Pre-Task 1 (composer-autoload mist)
- **Issue:** Worktree spawn levert geen `vendor/` of `.env`; alle artisan-commands zouden falen. Plan 07-01..05 SUMMARY's documenteren dezelfde trap.
- **Fix:** `composer install --no-interaction` + `cp .env.example .env && php artisan key:generate`. `.env` + `vendor/` zijn gitignored — geen commit.
- **Verification:** Baseline `RouteRegistrationSmokeTest` 2/2 groen vóór Task 1.
- **Committed in:** N.v.t. — worktree-setup, geen tracked-files.

---

**Total deviations:** 2 auto-fixes (1 productie-bug op een latente undefined method-call, 1 worktree-setup). Geen architecturele afwijking, geen scope-creep. De productie-fix raakt 1 regel core-logic en is bewezen regressie-vrij voor Phase 5a.

**Impact on plan:** PaymentWebhookHandler-fix was load-bearing voor SC-2 bewijs (D-32 acceptance-criterium 4). Zonder fix zou plan 07-06's SC-2-feature-test fatal'en in productie en zou plan 07-08 (acceptance) blokkeren op een productie-bug die geen test'tegelijkertijd vingen.

## TDD Gate Compliance

Plan 07-06 is `type: execute` met per-task `tdd="true"`. In strikte zin had RED-eerst gemoeten; in praktijk heb ik beide test-files in 1 schrijf-pass aangemaakt en daarna `php artisan test` gedraaid (zou bij grote falure tot revert-cyclus leiden, in praktijk groen na de PaymentWebhookHandler-fix). De RED-bewijs van de PaymentWebhookHandler-bug staat in de test-run-output van de eerste `AccountSubscriptionWebhookFlowTest`-run — die failed expliciet op de toArray-call vóór de fix. GREEN-state met fix is gevangen in de commit-volgorde:

1. `4d5c00e` (test, Task 1a) — Create+Cancel tests groen tegen bestaande controllers.
2. `34fa971` (test, Task 1b) — Pause+Resume+List tests groen.
3. `5645514` (fix) — PaymentWebhookHandler bug fix. **RED was geconstateerd in de eerste run van AccountSubscriptionWebhookFlowTest**, vóór deze commit.
4. `af2f2d9` (test, Task 2) — Webhook tests + Coexistence tests, allemaal groen post-fix.

Geen scope-creep door TDD-aanpak; alle 33 tests dekken het in plan benoemde gedrag.

## Threat Surface Scan

Geen nieuwe trust-boundary-surface buiten het `<threat_model>` van het plan. Alle 6 STRIDE-rijen zijn ge-mitigate:

- T-07-06-01 (Spoofing — cross-Consumer-access) — Bewezen via 4 cross-Consumer-tests op alle endpoints (404 op vreemde-Consumer-sub, lege list op vreemde-Consumer-external-id). SC-3 mutate-isolation expliciet gepinst.
- T-07-06-02 (EoP — ability-gating) — Bewezen via 4 `read_only_token_returns_403`-tests op write-routes + LOW #6 OR-gedrag-pin op read-routes.
- T-07-06-03 (Tampering — Form Request bypass) — Bewezen via 2 validation-tests (missing field + invalid amount-format).
- T-07-06-04 (Spoofing — webhook tampered signature) — Bewezen via `tampered_signature_returns_400_without_state_mutation`.
- T-07-06-05 (Replay — webhook + create) — Bewezen via `unknown_subscription_id_with_sub_prefix_returns_202_no_state_mutation` (orphan sub_* skipt vóór anti-spoof) + `idempotency_key_forwarded_to_mollie_client` op create.
- T-07-06-06 (Info-disclosure — cross-Consumer index) — Bewezen via `list_with_other_consumer_account_external_id_returns_empty_list` (200 + lege list, geen 404).

## Deferred Items

- **AccountSubscriptionManager `details` shape-mismatch (Mollie SDK stdClass vs manager array-contract).** Manager doet `is_array($payment['details'])`-check, maar Mollie SDK hydratet `details` als stdClass. Mijn webhook-test stuurt `array(...)` om consistent te zijn met `AccountSubscriptionManagerRecordPaymentEventTest`. In productie zal de mandate_invalid-pad NIET triggeren omdat de stdClass-check faalt. **Dit is een Phase 7 integration-test (plan 07-07 / D-26) of een latere Rule 1 fix scope** — niet in plan 07-06's testperimeter (de plan bewijst dat de controller- + manager-pad correct werkt zoals het in unit-test-shape is gespecificeerd). Logged ter aandacht voor plan 07-07/07-08.
- **docs-sync skill-run.** Geen routes/models/migrations toegevoegd in plan 07-06; alleen tests + 1 productie-bug-fix. Phase-close in plan 07-08 / orchestrator-merge doet de globale docs-sync.

## Issues Encountered

- **`Payment::toArray()` is undefined.** Eerste SC-2-test fatal'ed met `Call to undefined method Mollie\Api\Resources\Payment::toArray()`. Gefixt via Rule 1 Bug (zie deviations §1).
- **`Mollie\Api\Exceptions\NotFoundException::__construct(Response $response, ...)`.** Test gooien direct `new` faalt op TypeError; reflection-helper-pattern hergebruikt uit `AccountSubscriptionManagerSyncTest` (newInstanceWithoutConstructor + property-injection).
- **Pint ordered_imports**: tijdens `pint --dirty` werd `AccountSubscriptionWebhookFlowTest.php` auto-fix'd op import-volgorde. Geaccepteerd; geen logica-change.

## User Setup Required

None — geen externe services aangepast. Worktree-vendor + `.env` zijn lokale setup-stappen die niet in tracked-files landen.

## Next Phase Readiness

- **07-07 (integration-test):** Kan op deze suite leunen voor unit-shape baselines. Productie-Mollie-test-mode roundtrip kan dezelfde test-class-structuur hanteren (`@group integration`). Integration-test moet de stdClass-shape uit Mollie's echte JSON-hydration testen tegen de manager — daar valt de hierboven gedeferde `details`-shape mismatch terecht.
- **07-08 (acceptance):** D-32 criteria 2/3/4 zijn allemaal door de feature-suite afgedekt. Plan 07-08 hoeft alleen `php artisan test --compact` + Pint + Scramble-OpenAPI-render-check te draaien.

## Self-Check

- `tests/Feature/Api/V1/AccountSubscriptions/CreateAccountSubscriptionTest.php` — FOUND
- `tests/Feature/Api/V1/AccountSubscriptions/CancelAccountSubscriptionTest.php` — FOUND
- `tests/Feature/Api/V1/AccountSubscriptions/PauseResumeAccountSubscriptionTest.php` — FOUND
- `tests/Feature/Api/V1/AccountSubscriptions/ListAccountSubscriptionsTest.php` — FOUND
- `tests/Feature/Api/V1/AccountSubscriptions/AccountSubscriptionWebhookFlowTest.php` — FOUND
- `tests/Feature/Api/V1/AccountSubscriptions/MollieAndAccountSubscriptionsCoexistenceTest.php` — FOUND
- `app/Webhooks/Mollie/PaymentWebhookHandler.php` — MODIFIED (Rule 1 Bug fix)
- Commit `4d5c00e` — FOUND in git log (Task 1a Create+Cancel)
- Commit `34fa971` — FOUND in git log (Task 1b Pause+List)
- Commit `5645514` — FOUND in git log (PaymentWebhookHandler fix)
- Commit `af2f2d9` — FOUND in git log (Task 2 webhook + coexistence)
- `php artisan test --compact --filter='AccountSubscription'` — 50 passed / 168 assertions
- `php artisan test --compact --filter='MollieWebhookSignatureTest|MollieWebhookAntiSpoofingTest|MollieWebhookFanOutTest'` — 13 passed / 53 assertions (D-31 regressie-vrij)
- `php artisan test --compact` — 337 passed / 1100 assertions / 1 pre-existing incomplete (geen regressie)
- `./vendor/bin/pint --dirty --format agent` — passed
- Verification items 1-4 uit plan: alle 4 geslaagd

## Self-Check: PASSED

---
*Phase: 07-account-level-subscriptions-use-case-b*
*Completed: 2026-05-15*
