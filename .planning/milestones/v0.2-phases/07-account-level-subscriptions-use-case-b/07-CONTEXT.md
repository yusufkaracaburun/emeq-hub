# Phase 7: Account-level subscriptions (use-case B) — Context

**Gathered:** 2026-05-15
**Status:** Ready for planning
**Discussion mode:** --auto (user-directive "no clarifying questions" → recommended option per gray area, single pass)
**Requirement:** SUB-02
**Depends on:** Phase 5a (Mollie SDK Subscriptions + Mandates resources, `/webhooks/mollie/{connection_id}` ingress + fan-out, `pass_through_calls` audit), Phase 4 (`MollieConnectionContext` + `HubMollieCredentialResolver` lazy refresh), Phase 3 (Hub-skeleton — Account/Connection scoping + Sanctum-PAT).
**Parallel-met:** Phase 6 ✅ (ACCEPTED 2026-05-15 — geen blocker).

<domain>
## Phase Boundary

**Wat deze fase levert:** Accounts (eindklanten van Consumers — bv. school A, vereniging C) kunnen via Hub-API recurring betalingen opzetten **voor hun eigen eindgebruikers** (ouders/leden), waarbij de Mollie-Subscription wordt aangemaakt op het Mollie-account van die Account zelf (via de Connect-`access_token` uit de Connection). De Hub onderhoudt een eigen multi-tenant `AccountSubscription`-state-machine bovenop Mollie's Subscriptions + Mandates API, omdat Cashier-Mollie (Phase 6) single-tenant is en alleen Emeq's eigen Mollie-key kent.

