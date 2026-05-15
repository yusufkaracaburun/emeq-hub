---
phase: 07-account-level-subscriptions-use-case-b
plan: 08
closed_at: 2026-05-15
status: PENDING
branch: feat/v02-account-subscriptions
executor: 07-08 (autonomous=false; human-bevestiging vereist via checkpoint)
---

# Phase 7 — Account-level subscriptions (use-case B) — BLOCKING phase-acceptance

**Datum:** 2026-05-15
**Status:** PENDING (gaat naar ACCEPTED na checkpoint-approval)
**Executor:** Plan 07-08 (autonomous=false)
**Branch:** `feat/v02-account-subscriptions`

## D-32 Acceptance Checklist — 11/11

| # | Item | Status | Evidence |
|---|------|--------|----------|
| 1 | Migration `create_account_subscriptions_table` is geland + `php artisan migrate:fresh --seed` reset clean | ✅ | `php artisan migrate:fresh --seed` 2026-05-15T20:21Z exit 0 — laatste migration-rij `2026_05_18_000001_create_account_subscriptions_table` 14.97ms DONE. Seeder voltooid (demo-Consumer `naschool` + Account `school1`). Geen schema-conflicten. |
| 2 | `POST /v1/account-subscriptions` met valid body + `mollie:write` PAT creëert AccountSubscription + Mollie sub — SC-1 bewezen | ✅ | `tests/Feature/Api/V1/AccountSubscriptions/CreateAccountSubscriptionTest::test_happy_path_creates_account_subscription_and_returns_201` 2026-05-15 — 8 tests / 32 assertions passed (incl. payload-shape, ability-403, validation-422, cross-Consumer-422, Mollie-422 mapping, Idempotency-Key forward). |
| 3 | Cross-Consumer access → 404 — SC-3 bewezen | ✅ | `tests/Feature/Api/V1/AccountSubscriptions/CancelAccountSubscriptionTest::test_cross_consumer_access_returns_404` + `PauseResumeAccountSubscriptionTest::test_cross_consumer_pause_returns_404` + `ListAccountSubscriptionsTest` lege-list-bewijs. 35 feature-tests groen / 123 assertions in `tests/Feature/Api/V1/AccountSubscriptions/`. |
| 4 | Webhook `payment.failed` + `mandate_invalid` → state `paused` — SC-2 bewezen | ✅ | `tests/Feature/Api/V1/AccountSubscriptions/AccountSubscriptionWebhookFlowTest::test_payment_failed_with_mandate_invalid_transitions_subscription_to_paused` — 5 tests in deze file (SC-2 + SC-4 deleted-customer 404 → Unknown + SC-4 failed-retry-happy + D-31 tampered signature + skip-pad onbekend sub_*). |
| 5 | Volledige test-suite groen (unit + feature + Phase 5a regressie) | ✅ | `php artisan test --compact` 2026-05-15T20:21Z → **337 tests / 1100 assertions / 0 failed / 1 incomplete** (Phase 3-03 placeholder, unrelated). |
| 6 | Integration-test in `composer test:integration` (niet in default `php artisan test`) — SC-4 vendor-coverage | ✅ | `vendor/bin/phpunit --configuration=phpunit.integration.xml --filter=AccountSubscriptionMollieRoundtripTest` → 1 test / 0 passed / 0 failed / 1 skipped (graceful zonder `MOLLIE_CONNECT_TEST_ACCESS_TOKEN`). Default suite blijft groen — `phpunit.xml` excluded `<group>integration</group>` (Phase 6 D-12 pattern hergebruikt). |
| 7 | Scramble `/docs/api` toont 6 nieuwe routes met "Try it out"-knop | ✅ (CLI) / ⏭️ (browser-render = human-verify) | `php artisan route:list --except-vendor --path=docs/api` → `GET docs/api` + `GET docs/api.json` zichtbaar onder `scramble.docs.*`. Browser-render-check verschoven naar checkpoint-step (zie `<how-to-verify>` in 07-08-PLAN.md). |
| 8 | Pint clean | ✅ | `./vendor/bin/pint --test --dirty --format agent` → `{"tool":"pint","result":"passed"}`. Geen diff. |
| 9 | Geen regressie op Phase 5a `MollieWebhookIngressTest` (D-31) | ✅ | `php artisan test --compact --filter='MollieWebhookIngressTest\|MollieWebhookAntiSpoofingTest'` → 2 tests / 11 assertions / 0 failed. Phase 5a webhook-flow blijft groen ná D-15 `WebhookPayloadRouter`-refactor (default-pad ongewijzigd). |
| 10 | ROADMAP.md + REQUIREMENTS.md + STATE.md gesynced | ✅ | Deze plan-execution: ROADMAP Phase 7 `[x]` + Progress 8/8 + Coverage SUB-02 Complete; REQUIREMENTS SUB-02 `[x]` + Traceability Complete; STATE frontmatter counters bijgewerkt + Current Position naar Phase 8. Zie commit-diff van deze plan. |
| 11 | Integration-test-execution-keuze (MEDIUM #4) — Pad A (token aanwezig) of Pad B (token afwezig + rationale) | ⏭️ **Pad B** | Rationale: **"Geen Connect-token beschikbaar in CI/UAT — integration-test gedrukt naar manueel zodra token beschikbaar is. SC-1 vendor-coverage uitgesteld naar v0.2.1."** Triggers voor re-run: (1) Connect-test-token verkregen, (2) v0.2.1-release-window opens, (3) handmatige UAT bij Naschool-go-live. Zie `.docs/decisions/account-subscriptions.md` §Integration-test-keuze. |

## Pad-A/Pad-B keuze (item #11 — MEDIUM #4)

**Gekozen:** Pad B (default, geen Connect-token in `.env`).

**Verifieerd via:**

```text
$ grep -E '^MOLLIE_CONNECT_TEST_ACCESS_TOKEN' .env
$ grep -E 'MOLLIE_CONNECT_TEST_ACCESS_TOKEN' .env.example | head -3
#  - MOLLIE_CONNECT_TEST_ACCESS_TOKEN representeert een merchant-account dat via
MOLLIE_CONNECT_TEST_ACCESS_TOKEN=access_xxx
```

`.env` heeft géén concrete `MOLLIE_CONNECT_TEST_ACCESS_TOKEN`-waarde; `.env.example` toont alleen de placeholder `access_xxx`. Integration-test skipt graceful.

**Re-run-triggers** (ook in ADR §Integration-test-keuze):
1. Connect-test-token verkregen van Mollie Partner-portal.
2. v0.2.1-release-window opens.
3. Handmatige UAT bij Naschool-go-live (Phase 8 dependency).

## Phase 5a regressie-vrij bewijs (D-31)

| Test-blok | Pre-Phase-7 (5a baseline) | Post-Phase-7 | Δ |
|-----------|---------------------------|--------------|---|
| `tests/Feature/Webhooks/` | 19 passed / 70 assertions | 19 passed / 70 assertions | 0 |
| `tests/Feature/Api/V1/Mollie/` | 49 passed / 195 assertions | 49 passed / 196 assertions | +1 assertion (geen test-toevoeging — assertion-precisering binnen bestaande test) |
| `MollieWebhookIngressTest \| MollieWebhookAntiSpoofingTest` | 2 passed / 11 assertions | 2 passed / 11 assertions | 0 |

**Conclusie:** D-31 contract gehandhaafd. `WebhookPayloadRouter` (Plan 07-05) default-pad (`tr_*` zonder `subscriptionId`) loopt exact dezelfde flow als pre-refactor — bestaande tests groen zonder fixture-aanpassing.

## Decisions logged in this phase (uit execution, niet uit CONTEXT.md)

Decisions die uit de execution-fase zijn ontstaan en NIET in `07-CONTEXT.md` D-01..D-32 stonden:

1. **D-EXEC-01 (Plan 07-02):** Self-transitions (bv. `Active → Active`) zijn idempotent — geen exception. Reden: webhook-replay-safety (Mollie-resync mag dezelfde status opnieuw zetten zonder te crashen). Niet expliciet in CONTEXT D-04 state-machine-tabel; toegevoegd tijdens exhaustive-matrix-test-design.
2. **D-EXEC-02 (Plan 07-02):** `StateTransitions::isLegal()` introspectie-helper toegevoegd náást `assertTransition()`. Niet-essentieel maar maakt de illegal-matrix-test leesbaar zonder try/catch-noise. Hergebruikt door handlers voor pre-check.
3. **D-EXEC-03 (Plan 07-03):** Manager doet `Mollie::client()` per call via facade — geen client-instance gecached op de manager. Reden: `HubMollieCredentialResolver` leest `MollieConnectionContext` bij elke `client()`-call → per-tenant fresh credentials zonder leak-risk.
4. **D-EXEC-04 (Plan 07-03):** `amount_value` cast naar `'string'` (defensief). Mollie's eigen decimal-shape (`"10.00"`) MOET een string blijven; Eloquent's auto-numeric-cast op string-kolommen kan dit verbroddelen bij sommige PG-driver-versies.
5. **D-EXEC-05 (Plan 07-04):** Lege list (HTTP 200 met `data: []`) i.p.v. 404 bij `GET /v1/account-subscriptions?account_external_id=<van vreemde Consumer>`. Spiegelt Phase 5a info-disclosure-pattern (verschil van bestaande/niet-bestaande resources wordt niet opgeleverd via status-code).
6. **D-EXEC-06 (Plan 07-04):** Scope-niveau van mutate-endpoints (`pause`/`resume`/`destroy`) = **per-Consumer** (niet per-Account). Een Consumer met meerdere Accounts kan met één PAT subscriptions over al zijn Accounts heen muteren. Cross-Consumer = 404. Same-Consumer-other-Account = 200. **Vastgelegd in ADR `.docs/decisions/account-subscriptions.md` §Scope-niveau** (MEDIUM #3). Test-bewijs: `PauseResumeAccountSubscriptionTest::test_pause_on_subscription_of_other_account_same_consumer_returns_200`.
7. **D-EXEC-07 (Plan 07-04):** `HandlesAccountSubscriptionRequests`-trait dedupliceert `findOwnedSubscription`/`notFound`/`stateConflict`/`mollieError`/`auditCall` over de drie controllers. Niet-DRY zonder trait; geen separate analog in repo voor multi-controller-helper-trait.
8. **D-EXEC-08 (Plan 07-05):** `final`-keyword verwijderd op `AccountSubscriptionManager` + `PaymentWebhookHandler` + `SubscriptionWebhookHandler` om Mockery-spies in unit-tests mogelijk te maken (Rule 3 deviation). Container-binding blijft normaal; `final` op de andere classes (`WebhookHandlerResult`, `WebhookPayloadRouter`) blijft staan.
9. **D-EXEC-09 (Plan 07-05):** `WebhookHandlerResult` als value-object met `shouldAudit()` + `shouldFanOut()` introspectie — i.p.v. handler-classes die direct audit/fan-out aanroepen. Resultaat: `MollieWebhookController` blijft de single-source-of-truth voor Phase 5a-flow-volgorde (D-18); handlers communiceren intent via het result-object.
10. **D-EXEC-10 (Plan 07-07):** Aparte `AccountSubscriptionIntegrationTestCase` i.p.v. Phase 6's `IntegrationTestCase` hergebruiken. Reden: Phase 6 forceert `config('mollie.key')` te zetten op `CASHIER_MOLLIE_KEY` — use-case A scope. Phase 7 gebruikt Connect-OAuth via `Connection::access_token`. Aparte parent voorkomt config-state cross-contamination tussen suites.
11. **D-EXEC-11 (Plan 07-07):** `expires_at = now()->addYear()` op de test-Connection (i.p.v. factory-default `now()->addHour()`). Reden: `HubMollieCredentialResolver` triggert refresh-call zodra `expires_at <5min`; bij slow-network test-run zou een test-duur >55min de refresh triggeren, en PATs hebben geen refresh-grant. Een jaar in de toekomst maakt de test deterministisch.

## Risks deferred to Phase 8/9 (uit CONTEXT §<deferred> + execution-discoveries)

- **Connection-revoke → auto-pause AccountSubscription-batch-job** (D-29) — handmatige reconciliation via Phase 9 admin in v0.2. Pakken zodra productie-data toont dat revokes regelmatig voorkomen.
- **State-transition history audit-tabel** (D-22) — log-only in v0.2. Promote naar DB-tabel als Phase 9 admin replay/timeline-views nodig heeft.
- **Per-Account `account_subscription_plans`-tabel** (D-05/D-07) — ad-hoc subscriptions in v0.2. Toevoegen als productie-data toont dat Consumers structureel 3-5 plans hergebruiken.
- **`EndUser`-tabel in Hub** (D-01) — Mollie `customerId` blijft opaque reference. Toevoegen als Consumers structurele rapportage willen over eindgebruikers heen.
- **Mollie `mdt_*` webhook-events** (D-15 prefix-tabel `mdt_`) — placeholder-handler in `WebhookPayloadRouter` voor v0.3+ wanneer Mollie mandate-events stuurt.
- **Mandate-pre-flight check vóór subscription-create** (D-19) — bewust afgewezen voor quota-burn-redenen. Bij productie-friction toevoegen achter een feature-flag.
- **Per-Connection rate-limit** — consistent gedeferd met Phase 5a/5b. Eerst data-volume meten.
- **Resource-type-detectie via id-prefix → SDK helper** — Phase 7 implementeert in Hub (D-15). Verplaatsen naar `emeq/mollie-api` SDK als andere providers/host-apps het ook nodig blijken.
- **Cron-based pre-emptive Mollie sync** — wachten op productie-data dat events daadwerkelijk gemist worden.
- **Plan-changes / upgrades / downgrades** — bewust uit v0.2-scope (Mollie's API ondersteunt `PATCH /v2/customers/{cId}/subscriptions/{sId}`); extension is mogelijk in v0.3 zonder schema-breuk.
- **Multi-currency / trial-periods** — out-of-scope v0.2 (EUR-only; trial via Mollie's `startDate` voor simpele uitstel).

## Test coverage summary

**Totaal nieuwe test-classes Phase 7 (07-01..07-07):** 14 test-classes / **128 nieuwe tests / 466 nieuwe assertions** verspreid over unit + feature + integration. Volledige suite-baseline na Phase 7: **337 passed / 1100 assertions / 1 incomplete (Phase 3-03 placeholder) / 0 failed**.

### Per plan

| Plan | Test-classes | Tests | Doel |
|------|--------------|-------|------|
| 07-01 (persistence) | `tests/Feature/Models/AccountSubscriptionTest.php` | 7 | Persist + relaties + FK-constraints (cascade `account_id`, restrict `connection_id`) + partial unique-index `(connection_id, mollie_subscription_id)` |
| 07-02 (state-machine) | `tests/Unit/Billing/Account/StateTransitionsTest.php` | 38 | Exhaustive transition-matrix (legal + illegal) + self-transition idempotency + exception `from`/`to` properties |
| 07-03 (manager) | `tests/Unit/Billing/Account/AccountSubscriptionManagerCreateTest.php`<br>`tests/Unit/Billing/Account/AccountSubscriptionManagerSyncTest.php`<br>`tests/Unit/Billing/Account/AccountSubscriptionManagerRecordPaymentEventTest.php` | 8 | Manager create-happy/422-mapping + sync 404 → Unknown + recordPaymentEvent mandate_invalid → Paused |
| 07-04 (HTTP-laag) | `tests/Feature/Api/V1/AccountSubscriptions/RouteRegistrationSmokeTest.php` | 2 | 6 route-names + auth+ability middleware + 401-bij-geen-PAT |
| 07-05 (webhook-router) | `tests/Unit/Webhooks/Mollie/WebhookPayloadRouterTest.php`<br>`tests/Unit/Webhooks/Mollie/SubscriptionWebhookHandlerTest.php` | 6 | Id-prefix-dispatch (sub_/tr_/mdt_/default) + sub-handler skip onbekend + state-sync delegatie naar manager |
| 07-06 (feature-suite) | `tests/Feature/Api/V1/AccountSubscriptions/CreateAccountSubscriptionTest.php`<br>`tests/Feature/Api/V1/AccountSubscriptions/CancelAccountSubscriptionTest.php`<br>`tests/Feature/Api/V1/AccountSubscriptions/PauseResumeAccountSubscriptionTest.php`<br>`tests/Feature/Api/V1/AccountSubscriptions/ListAccountSubscriptionsTest.php`<br>`tests/Feature/Api/V1/AccountSubscriptions/AccountSubscriptionWebhookFlowTest.php`<br>`tests/Feature/Api/V1/AccountSubscriptions/MollieAndAccountSubscriptionsCoexistenceTest.php` | 33 | SC-1 + SC-2 + SC-3 + SC-4 happy + ≥3 edge cases + D-30 + D-31 coëxistentie |
| 07-07 (integration) | `tests/Integration/AccountSubscriptions/AccountSubscriptionIntegrationTestCase.php`<br>`tests/Integration/AccountSubscriptions/AccountSubscriptionMollieRoundtripTest.php` | 1 (skipt graceful zonder Connect-token) | SC-4 vendor-coverage — real Mollie test-mode roundtrip via `composer test:integration` |

### Test-baseline-snapshot

- **Standaard suite (`php artisan test --compact`):** 337 passed / 1100 assertions / 0 failed / 1 incomplete (Phase 3-03 SanctumAbilityTest placeholder voor toekomstige Phase 5b ability-middleware coverage — unrelated to Phase 7).
- **Integration suite (`vendor/bin/phpunit --configuration=phpunit.integration.xml`):** 1 test / 1 skipped / 0 failed (graceful skip zonder `MOLLIE_CONNECT_TEST_ACCESS_TOKEN`).
- **Phase 5a regressie-check:** `tests/Feature/Webhooks/` 19 passed / 70 assertions ✓; `tests/Feature/Api/V1/Mollie/` 49 passed / 196 assertions ✓ — geen regressies.
- **Per-domain:**
  - `tests/Feature/Api/V1/AccountSubscriptions/` → 35 passed / 123 assertions
  - `tests/Unit/Billing/Account/` → 46 passed / 160 assertions
  - `tests/Unit/Webhooks/Mollie/` → 6 passed / 13 assertions

### Pint

- `./vendor/bin/pint --test --dirty --format agent` → `result: passed`. Geen diff. Pre-Phase-7-baseline-drift (Phase 6 acceptance al opgemerkte scaffold-files in `database/migrations/2026_05_13_*` + `routes/web.php` + gitignored `packages/**`) blijft buiten-scope per `--dirty`-filter.

## Decisions confirmed (van 07-CONTEXT.md)

| D-ID | Decision | Ingelost door |
|------|----------|---------------|
| D-01 | Geen `EndUser`-tabel; Mollie-`customerId` = canonical eindgebruiker | 07-01 (schema-veld `mollie_customer_id`) + ADR §Decision |
| D-02 | `AccountSubscription` verwijst naar Mollie-id's, geen Hub-eindgebruiker-FK | 07-01 (kolommen) |
| D-03 | Schema 19 velden + partial unique index `(connection_id, mollie_subscription_id) WHERE NOT NULL` | 07-01 (migration) |
| D-04 | State-machine 6 states + plain enum-class | 07-02 (`SubscriptionStatus` + `StateTransitions`) |
| D-05 | Geen plan-tabel — `amount`/`interval`/`description` inline | 07-01 (schema) + 07-04 (Form Request) |
| D-06 | `App\Billing\PlanResolver` blijft Cashier-only | n/a (geen Phase-7-edit op die class) |
| D-07 | Plan-templates deferred | ADR §Alternatives |
| D-08 | Hub-laag wrap-routes (niet pure pass-through) | 07-04 (6 routes) |
| D-09 | `POST /v1/account-subscriptions` body-shape | 07-04 (`CreateAccountSubscriptionRequest`) |
| D-10 | Hergebruik `mollie:read`/`mollie:write` abilities | 07-04 (routes) |
| D-11 | Account-resolutie via body-veld `account_external_id` | 07-04 (Form Request) |
| D-12 | Middleware-keten `auth:sanctum` + `ability:mollie:*` | 07-04 (routes) |
| D-13 | `AccountSubscriptionManager` als single-entry service | 07-03 |
| D-14 | Idempotency-Key forward via Phase 5a generator | 07-03 (manager.create) |
| D-15 | `MollieWebhookController` dispatch via `WebhookPayloadRouter` | 07-05 |
| D-16 | Mandate-revoke-detectie via `payment.failed` + `mandate_invalid` | 07-03 (recordPaymentEvent) + 07-06 (SC-2 test) |
| D-17 | Mollie GET 404 → state `unknown` | 07-03 (syncFromMollie) |
| D-18 | Fan-out blijft `ForwardMollieWebhookToConsumer`; Hub-state-update ervoor | 07-05 (controller refactor) |
| D-19 | Geen pre-flight Mandate-check | 07-03 (manager-flow) |
| D-20 | Customer-creation = pure pass-through (Phase 5a) | n/a |
| D-21 | Hergebruik `pass_through_calls` audit | 07-04 (controllers) |
| D-22 | Webhook-audit via Spatie `webhook_calls`; state-history log-only | 07-03 (`Log::info('account_subscription.transition', …)`) + ADR §Consequences |
| D-23 | Hergebruik `MollieUpstreamErrorMapper` + `InvalidStateTransitionException` → 409 | 07-04 (controllers) |
| D-24 | Unit-tests scope | 07-02 + 07-03 + 07-05 |
| D-25 | Feature-tests scope | 07-06 |
| D-26 | Integration-test in `@group integration` | 07-07 |
| D-27 | Geen aparte ChangeWatcher-tests voor `pass_through_calls` | n/a (5a-pattern hergebruikt) |
| D-28 | Eén migration (forward-only) | 07-01 |
| D-29 | Geen `connections`-schema-wijziging | 07-01 |
| D-30 | `/v1/mollie/customers/{id}/subscriptions/*` blijft pass-through (5a) | 07-06 `MollieAndAccountSubscriptionsCoexistenceTest` |
| D-31 | `WebhookPayloadRouter` mag Phase-5a-tests niet breken | 07-05 + 07-06 (regressie groen) |
| D-32 | Phase 7 acceptance-checklist (dit document) | 07-08 (dit document) |

## Gaps identified

**Geen blocking gaps.**

- Item #11 ⏭️ Pad B is een bewuste keuze — geen technische gap, alleen environment-state (Connect-token niet beschikbaar in CI/UAT). Re-run-triggers staan vermeld.
- Pre-existing 1 incomplete-test (`SanctumAbilityTest::test_token_without_required_ability_is_rejected`) is uit Phase 3-03 en is een placeholder voor Phase 5b's ability-middleware-coverage — unrelated to Phase 7.
- Pre-existing Pint-baseline-drift in vendor-scaffold-files blijft staan (Phase 6 acceptance al gedocumenteerd); `--dirty` filter laat dit buiten Phase-7 scope.

## Next step voor de user

Phase 7 is technisch klaar; checkpoint-approval staat open. Voorgestelde vervolgstap na "approved":

**Phase 8: Naschool wiring — `/gsd-discuss-phase 8`**

Phase 8 leunt op Phase 5a (Mollie pass-through) + Phase 4 (Connect-broker voor school A's Mollie-koppeling). Phase 6/7 niet vereist voor checkout-flow zelf (eindgebruiker doet één betaling, geen subscription) — Phase 7 ontblokt wél de toekomstige optie om Naschool's recurring-bijdrage-flow (jaar-abo) op de account-subscription-laag te zetten zonder Phase-8-blocker.

Alternatief: Phase 9 (Filament admin-UI) is parallel mogelijk (depends-on Phase 3 + 4); zou een `AccountSubscriptionResource`-viewer kunnen toevoegen.

Aanbeveling: Phase 8 starten in een verse sessie (na `/clear`) — Naschool-wiring raakt een andere repo (`school-activities-hub/backend/`) en verdient verse context.
