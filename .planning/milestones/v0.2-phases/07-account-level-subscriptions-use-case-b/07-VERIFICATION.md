---
phase: 07-account-level-subscriptions-use-case-b
verified: 2026-05-18T19:04:00Z
status: passed
score: 4/4 must-haves verified (SC-4 vendor-deferred via Pad-B)
overrides_applied: 0
previous_status: missing
triggered_by: "Phase 15 verification-debt backfill (VERIF-03)"
---

# Phase 7: Account-level subscriptions (use-case B) — Verification Report

**Phase Goal (verbatim v0.2-ROADMAP.md regel 179):**
> Accounts factureren hun eindgebruikers via eigen Mollie-account via Connect, met multi-tenant subscription-laag bovenop Mollie Subscriptions + Mandates.

**Verified:** 2026-05-18T19:04:00Z
**Status:** passed
**Triggered by:** Phase 15 verification-debt backfill (VERIF-03)
**Re-verification:** No — eerste verifier-pass voor Phase 7 (verification-debt backfill conform Phase 15 plan 15-03).

---

## Goal Achievement

**Primair startbewijs-anker:** `.planning/milestones/v0.2-phases/07-account-level-subscriptions-use-case-b/07-08-ACCEPTANCE.md` — D-32 acceptance-checklist **11/11 ✅** met `status: ACCEPTED` op `accepted_at: 2026-05-15` via de Plan 07-08 human-verify-checkpoint. Item #11 documenteert expliciet de **Pad-B-keuze** voor SC-4 vendor-coverage (Connect-token niet beschikbaar in `.env` → integration-test skipt graceful + re-run-triggers vastgelegd). De 8 plan-SUMMARYs (`07-01-SUMMARY.md` t/m `07-08-SUMMARY.md`) en de in-codebase-greps in deze verificatie zijn detail-evidence ter ondersteuning van die acceptance — niet als zelfstandig startbewijs.

### Success Criteria (v0.2-ROADMAP.md regels 185-189)

| # | Success Criterion | Status | Evidence |
|---|-------------------|--------|----------|
| SC-1 | Account kan `AccountSubscription` aanmaken → Mollie Subscription op eigen Mollie-account via juiste Connection | ✅ VERIFIED | Model `app/Models/AccountSubscription.php` + migration `database/migrations/2026_05_18_000001_create_account_subscriptions_table.php` + manager `app/Billing/Account/AccountSubscriptionManager.php` (306 LOC, niet-stub) + route `POST /v1/account-subscriptions` → `AccountSubscriptionController::store`. Feature-test `CreateAccountSubscriptionTest::test_happy_path_creates_account_subscription_and_returns_201` groen in suite-run (zie probe). Manager roept `Mollie::client()` per call met `HubMollieCredentialResolver` voor per-Connection access_token (D-EXEC-03 ACCEPTANCE regel 70). |
| SC-2 | Mandate-revoke webhook → state `paused` zonder cancel | ✅ VERIFIED | `app/Webhooks/Mollie/PaymentWebhookHandler.php` regel 77 dispatcht `manager->recordPaymentEvent`. `AccountSubscriptionManager::recordPaymentEvent()` regel 217: `if ($failureReason === 'mandate_invalid' && $sub->status === SubscriptionStatus::Active)` → transitie naar `Paused` met `reason = payment_failed_mandate_invalid` zonder Mollie-cancel-call. Test `AccountSubscriptionWebhookFlowTest::test_payment_failed_with_mandate_invalid_transitions_subscription_to_paused` groen in probe-suite. |
| SC-3 | Twee Accounts met eigen subscriptions op zelfde eindgebruiker-email = gescheiden state | ✅ VERIFIED | `ListAccountSubscriptionsTest::test_list_with_other_consumer_account_external_id_returns_empty_list` (lege list i.p.v. 404 per D-EXEC-05 — info-disclosure-pattern); `CancelAccountSubscriptionTest::test_cross_consumer_destroy_returns_404` + `PauseResumeAccountSubscriptionTest::test_cross_consumer_pause_returns_404`. Multi-tenant scoping via `Consumer → Account → Connection`-chain in `HandlesAccountSubscriptionRequests::findOwnedSubscription` (D-EXEC-07). 35 Phase-7 feature-tests groen. |
| SC-4 | ⏭️ Vendor-coverage via unit + feature stubs + skip-graceful integration-test | ⏭️ PARTIAL — Pad-B (gedocumenteerd-deferred) | Integration-test `tests/Integration/AccountSubscriptions/AccountSubscriptionMollieRoundtripTest.php` met `AccountSubscriptionIntegrationTestCase::setUp()` regel 42-46: `env('MOLLIE_CONNECT_TEST_ACCESS_TOKEN')` empty → `markTestSkipped('… access_-prefix, niet de access_xxx-placeholder …')`. Unit-coverage geleverd via `tests/Unit/Billing/Account/AccountSubscriptionManagerCreateTest.php` (Mollie SDK Mockery) + feature-stubs in `CreateAccountSubscriptionTest`. **Pad-B-rationale ACCEPTANCE item #11:** "Geen Connect-token beschikbaar in CI/UAT — integration-test gedrukt naar manueel zodra token beschikbaar is. SC-1 vendor-coverage uitgesteld naar v0.2.1." Geen failure, geen blocker — bewuste environment-state-keuze gedocumenteerd in ADR `.docs/decisions/account-subscriptions.md` §Integration-test-keuze (lokaal/gitignored artefact). Zie **Deferred / Open Items** sectie voor re-run-triggers. |

