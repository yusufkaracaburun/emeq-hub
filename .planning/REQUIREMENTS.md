# Requirements: Emeq integration stack (v0.2)

**Defined:** 2026-05-14
**Milestone:** v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton
**Core Value:** Naschool's vrijwillige-bijdrage flow loopt namens School A op School A's eigen Mollie-account via Hub-Connect, met `emeq/mollie-api` SDK + Hub-skeleton + Subscriptions-laag als generieke fundatie voor toekomstige Consumers en providers.

## v1 Requirements

Requirements voor v0.2 (~8-10 weken). Elke vereiste mapt naar één roadmap-fase (Phase 2-9, continued numbering vanaf v0.1).

### Mollie SDK

- [x] **MOLL-01**: `emeq/mollie-api` skeleton + ServiceProvider + `MollieCredentialResolver`-contract + dual-credential Data-classes (`MollieApiKeyCredentials` met `test_|live_`-prefix-validatie + `MollieOAuthCredentials` met `access_`-prefix-validatie). Wraping van `mollie/mollie-api-php` ^3.11 (BSD-2-Clause). Facade-alias = `EmeqMollie`. ≥10 Pest-tests groen op auth + multi-tenant resolver + error-mapping. *Validated in Phase 2 (2026-05-14) — SDK gepubliceerd als `emeq/mollie-api v0.1.0-alpha.1`, 8/8 plans DONE, Hub-side composer-binding actief.*

- [x] **MOLL-02**: Mollie Connect OAuth-broker — `client_id`/`client_secret` config (Emeq als Mollie Partner) + redirect-handler endpoint + authorization-code → token-exchange + refresh-token-flow met automatische renewal vóór expiry. Access-tokens + refresh-tokens encrypted-at-rest opgeslagen via Eloquent `encrypted` cast. Geen raw tokens in logs/exceptions — alleen fingerprints. *Validated in Phase 4 (2026-05-14) — provider-agnostisch `OAuthFlow`-contract + `MollieConnectOAuthFlow`, 5/5 plans, 129/129 tests, BLOCKING acceptance 8/8.*

- [x] **MOLL-03**: `emeq/mollie-api` Resources + DTOs voor Payments (create/read/cancel), Customers (read/create), PaymentMethods (list), Refunds (create/read), Mandates (list/get/revoke), Subscriptions (create/read/cancel). Idempotency-Key auto-injectie op writes via Mollie's `IdempotencyKeyGeneratorContract`. *Validated in Phase 5a (2026-05-15) — 7 resources + 22 routes + Idempotency-Key forward via gedeelde `AbstractMolliePassThroughController::buildClient` op alle 5 write-endpoints.*

- [x] **MOLL-04**: `MollieWebhookVerifier` voor Connect-webhooks — HMAC-SHA256 signature-verificatie (`Mollie-Signature` header) namens platform-secret. Happy + tampered signature paths gedekt door tests. Queueable optie voor langlopende webhook-handlers via `spatie/laravel-webhook-client`. *Validated in Phase 5a (2026-05-15) — signature-verify + anti-spoofing-fetch + fan-out via `spatie/laravel-webhook-server` + stap-0 hard-fail guard bij empty `MOLLIE_WEBHOOK_SECRET`.*

### Hub-skeleton

- [x] **HUB-01**: `consumers`/`accounts`/`connections` tabellen + Sanctum-PAT auth voor Consumer-routes. `consumers` houdt SaaS-app-registraties (Naschool, Planny, derde-partijen). `accounts` houdt klanten van die SaaS-apps (school A, vereniging C) by `consumer_id + external_id`. `connections` houdt per-provider credentials per Account, encrypted-at-rest. Provider-specifieke credentials: voor OAuth-providers (Mollie) `access_token` + `refresh_token` + `expires_at` + `scopes`; voor key-based providers (Snelstart) `client_key` + `subscription_key` + `subscription_id`. Provider-specifieke velden worden of in dedicated kolommen of in een `metadata` JSON-kolom opgeslagen — te beslissen bij Phase 3-planning.

- [x] **HUB-02**: `OAuthFlow`-contract provider-agnostisch (`getAuthorizationUrl()`, `exchangeCode()`, `refreshToken()`, `revoke()`). Eerste implementatie `MollieConnectOAuthFlow`. Pattern toekomst-bestendig voor Snelstart-OAuth, Exact-OAuth, Ibanity-OAuth in latere milestones. *Validated in Phase 4 (2026-05-14) — 5/5 plans, 129/129 tests groen, BLOCKING acceptance 8/8; `FakeOAuthFlow` bewijst dat het pattern niet Mollie-specifiek is (SC-4).*

