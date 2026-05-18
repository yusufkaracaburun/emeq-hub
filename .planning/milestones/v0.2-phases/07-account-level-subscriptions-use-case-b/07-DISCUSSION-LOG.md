# Phase 7: Account-level subscriptions (use-case B) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-15
**Phase:** 07-account-level-subscriptions-use-case-b
**Mode:** --auto (user directive: "work without stopping for clarifying questions")
**Areas auto-resolved:**
1. End-user identity model
2. AccountSubscription schema + state-machine
3. Plan-storage strategie
4. API shape (Hub-wrap routes vs pure pass-through)
5. Mandate flow + Customer bootstrap
6. Webhook routing (Hub-state update + fan-out)
7. Audit-logging
8. Error-mapping
9. Testing-strategie
10. Database schema + coëxistentie met Phase 5a

---

## 1. End-user identity model

| Option | Description | Selected |
|--------|-------------|----------|
| (a) `EndUser`-tabel in Hub: `(account_id, external_id, mollie_customer_id)` | Hub becomes source-of-truth voor eindgebruiker; rapportage cross-Account mogelijk | |
| (b) Geen Hub-tabel; Mollie-`customer_id` is opaque reference | Consumer onderhoudt eigen ouder/lid-mapping; Hub thin | ✓ |
| (c) Lightweight `AccountSubscriptionParty` lookup-tabel | Compromis tussen (a) en (b) | |

**Auto-rationale:** Memory `feedback_pass_through_sdk_pattern.md` + Phase 5a-invariant (Hub thin, Consumer beheert externe identiteit). Optie (a) zou Naschool's bestaande ouder-tabel dupliceren.

---

## 2. AccountSubscription schema + state-machine

| Option | Description | Selected |
|--------|-------------|----------|
| (a) Volledige spatie/laravel-model-states library | Industry-standard, type-safe transitions, extra dependency | |
| (b) Plain PHP enum + `StateTransitions`-helper class | Minimal-deps, match Phase 6's stance, ~50 LOC | ✓ |
| (c) String-status + database-CHECK constraint | Simpelste vorm, minst type-safe | |

**Auto-rationale:** Phase 6 koos plain-PHP `PlanResolver` boven plugin (D-06); consistente codestijl. State-set (`pending/active/paused/canceled/completed/unknown`) is klein genoeg om handmatig te valideren.

---

## 3. Plan-storage strategie

| Option | Description | Selected |
|--------|-------------|----------|
| (a) Config-driven `config/billing-plans.php` (Phase 6 pattern) | Hergebruik PlanResolver; single source | |
| (b) Per-Account DB-tabel `account_subscription_plans` | Multi-tenant plan-catalog | |
| (c) Geen plan-tabel; inline `amount`/`interval`/`description` per subscription | Match Mollie's eigen contract (Mollie heeft ook geen plan-id) | ✓ |

**Auto-rationale:** Use-case B is per definitie per-tenant-variabel (school A €5/maand, school B €7.50/kwartaal). Config (a) faalt multi-tenant. DB (b) is over-engineering zonder bewijs van plan-hergebruik. Mollie's API ondersteunt inline values native.

---

## 4. API shape — Hub-wrap routes vs pure pass-through

| Option | Description | Selected |
|--------|-------------|----------|
| (a) Pure pass-through wrapping Phase 5a's `/v1/mollie/customers/{id}/subscriptions` | Geen nieuwe routes; Hub-state in webhook-flow alleen | |
| (b) Nieuwe `/v1/account-subscriptions/*`-routes die Mollie-call + Hub-state in één operatie afhandelen | Higher-level API; explicieter contract met Consumer | ✓ |

**Auto-rationale:** Hub-state-management (SC-2: paused na mandate-revoke; SC-4: edge cases) vergt server-side orchestratie. Pure pass-through (5a) blijft beschikbaar voor Consumers die geen state-laag willen — beide paden coëxisteren (D-30).

---

## 5. Mandate flow + Customer bootstrap

| Option | Description | Selected |
|--------|-------------|----------|
| (a) Phase 7 wrapt customer-create + first-payment + subscription-create als één meta-operatie | "Subscribe new customer" in één call | |
| (b) Customer + first-payment = pass-through (5a); Phase 7 wrapt alleen recurring-subscription-create | Smalle scope, Consumer beheert bootstrap | ✓ |
| (c) Phase 7 valideert pre-flight dat een Mandate bestaat | Extra Mollie GET per create-call | |

**Auto-rationale:** Scope-minimalisme + Mollie valideert zelf bij subscription-create (geen mandate → 422 met details). Pre-flight (c) = quota-burn voor info die Mollie binnen 50ms returnt. Meta-operatie (a) kan later als productie-friction toont dat Consumers het willen.

---

## 6. Webhook routing — Hub-state update + fan-out

| Option | Description | Selected |
|--------|-------------|----------|
| (a) Extend `MollieWebhookController` (5a) met resource-type-routing + Hub-state-handler vóór fan-out | Eén ingress-point, geen dubbele HMAC-verify | ✓ |
| (b) Spatie `WebhookCall::created`-listener doet Hub-state-update async | Decoupled; race conditions met fan-out mogelijk | |
| (c) Aparte `/webhooks/mollie-subscriptions/*`-endpoint | Verdubbelt routes; Mollie kent maar 1 webhook-URL per Connect-Org | |