**Score:** **4/4** must-haves verified (SC-1..SC-3 = VERIFIED, SC-4 = PARTIAL met Pad-B vendor-coverage-deferral conform v0.2-ROADMAP `⏭️`-marker). Overall `status: passed` — geen blocking gaps.

---

## Evidence Summary

### Required Artifacts (D-32 #1..#10 + Phase-7 plan-scope)

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Models/AccountSubscription.php` | Eloquent-model met 19 schema-velden (D-03) + relaties + casts | ✅ VERIFIED | 66 LOC, substantive (niet placeholder) |
| `database/migrations/2026_05_18_000001_create_account_subscriptions_table.php` | Forward-only migration met partial unique index `(connection_id, mollie_subscription_id) WHERE NOT NULL` | ✅ VERIFIED | 49 LOC; ACCEPTANCE D-32 #1 bevestigt `migrate:fresh --seed` exit 0 op 2026-05-15T20:21Z |
| `app/Billing/Account/SubscriptionStatus.php` | Enum met 6 states: `pending`/`active`/`paused`/`canceled`/`completed`/`unknown` (D-04) | ✅ VERIFIED | 6/6 cases aanwezig (regel 9-14) — exact zoals D-04 specificeert |
| `app/Billing/Account/StateTransitions.php` | Transition-tabel + `assertTransition()` + `isLegal()` introspectie (D-EXEC-02) | ✅ VERIFIED | Aanwezig naast `SubscriptionStatus`; 38 transition-matrix-tests groen in `StateTransitionsTest` |
| `app/Billing/Account/AccountSubscriptionManager.php` | Single-entry service voor create + sync + recordPaymentEvent + pause/resume/destroy (D-13) | ✅ VERIFIED | 306 LOC, substantive. SC-2-path (`mandate_invalid` → Paused) op regel 217-220. `final` verwijderd voor Mockery (D-EXEC-08) |
| `app/Webhooks/Mollie/WebhookPayloadRouter.php` | Id-prefix-dispatch: `sub_*` → SubscriptionHandler, `tr_*` → PaymentHandler, `mdt_*` → placeholder, default → 5a-pad (D-15) | ✅ VERIFIED | 40 LOC; tested in `WebhookPayloadRouterTest` (6 tests) |
| `app/Webhooks/Mollie/SubscriptionWebhookHandler.php` | Skip-pad onbekende sub_* + state-sync delegatie naar manager | ✅ VERIFIED | 53 LOC |
| `app/Webhooks/Mollie/PaymentWebhookHandler.php` | Roept `manager->recordPaymentEvent` aan met Mollie-payment-array (D-16, SC-2) | ✅ VERIFIED | 81 LOC; regel 77 `$this->manager->recordPaymentEvent($sub, $paymentArray)` |
| `app/Webhooks/Mollie/WebhookHandlerResult.php` | Value-object met `shouldAudit()` + `shouldFanOut()` (D-EXEC-09) | ✅ VERIFIED | Aanwezig in `app/Webhooks/Mollie/` |
| `app/Http/Controllers/Api/V1/AccountSubscriptions/AccountSubscriptionController.php` | Store/show/index/destroy actions (D-08) | ✅ VERIFIED | Inclusief `Concerns/HandlesAccountSubscriptionRequests` trait (D-EXEC-07) |
| `app/Http/Controllers/Api/V1/AccountSubscriptions/PauseController.php` + `ResumeController.php` | Single-action controllers voor pause/resume | ✅ VERIFIED | Beide files aanwezig |
| `app/Http/Requests/Api/V1/AccountSubscriptions/CreateAccountSubscriptionRequest.php` | Form-request met `account_external_id` veld (D-11) | ✅ VERIFIED | Aanwezig |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `POST /v1/account-subscriptions` | `AccountSubscriptionController::store` | `routes/api.php` regel 130 | ✅ WIRED | `api.account-subscriptions.store` zichtbaar in `route:list` met `auth:sanctum` + `ability:mollie:write` middleware |
| `AccountSubscriptionController::store` | `AccountSubscriptionManager::create` | Service-laag-call | ✅ WIRED | Manager-create geverifieerd in `AccountSubscriptionManagerCreateTest` (3 unit-tests) |
| `MollieWebhookController` | `WebhookPayloadRouter::route` | D-15 refactor | ✅ WIRED | `WebhookPayloadRouter` aanwezig + 6 router-tests groen; D-31 Phase-5a-regressie-vrij bevestigd in ACCEPTANCE Phase 5a-tabel (19 + 49 tests pre/post identiek) |
| `WebhookPayloadRouter` | `SubscriptionWebhookHandler` (sub_*) + `PaymentWebhookHandler` (tr_*) | Id-prefix-dispatch | ✅ WIRED | Beide handler-files non-stub (53 + 81 LOC); placeholder voor `mdt_*` (D-15) bewust deferred |
| `PaymentWebhookHandler` | `AccountSubscriptionManager::recordPaymentEvent` | SC-2 mandate-revoke-pad | ✅ WIRED | Regel 77 directe call; SC-2 test `test_payment_failed_with_mandate_invalid_transitions_subscription_to_paused` groen |
| `AccountSubscription` model | `accounts` (cascade) + `connections` (restrict) FKs | Migration D-03 | ✅ WIRED | ACCEPTANCE D-32 #1 bevestigt cascade-delete-test via factory-state; `tests/Feature/Models/AccountSubscriptionTest` (7 tests / model-relatie-coverage) |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| 6 `/v1/account-subscriptions/*` routes geregistreerd | `php artisan route:list --except-vendor \| grep account-subscriptions` | 6 v1-routes (POST `/`, GET `/`, GET `/{id}`, DELETE `/{id}`, POST `/{id}/pause`, POST `/{id}/resume`) + 2 Filament-admin-routes | ✅ PASS |
| Phase-7-scope test-suite groen | `php artisan test --compact tests/Feature/Api/V1/AccountSubscriptions tests/Unit/Billing/Account tests/Unit/Webhooks/Mollie` | `{"tool":"phpunit","result":"passed","tests":87,"passed":87,"assertions":296,"duration_ms":1869}` | ✅ PASS |
| 6-state enum compleet | `grep -n "case .*= '" app/Billing/Account/SubscriptionStatus.php` | 6/6 cases zichtbaar (Pending/Active/Paused/Canceled/Completed/Unknown) | ✅ PASS |
| Integration-test skipt graceful zonder Connect-token | `grep markTestSkipped tests/Integration/AccountSubscriptions/AccountSubscriptionIntegrationTestCase.php` | Regel 44 `$this->markTestSkipped(...)` als `env('MOLLIE_CONNECT_TEST_ACCESS_TOKEN')` empty/placeholder | ✅ PASS |

### Requirements Coverage

| Requirement | Description | Status | Evidence |
|-------------|-------------|--------|----------|
| **SUB-02** | Account-level subscriptions use-case B (Accounts→eindgebruikers via Connect) | ✅ SATISFIED | v0.2-REQUIREMENTS.md regel 50-51: `[x] SUB-02 … Validated in Phase 7 (2026-05-15)`. Traceability-tabel regel 79: `SUB-02 \| Phase 7 \| Validated \| AccountSubscription multi-tenant, 337 tests`. SC-1+SC-2+SC-3 codebase-bewezen; SC-4 vendor-deferred via Pad-B met re-run-triggers. |

### Anti-Patterns Found

| File | Pattern | Severity | Impact |
|------|---------|----------|--------|
| — | Geen blocker-patterns aangetroffen in Phase-7-scope-files | — | Manager + handlers + controllers zijn substantive (geen `return null`, geen `return Response::json([])` stubs). `mdt_*` placeholder-pad in `WebhookPayloadRouter` is bewust gedefereerd per D-15 voor v0.3+ (geen TBD/FIXME). |

---

## Deferred / Open Items

| # | Item | Type | Addressed In / Trigger | Evidence |
|---|------|------|------------------------|----------|
| 1 | **SC-4 vendor-coverage — Pad B** | Bewust-deferred (geen failure) | v0.2.1 of Phase-8-Naschool-go-live, afhankelijk van Mollie Connect-test-token-beschikbaarheid | ACCEPTANCE item #11; ADR `.docs/decisions/account-subscriptions.md` §Integration-test-keuze. Integration-test bestaat (`AccountSubscriptionMollieRoundtripTest`) en draait via `composer test:integration` zodra `MOLLIE_CONNECT_TEST_ACCESS_TOKEN` aanwezig is. Zonder token: graceful skip, 0 failures. |
| 2 | **Connection-revoke → auto-pause batch-job** (D-29) | Risk deferred uit CONTEXT §deferred | Phase 9 admin / v0.3 | ACCEPTANCE §Risks deferred. Handmatige reconciliation via Filament admin volstaat in v0.2. |
| 3 | **State-transition history DB-tabel** (D-22) | Log-only in v0.2 | v0.3 als Phase 9 admin replay/timeline-views nodig heeft | ACCEPTANCE §Risks deferred + ADR §Consequences |
| 4 | **`account_subscription_plans`-tabel** (D-05/D-07) | Ad-hoc-subscriptions in v0.2 | Toevoegen wanneer Consumers structureel 3-5 plans hergebruiken | ACCEPTANCE §Risks deferred |
| 5 | **`mdt_*` mandate-webhook-events** | Placeholder-handler in router | v0.3+ wanneer Mollie mandate-events stuurt | D-15 prefix-tabel; WebhookPayloadRouter laat ruimte voor handler-binding |
| 6 | **Scramble `/docs/api` browser-render-check** (D-32 #7) | Human-verify (CLI-evidence reeds geleverd) | Reeds approved via human-verify-checkpoint 2026-05-15 | ACCEPTANCE item #7 |

### SC-4 Pad-B re-run-triggers (verbatim ACCEPTANCE item #11)

1. **Connect-test-token verkregen** van Mollie Partner-portal (`access_*`-prefix).
2. **v0.2.1-release-window opens** — als onderdeel van polish-release.
3. **Handmatige UAT bij Naschool-go-live** (Phase 8 dependency — eerste live Connect-Customer + Subscription via school A's eigen Mollie-account).

Re-run-procedure: `MOLLIE_CONNECT_TEST_ACCESS_TOKEN=access_<live-token> composer test:integration`. Default `php artisan test`-suite blijft groen ongeacht token-aanwezigheid (`phpunit.xml` excluded `<group>integration</group>`, Phase 6 D-12 pattern hergebruikt — bevestigd ACCEPTANCE D-32 #6).

---

## Conclusion

Phase 7 levert het v0.2-ROADMAP-doel: Accounts factureren hun eindgebruikers via eigen Mollie-account via Connect met multi-tenant subscription-laag bovenop Mollie Subscriptions + Mandates. SC-1..SC-3 zijn codebase-bewezen via 87 Phase-7-scope tests / 296 assertions (Feature + Unit + Webhooks) + 6 geregistreerde `/v1/account-subscriptions/*`-routes + multi-tenant scoping via `Consumer → Account → Connection`-chain + SC-2 mandate-revoke-pad expliciet in `PaymentWebhookHandler` → `AccountSubscriptionManager::recordPaymentEvent`. SC-4 is een **bewuste Pad-B-deferral** (vendor-coverage via skip-graceful integration-test, conform v0.2-ROADMAP `⏭️`-marker en ACCEPTANCE item #11), niet een failure — geen blocking gap.

Geen overrides nodig. Geen anti-patterns in Phase-7-scope. ACCEPTANCE-checklist 11/11 ✅ blijft het primaire startbewijs; deze verifier-pass cross-checkt die acceptance tegen de codebase en bevestigt consistentie.

---

_Verified: 2026-05-18T19:04:00Z_
_Verifier: Claude (gsd-verifier — Phase 15 verification-debt backfill VERIF-03)_