- [x] **HUB-03**: Pass-through REST API `/v1/mollie/*` — Bearer Consumer-PAT-resolutie → `Account` → `Connection.access_token` → `emeq/mollie-api` SDK-call. Audit-logging van inkomende + uitgaande requests in `webhook_calls`-tabel (al gepland in PROJECT.md architectuur). `dedoc/scramble` genereert OpenAPI spec op `/docs/api`. *Validated in Phase 5a (2026-05-15) — multi-tenant Bearer→Consumer→Account→Connection resolution + audit-log in `pass_through_calls` + error-mapping (401→502 cloaked, 422→422, 404→404, 429→429+RetryAfter, 5xx→502, timeout→504) + Scramble OpenAPI op `/docs/api`.*

- [x] **HUB-04**: Filament v4 admin-paneel op `/admin` voor Emeq-medewerkers — 7 resources (Consumer CRUD + PAT issue/revoke, Connection read+revoke met fingerprint-only via `ProviderCredentialDescriptor`, Account read-only, WebhookCall viewer met audit-kolommen, AccountSubscription read+state-flip via manager-delegation, Cashier-Subscription read-only met derived-status, User super-admin-gated). Panel-auth via Spatie laravel-permission ^6 (2-rol-model `super-admin`/`staff` met 6 permissions, drop `is_emeq_staff` boolean per D-05) + `canAccessPanel()`-check. Raw tokens nooit in UI (computed `Connection::fingerprint()`-accessor, descriptor-aware refactor). Out-of-scope: 2FA/MFA (v1.0+), e-mail notificaties, audit-log (HUB-AUDIT backlog), Consumer self-service dashboard (v1.0+), `PassThroughCallResource` (HUB-OBSERVABILITY backlog), Tailwind-thema-customizing. *Validated in Phase 9 (2026-05-16) — 11/11 plans, HUB-04 SC-1..SC-10 bewezen via tests/Feature/Admin/* (52 nieuwe tests in 15 test-classes) + 1 audit-migratie. Pre-existing 1 incomplete-test (Phase 3-03 SanctumAbilityTest-placeholder) blijft incomplete, unrelated to Phase 9. 389 tests / 1343 assertions / 0 failed.*

- [x] **HUB-05**: Pass-through REST API `/v1/snelstart/{path}` — Bearer Consumer-PAT-resolutie → `Account` → `Connection` (Snelstart-provider met `client_key` + `subscription_key` + `subscription_id` encrypted) → `emeq/snelstart-api` SDK-call via `RawSnelstartRequest` of OData QueryBuilder. Bind `HubSnelstartCredentialResolver` aan `Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver`. Audit-logging in eigen `pass_through_calls`-tabel (zie ADR `.docs/decisions/pass-through-calls-table.md` — afgesplitst van `webhook_calls` omdat pass-through ≠ fan-out). Scramble pakt automatisch de routes op via Phase 5a OpenAPI-config. Parallel met Phase 4 mogelijk (Snelstart heeft géén OAuth-broker nodig — clientKey is door Snelstart uitgegeven aan eindklant). Endpoint-flow: ondersteunt GET (proxy naar OData query), POST (proxy naar create), PATCH/DELETE (proxy naar update/delete). `POST /v1/accounts` en `POST /v1/connections` zijn onderdeel van dit requirement (Consumer-provisioning-flow). *Validated in Phase 5b (2026-05-16) — 5/5 plans afgerond + `05b-VERIFICATION.md` passed 8/8 must-haves (verifier-close 2026-05-17): SC-1..SC-8 bewezen via 86 Phase-5b-scoped tests (15 testfiles), CR-01 (415-guard) + CR-02 (PII-safe `query_keys`) + CR-03 (NULL fingerprint empty body) code-resident, UAT 9/9 live-passed (`05b-UAT.md`), SECURITY 24/24 gemitigeerd (`05b-SECURITY.md`).*

- [ ] **HUB-06**: Snelstart webhook-handler op `POST /webhooks/snelstart` — publieke ingress (geen Sanctum, geen `throttle:api`), HMAC-verified met globale `SNELSTART_WEBHOOK_SECRET`. Connection-resolutie via payload `administratieId`-veld (één URL voor alle administraties). Audit-row in `pass_through_calls` met `direction=inbound`; async fan-out via Horizon-queue `webhooks` naar `consumers.webhook_callback_url` met per-Consumer HMAC-signing (Phase 5a-01 fan-out-pattern hergebruikt). Idempotency via `event_id` unique-per-provider. Onbekende `administratieId` → 200 + NULL-tenant audit (anti-retry-storm); invalid HMAC → 401 zonder audit (anti-amplification). Cross-Consumer-isolation bewezen via feature-test. Productie-certificeringsblocker (zie `.docs/decisions/snelstart-certificering-pad.md`).

### Subscriptions

- [x] **SUB-01**: Cashier-Mollie integratie voor use-case A (Emeq rekent aan Consumers via Emeq's eigen Mollie-account). PHP 8.4 / Laravel 13 compatibiliteit gevalideerd of fork-and-update uitgevoerd. `Billable` trait op `Consumer`-model; subscription-plans (Naschool-license, Planny-license, etc.) gedefinieerd via Cashier's `Plan` model. Recurring billing via Mandates-flow. *Validated in Phase 6 (2026-05-15) — pad-a (out-of-the-box) gekozen met `mollie/laravel-cashier-mollie ^2.20.1`; 8/8 plans + 3/3 SC's bewezen (SC-4 vendor-coverage); `Consumer` Billable, `App\Billing\PlanResolver` + `config/billing-plans.php`, 3 billing-routes met `billing:read|write`-abilities + admin-allowlist, Cashier-webhook hard-fail-guard op `/cashier/webhook*`, integration-suite via `composer test:integration`. 237 tests passed.*

- [x] **SUB-02**: Account-level subscriptions via Connect voor use-case B (Accounts rekenen aan eindgebruikers via hun eigen Mollie via Connect). Eigen `AccountSubscription`-model + service-laag boven Mollie's Subscriptions + Mandates API. Multi-tenant: subscription-state per `Account`-Connection, niet single-tenant zoals Cashier. Tests dekken create/cancel/webhook-update happy paths + edge cases (revoked mandate, failed retry). *Validated in Phase 7 (2026-05-15) — multi-tenant `AccountSubscription`-model + 6 `/v1/account-subscriptions/*`-routes + state-machine met 6 states (`pending`/`active`/`paused`/`canceled`/`completed`/`unknown`) + `WebhookPayloadRouter`-dispatch (D-15) zonder Phase-5a-regressie (D-31). 8/8 plans, 337 tests / 1100 assertions groen, SC-1+SC-2+SC-3 bewezen, SC-4 vendor-coverage; integration-test skipt graceful zonder Connect-token (Pad B per ADR `account-subscriptions.md`).*

### Naschool wiring

- [x] **NSCH-01**: Naschool `backend/composer.json` heeft path/VCS-repository-entry voor `emeq/snelstart-api` + `emeq/mollie-api` (publiek, geen private-token). `StancltenancyCredentialResolver` voor Snelstart geïmplementeerd in `backend/app/Services/Snelstart/` + gebonden in `AppServiceProvider`. Mollie-deel werkt via Hub (zie NSCH-03) — geen directe Stancl-resolver voor Mollie. Hub-side substrate (`App\Services\ConsumerOnboarding` atomic Consumer+Account+Connection+PAT-flow) bewezen in Phase 8 plan 08-01 (14/14 tests, 2026-05-17); Naschool-repo composer-entries + Stancl-resolver buiten Hub-scope (D-03).

- [ ] **NSCH-02**: `SyncEnrollmentToSnelstartJob` als event-handler op `EnrollmentConfirmed`. Maakt verkoopfactuur aan in Snelstart's test-omgeving. Smoke-test groen op `php artisan migrate:fresh --seed` (school1 demo-seed) — factuur zichtbaar in Snelstart-UI of via API-GET.

- [ ] **NSCH-03**: Mollie checkout-flow op één activiteit met vrijwillige bijdrage **via Hub-Connect**. Naschool POSTs naar Hub `/v1/mollie/payments` met Consumer-PAT + Account-id (school A) → Hub haalt Connection.access_token van school A → Mollie checkout aangemaakt op school A's eigen Mollie-account → checkout-URL terug naar Naschool → ouder doorloopt Mollie test-mode → webhook signature-verified door Hub → Hub doet pass-through fan-out naar Naschool's callback-URL → Naschool update enrollment-status. End-to-end smoke handmatig doorlopen. Hub-side admin-trigger (`StartOAuthFlowAction` op AccountResource + ConnectionResource voor pending Mollie) bewezen in Phase 8 plan 08-03 (14/14 tests, 2026-05-17); resterende Hub-side wiring (partner-pages + Filament onboard-wizard) in PLAN 08-02/04/05; Mollie-checkout-uitvoering + e2e-smoke vereist Naschool-repo werk + UAT.

## Future Requirements

Deferred to v0.3+. Tracked, niet in v0.2 roadmap.

### Snelstart Saloon v4

- **SNEL-V4**: Upgrade Saloon v3 → v4 (3 ignored security advisories oplossen, o.a. SSRF via endpoint-override)

### Andere providers (volgende milestones)

- **PROV-MONEYBIRD**: `emeq/moneybird-api` SDK
- **PROV-EXACT**: `emeq/exact-api` SDK + OAuth-flow
- **PROV-IBANITY**: `emeq/ibanity-api` SDK (PSD2 met eIDAS/QSEAL certificaten)
- **PROV-STRIPE**: `emeq/stripe-api` SDK (positie-3-uitbreiding)

### Hub commerciële features

- **HUB-BILLING**: Cashier-Mollie publieke billing-flow voor derde-partij Consumers (self-service Mollie-subscription voor Hub-toegang)
- **HUB-DOCS**: Public docs-site (Scramble + landing-page) op `docs.hub.emeq.nl`
- **HUB-ONBOARDING**: Self-service Consumer-onboarding-flow (registratie → PAT-uitgifte → eerste Connection)

## Out of Scope

Expliciet uitgesloten voor v0.2. Niet re-adden zonder PROJECT.md herziening.

| Feature | Reason |
|---------|--------|
| OAuth Connect voor andere providers (Snelstart oAuth, Exact, Ibanity) | OAuthFlow-contract wordt provider-agnostisch ontworpen, maar concrete implementaties wachten op v0.3+ |
| Cashier-Mollie als zelfstandig package upstream-fixen voor PHP 8.4 | Als compat-check faalt: fork-and-update in v0.2 of zelf subscription-laag bouwen — geen upstream-PR-pad in deze milestone |
| Snelstart-resource-classes + DTO-codegen | `RawSnelstartRequest` + OData QueryBuilder dekken alle 96 endpoints; geen typed-response consumers in v0.2 |
| Mollie's volledige API-oppervlak | Alleen Payments/Customers/PaymentMethods/Refunds/Mandates/Subscriptions; geen Settlements, Chargebacks, Invoices, Onboarding, Profiles-management, etc. — wachten tot host-apps het concreet nodig hebben |
| Naschool's volledige financiële module | Alleen vrijwillige-bijdrage checkout-flow + Snelstart-verkoopfactuur-flow als POC; geen full ledger, geen multi-currency, geen tax-rule-engine |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| MOLL-01 | Phase 2 | Complete |
| MOLL-02 | Phase 4 | Complete |
| MOLL-03 | Phase 5a | Complete |
| MOLL-04 | Phase 5a | Complete |
| HUB-01 | Phase 3 | Complete |
| HUB-02 | Phase 4 | Complete |
| HUB-03 | Phase 5a | Complete |
| HUB-04 | Phase 9 | Complete |
| HUB-05 | Phase 5b | Complete |
| HUB-06 | Phase 5c | Pending |
| SUB-01 | Phase 6 | Complete |
| SUB-02 | Phase 7 | Complete |
| NSCH-01 | Phase 8 | In Progress (Hub-substrate landed 08-01; Naschool-repo werk pending) |
| NSCH-02 | Phase 8 | Pending |
| NSCH-03 | Phase 8 | In Progress (Hub-admin-trigger landed 08-03; partner-pages + onboard-wizard pending; e2e UAT in Naschool-repo) |

**Coverage:**

- v1 requirements: 15 total
- Mapped to phases: 15 (Phase 2-9, Phase 5 gesplitst in 5a + 5b + 5c)
- Unmapped: 0

---

*Requirements defined: 2026-05-14. Traceability gemapped naar ROADMAP.md Phase 2-9 op dezelfde datum (Phase 9 added 2026-05-14). HUB-05 (Snelstart-pass-through) added 2026-05-14 — Phase 5 gesplitst in 5a (Mollie) + 5b (Snelstart) zodat Snelstart-test los van Mollie-OAuth-broker geleverd kan worden. HUB-06 (Snelstart webhook-handler) added 2026-05-15 — Phase 5c toegevoegd als productie-certificeringsblocker per `.docs/decisions/snelstart-certificering-pad.md`.*