**Auto-rationale:** Mollie stuurt naar één URL (Connect-platform-niveau). (c) is technisch niet haalbaar. (b) introduceert eventual-consistency tussen Hub-state en fan-out (Consumer ontvangt event vóór Hub-state is bijgewerkt → callback-handler ziet stale data).

---

## 6b. Mandate-revoke-detectie (sub-decision binnen webhook-routing)

| Option | Description | Selected |
|--------|-------------|----------|
| (a) Mollie's `mdt.*`-webhook-event detecteren | Direct, schoon — maar Mollie stuurt geen mandate-events vandaag | |
| (b) `payment.failed` met `details.failureReason='mandate_invalid'` herkennen | Indirect maar werkt op huidige Mollie-API | ✓ |
| (c) Periodiek Mandates API pollen | Eventually-consistent, extra Mollie-calls | |

**Auto-rationale:** Mollie's huidige API levert mandate-state-changes alleen via failed Payment-webhooks. (a) is gereserveerd voor v0.3+ (placeholder in `WebhookPayloadRouter`). (c) is buiten scope (zelfde reasoning als 5c's OData-safety-net deferral).

---

## 7. Audit-logging

| Option | Description | Selected |
|--------|-------------|----------|
| (a) Hergebruik `pass_through_calls` met `provider='mollie'` voor de nieuwe routes | Geen schema-change | ✓ |
| (b) Aparte `account_subscription_events`-tabel | State-transition-history als eerste-klas data | |
| (c) Alleen Laravel-log, geen DB-rij | Minimal | |

**Auto-rationale:** Phase 5a's `pass_through_calls` is provider-agnostisch en zit al goed (D-21). State-transition-history (b) is een Phase 9 admin-feature-wens, geen v0.2-MVP-eis (D-22 — wel log via Laravel `info`-level). (c) verliest forensics op de write-endpoints.

---

## 8. Error-mapping

| Option | Description | Selected |
|--------|-------------|----------|
| (a) Hergebruik `MollieUpstreamErrorMapper` (Phase 5a D-13) | Consistent error-envelope | ✓ |
| (b) Eigen mapper met Phase-7-specifieke codes | Domain-specifieke errors | |

**Auto-rationale:** 5a-mapper dekt 401/422/404/429/5xx/timeout. Phase 7's eigen errors (`InvalidStateTransitionException`) zijn Hub-side niet Mollie-side — die mappen we apart naar 409 Conflict (D-23).

---

## 9. Testing-strategie

| Option | Description | Selected |
|--------|-------------|----------|
| (a) Alleen feature-tests met `MollieApiClient::fake()` | Snel, geen vendor-dependency | |
| (b) Unit-tests (manager + state-machine) + feature-tests + integration-tests `@group integration` | Drielaags coverage (Phase 6 pattern) | ✓ |

**Auto-rationale:** Phase 6's SC-4 vendor-coverage werd via `composer test:integration` opgelost (D-12). Phase 7 erft dat pattern: standaard `php artisan test` blijft snel + groen, vendor-roundtrip alleen in opt-in suite.

---

## 10. Database schema + Phase 5a coëxistentie

| Option | Description | Selected |
|--------|-------------|----------|
| (a) Eén nieuwe migration `create_account_subscriptions_table` + geen wijziging aan bestaande tabellen | Smal, atomisch | ✓ |
| (b) Voeg `subscription_id` aan `connections` toe + denormaliseer | Vermijdt aparte tabel maar breekt 1:N-relatie | |
| (c) Reuse Cashier's `subscriptions`-tabel (Phase 6) | Vermijdt nieuwe tabel maar mixt single-tenant en multi-tenant data | |

**Auto-rationale:** Cashier's tabel-shape (`user_id`/`mollie_subscription_id`/`mollie_customer_id` — single-tenant op Emeq's eigen Mollie-key) past niet bij multi-tenant Connect-flow (verschillende Mollie-accounts per row). (b) breekt het 1-Connection-N-Subscriptions-pattern.

---

## Claude's Discretion

- Exacte controller-shape (resource-controller vs single-action per HTTP-verb).
- Of `AccountSubscriptionManager` getest wordt met SDK's `MollieApiClient::fake()` of een custom test-double.
- Aantal en granulariteit van plans (verwacht 6-8 plans bij `/gsd-plan-phase 7`).
- Of `WebhookPayloadRouter` als aparte class of inline in controller — voorkeur class voor testbaarheid.
- Regex-strictheid voor `amount.value`-validatie.

## Deferred Ideas

Volledige lijst in CONTEXT.md `<deferred>`-sectie. Highlights:

- Per-Account plan-templates-tabel (productie-data-driven).
- `EndUser`-tabel in Hub (Consumer-reporting-driven).
- Connection-revoke → auto-pause batch-job (Phase 9 admin doet handmatig in v0.2).
- State-transition history audit-tabel.
- `mdt_*`-webhook-handler (Mollie levert vandaag niet).
- Mandate-pre-flight check (productie-friction-driven).
- Plan changes / upgrades / downgrades (Mollie ondersteunt het wel — v0.3 mogelijk).