**Eindstand:** Naschool (Consumer) kan namens "school A" (Account) een recurring SEPA-bijdrage opzetten op ouder X (Mollie Customer op school A's eigen Mollie test-account). Mollie's recurring `Payment`-webhook landt op `/webhooks/mollie/{connection_id}`, Hub vindt de matching `AccountSubscription` via `mollie_subscription_id`, updatet de state, en fan-out gebeurt **na** de state-update naar Naschool's callback. Bij `payment.failed` met `mandate_invalid` reason transitionneert de state naar `paused` zonder Mollie te cancellen.

**Strikt buiten scope:**
- Cashier-Mollie-style use-case A (= Phase 6, shipped).
- Naschool-specifieke wiring (= Phase 8 — Phase 7 levert alleen het Hub-platform).
- Filament admin-UI voor AccountSubscriptions (= Phase 9 `AccountSubscriptionResource`).
- Customer-creation + first-payment-mandate-bootstrap flow — blijft pure pass-through via `/v1/mollie/customers` + `/v1/mollie/payments` (Phase 5a). Phase 7 wrapt alleen de recurring-subscription-laag.
- Plan-templates / plan-catalog per Account (deferred — v0.2 levert ad-hoc subscriptions met inline `amount`/`interval`/`description`).
- Multi-currency: alleen EUR.
- BTW-engine of invoicing — Mollie verzorgt payment-statements; Hub doet geen factuur-rendering.

</domain>

<decisions>
## Implementation Decisions

### End-user identity model

- **D-01: Geen `EndUser`-tabel in Hub.** De Mollie-`customerId` is de canonical eindgebruiker-identifier vanuit Hub-perspectief. Consumer (Naschool) onderhoudt de mapping `ouder X ↔ Mollie customer_id` zelf — Hub blijft thin, conform feedback-memory `feedback_pass_through_sdk_pattern.md`. Reden: een `EndUser`-tabel zou Hub-domain-modellen toevoegen voor data die de Consumer al heeft; we hergebruiken het pass-through-pattern van 5a waar de Consumer Mollie-id's beheert.
- **D-02: `AccountSubscription` verwijst naar Mollie-id's, niet naar Hub-side end-user-rows.** Velden: `mollie_customer_id`, `mollie_subscription_id`, `mollie_mandate_id`. Geen FK naar een Hub-eindgebruiker-tabel.

### AccountSubscription model + schema

- **D-03: Nieuwe tabel `account_subscriptions`** met deze kolommen:
  ```
  id (bigint PK)
  account_id (FK accounts.id, cascade on delete)
  connection_id (FK connections.id, restrict on delete — Connection-revoke pause't subscription, niet verwijderen)
  mollie_customer_id (string, indexed)
  mollie_subscription_id (string, unique-per-connection, nullable tot Mollie-create slaagt)
  mollie_mandate_id (string, nullable — Mollie kiest 'any valid mandate' als niet meegegeven)
  status (string, indexed)            — zie D-04 state-machine
  amount_currency (char(3), default 'EUR')
  amount_value (string)               — Mollie's eigen decimal-string-shape (bv. "10.00")
  interval (string)                   — Mollie-format: "1 month" / "2 weeks" / "5 days"
  description (string)                — Mollie-required, uniek per (customer, active sub)
  times (integer, nullable)           — totaal aantal payments, nullable = unlimited
  start_date (date, nullable)
  starts_at (timestamp, nullable)     — eerste recurring payment-event verwerkt
  paused_at (timestamp, nullable)     — D-04 transition naar `paused`
  canceled_at (timestamp, nullable)
  completed_at (timestamp, nullable)
  metadata (jsonb, nullable)
  last_payment_status (string, nullable)
  last_webhook_event_at (timestamp, nullable)
  created_at, updated_at
  ```
  Composite unique index: `(connection_id, mollie_subscription_id)` waar `mollie_subscription_id IS NOT NULL` (partial). Reden: per Mollie-account is een subscription-id uniek; per Hub-Connection idem.
- **D-04: State-machine** (plain PHP enum-class `App\Billing\Account\SubscriptionStatus`, géén spatie/laravel-model-states — match Phase 6's minimal-dependencies stance):
  | State | Betekenis | Trigger naar volgende state |
  |---|---|---|
  | `pending` | Hub-row aangemaakt, Mollie-call nog niet succesvol | → `active` na succesvolle Mollie-create |
  | `active` | Mollie-subscription bestaat + draait | → `paused` (mandate_invalid), `canceled` (consumer cancel), `completed` (`times` bereikt) |
  | `paused` | Mandaat ongeldig of Mollie-retry-budget op | → `active` (nieuwe mandate + Hub-side `POST .../resume`), `canceled` (consumer cancel) |
  | `canceled` | Consumer of Hub-admin heeft gecanceld | terminal |
  | `completed` | Mollie heeft `times` payments geleverd | terminal |
  | `unknown` | Mollie GET retourneert 404 op resync | terminal — handmatig opruimen via Phase 9 admin |

  Overgangen leven in een `App\Billing\Account\StateTransitions`-klasse met `assertTransition(from, to)`-helper die `InvalidStateTransitionException` gooit. Niet alle states zijn unidirectional — `paused → active` mag.

### Plan-storage strategie

- **D-05: Géén plan-tabel in Phase 7.** Iedere `AccountSubscription` heeft `amount` + `interval` + `description` inline opgeslagen (Mollie's eigen contract: een Mollie-Subscription is ad-hoc, geen plan-id-referentie). Consumer (Naschool) is verantwoordelijk voor plan-rendering aan eindgebruiker; Hub forward't de ingegeven values verbatim naar Mollie + persist't ze in `account_subscriptions`.
- **D-06: Phase 6's `App\Billing\PlanResolver` blijft Cashier-only.** Geen herbruik voor use-case B. Reden: Cashier-plans zijn Emeq-internal (Naschool-license, Planny-license, 2-3 stuks), gedefinieerd in `config/billing-plans.php`; Account-level subscriptions zijn per definitie per-tenant-variabel (school A €5/maand, school B €7.50/kwartaal) — config-driven werkt niet multi-tenant.
- **D-07: Per-Account "plan-templates" → deferred.** Als productie-friction toont dat Consumers steeds dezelfde 3-5 plans hergebruiken, voegen we later een `account_subscription_plans`-tabel toe (per Account). Niet in v0.2.

### API shape — nieuwe `/v1/account-subscriptions/*` endpoints

- **D-08: Hub-laag wrap-routes, niet pure pass-through.** Phase 5a's `/v1/mollie/customers/{id}/subscriptions/*` blijft puur pass-through (Mollie-payload verbatim back, geen Hub-state). Phase 7 voegt **nieuwe** higher-level routes toe die Mollie-call + Hub-state in één operatie afhandelen:
  ```
  POST   /v1/account-subscriptions                              — Hub-create + Mollie-create + persist
  GET    /v1/account-subscriptions/{id}                         — Hub-state (+ optioneel lazy-resync via Mollie GET)
  GET    /v1/account-subscriptions?account_id={external_id}     — list per Account (Consumer scope)
  DELETE /v1/account-subscriptions/{id}                         — Mollie-cancel + Hub-state → canceled
  POST   /v1/account-subscriptions/{id}/pause                   — Hub-only: status → paused (geen Mollie-call; gebruik wanneer Consumer mandate-issue weet)
  POST   /v1/account-subscriptions/{id}/resume                  — Hub-only: status → active (assumeert geldige mandate)
  ```
- **D-09: `POST /v1/account-subscriptions` body-shape:**
  ```json
  {
    "account_external_id": "school-a-uuid",
    "mollie_customer_id": "cst_abc123",
    "mollie_mandate_id": "mdt_xyz456",         // optional — Mollie kiest 'any valid mandate' als ontbrekend
    "amount": {"currency": "EUR", "value": "10.00"},
    "interval": "1 month",
    "description": "Maandelijkse bijdrage Pieter Janssen 2026",
    "times": 12,                                // optional — null = unlimited
    "start_date": "2026-06-01",                 // optional
    "metadata": {"enrollment_id": "..."}        // optional, max ~1kB (Mollie-limit)
  }
  ```
  Form Request: `CreateAccountSubscriptionRequest` met validatie: amount.value `regex:/^\d+\.\d{2}$/`, currency `in:EUR`, interval `regex:/^\d+\s+(days|weeks|months)$/`, description `required|string|max:255`, mandate-id-prefix `mdt_`, customer-id-prefix `cst_`. **Hub-edge-validatie houdt Mollie-quota-burn laag.**
- **D-10: PAT-abilities — hergebruik `mollie:write` voor create/cancel/pause/resume + `mollie:read` voor GET/list.** Geen nieuwe abilities. Account-level subscriptions zijn semantisch Mollie-domain. Sluit aan op Phase 5a's `TokenAbilities`.
- **D-11: Account-resolutie via body-veld `account_external_id`** (niet via `X-Account-Id`-header zoals 5a). Reden: dit is een Hub-side resource-create (geen pass-through call); body-veld is RESTful + matchet Phase 5b's `POST /v1/accounts`-en-`POST /v1/connections`-pattern.
- **D-12: Middleware-keten:** `auth:sanctum` (PAT) → `ability:mollie:write` (create) of `ability:mollie:read,mollie:write,*` (read). **Geen** `resolve.mollie.account`-middleware — dat is voor pass-through-routes; Phase 7 resolved Account+Connection in de controller zelf (Form Request → service-laag).

### Service-laag

- **D-13: `App\Billing\Account\AccountSubscriptionManager` als single-entry service** met methods:
  ```php
  public function create(Account $account, Connection $connection, CreateAccountSubscriptionDto $dto): AccountSubscription;
  public function cancel(AccountSubscription $sub): void;
  public function pause(AccountSubscription $sub, string $reason): void;
  public function resume(AccountSubscription $sub): void;
  public function syncFromMollie(AccountSubscription $sub): void;   // GET subscription + state-machine update
  public function recordPaymentEvent(AccountSubscription $sub, array $paymentPayload): void;  // door webhook-handler
  ```
  Manager doet Mollie-calls via `Emeq\MollieApi\Facades\Mollie::client()` + `MollieConnectionContext::set($connection)`. State-transitions via `StateTransitions::assertTransition()`-helper.
- **D-14: Idempotency-Key forward op `create` via Phase 5a's `UuidV7IdempotencyKeyGenerator` (`config('mollie.idempotency.generator')`).** Consumer mag `Idempotency-Key`-header meesturen op `POST /v1/account-subscriptions`; Hub forward't naar Mollie's subscription-create. Reden: retry-storm = dubbele Mollie-subscription = financieel risico (zelfde reasoning als Phase 5a D-06).

### Webhook routing — Hub-state update + fan-out

- **D-15: Extend `MollieWebhookController` (Phase 5a) met resource-type-detectie + Hub-state update *vóór* fan-out.** Huidige controller (`app/Http/Controllers/Webhooks/MollieWebhookController.php`) detecteert geen resource-type — alleen Payment-anti-spoofing-fetch op `$payload['id']`. Phase 7 voegt id-prefix-routing toe:
  | Prefix | Resource | Phase-7-actie |
  |---|---|---|
  | `tr_` | Payment | Als `payment.subscriptionId` aanwezig: `AccountSubscriptionManager::recordPaymentEvent()`. Anders: bestaande Phase-5a-flow (anti-spoof + fan-out). |
  | `sub_` | Subscription | Direct Mollie GET (anti-spoof) + `AccountSubscriptionManager::syncFromMollie()`. Fan-out daarna. |
  | `mdt_` | Mandate | (toekomst — Mollie stuurt geen mandate-events vandaag; gereserveerd voor V0.3+) |
  | overig | Onbekend | Bestaande 5a-flow (Payment-fetch) — graceful no-op als geen match. |

  De controller refactored zich naar een dispatcher-pattern: `WebhookPayloadRouter::routeFor($payload['id'])` → handler-class. Bestaande Payment-pad blijft werken (5a-tests groen).

- **D-16: Mandate-revoke-detectie via `payment.failed` met `details.failureReason='mandate_invalid'`.** Mollie stuurt geen aparte mandate-revoke-webhook. `AccountSubscriptionManager::recordPaymentEvent()` checkt `payload.status` + `payload.details.failureReason`: bij `mandate_invalid` → state `active → paused` + `paused_at` zetten + `last_payment_status='failed_mandate_invalid'`. Geen Mollie-cancel-call (Mollie's eigen retry stopt automatisch; Consumer kan nieuwe mandate opzetten + `POST .../resume`).

- **D-17: Mollie GET retourneert 404 op resync → state `unknown`.** `syncFromMollie()` vangt `NotFoundException` en zet `status='unknown'`. Reden: Mollie kan customer aan zijn kant verwijderen (Connect-merchant action); Hub mag niet stilzwijgend cancel'en (data-loss-risico). Phase 9 admin krijgt een "force-cleanup unknown rows"-action.

- **D-18: Fan-out blijft via `ForwardMollieWebhookToConsumer` (Phase 5a) — Hub-state-update gebeurt ervoor, niet erna.** Volgorde in `MollieWebhookController`:
  1. Hard-fail guard (5a D-08 stap 1)
  2. Signature-verify (5a D-08 stap 1)
  3. Connection-lookup (5a D-08 stap 2)
  4. Resource-type-routing → Hub-state-update (Phase 7 D-15)
  5. Anti-spoofing-fetch + Spatie `webhook_calls` audit (5a D-08 stap 3-5) — *als state-update geslaagd is*
  6. Fan-out dispatch (5a D-08 stap 6)
  7. 202 Accepted

  Reden volgorde 4 vóór 5: voor `sub_*`-events doet de Phase-7-handler **al** een Mollie GET (`syncFromMollie`), die functioneel dezelfde anti-spoofing-check is. Dubbele GET vermijden door state-handler's resultaat door te geven aan audit-stap. Voor `tr_*`-events blijft 5a's anti-spoof-fetch staan; Phase 7 hangt z'n state-update aan diezelfde resource op (geen extra Mollie-call).

### Mandate-pre-flight + Customer-bootstrap

- **D-19: Phase 7 valideert NIET pre-flight dat een Mandate bestaat.** Consumer is verantwoordelijk: óf eerst een first-payment doen met `sequenceType=first` (via `/v1/mollie/payments` pass-through) waardoor Mollie zelf een Mandate creëert, óf een bestaande `mandate_id` meegeven aan `POST /v1/account-subscriptions`. Hub laat Mollie de mandate-validatie doen — als de subscription-create 422 retourneert door missende mandate, mapt `MollieUpstreamErrorMapper` (5a D-13) naar Hub-422 met de Mollie-error-message intact. Reden: pre-flight check = extra Mollie API call per create = quota-burn voor info die Mollie zelf binnen 50ms returnt.
- **D-20: Customer-creation = pure pass-through.** Consumer maakt Customer aan via `POST /v1/mollie/customers` (Phase 5a, al live); Phase 7 verwacht een bestaande `cst_*`-id in de create-body. Geen Hub-side Customer-tabel.

### Audit-logging

- **D-21: Hergebruik `pass_through_calls` met `provider='mollie'` voor de nieuwe `/v1/account-subscriptions/*`-endpoints.** Schema-pattern uit Phase 5a (D-05) — `path`-kolom = endpoint-template (`/v1/account-subscriptions`, `/v1/account-subscriptions/{id}/pause`, etc.) zonder query-string, `request_fingerprint` nullable bij empty body, `query_keys` voor lijst-call. Phase 7 vraagt **géén** kolom-toevoegingen aan `pass_through_calls`.
- **D-22: Webhook-audit blijft via Spatie's `webhook_calls`-tabel (Phase 5a flow).** Geen Phase-7-extensie. State-machine-transitions worden gelogd via Laravel-log (`info`-level, structured: `account_subscription.transition`, `from`, `to`, `reason`, `subscription_id`) — niet als DB-rij. Reden: state-history is een Phase 9 admin-feature-wens, niet v0.2 v.

### Error-mapping

- **D-23: Hergebruik `App\Support\Mollie\MollieUpstreamErrorMapper` (Phase 5a D-13).** Geen Phase-7-eigen mapper. State-transition-fouten (bv. `paused → completed` is illegal) worden via custom `InvalidStateTransitionException` → 409 Conflict met `error_code: invalid_state_transition`. Niet via de Mollie-mapper.

### Testing-strategie

- **D-24: Unit-tests** (`tests/Unit/Billing/Account/`):
  - `StateTransitionsTest` — happy + illegal transition matrix
  - `AccountSubscriptionManagerCreateTest` — mock Mollie via SDK's `MollieApiClient::fake()` + `StubsMollieClient`-trait (Phase 5a reuse)
  - `AccountSubscriptionManagerSyncTest` — 404 → `unknown`, GET-success → state-update
  - `SubscriptionWebhookHandlerTest` — id-prefix-routing, `mandate_invalid` → paused
- **D-25: Feature-tests** (`tests/Feature/Api/V1/AccountSubscriptions/`):
  - `CreateAccountSubscriptionTest` — happy + ability-gating + Form-Request-validation + cross-Consumer 404
  - `CancelAccountSubscriptionTest` — happy + already-canceled-409 + cross-Consumer 404
  - `PauseResumeAccountSubscriptionTest` — happy + illegal-transition-409
  - `ListAccountSubscriptionsTest` — filter op `account_external_id` + scope-isolation
  - `AccountSubscriptionWebhookFlowTest` — `mandate_invalid` payment-webhook → paused; recurring success-webhook → `last_payment_status=paid` + `last_webhook_event_at`
- **D-26: Integration-tests (echte Mollie test-mode)** in `tests/Integration/` (Phase 6's pattern, `@group integration`, run via `composer test:integration` — niet in default `php artisan test`):
  - 1 happy-path test die end-to-end een Mollie test-mode `Customer`+`first payment`+`Subscription` aanmaakt en het webhook ontvangt op een lokale tunnel/queue-fake. Optioneel — kan ook handmatige UAT zijn als de tunnel-setup te brittle is.
- **D-27: Geen ChangeWatcher-tests voor `pass_through_calls` audit-rows** — al gedekt door 5a's `MolliePassThroughAuditTest`-pattern. Hergebruik de assertion-helpers.

### Database schema

- **D-28: Eén nieuwe migration** — `database/migrations/<datum>_create_account_subscriptions_table.php`. Forward-only, geen `down()` op productie-pad (PROJECT.md invariant). Cascade-rules zoals D-03.
- **D-29: Geen wijzigingen aan `connections`-tabel.** Account-subscriptions hangen via `connection_id`-FK aan een bestaande Mollie-Connection. Connection-revoke (`revoked_at` set) → `AccountSubscriptionManager::pause()`-batch-job? **Geparkeerd** — eerst observeren of dit een productie-issue is. v0.2 levert handmatige reconciliation via Phase 9 admin als een Connection wordt ge-revoked.

### Backwards-compatibiliteit / Phase 5a coëxistentie

- **D-30: `/v1/mollie/customers/{id}/subscriptions/*` blijft pure pass-through (Phase 5a).** Phase 7's `/v1/account-subscriptions/*` is een PARALLEL, hogere-laag API. Consumers die direct met Mollie-resources willen werken houden 5a-routes; Consumers die Hub-state-management willen gebruiken de nieuwe routes. Beide paden samen werkend bewijzen in een test (`MollieAndAccountSubscriptionsCoexistenceTest`).
- **D-31: `WebhookPayloadRouter` (D-15) MAG niet de huidige Phase-5a-tests breken.** Default-path (geen `subscriptionId` in payload, geen `sub_*`-id) hergebruikt 5a-flow exact. Regressie-acceptance: bestaande `tests/Feature/Webhooks/MollieWebhookIngressTest.php` blijft groen zonder wijziging.

### Acceptance + done-criteria

- **D-32: Phase 7 is klaar als:**
  1. Migration `create_account_subscriptions_table` is geland + `php artisan migrate:fresh` reset clean.
  2. `POST /v1/account-subscriptions` met geldige body + `mollie:write`-PAT creëert een `AccountSubscription`-rij + een Mollie test-mode Subscription (integration-test of feature-test met fake) — SC-1 bewijs.
  3. Cross-Consumer toegang tot een vreemde `AccountSubscription` retourneert 404 — SC-3 bewijs.
  4. Webhook met `payment.failed` + `failureReason='mandate_invalid'` transitioneert state naar `paused` — SC-2 bewijs.
  5. Test-suite groen: alle nieuwe unit + feature-tests + bestaande Phase 5a/6-tests (regressie-free).
  6. Integration-test (`@group integration`) draait NIET in default `php artisan test` maar wel in `composer test:integration` — SC-4 vendor-coverage-pattern uit Phase 6.
  7. `/docs/api` (Scramble) toont de 7 nieuwe routes met "Try it out"-knop.
  8. Pint clean (`./vendor/bin/pint --dirty --format agent`).
  9. Geen regressie op Phase 5a's `MollieWebhookIngressTest` (D-31 invariant).
  10. ROADMAP.md + REQUIREMENTS.md + STATE.md geüpdatet bij phase-close (SUB-02 → Validated).

### Claude's Discretion

- Exacte controller-shape (single-action `__invoke` vs resource-controller met `index/show/store/destroy`-methods) — kies wat het cleanst is. Voorkeur: resource-controller voor `AccountSubscriptionController` met `index/show/store/destroy` + separate `PauseController` + `ResumeController` (single-action) voor de twee Hub-only-transitions.
- Of `AccountSubscriptionManager` direct getest wordt met `MollieApiClient::fake()` of via een `MollieClientStub`-double — beide werken; SDK's `fake()` is dichter bij de echte stack.
- Exact aantal plans (verwacht 6-8 plans: migration + model+factory + manager-service + Form-Requests + controllers + webhook-router-extension + tests + acceptance). `/gsd-plan-phase 7` beslist.
- Of `WebhookPayloadRouter` apart class wordt of inline in `MollieWebhookController` — voorkeur class voor testbaarheid + Phase 5c kan 'm ook benutten voor Snelstart-event-routing als die fase landt.
- Decimal-string-validatie regex voor `amount.value` — kies `/^\d+\.\d{2}$/` of `/^\d{1,8}\.\d{2}$/` (max amount-guard). Niet kritisch; Mollie valideert zelf hard.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Plan-source + scope (autoritief)
- `.planning/ROADMAP.md` §"Phase 7: Account-level subscriptions (use-case B)" (regels 199-214) — goal, depends-on (Phase 5a), 4 success criteria
- `.planning/REQUIREMENTS.md` §SUB-02 — "Eigen `AccountSubscription`-model + service-laag boven Mollie's Subscriptions + Mandates API. Multi-tenant. Tests dekken create/cancel/webhook-update happy paths + edge cases (revoked mandate, failed retry)."
- `.planning/PROJECT.md` §"Current Milestone v0.2" — use-case B definitie (Accounts → eindgebruikers via Connect)

### Architectuur-invariants
- `.ai/rules/global.md` — tokens encrypted-at-rest, fingerprint-only in logs, multi-tenant scope (Consumer ↔ Account ↔ Connection chain strict)
- `.ai/rules/engineering.md` — chirurgisch wijzigen, conflicten oppervlakken, lezen vóór schrijven
- `CLAUDE.md` §"Architectuur-invariants" — geen partner-business-logic in SDK-packages (Phase-7-state-machine leeft in Hub, niet in `emeq/mollie-api`)

### Sibling phase-context (referentie-pattern)
- `.planning/phases/05a-mollie-sdk-resources-webhooks-pass-through-api/05a-CONTEXT.md` — pass-through pattern (route shape, resolver-binding, error-mapper, webhook-ingress-flow); D-08 webhook-volgorde is uitgangspunt voor D-18; D-13 error-mapping wordt hergebruikt; D-06 idempotency-pattern wordt geconsumeerd in D-14
- `.planning/phases/06-cashier-mollie-integratie-use-case-a/06-CONTEXT.md` — D-04 expliciet: "Billable NIET op Account; Phase 7 krijgt eigen `AccountSubscription` via Connect"; D-12 integration-test-strategy wordt hier hergebruikt; PlanResolver-discussie context
- `.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md` — `pass_through_calls`-audit-pattern + Form-Request-pattern voor write-endpoints
- `.planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md` — webhook-handler-pattern als alternatief reference; *let op:* 5c is nog tentative (wacht op partner-respons) — Phase 7 leunt NIET op 5c, maar deelt het `WebhookPayloadRouter`-idee uit D-15

### Mollie SDK + partner-docs (LOCKED architectuur-baseline)
- `.docs/decisions/mollie-passthrough-api.md` — pass-through pattern + error-envelope-tabel
- `.docs/decisions/pass-through-calls-table.md` — audit-tabel (geen schema-wijziging voor Phase 7)
- `.docs/decisions/upstream-error-mapping.md` — error-mapper-pattern (5a + Snelstart)
- `.docs/partners/mollie/subscriptions-api.md` — `POST /v2/customers/{customerId}/subscriptions` create + body-velden (`amount`, `interval`, `description`, `times`, `startDate`, `mandateId`, `metadata`, `webhookUrl`)
- `.docs/partners/mollie/mandates-api.md` — Mandate-statussen (`valid`/`pending`/`invalid`), scopes (`customer-present`/`customer-not-present`), `id`-prefix `mdt_`
- `.docs/partners/mollie/payments-api.md` — `sequenceType` (`first`/`recurring`/`oneoff`), `subscriptionId`-veld op recurring Payments → de webhook-routing-trigger
- `.docs/partners/mollie/webhooks-overview.md` — signature-verificatie, anti-spoofing-pattern, Payment-id als enige payload-veld
- `.docs/partners/mollie/api-idempotency.md` — Idempotency-Key forward (Phase 5a D-06, herbruikt in D-14)
- `.docs/partners/mollie/oauth-overview.md` — Connect-scopes (`subscriptions.write`, `mandates.read`, etc.); HubMollieCredentialResolver gebruikt deze

### Hub-skeleton + Phase 4/5a output (fundering — code te lezen vóór planning)
- `app/Models/Account.php` — `belongsTo(Consumer)` + `hasMany(Connection)`; AccountSubscription krijgt nieuwe `hasMany`-relatie
- `app/Models/Connection.php` — `provider='mollie'` shape met encrypted `access_token`/`refresh_token`, `expires_at`, `scopes`, `revoked_at`, `fingerprint()`-accessor
- `app/Mollie/MollieConnectionContext.php` — scoped singleton (`set/current/has`); `AccountSubscriptionManager` vult deze vóór elke Mollie-call
- `app/Mollie/HubMollieCredentialResolver.php` — lazy-refresh-window (<5 min) — geen wijziging nodig
- `app/Http/Controllers/Webhooks/MollieWebhookController.php` — **wordt uitgebreid in D-15** (WebhookPayloadRouter-dispatch); huidige Payment-anti-spoof-flow blijft default-pad
- `app/Jobs/ForwardMollieWebhookToConsumer.php` — geen wijziging; Phase 7 hangt ervoor (D-18 volgorde)
- `app/Support/Mollie/MollieUpstreamErrorMapper.php` — hergebruik 1:1 (D-23)
- `app/Sanctum/TokenAbilities.php` — `MOLLIE_READ` + `MOLLIE_WRITE` worden hergebruikt; **geen** nieuwe ability nodig (D-10)
- `app/Models/PassThroughCall.php` + `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php` — audit-rij-schrijver voor de 7 nieuwe routes (D-21)
- `app/Billing/PlanResolver.php` — Cashier-only; NIET hergebruiken voor Phase 7 (D-06)
- `packages/mollie-api/src/Mollie.php` + `packages/mollie-api/src/Idempotency/UuidV7IdempotencyKeyGenerator.php` — al gepubliceerd, alleen consumeren via facade
- `packages/mollie-api/src/Webhooks/MollieWebhookSignature.php` — al gebruikt door 5a's controller; geen wijziging
- `tests/Concerns/StubsMollieClient.php` (Phase 5a) — herbruik voor unit-tests van `AccountSubscriptionManager`

### Routes + bootstrap
- `routes/api.php` — toe te voegen `Route::prefix('account-subscriptions')->group(...)` blok onder de `auth:sanctum`-groep
- `routes/webhooks.php` — geen route-toevoeging; bestaande `/webhooks/mollie/{connection_id}` blijft de enige ingress
- `bootstrap/app.php` — geen wijziging (geen nieuwe middleware-alias)

### Externe risk-references
- Memory: `feedback_pass_through_sdk_pattern.md` — Hub doet resource-wrapping; SDK blijft thin → AccountSubscription-state leeft in Hub, niet in SDK (D-13 invariant)
- Memory: `project_emeq_mollie_connect_partner.md` — Emeq = Mollie Connect Partner; multi-tenant via OAuth (= use-case B context)
- Memory: `project_mollie_sdk_wraps_mollie_api_php.md` — SDK wrapt `mollie/mollie-api-php`, geen Saloon (D-13 SDK-calls via facade)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`MollieConnectionContext`** (`app/Mollie/MollieConnectionContext.php`): scoped singleton; `AccountSubscriptionManager` zet 'm vóór elke Mollie-call. Geen `MollieCredentialResolver`-rebind nodig (Phase 4 D-16 binding doet het werk).
- **`HubMollieCredentialResolver`** (`app/Mollie/HubMollieCredentialResolver.php`): lazy refresh `<5 min`-window — Phase 7 erft dat zonder code-wijziging. Connection-`access_token` blijft fresh tijdens Mollie-calls.
- **`MollieUpstreamErrorMapper`** (`app/Support/Mollie/MollieUpstreamErrorMapper.php`): 401→502 cloak, 422→422, 404→404, 429→429+RetryAfter, 5xx→502, timeout→504. Phase 7 mappt alle Mollie-failures via deze mapper (D-23).
- **`StubsMollieClient`-trait** (`tests/Concerns/StubsMollieClient.php`): stubs voor `customers`, `paymentRefunds`, `subscriptions`, `paymentLinks`, `mandates`. Direct hergebruikbaar in `AccountSubscriptionManagerCreateTest` etc.
- **`UuidV7IdempotencyKeyGenerator`** (uit SDK): geconfigureerd via `config('mollie.idempotency.generator')` — Phase 7 hoeft alleen Idempotency-Key te forwarden in `AccountSubscriptionManager::create()` (D-14).
- **`pass_through_calls`-tabel + `PassThroughCall`-model** (Phase 5a): audit-rij-schrijven voor 7 nieuwe routes (D-21). `provider='mollie'`-rijen, geen schema-change.
- **Spatie `webhook_calls`-tabel + `MollieWebhookController`**: ingress + signature-verify + fan-out — Phase 7 voegt state-update-tussenstap toe (D-15/D-18).
- **`ForwardMollieWebhookToConsumer`** (`app/Jobs/ForwardMollieWebhookToConsumer.php`): fan-out-job — geen wijziging; Phase 7 hangt ervoor.

### Established Patterns

- **Hub-laag = business-logic, SDK = thin** (Phase 2/5a invariant) — `AccountSubscriptionManager` + state-machine leven in `app/Billing/Account/`, niet in `packages/mollie-api/`.
- **Encrypted-at-rest** (Phase 3 invariant) — geen nieuwe credential-velden in Phase 7. Mollie-id's (`cst_*`, `sub_*`, `mdt_*`) zijn opaque references, geen secrets.
- **Cross-Consumer-scoping op route-niveau** — Phase 7 controllers gebruiken `$request->user()` (PAT-Consumer) → `Account::where('consumer_id', $consumer->id)->where('external_id', $externalId)->first()` → 404 anders. Mirror van Phase 5a's `ResolveMollieAccount`-middleware-pattern, maar inline in de controller (D-12).
- **Form Requests voor write-endpoints** (`app/Http/Requests/Api/V1/`) — Phase 7 voegt `CreateAccountSubscriptionRequest` toe in `app/Http/Requests/Api/V1/AccountSubscriptions/`.
- **TDD RED-first per task** (Phase 5a/6 pattern, gsd-executor default) — alle nieuwe tests RED-first committen.
- **Pint vóór commit** (`./vendor/bin/pint --dirty --format agent`).
- **Integration-tests scheiden via `composer test:integration`** (Phase 6 D-12 pattern) — Mollie-test-mode-roundtrip integration-tests `@group integration`, niet in default `php artisan test`.

### Integration Points

- **`routes/api.php`** — nieuwe `Route::prefix('account-subscriptions')->middleware(['auth:sanctum'])->group(...)` blok ná de Mollie-pass-through-blok; PAT-ability-gating per route.
- **`app/Models/Account.php`** — `hasMany(AccountSubscription::class)` toevoegen.
- **`app/Models/Connection.php`** — `hasMany(AccountSubscription::class)` toevoegen.
- **`app/Http/Controllers/Webhooks/MollieWebhookController.php`** — refactor naar `WebhookPayloadRouter`-dispatch (D-15); default-pad blijft identiek (D-31 regressie-vrije eis).
- **`composer.json`** — geen nieuwe dependencies. Phase 7 leunt volledig op bestaande stack (`emeq/mollie-api` + Laravel-core + Spatie).
- **`bootstrap/app.php`** — geen wijziging.

</code_context>

<specifics>
## Specific Ideas

- **Routes (sketch — definitieve mapping bij planning):**
  ```
  POST   /v1/account-subscriptions                                — create (D-09 body-shape)
  GET    /v1/account-subscriptions                                — list, ?account_external_id={...} filter verplicht
  GET    /v1/account-subscriptions/{id}                           — show (Hub-state, optioneel ?resync=1 → Mollie GET)
  DELETE /v1/account-subscriptions/{id}                           — cancel (Mollie + Hub)
  POST   /v1/account-subscriptions/{id}/pause                     — Hub-only state-flip
  POST   /v1/account-subscriptions/{id}/resume                    — Hub-only state-flip
  ```

- **Folder-conventie:**
  - Controllers: `app/Http/Controllers/Api/V1/AccountSubscriptions/{AccountSubscriptionController,PauseController,ResumeController}.php`
  - Form Requests: `app/Http/Requests/Api/V1/AccountSubscriptions/{CreateAccountSubscriptionRequest}.php`
  - Resources: `app/Http/Resources/Api/V1/AccountSubscriptionResource.php`
  - Service-laag: `app/Billing/Account/{AccountSubscriptionManager,SubscriptionStatus,StateTransitions,Exceptions/}.php`
  - Webhook-router: `app/Webhooks/Mollie/{WebhookPayloadRouter,SubscriptionWebhookHandler,PaymentWebhookHandler}.php`
  - Tests: `tests/Feature/Api/V1/AccountSubscriptions/`, `tests/Unit/Billing/Account/`, `tests/Integration/AccountSubscriptions/` (@group integration)

- **SC-2 webhook-test concreet:** mock `MollieApiClient::fake()` met een Payment-payload waarin `status='failed'` + `details.failureReason='mandate_invalid'` + `subscriptionId='sub_test123'`. POST naar `/webhooks/mollie/{id}` met geldige HMAC. Assert: `AccountSubscription::find(...)->status === 'paused'`, `paused_at` is set, fan-out-job ook dispatched (Spatie `webhook_calls`-rij gemaakt).

- **SC-3 cross-Account-isolation-test:** twee Accounts (`school-a`, `school-b`) bij dezelfde Consumer, beide met een `AccountSubscription` op dezelfde test-`cst_*`-customer-id (verschillende mandates). Query vanuit school-a's Consumer met `account_external_id=school-b` retourneert 404. Query met `account_external_id=school-a` retourneert alleen die ene rij.

- **AccountSubscription factory** — `database/factories/AccountSubscriptionFactory.php` met states `pending`/`active`/`paused`/`canceled`. Default state `pending`. Helper `forConnection(Connection $connection)` zoals Phase 3's `forSnelstart()`/`forMollie()`-conventie.

- **State-machine test-matrix** (`StateTransitionsTest`):
  - Legal: `pending → active`, `pending → canceled`, `active → paused`, `active → canceled`, `active → completed`, `paused → active`, `paused → canceled`, `active → unknown`, `paused → unknown`
  - Illegal: `canceled → *`, `completed → *`, `unknown → *` (alle terminal), `pending → paused` (zonder eerst `active`)
  - Exceptie-type: `InvalidStateTransitionException` met `from`/`to` properties voor inspectie

- **Idempotency-Key bewijs:** twee `POST /v1/account-subscriptions` met dezelfde `Idempotency-Key` retourneren één Mollie-subscription-id + één `account_subscriptions`-rij. SDK's `MollieApiClient::fake()` matched op `Idempotency-Key`-header (Phase 5a SC-5 pattern).

- **Scramble OpenAPI:** Phase 5a heeft Scramble's route-discovery al geconfigureerd. Phase 7's `account-subscriptions`-routes worden automatisch opgepakt; PHPDoc + Form Request rules → OpenAPI-schema. Geen extra Scramble-config nodig.

</specifics>

<deferred>
## Deferred Ideas

- **Per-Account `account_subscription_plans`-tabel** — als productie-data toont dat Consumers steeds dezelfde 3-5 plans hergebruiken per Account. v0.2 levert ad-hoc subscriptions (D-05/D-07).
- **`EndUser`-tabel in Hub** — als Consumers structurele rapportage willen ("hoeveel actieve subscriptions per ouder over alle Accounts heen?"). v0.2 = Mollie-customer-id is opaque reference (D-01).
- **Connection-revoke → auto-pause AccountSubscription-batch-job** — handmatige reconciliation via Phase 9 admin in v0.2 (D-29).
- **Mandate-pre-flight check vóór subscription-create** — bewust afgewezen voor quota-burn-redenen (D-19). Bij productie-friction kunnen we 'm later toevoegen achter een feature-flag.
- **State-transition history audit-tabel** — log-only in v0.2 (D-22). Promote naar DB-tabel als Phase 9 admin replay/timeline-views nodig heeft.
- **Resource-type-detectie via id-prefix → SDK helper** — Phase 7 implementeert in Hub (D-15). Verplaatsen naar `emeq/mollie-api` SDK als andere providers/host-apps het ook nodig blijken (= 2 use-cases regel).
- **Mollie `mdt_*` webhook-events** — Mollie stuurt vandaag geen mandate-direct-webhooks; placeholder-handler in `WebhookPayloadRouter` voor v0.3+ (D-15 prefix-tabel rij `mdt_`).
- **Per-Connection rate-limit** — consistent gedeferd met Phase 5a/5b (zelfde reasoning: data-volume eerst meten).
- **Cron-based pre-emptive Mollie sync** (om webhook-gemiste events op te vangen) — analoog aan Phase 5c's OData-safety-net-idee. Wachten op productie-data dat events daadwerkelijk gemist worden.
- **Plan-changes / upgrades / downgrades** — bewust uit v0.2-scope (Phase 6 D-deferred & PROJECT.md "Out of Scope v0.2"); Mollie's API ondersteunt het wel (`PATCH /v2/customers/{cId}/subscriptions/{sId}`) — extension is mogelijk in v0.3 zonder schema-breuk.
- **Multi-currency / multi-country tax** — out-of-scope (alleen EUR; conform Phase 6 specifics).
- **Trial-periods voor Account-level subscriptions** — Mollie's `startDate` dekt simpele uitstel; echte trial-met-€0-first-payment is een aparte productdiscussie.

</deferred>

---

*Phase: 07-account-level-subscriptions-use-case-b*
*Context gathered: 2026-05-15 — autonomous discuss-phase pass (no clarifying questions per user directive); decisions auto-resolved naar recommended-default met inline rationale*
