# Roadmap: Emeq integration stack

**Project code:** EMEQ
**Granularity:** ~10 phases voor v0.2 (standard band, requirements-driven; Phase 5 gesplitst in 5a + 5b + 5c)
**Execution:** sequentieel met parallel-ramps — Phase 5b is parallelliseerbaar met Phase 4 (beide afhankelijk van Phase 3, niet van elkaar); Phase 6 en Phase 7 zijn parallelliseerbaar (beide afhankelijk van Phase 5a); Phase 9 is parallelliseerbaar met Phase 6/7 (afhankelijk van Phase 3 + Phase 4)

## Shipped Milestones

- **v0.1 (2026-05-14)** — Snelstart-SDK finale. Zie [`milestones/v0.1-ROADMAP.md`](milestones/v0.1-ROADMAP.md) · [`MILESTONES.md`](MILESTONES.md)

## Active Milestone: v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton

**Defined:** 2026-05-14
**Indicatie:** ~8-10 weken vanaf kickoff
**Master-plan:** [`.claude/plans/fancy-honking-spring.md`](../.claude/plans/fancy-honking-spring.md)

### Overview

v0.2 bouwt drie samenhangende lagen: (1) `emeq/mollie-api` SDK die `mollie/mollie-api-php` wrapt met multi-tenant credential-resolution + dual creds (API-key en OAuth), (2) Hub-skeleton met `consumers`/`accounts`/`connections`-tabellen, Sanctum-PAT-auth, Mollie Connect OAuth-broker, pass-through `/v1/mollie/*`-API (Phase 5a) én pass-through `/v1/snelstart/{path}`-API (Phase 5b, los van Mollie-OAuth zodat Snelstart-test parallel kan landen), en (3) twee subscription-use-cases (Cashier-Mollie voor Emeq→Consumers + eigen subscription-laag voor Accounts→eindgebruikers via Connect). Sluit af met Naschool als eerste concrete consumer: Snelstart-verkoopfactuur op `EnrollmentConfirmed` + vrijwillige-bijdrage-checkout via Hub-Connect op school A's eigen Mollie-account.

### Phases

- [x] **Phase 2: emeq/mollie-api foundation** — SDK skeleton + multi-tenant resolver + dual creds + Pest-suite groen *(voltooid 2026-05-14; SDK gepubliceerd als `emeq/mollie-api v0.1.0-alpha.1`, 8/8 plans DONE in eigen sub-repo, Hub-side composer-binding actief; bonus: exception-mappers + idempotency-generator + webhook-helper boven plan-scope)*
- [x] **Phase 3: Hub-skeleton** — `consumers`/`accounts`/`connections`-tabellen + Sanctum-PAT-auth + Consumer-routing + Snelstart-credential-velden encrypted *(voltooid 2026-05-14; 5/5 plans, HUB-01 SC-1 t/m SC-5 bewezen)*
- [x] **Phase 4: Mollie Connect OAuth-broker** — provider-agnostisch `OAuthFlow`-contract + `MollieConnectOAuthFlow` + encrypted token-storage *(voltooid 2026-05-14; 5/5 plans, alle 5 SC's bewezen, BLOCKING acceptance 8/8 + 129/129 tests)*
- [x] **Phase 5a: Mollie SDK Resources + Webhooks + Pass-through API** — Payments/Customers/PaymentMethods/Refunds/Mandates/Subscriptions/PaymentLinks + Connect-webhook verifier + `/v1/mollie/*` audit-logged (zie ADR `mollie-passthrough-api.md`) *(voltooid 2026-05-15; 6/6 plans, 207 tests groen, 13/13 truths verified incl. gap-closure plan 05a-06 voor D-06 + D-08 stap 1; 3 human-UAT items pending in `05a-HUMAN-UAT.md`)*
- [x] **Phase 5b: Snelstart-pass-through API** — `/v1/snelstart/{path}` pass-through via `HubSnelstartCredentialResolver` + `POST /v1/accounts` + `POST /v1/connections` provisioning-endpoints + audit-logging. Parallel met Phase 4 mogelijk. (completed 2026-05-16)
- [ ] **Phase 5c: Snelstart webhook-handler** — `POST /webhooks/snelstart` HMAC-verified ingress + Connection-resolutie via `administratie_id` + audit-log (`direction=inbound`) + async fan-out naar Consumer-callback. Productie-certificeringsblocker (zie `.docs/decisions/snelstart-certificering-pad.md`).
- [x] **Phase 6: Cashier-Mollie integratie (use-case A)** — Emeq → Consumers billing op Emeq's eigen Mollie *(voltooid 2026-05-15; 8/8 plans, SC-1+SC-2+SC-3 bewezen, SC-4 vendor-coverage; 237 tests passed + integration-suite gescheiden via `composer test:integration`)*
- [x] **Phase 7: Account-level subscriptions (use-case B)** — Accounts → eindgebruikers via Connect + Mandates + Subscriptions *(voltooid 2026-05-15; 8/8 plans, SC-1+SC-2+SC-3 bewezen, SC-4 vendor-coverage via unit + feature stubs + skipt-graceful integration-test, 337 tests groen, ADR `account-subscriptions.md`)*
- [x] **Phase 8: Naschool wiring** — composer-wiring + Snelstart Stancl-resolver + `SyncEnrollmentToSnelstartJob` + Mollie checkout-flow via Hub-Connect (completed 2026-05-17)
- [x] **Phase 9: Filament admin-UI voor Emeq-medewerkers** — `/admin`-panel met 7 resources (Consumer CRUD + Connection read+revoke + Account read + WebhookCall viewer + AccountSubscription read+state-flip + Cashier-Subscription read + User super-admin-gated) *(voltooid 2026-05-16; 11/11 plans, HUB-04 SC-1..SC-10 bewezen via 52 nieuwe tests in `tests/Feature/Admin/` + 1 audit-migratie, ADR `filament-admin-panel.md`, 389 tests / 1343 assertions groen)*
- [x] **Phase 10: Phase 9 polish — deferred review-findings** — 11 bevindingen uit `09-REVIEW.md` afsluiten: CR-02 permission-enforcement op 6 resources + Hub `WebhookCall`-model met `consumer()` belongs-to + cross-Consumer-isolation-test (SC-7 bewijs); WR-01..06 (last-super-admin guards, exception-veld, role-validatie, seeder password-reset, password-edit-regressie, PAT-token uit Livewire-state); IN-01..04 (N+1, exception-leak, AdminPanelProvider-comment, descriptor `tryFor()`). (completed 2026-05-16)

### Phase Details

#### Phase 2: emeq/mollie-api foundation

**Goal:** Een dunne, multi-tenant, dual-credential SDK-laag rond `mollie/mollie-api-php` waarop alle Hub-fasen kunnen leunen.
**Depends on:** Nothing (eerste v0.2-fase)
**Requirements:** MOLL-01
**Working repo:** `packages/mollie-api/` ↔ `github.com:yusufkaracaburun/emeq-mollie-api` (publiek, leeg sinds 2026-05-13)
**Context:**

- Wrapt `mollie/mollie-api-php` ^3.11 (BSD-2-Clause); geen eigen Saloon-laag (zie reversed-decision in PROJECT.md)
- Multi-tenant via runtime `setApiKey()` / `setAccessToken()`-swap door `MollieCredentialResolver`-contract
- Facade-alias = `EmeqMollie` (niet `Mollie` — collision met `laravel-mollie` dat Cashier-Mollie meeneemt in Phase 6)
- Dual creds van dag 1: `MollieApiKeyCredentials` (`test_|live_`-prefix-validatie) + `MollieOAuthCredentials` (`access_`-prefix-validatie)
- Bij start: `.docs/partners/mollie/` aanmaken met links naar officiële Mollie-docs (huidige dir bestaat nog niet — zie PROJECT.md "geen verzonnen partner-features")

**Success Criteria** (what must be TRUE):

  1. `composer require emeq/mollie-api` via VCS-repository slaagt zonder authenticatie tegen `yusufkaracaburun/emeq-mollie-api`
  2. `MollieCredentialResolver`-binding kan runtime-swappen tussen `MollieApiKeyCredentials` en `MollieOAuthCredentials` per request zonder cross-tenant lekkage
  3. `EmeqMollie`-facade en `Mollie`-facade (uit `laravel-mollie`) kunnen tegelijk geregistreerd zijn zonder Laravel-alias-conflict
  4. Pest-suite groen met ≥10 cases over auth-laag, credential-resolver, en error-mapping (`Mollie\Api\Exceptions\ApiException` → SDK-eigen exceptions)
  5. Geen raw API-key/access-token in logs of exception-messages — alleen sha256-fingerprint (eerste 12 chars)

**Plans:** 8 plans

- [ ] 02-01-PLAN.md — Sub-repo package-skeleton + composer.json + tooling-config + composer install groen
- [ ] 02-02-PLAN.md — MollieCredentialResolver-contract + abstract MollieCredentials + dual creds (API key + OAuth) + Data-tests
- [ ] 02-03-PLAN.md — MollieException + MissingCredentialResolverException (package-base + ::notBound() factory)
- [ ] 02-04-PLAN.md — MollieServiceProvider + Mollie facade-target met type-discriminator + Facades\Mollie
- [ ] 02-05-PLAN.md — Pest-bootstrap: TestCase + Pest.php + ArchTest + PackageSmokeTest + FakeMollieCredentialResolver
- [ ] 02-06-PLAN.md — MollieTest (multi-tenant key-wissel + env-guard + idempotency) + MollieServiceProviderTest (binding shapes)
- [ ] 02-07-PLAN.md — ErrorMappingTest (ValidationException::getField + Authorization: Bearer header) via MollieApiClient::fake()
- [ ] 02-08-PLAN.md — Sub-repo commit + push (checkpoint) + GitHub repo-description update + Hub composer.json path-repo entry

#### Phase 3: Hub-skeleton

**Goal:** Een werkende Hub-app met multi-tenant data-model en Consumer-authenticatie waarop de OAuth-broker (Phase 4), Mollie-pass-through (Phase 5a) en Snelstart-pass-through (Phase 5b) kunnen landen.
**Depends on:** Nothing (parallel met Phase 2 mogelijk; in praktijk sequential na Phase 2 tenzij Snelstart-test prioriteit krijgt)
**Requirements:** HUB-01
**Working repo:** `emeq-hub` (deze repo)
**Context:**

- `consumers`-tabel: SaaS-app-registraties (Naschool, Planny, derde-partijen) — `id`, `name`, `slug`, timestamps
- `accounts`-tabel: klanten van die SaaS-apps (school A, vereniging C) — `consumer_id` + `external_id` uniek samen, `display_name`
- `connections`-tabel: per-provider credentials per Account — `account_id`, `provider`, OAuth-velden (`access_token` encrypted, `refresh_token` encrypted, `expires_at`, `scopes` JSON), key-based-velden (`client_key` encrypted, `subscription_key` encrypted, `subscription_id`), en `metadata` JSON voor provider-specifieke overflow. Niet-gebruikte velden voor een bepaalde provider blijven NULL.
- Snelstart-provider gebruikt: `client_key` + `subscription_key` + `subscription_id` (geen OAuth-velden)
- Mollie-provider gebruikt: `access_token` + `refresh_token` + `expires_at` + `scopes` (geen key-based-velden) — gevuld door Phase 4 OAuth-broker
- Sanctum-PAT voor Consumer-auth; Consumers krijgen Personal Access Tokens met provider-scope-abilities (`snelstart:read`, `snelstart:write`, `mollie:read`, `mollie:write`)
- Migrations forward-only (PROJECT.md invariant); geen `down()` in prod-pad
- Eloquent `encrypted` cast op alle gevoelige credential-velden — nooit raw in `->toArray()` of `tinker`-output

**Success Criteria** (what must be TRUE):

  1. `php artisan migrate:fresh --seed` levert demo-Consumer ("naschool"), demo-Account (school1) en lege `connections`-tabel
  2. Een Consumer kan een Sanctum-PAT verkrijgen en authenticeren tegen een `/v1/ping`-smoke-endpoint met `Authorization: Bearer …`
  3. Een Connection bewaard met test-credentials (zowel OAuth-shape als Snelstart-key-shape) toont nooit raw waardes in `php artisan tinker` `->toArray()` output zonder expliciete decrypt-call
  4. Cross-Consumer query-poging (Consumer A's PAT → Account van Consumer B) faalt met 403/404 voor route-level scoping check
  5. Een Snelstart-Connection kan worden aangemaakt met alleen `client_key` + `subscription_key` + `subscription_id` (zonder OAuth-velden); een Mollie-Connection kan worden aangemaakt met alleen `access_token` + `refresh_token` + `expires_at` — beide vormen passeren validatie

**Plans:** 4/5 plans executed

- [x] 03-01-PLAN.md — Migrations + Eloquent-models + factories voor consumers/accounts/connections (encrypted casts + #[Hidden] + fingerprint-accessor + forSnelstart/forMollie states)
- [x] 03-02-PLAN.md — Sanctum-config (auth.guards.sanctum + consumers-provider + bootstrap apiPrefix:v1) + App\Sanctum\TokenAbilities constants-class + routes/api.php skeleton
- [x] 03-03-PLAN.md — routes/api.php /v1/ping + PingController + PingTest (3 tests) + SanctumAbilityTest (3 tests, 1 incomplete placeholder voor Phase 5b)
- [x] 03-04-PLAN.md — ConnectionEncryptionTest (7 tests: at-rest + Hidden + fingerprint) + ConsumerAccountScopingTest (4 tests: cross-Consumer query-isolation)
- [x] 03-05-PLAN.md — hub:consumer:create artisan-command + DatabaseSeeder demo-data (production-guarded) + HubConsumerCreateTest (5 tests) + Phase 3 acceptance-run

#### Phase 4: Mollie Connect OAuth-broker

**Goal:** Een werkende OAuth-broker waarmee Accounts hun eigen Mollie-account aan een Consumer kunnen koppelen via Mollie Connect, met provider-agnostisch contract voor toekomstige providers.
**Depends on:** Phase 2 (gebruikt `MollieOAuthCredentials`), Phase 3 (schrijft naar `connections`-tabel)
**Requirements:** MOLL-02, HUB-02
**Working repo:** `emeq-hub` (controllers + flow-implementatie) — `packages/mollie-api/` aanrakingen alleen als `MollieOAuthCredentials` aanpassing nodig blijkt
**Context:**

- Emeq registreert zich éénmalig als Mollie Connect Partner; `client_id`/`client_secret` in `config/services.php` (encrypted via Laravel config-cache prevention voor secrets)
- `OAuthFlow`-contract: `getAuthorizationUrl(Account, scopes)`, `exchangeCode(Account, code, state)`, `refreshToken(Connection)`, `revoke(Connection)`
- Eerste implementatie `MollieConnectOAuthFlow`; pattern voor latere Snelstart-OAuth / Exact-OAuth / Ibanity-OAuth in v0.3+
- Automatic refresh ruim vóór expiry (`.ai/rules/global.md`: niet wachten op 401)
- Encrypted-at-rest; geen raw tokens in logs/exceptions — alleen fingerprints

**Success Criteria** (what must be TRUE):

  1. Een Consumer kan via `POST /v1/oauth/mollie/init` (Sanctum + `ability:mollie:write`, JSON body `{account_external_id}`) een pending Connection laten pre-creëren en in de JSON-respons `{connection_id, redirect_url}` de Mollie authorization-URL ontvangen met juiste `client_id`, `state`, en `redirect_uri` *(D-01 + D-08; CONTEXT overstijgt pre-discuss ROADMAP-wording `GET /authorize?…`)*
  2. Callback op `GET /v1/oauth/mollie/callback?code=…&state=…` ruilt authorization-code in voor `access_token` + `refresh_token` en bewaart die encrypted op de juiste Connection
  3. Een Connection met `expires_at` < 5 minuten triggert automatische refresh (`refreshToken()`) en updatet `access_token`/`expires_at` zonder dat de pass-through-API een 401 ziet
  4. `OAuthFlow`-contract heeft een tweede dummy-implementatie (test-fixture, niet productie) die laat zien dat het pattern niet Mollie-specifiek is
  5. Tampered/expired/replay `state`-parameter (CSRF-check) wordt afgewezen met 400

**Plans:** 5 plans

- [x] 04-01-PLAN.md — OAuthFlow-contract + FakeOAuthFlow + migration + Connection-model edit + factory-states + OAuthFlowContractTest (SC-4 bewezen)
- [x] 04-02-PLAN.md — MollieConnectOAuthFlow (Http::post direct) + OAuthFlowRegistry + config/services.mollie + .env.example + AppServiceProvider OAuthFlow-binding + Http::fake-test
- [x] 04-03-PLAN.md — MollieConnectionContext (scoped) + HubMollieCredentialResolver (lazy refresh D-04/D-06) + AppServiceProvider SDK-bindings + 3 testpaden
- [x] 04-04-PLAN.md — InitController + CallbackController + routes/api.php + 7 feature-tests (SC-1 + SC-2 + SC-5 bewezen)
- [x] 04-05-PLAN.md — PruneOAuthPendingConnections command + test + BLOCKING migrate + full test-suite Phase-acceptance (8/8 groen, 129/129 tests)

#### Phase 5a: Mollie SDK Resources + Webhooks + Pass-through API

**Goal:** Een werkende end-to-end Mollie-pass-through: Consumer doet HTTP-call naar Hub, Hub resolved Connection, SDK doet Mollie-call, response stroomt terug — voor alle 6 in-scope resources, inclusief inkomende webhook-verificatie.
**Depends on:** Phase 2 (SDK foundation), Phase 3 (Hub-skeleton tabellen), Phase 4 (OAuth-broker resolved access_token)
**Requirements:** MOLL-03, MOLL-04, HUB-03
**Working repo:** `packages/mollie-api/` (Resources + DTOs + WebhookVerifier) + `emeq-hub` (`/v1/mollie/*` controllers + audit-log)
**Context:**

- MOLL-03 Resources: Payments (create/read/cancel), Customers (read/create), PaymentMethods (list), Refunds (create/read), Mandates (list/get/revoke), Subscriptions (create/read/cancel), PaymentLinks (create/read/list) — alle 7 in één fase omdat ze dezelfde pass-through-pattern delen (zie `.docs/decisions/mollie-passthrough-api.md`)
- `Idempotency-Key` auto-injectie op writes via Mollie's `IdempotencyKeyGeneratorContract`
- MOLL-04: `MollieWebhookVerifier` met HMAC-SHA256 (`Mollie-Signature` header) namens platform-secret; Connect-flow betekent platform-signed (niet per-Connection-signed)
- HUB-03: pass-through `/v1/mollie/*` met Bearer Consumer-PAT → Account → Connection.access_token → SDK-call; audit in `webhook_calls`-tabel (inkomend Consumer-request + uitgaand Mollie-call); fan-out via `spatie/laravel-webhook-client` queueable
- `dedoc/scramble` genereert OpenAPI op `/docs/api` (Scramble-installatie is een Phase-3-voorbereiding of quick-task)
- Mollie-docs in `.docs/partners/mollie/` moeten gelinkt staan voor elk endpoint dat geïmplementeerd wordt (geen verzonnen velden)

**Success Criteria** (what must be TRUE):

  1. Een Consumer kan `POST /v1/mollie/payments` doen met Bearer PAT + Account-ID en krijgt een Mollie-checkout-URL terug die door Mollie als test-mode geldig wordt geaccepteerd
  2. Alle 7 resources (Payments, Customers, PaymentMethods, Refunds, Mandates, Subscriptions, PaymentLinks) zijn callable via pass-through API en hun pad in `/docs/api` OpenAPI-spec
  3. Een inkomende Mollie Connect-webhook met geldige `Mollie-Signature` wordt geaccepteerd en gerouteerd; tampered signature retourneert 400 en wordt niet doorgegeven aan Consumer-callback
  4. Elke pass-through-call schrijft één regel in `webhook_calls` met Consumer-ID, Account-ID, Connection-ID-fingerprint, request-summary en response-status
  5. Twee identieke `POST /v1/mollie/payments` met dezelfde idempotency-key retourneren één Mollie-payment-ID (geen duplicate)

**Plans:** 6 plans (incl. 1 gap-closure)

- [x] 05a-01-PLAN.md — Cross-cutting infra: AbstractMolliePassThroughController + ResolveMollieAccount-middleware + MollieUpstreamErrorMapper + MollieHeaderForwarder + Consumer.webhook_callback_* migration
- [x] 05a-02-PLAN.md — Webhook ingress + fan-out: MollieWebhookController + ForwardMollieWebhookToConsumer-job + routes/webhooks.php + Spatie laravel-webhook-server install
- [x] 05a-03-PLAN.md — Payments resource (create+get+cancel) + config/mollie.php idempotency-binding + ConsumerIdempotencyKeyGenerator + 4 feature-tests (incl. SC-1 + SC-5)
- [x] 05a-04-PLAN.md — Customers + PaymentMethods + Refunds + Mandates resources (4 controllers, 10 routes, 4 tests)
- [x] 05a-05-PLAN.md — Subscriptions + PaymentLinks resources + Scramble route-discovery + SanctumAbility-mollie-completion + BLOCKING phase-acceptance 8/8
- [x] 05a-06-PLAN.md — Gap-closure: hoist Idempotency-Key-forward to AbstractMolliePassThroughController (D-06 / CR-01) + webhook-secret hard-fail guard (D-08 stap 1 / CR-02) + 6 nieuwe tests

#### Phase 5b: Snelstart-pass-through API

**Goal:** Een werkende end-to-end Snelstart-pass-through: Consumer doet HTTP-call naar `/v1/snelstart/{path}` met Bearer-PAT + Account-ID, Hub resolved Connection naar Snelstart-credentials (`client_key` + `subscription_key` + `subscription_id`), SDK doet OData/REST-call namens die Account, response stroomt terug.
**Depends on:** Phase 3 (Hub-skeleton — `connections`-tabel + Sanctum-PAT). **Parallel met Phase 4 mogelijk** — Snelstart heeft géén OAuth-broker nodig (`clientkey`-flow wordt direct door Snelstart aan eindklant uitgegeven, geen authorize/callback-stap).
**Requirements:** HUB-05
**Working repo:** `emeq-hub` (controllers + credential-resolver-binding); SDK (`emeq/snelstart-api`) is shipped en wordt alleen geconsumeerd
**Context:**

- HUB-05 pass-through `/v1/snelstart/{path}` met Bearer Consumer-PAT → Account → Snelstart-Connection → `HubSnelstartCredentialResolver` (bindt aan `Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver`) → SDK-call via `RawSnelstartRequest` of OData QueryBuilder
- Audit-logging in `webhook_calls`-tabel — Consumer-ID, Account-ID, Connection-fingerprint (`sha256(client_key)[0..12]`), request-method + path, response-status. **Nooit raw credentials in audit-log.**
- Provisioning-endpoints toegevoegd in deze fase (kunnen niet in Phase 3 omdat Phase 3 alleen smoke-endpoint heeft):
  - `POST /v1/accounts` — Consumer maakt Account aan (`external_id`, `display_name`)
  - `POST /v1/connections` — Consumer POST't Snelstart-credentials voor een Account
  - `GET/DELETE /v1/connections/{id}` — Consumer kan eigen Connections lezen en revoken
- PAT-abilities: `snelstart:read` (alleen GET-pass-through) en `snelstart:write` (alle methoden + Account/Connection-provisioning)
- Account-resolving: `X-Account-Id`-header (Account.external_id) op pass-through-calls; cross-Consumer-leakage → 404 (niet 403 — voorkomt info-disclosure)
- Snelstart-docs in `.docs/partners/snelstart/` moeten gelinkt staan voor de endpoints die in test-scope vallen (PROJECT.md "geen verzonnen partner-features")
- `dedoc/scramble` (Phase-3-voorbereiding) pakt de routes automatisch op zodat ze in `/docs/api` met "Try it out"-knop verschijnen

**Success Criteria** (what must be TRUE):

  1. Een Consumer met `snelstart:write`-PAT kan `POST /v1/accounts` doen en krijgt een Account-resource terug met `external_id` en `display_name`
  2. Een Consumer kan voor een eigen Account een Snelstart-Connection aanmaken via `POST /v1/connections` met `provider=snelstart` + de drie credential-velden; raw waardes komen nooit terug in de response (alleen fingerprint)
  3. Een Consumer kan `GET /v1/snelstart/echo/ping` doen (met `X-Account-Id`-header) en krijgt Snelstart's echo-response terug — bewijst dat de credential-resolver-binding werkt
  4. Een Consumer kan `GET /v1/snelstart/relaties?$top=5` doen en krijgt een OData-response van Snelstart's test-omgeving — bewijst dat het pad-doorgeefluik werkt voor read-endpoints
  5. Een Consumer-A's PAT kan géén Account/Connection van Consumer-B benaderen (alle endpoints retourneren 404 voor cross-Consumer-scoping)
  6. Mismatched `X-Account-Id` (Account hoort niet bij deze Consumer) → 404; ontbrekende header op pass-through-route → 400 met duidelijke error
  7. Elke pass-through-call landt één regel in `webhook_calls` met Consumer-ID, Account-ID, Connection-fingerprint, request-summary; raw `client_key`/`subscription_key` komen nergens in de log voor
  8. `/docs/api` toont alle `/v1/accounts`, `/v1/connections` en `/v1/snelstart/*`-routes met "Try it out"-knop die werkt met een geplakte Bearer-PAT

**Plans:** 5/5 plans complete

- [x] 05b-01-PLAN.md — pass_through_calls migratie + PassThroughCall-model + factory + ADR (deviatie van `webhook_calls`)
- [x] 05b-02-PLAN.md — HubSnelstartCredentialResolver service + contract-conformance + decryption tests
- [x] 05b-03-PLAN.md — UpstreamErrorMapper + HeaderForwarder support-classes + upstream-error-mapping ADR
- [x] 05b-04-PLAN.md — Provisioning-endpoints (POST /v1/accounts, POST /v1/connections, GET/DELETE /v1/connections/{id}) + Form-Requests + Resources + feature-tests
- [x] 05b-05-PLAN.md — ResolveSnelstartAccount middleware + PassThroughController + catch-all route + audit-write + 6 feature-tests + Scramble discovery test + SanctumAbility-completion

#### Phase 5c: Snelstart webhook-handler

**Goal:** Een werkende ingress voor Snelstart-webhooks op `POST /webhooks/snelstart` met HMAC-verificatie, Connection-resolutie via payload `administratieId`, audit in `pass_through_calls` (`direction=inbound`) en async fan-out via Horizon `webhooks`-queue + Spatie `laravel-webhook-server` naar de Consumer-callback. Productie-certificeringsblocker (Snelstart vereist webhook-URL bij certificeringsaanvraag).
**Depends on:** Phase 5b (`connections` + `pass_through_calls`-tabel), Phase 5a-01 (`consumers.webhook_callback_url` + `consumers.webhook_callback_secret`)
**Requirements:** HUB-06
**Working repo:** `emeq-hub`
**Context:**

- Eén partner-URL `/webhooks/snelstart` voor alle administraties; per-Connection routing via payload `administratieId`-veld (camelCase OData)
- HMAC-secret globaal per AppShortName via `SNELSTART_WEBHOOK_SECRET` env (header-naam + algorithme nog ❓ tot partner-respons; config-driven defensief)
- Anti-correlation: inbound HMAC-secret (Snelstart→Hub) ≠ outbound HMAC-secret (Hub→Consumer via per-Consumer `webhook_callback_secret`)
- Onbekende `administratieId` met geldige HMAC → 200 + audit-row NULL-tenant + geen fan-out (anti-retry-storm)
- Invalid HMAC → 401 + lege body + géén audit-row (anti-amplification)
- `pass_through_calls` krijgt `direction` enum + `event_id` unique-per-provider (idempotency) + nullable tenant-FKs
- Async fan-out via Horizon `webhooks`-queue; Snelstart krijgt 200 in <500ms (niet wachten op Consumer-callback)
- Volledige decisions + ❓-aannames staan in `.planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md`
- Plan-phase wacht op partner-respons (Gmail-draft `r-8836998535038336548` verzonden 2026-05-15; verwacht ≤2026-05-26)

**Success Criteria** (what must be TRUE):

  1. `POST /webhooks/snelstart` met geldige HMAC + bekende `administratieId` → 200 + audit-row `direction=inbound` + `ForwardSnelstartWebhookToConsumerJob` dispatched
  2. Invalid HMAC → 401, lege body, géén audit-row
  3. Onbekende `administratieId` met geldige HMAC → 200 + audit-row met `connection_id=NULL` + geen fan-out
  4. Idempotency: zelfde `event_id` 2× → tweede call = 200 + 1 audit-dup-row + 1 job (originele) — geen dubbele forward
  5. Cross-Consumer-isolation: een webhook voor administratie van Consumer X kan nooit fan-outten naar Consumer Y's callback

**Plans:** 2/5 plans executed

- [x] 05c-01-PLAN.md — Schema-fundatie: pass_through_calls inbound-kolommen + connections.administratie_id + model/factory-updates
- [x] 05c-02-PLAN.md — HMAC-verifier (App\Webhooks\SnelstartSignatureVerifier) + middleware (VerifySnelstartSignature) + alias-registratie + 5 services.snelstart.webhook_*-config-keys
- [ ] 05c-03-PLAN.md — Route + SnelstartWebhookController + tenant-resolve + audit-write
- [ ] 05c-04-PLAN.md — ForwardSnelstartWebhookToConsumerJob + Spatie webhook-server fan-out
- [ ] 05c-05-PLAN.md — Integration-tests (valid + invalid + unknown-administratie + idempotency + cross-Consumer-isolation)

#### Phase 6: Cashier-Mollie integratie (use-case A)

**Goal:** Emeq factureert zijn eigen Consumers (Naschool, Planny) recurring via Emeq's eigen Mollie-account met de Cashier-Mollie pattern.
**Depends on:** Phase 5a (SDK Mandates + Subscriptions wrapping productie-klaar)
**Requirements:** SUB-01
**Working repo:** `emeq-hub` (Consumer-model Billable-trait + plans + Cashier-config); eventueel fork-and-update van `mollie/laravel-cashier-mollie` in eigen vendor-dir of separate fork-repo
**Context:**

- **Blocker-risk uit STATE.md**: `mollie/laravel-cashier-mollie` master hangt op PHP 7.2 / Laravel 6-8 + `mollie/laravel-mollie` ^2.9. Compat-check is plan-1 van deze fase
- Drie paden afhankelijk van compat-check: (a) Cashier werkt out-of-the-box, (b) Cashier werkt met minor patch via composer-bin-plugin / fork, (c) Cashier niet haalbaar → eigen subscription-laag voor use-case A
- `Billable` op `Consumer`-model; subscription-plans (Naschool-license, Planny-license) in DB of config
- Cashier gebruikt `laravel-mollie` (Mollie-facade); naast `EmeqMollie`-facade uit Phase 2 — geen alias-collision per Phase 2 success criterion 3
- Recurring billing via Mandates-flow op Emeq's eigen API-key (geen Connect)

**Success Criteria** (what must be TRUE):

  1. Compat-check van `mollie/laravel-cashier-mollie` tegen PHP 8.4 / Laravel 13 is gedocumenteerd in `.docs/decisions/` met conclusie (werkt / patch nodig / eigen laag)
  2. Een test-Consumer kan een subscription starten op een test-plan en een eerste Mandate + Payment is zichtbaar in Emeq's eigen Mollie test-dashboard
  3. Cashier-billing en Connect-pass-through (Phase 5a) draaien naast elkaar in dezelfde request-cycle zonder credential-cross-contamination tussen Emeq's eigen Mollie-key en Account-Connection-tokens
  4. Een failed-payment (test-mode forced fail) triggert Cashier's retry-flow zonder dat de subscription direct gecancelled wordt

**Plans:** 8 plans (8/8 executed)

- [x] 06-01-PLAN.md — Cashier-Mollie compat-ADR (pad-a gekozen: `mollie/laravel-cashier-mollie ^2.20`)
- [x] 06-02-PLAN.md — Install Cashier-Mollie + publish migrations & configs + env-skeleton
- [x] 06-03-PLAN.md — Billable trait op Consumer + owner_type-align migration + factory-state
- [x] 06-04-PLAN.md — PlanResolver + config/billing-plans.php + UnknownPlanException
- [x] 06-05-PLAN.md — Sanctum billing-abilities + Consumer-read + Admin create/cancel routes + middleware
- [x] 06-06-PLAN.md — Cashier-webhook hard-fail-guard + Cashier::ignoreRoutes + 3 routes onder /cashier/webhook*
- [x] 06-07-PLAN.md — Integration-suite gescheiden via phpunit.integration.xml + 3 happy-path-tests
- [x] 06-08-PLAN.md — BLOCKING phase-acceptance + ROADMAP/REQUIREMENTS/STATE updates (8/8 D-18 items + 3/3 SC's bewezen; ACCEPTED 2026-05-15)

#### Phase 7: Account-level subscriptions (use-case B)

**Goal:** Accounts factureren hun eindgebruikers via hun eigen Mollie-account (via Connect) met een multi-tenant subscription-laag bovenop Mollie's Subscriptions + Mandates API.
**Depends on:** Phase 5a (SDK Mandates + Subscriptions + Connect-webhook verifier). Parallelliseerbaar met Phase 6.
**Requirements:** SUB-02
**Working repo:** `emeq-hub` (`AccountSubscription`-model + service-laag + webhook-handlers)
**Context:**

- Cashier is single-tenant — eigen `AccountSubscription`-model nodig voor multi-tenant state per `Account`+`Connection`
- Service-laag wrapt Mollie Subscriptions + Mandates via SDK uit Phase 2/5
- Webhook-updates van Mollie Connect (uit Phase 5a webhook-pipeline) routeren naar `AccountSubscription`-state-machine
- Edge cases die in tests gedekt moeten: revoked mandate → subscription paused, failed retry → state transition, customer-deleted on Mollie's side → graceful degrade

**Success Criteria** (what must be TRUE):

  1. Een Account kan via Hub-API een `AccountSubscription` aanmaken voor een van zijn eindgebruikers; resulteert in een Mollie Subscription op het eigen Mollie-account van dat Account (via de juiste Connection)
  2. Een Mollie Connect webhook over een mandate-revoke transitioneert de `AccountSubscription` naar `paused` zonder dat Hub direct cancellt
  3. Twee Accounts met elk een eigen `AccountSubscription` op dezelfde test-eindgebruiker (verschillende email) hebben volledig gescheiden state — geen cross-Account-data in queries vanuit Account A
  4. Tests dekken create/cancel/webhook-update happy paths + ≥3 edge cases (revoked mandate, failed retry, deleted customer)

**Plans:** 8/8 plans executed

- [x] 07-01-PLAN.md — Migration + AccountSubscription-model + factory + Account/Connection hasMany-relaties
- [x] 07-02-PLAN.md — SubscriptionStatus-enum + StateTransitions-helper + InvalidStateTransitionException
- [x] 07-03-PLAN.md — AccountSubscriptionManager service (create/cancel/pause/resume/syncFromMollie/recordPaymentEvent) + Idempotency-Key forward + status-cast op model
- [x] 07-04-PLAN.md — CreateAccountSubscriptionRequest + Resource + 3 controllers + 6 routes onder /v1/account-subscriptions + cross-Consumer-scope + ability-gating
- [x] 07-05-PLAN.md — WebhookPayloadRouter + SubscriptionWebhookHandler + PaymentWebhookHandler + MollieWebhookController refactor (D-15/D-18) zonder Phase-5a-regressie (D-31)
- [x] 07-06-PLAN.md — Feature-test-suite (Create/Cancel/PauseResume/List/WebhookFlow/Coexistence) — SC-1+SC-2+SC-3+SC-4 happy + ≥3 edge cases
- [x] 07-07-PLAN.md — Integration-test @group integration + AccountSubscriptionIntegrationTestCase + .env.example MOLLIE_CONNECT_TEST_ACCESS_TOKEN — SC-4 vendor-coverage
- [x] 07-08-PLAN.md — BLOCKING phase-acceptance D-32 11/11 + ADR account-subscriptions + ROADMAP/REQUIREMENTS/STATE sync (PENDING checkpoint-approval 2026-05-15)

#### Phase 8: Naschool wiring (Snelstart + Mollie-via-Hub)

**Goal:** Naschool als eerste concrete Consumer: Snelstart-verkoopfactuur op `EnrollmentConfirmed` + vrijwillige-bijdrage-checkout via Hub-Connect op school A's eigen Mollie-account, end-to-end smoke-getest.
**Depends on:** Phase 5a (Mollie pass-through API + webhook-fan-out), Phase 4 (Connect-broker voor school A's Mollie-koppeling). Snelstart-deel werkt ofwel direct via SDK in Naschool (huidige NSCH-01-aanpak) ofwel via Phase 5b's pass-through (alternatief; te beslissen bij Phase 8-planning). Phase 6/7 niet vereist voor checkout-flow zelf (eindgebruiker doet één betaling, geen subscription).
**Requirements:** NSCH-01, NSCH-02, NSCH-03
**Working repo:** `/Users/yusufkaracaburun/Sites/localhost/school-activities-hub/backend/` (Naschool app, **buiten** deze workspace). Hub-deel kan kleine HUB-3 endpoint-tweaks vergen in `emeq-hub`.
**Context:**

- NSCH-01: composer-wiring naar publieke VCS-repos `emeq/snelstart-api` + `emeq/mollie-api`. Snelstart-deel: `StancltenancyCredentialResolver` in `backend/app/Services/Snelstart/`, gebonden in `AppServiceProvider`. Mollie-deel werkt **niet** via Stancl-resolver maar via Hub (zie NSCH-03)
- NSCH-02: `SyncEnrollmentToSnelstartJob` als listener op `EnrollmentConfirmed`; maakt verkoopfactuur in Snelstart test-env; smoke op `php artisan migrate:fresh --seed` (school1 demo-seed)
- NSCH-03: Mollie checkout-flow op één activiteit met vrijwillige bijdrage. Naschool POSTs naar Hub `/v1/mollie/payments` met Consumer-PAT + Account-id (school A) → Hub resolved Connection.access_token van school A → checkout op school A's eigen Mollie → URL terug → ouder doorloopt → webhook signature-verified door Hub → pass-through fan-out naar Naschool's callback → enrollment-status update
- NSCH-01+NSCH-02 (Snelstart-pad) en NSCH-03 (Mollie-via-Hub-pad) zijn onafhankelijk; kunnen parallel in deze fase, maar samen de Naschool-end-state vormen
- School1 demo-seed moet realistische test-data hebben: één school, één Connection naar test-Mollie, één activiteit met vrijwillige bijdrage

**Success Criteria** (what must be TRUE):

  1. `composer install` in `school-activities-hub/backend/` resolved `emeq/snelstart-api` en `emeq/mollie-api` via publieke VCS zonder GitHub-auth-token
  2. `EnrollmentConfirmed`-event in Naschool dispatched de job; verkoopfactuur is daarna zichtbaar in Snelstart test-omgeving (UI of API-GET) voor school1 demo-seed
  3. Een ouder kan op school A's activiteit met vrijwillige bijdrage een Mollie test-betaling doorlopen die op school A's eigen Mollie test-dashboard verschijnt (niet op Emeq's eigen Mollie)
  4. Na webhook-bevestiging van Mollie → Hub → Naschool-callback is de enrollment-status in Naschool geüpdatet naar `paid` zonder handmatige interventie
  5. End-to-end smoke (handmatig doorlopen) is gedocumenteerd in `.docs/` of vergelijkbaar locatie in Naschool-repo

**Plans:** 5/5 plans complete

- [x] 08-01-PLAN.md — ConsumerOnboarding service (atomic DB::transaction multi-model create) + HubConsumerCreate artisan-command refactor naar service-delegate (Wave 1)
- [x] 08-02-PLAN.md — Filament OnboardConsumer Page met 4-staps Wizard (Consumer → Account → Connection → PAT) + ListConsumers header-action + RBAC + no-secret-leak tests (Wave 2)
- [x] 08-03-PLAN.md — Shared StartOAuthFlowAction (forAccount + forConnection, descriptor-driven) + mount op ConnectionResource + AccountResource (Wave 1)
- [x] 08-04-PLAN.md — ConsumerInfolist hint-Section + ViewConsumer-page + AccountInfolist hint-extension + Tenants-navgroup-tooltip (Wave 1)
- [x] 08-05-PLAN.md — PartnerStatus service + domeinmodel/status-widget Blade-partials + /dev/partners pages (index + mollie + snelstart) uitbreiden (Wave 2)

**UI hint:** yes

#### Phase 9: Filament admin-UI voor Emeq-medewerkers

**Goal:** Een intern Filament v4 admin-paneel op `/admin` waarmee Emeq-medewerkers 7 resources (Consumers + Connections + Accounts + WebhookCalls + AccountSubscriptions + Cashier-Subscriptions + Users) kunnen beheren zonder tinker — met de Hub-invariant dat raw tokens nooit in de UI verschijnen.
**Depends on:** Phase 3 (Hub-skeleton — `Consumer`/`Account`/`Connection` modellen + Sanctum-PAT), Phase 4 (`OAuthFlow`-contract voor upstream revoke). Parallelliseerbaar met Phase 6/7. Blokkeert Phase 8 niet.
**Requirements:** HUB-04
**Working repo:** `emeq-hub` (deze repo)
**Context:**

- Stack: Filament v4 als PHP-only admin-paneel (Livewire onder de motorkap); past bij API-first Hub, geen aparte SPA/Inertia-laag nodig
- Eigen panel-auth via Filament's ingebouwde login op `/admin/login` — géén Fortify, géén Sanctum SPA-tokens
- `User`-model krijgt `implements FilamentUser` + `canAccessPanel()` check op nieuwe `is_emeq_staff` boolean (default false, niet fillable, alleen via seeder/command)
- 4 resources met scope:
  - `ConsumerResource`: CRUD + table-actions `Issue PAT` (modal → Sanctum `createToken()` → plain-token éénmalig in `Notification`) en `Revoke token`
  - `ConnectionResource`: read + revoke action via `OAuthFlow::revoke()`; toont alleen `access_token_fingerprint` (computed accessor `sha256(decrypted)[0..12]`) — raw `access_token` / `refresh_token` velden komen nooit in form/table
  - `AccountResource`: read-only met `connections_count` via `withCount()`
  - `WebhookCallResource`: read-only viewer met collapsible JSON-payload, status-badge, filters op `direction`/`provider`/date-range
- Tailwind v4 al in de stack — Filament v4 brengt eigen Vite-asset-build via `php artisan filament:assets`
- Seeder `EmeqStaffSeeder` leest `EMEQ_STAFF_SEED_EMAIL` + `EMEQ_STAFF_SEED_PASSWORD` uit env — geen hardcoded creds
- `webhook_calls`-tabel bestaat al via `spatie/laravel-webhook-client`; `direction`-kolom (incoming/outgoing) komt in een Phase 5a- of 5b-aanvullende migratie (afhankelijk van welke fase audit-logging als eerste landt)
- Plan-bron: `.claude/plans/ow-dit-wil-ik-immutable-snowglobe.md` (goedgekeurd 2026-05-14)

**Success Criteria** (what must be TRUE):

  1. Een geseede staff-user kan inloggen op `/admin` en zien Consumers/Connections/Accounts/WebhookCalls in 4 aparte resource-lijsten
  2. Een non-staff `User` (waar `is_emeq_staff = false`) krijgt 403 op `/admin` — `canAccessPanel()` blokkeert
  3. `ConsumerResource` issue-PAT-action retourneert plain-text token in een notification (éénmalig zichtbaar) + maakt een rij in `personal_access_tokens`
  4. `ConnectionResource` toont alleen fingerprints (sha256[0..12]) — een feature-test asserteert dat de plain-text `access_token` waarde nooit in de HTML-respons van `livewire(ListConnections::class)` voorkomt
  5. `ConnectionResource` revoke-action roept `OAuthFlow::revoke($connection)` aan (uit Phase 4-contract) en zet `revoked_at` — niet alleen een DB-flag zonder upstream revoke

**Out of scope (geparkeerd):**

- Multi-rol RBAC (alleen `is_emeq_staff` boolean — Spatie-permission pas als meer rollen ontstaan)
- Consumer self-service dashboard (`/portal` met eigen creds → v1.0+, React+shadcn op aparte panel-route)
- E-mail notificaties uit Filament
- 2FA/MFA voor admin login
- Audit-log via `spatie/laravel-activitylog` (geparkeerd als `HUB-AUDIT` backlog-item)
- Tailwind-thema-customizing — default Filament-look is goed genoeg voor intern gebruik

**Plans:** 11/11 plans complete

- [x] 09-01-PLAN.md — webhook_calls audit-kolommen-migratie (D-02)
- [x] 09-02-PLAN.md — Filament v4 + Spatie laravel-permission install + AdminPanelProvider
- [x] 09-03-PLAN.md — User-model (HasRoles + FilamentUser + canAccessPanel) + EmeqStaffSeeder
- [x] 09-04-PLAN.md — ProviderCredentialDescriptor + Connection::fingerprint() refactor (D-04)
- [x] 09-05-PLAN.md — ConsumerResource CRUD + Issue-PAT-action (D-03 presets)
- [x] 09-06-PLAN.md — ConnectionResource read + revoke + no-secret-leak tests
- [x] 09-07-PLAN.md — AccountResource + WebhookCallResource (read-only viewers)
- [x] 09-08-PLAN.md — AccountSubscriptionResource read + Pause/Resume/Cancel (manager-only)
- [x] 09-09-PLAN.md — Cashier\\SubscriptionResource read-only met derived-status
- [x] 09-10-PLAN.md — UserResource super-admin-gated + manage-staff gate (D-05)
- [x] 09-11-PLAN.md — Phase-acceptance + ADR + ROADMAP/REQUIREMENTS/STATE sync

**UI hint:** yes

### Progress

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 2. emeq/mollie-api foundation | 0/8 | Not started | - |
| 3. Hub-skeleton | 3/5 | In Progress|  |
| 4. Mollie Connect OAuth-broker | 0/0 (TBD) | Not started | - |
| 5a. Mollie SDK Resources + Webhooks + Pass-through API | 0/5 | Planned | - |
| 5b. Snelstart-pass-through API | 5/5 | Complete    | 2026-05-16 |
| 5c. Snelstart webhook-handler | 2/5 | In Progress|  |
| 6. Cashier-Mollie integratie | 8/8 | Done | 2026-05-15 |
| 7. Account-level subscriptions | 8/8 | Done | 2026-05-15 |
| 8. Naschool wiring | 5/5 | Complete    | 2026-05-17 |
| 9. Filament admin-UI voor Emeq-medewerkers | 11/11 | Complete    | 2026-05-16 |
| 10. Phase 9 polish — deferred review-findings | 6/6 | Complete    | 2026-05-16 |

### Coverage

| Requirement | Phase | Why this phase |
|-------------|-------|----------------|
| MOLL-01 | Phase 2 | SDK foundation is dependency voor alles wat erop volgt |
| MOLL-02 | Phase 4 | OAuth-broker scope; samen met HUB-02 één coherent pakket |
| MOLL-03 | Phase 5a | Alle 6 Mollie-resources delen wrapping-pattern; samen geleverd |
| MOLL-04 | Phase 5a | Webhook-verifier is randvoorwaarde voor Mollie-pass-through fan-out |
| HUB-01 | Phase 3 | Skeleton is dependency voor alles wat naar `connections` schrijft |
| HUB-02 | Phase 4 | `OAuthFlow`-contract komt logisch samen met eerste Mollie-implementatie |
| HUB-03 | Phase 5a | Mollie-pass-through API kan pas live na SDK Resources + webhook-verifier |
| HUB-05 | Phase 5b | Snelstart-pass-through is los van Mollie-OAuth; parallel met Phase 4 mogelijk; eerste end-to-end pass-through-test |
| HUB-06 | Phase 5c | Snelstart webhook-ingress is productie-certificeringsblocker; los van pass-through (5b) |
| SUB-01 | Phase 6 | Cashier-Mollie heeft SDK Mandates + Subscriptions uit Phase 5a nodig |
| SUB-02 | Phase 7 | Account-subs hebben SDK Subscriptions + Connect-webhooks uit Phase 5a nodig |
| NSCH-01 | Phase 8 | Naschool-consumerwerk geclusterd in één wiring-fase |
| NSCH-02 | Phase 8 | Snelstart-job is onafhankelijk van Mollie maar samen Naschool-eindstand |
| NSCH-03 | Phase 8 | End-to-end smoke vereist Hub-fasen 3 + 4 + 5a live |
| HUB-04 | Phase 9 | Admin-paneel leunt op `Consumer`/`Account`/`Connection` modellen (Phase 3) + `OAuthFlow::revoke()` (Phase 4); parallel met Phase 6/7 |

**Coverage:** 15/15 v1-requirements gemapped naar exact één fase. Geen orphans.

## Backlog (v0.3+)

Verzamelpunt voor ideeën die nog geen milestone hebben. Bij milestone-kickoff worden relevante items uit deze sectie naar de active milestone gepromoveerd.

- Snelstart Saloon v3 → v4 upgrade (3 ignored security advisories oplossen, o.a. SSRF via endpoint-override)
- Andere providers wanneer Mollie+Snelstart in productie gevalideerd: `emeq/moneybird-api`, `emeq/exact-api`, `emeq/ibanity-api`, `emeq/stripe-api`, `emeq/bizcuit-api`
- **`emeq/bizcuit-api`**: Bizcuit SDK (NL boekhouden/banking) — OpenAPI docs op https://app.bizcuit.nl/openapi/documentation/getting-started.html. Volgt SDK-pattern uit `packages/`-conventie (eigen VCS-repo, dunne Saloon-laag of officiële-SDK-wrap analoog aan Mollie-pad). Trigger: zodra een host-app Bizcuit-integratie nodig heeft. User-captured 2026-05-17.
- OAuth Connect-implementaties voor providers die in v0.2 alleen contract-level zijn gedekt (Snelstart-OAuth, Exact-OAuth, Ibanity-OAuth)
- DTO-codegen vanuit OpenAPI specs voor providers die typed-response consumers nodig hebben
- Hub commerciële features: public billing-flow voor derde-partij Consumers (`HUB-BILLING`), public docs-site `docs.hub.emeq.nl` (`HUB-DOCS`), self-service onboarding (`HUB-ONBOARDING`)
- `HUB-AUDIT`: admin-acties audit-log via `spatie/laravel-activitylog` (Phase 9 admin-paneel out-of-scope) — pas als compliance of incident-respons het vereist
- **`MOLL-CONNECT-RES`**: Mollie Connect partner-resources via pass-through (Onboarding-status, Organizations, Profiles, Permissions, ClientLinks) — pad onbekend in v0.2, maar **blokkerend voor host-app productie-go-live** wanneer een Connect-merchant via de Hub moet onboarden. Volgt hetzelfde pass-through-pattern als Phase 5a (zie ADR `mollie-passthrough-api.md`). Promote naar active milestone zodra een host-app dit nodig heeft.
- **`SCRAMBLE-NESTED-GROUPS`**: Echte hiërarchische groepering in `/docs/api`. v0.2 gebruikt platte per-resource groepen met `Mollie · {Resource}`-prefix omdat Scramble v0.13 + Stoplight Elements 8.4 geen native nesting hebben (Elements honoreert `x-tagGroups` niet). Werkt nu voor 2 SDK's, maar bij 5+ providers wordt de sidebar lang en onoverzichtelijk. Pad: (1) tags blijven per-resource via `#[Group]`; (2) custom middleware op `docs/api.json` injecteert `x-tagGroups` post-serialisatie; (3) `docs.blade.php` overgezet van Stoplight Elements naar Redoc (honoreert `x-tagGroups` native). Trigger: zodra Moneybird/Exact/Ibanity erbij komen of de Mollie-resource-lijst groeit voorbij ~10 endpoints per resource.
- **`BRAIN-AUDIT-CI`**: `laramint/laravel-brain` promoveren tot dev-dep en `bin/audit-pennant-gates.php` activeren als blokkerende CI-check. Vandaag (2 providers) zit het audit-script al in de repo en runt standalone tegen Brain's JSON-output — Brain zelf nog niet geïnstalleerd; install-recept staat in `.docs/stack/architecture-audit.md`. Spike-validatie op 2026-05-17 toonde 21/21 SDK-routes met correcte `feature.provider:{provider}` gate. Trigger: (1) 3e SDK toegevoegd (Moneybird/Ibanity/Exact) — dan worden audit-checks non-optional, OF (2) 2e dev op de repo — dan wordt graph-onboarding waardevol, OF (3) v1.0+ commercieel — derde-partij dev-shops hebben graph + AI-context-export nodig. Bij promotion: `composer require --dev laramint/laravel-brain`, `php artisan brain:scan` in `composer install` post-hook, audit-script in CI met exit-code-gate, manifest-pad in `storage/app/laravel-brain/` gitignoren. **Niet** `brain:generate-rules --target=claude` runnen — `CLAUDE.md` is authored guidance, geen scan-output; gebruik `--target=agents` voor een aanvullende `AGENTS.md`.

#### Phase 10: Phase 9 polish — deferred review-findings

**Goal:** Sluit 11 deferred bevindingen uit `09-REVIEW.md` af (1 BLOCKER-class CR-02, 6 warnings, 4 info) zodat Phase 9 daadwerkelijk ship-quality is en HUB-04 SC-7 cross-Consumer-isolatie test-bewezen wordt.
**Depends on:** Phase 9
**Requirements:** HUB-04 SC-7 (cross-Consumer-isolation in WebhookCallResource)
**Working repo:** `emeq-hub` (Filament-resources + Hub-eigen `App\Models\WebhookCall` + tests + seeders)
**Context:**

- Volledige scope: alle items uit `.planning/phases/09-filament-admin-ui-voor-emeq-medewerkers/09-REVIEW.md` behalve CR-01 (al gefixt in commit `7f86c6d`).
- **CR-02 (BLOCKER-class)**: 6 resources missen `canAccess()` ondanks dat `EmeqStaffSeeder` permissions provisioneert (`view-webhooks`, `view-account-subscriptions`, `view-billing`, `manage-consumers`, `manage-connections`). D-05 permission-model is dead code totdat dit landt. SC-7 cross-Consumer-isolatie nooit getest in `WebhookCallResource`.
- **WR-01..06**: last-super-admin downgrade-/delete-guard, `WebhookCallInfolist` exception dubbel-encoded, `assignRole` server-side `->in()`-validatie, `EmeqStaffSeeder` silent-password-non-reset, `UserForm` edit-zonder-password regressie-test, plain PAT-token in Livewire `wire:snapshot`.
- **IN-01..04**: N+1 op `Consumer::find()` per webhook-rij (lost samen met CR-02 op via Hub-eigen `WebhookCall extends Spatie's class`), `AccountSubscriptionResource::cancelAction` exception-message-leak, `AdminPanelProvider::default()`-footgun-comment, `ProviderCredentialDescriptor::tryFor()`-helper.

**Success Criteria** (what must be TRUE):

  1. Alle 6 in-scope Filament-resources (`Consumer`/`Connection`/`Account`/`WebhookCall`/`AccountSubscription`/`CashierSubscription`) hebben `canAccess()` die de bijbehorende Spatie-permission consulteert; navigatie-items verschijnen niet zonder permission.
  2. Hub-eigen `App\Models\WebhookCall extends Spatie\WebhookClient\Models\WebhookCall` met `consumer()` belongs-to bestaat; `WebhookCallResource` eager-loadt via `->modifyQueryUsing(fn ($q) => $q->with('consumer'))`; geen `Consumer::find()` meer in tabel of infolist.
  3. `WebhookCallResourceTest::test_cross_consumer_isolation_*` bewijst dat een staff-user met alleen `view-webhooks`-permission geen webhooks van andere Consumers ziet (HUB-04 SC-7 closure).
  4. `UsersTable` `assignRole`-action + `EditUser` `DeleteAction` blokkeren (a) self-downgrade door current super-admin en (b) downgrade/delete van de laatste super-admin; 2 nieuwe regression-tests bewijzen beide paden.
  5. `WebhookCallInfolist` rendert `exception`-veld niet meer via `json_encode()` (zichtbaar als multiline plain text).
  6. `assignRole`-Select heeft `->in(['super-admin','staff'])` + try/catch met user-friendly notification op `RoleDoesNotExist`.
  7. `EmeqStaffSeeder` reset password van bestaande user (of hard-failed met expliciete error) — `EmeqStaffSeederTest` dekt het pad.
  8. `UserResourceTest::test_edit_user_without_password_keeps_existing_hash` is groen.
  9. Plain PAT-token zit niet meer in `wire:snapshot`/Alpine `x-data`; gebruikt `Cache::pull()` one-shot pattern.
  10. `AccountSubscriptionResource::cancelAction` heeft try/catch dat `report($e)` doet en generieke notification toont met sha256-fingerprint.
  11. `ProviderCredentialDescriptor::tryFor()` bestaat; `Connection::fingerprint()` gebruikt het in plaats van inline try/catch.
  12. Volledige test-suite groen (`php artisan test --compact`) — minimaal 389 + nieuwe-tests passing.

**Plans:** 6/6 plans complete

- [x] 10-01-PLAN.md — Hub-eigen `App\Models\WebhookCall` + `consumer()` belongs-to + `config/webhook-client.php` model-binding (wave 1)
- [x] 10-02-PLAN.md — `ProviderCredentialDescriptor::tryFor()` helper + `Connection::fingerprint()` refactor (IN-04 / D-11) (wave 1)
- [x] 10-03-PLAN.md — `canAccess()` + `shouldRegisterNavigation()` op 6 niet-User-Resources (CR-02 hoofd-fix / D-1) (wave 2)
- [x] 10-04-PLAN.md — `WebhookCallsTable` + `WebhookCallInfolist` consumer-relatie + exception unwrap (WR-02 + IN-01 / D-5) (wave 2)
- [x] 10-05-PLAN.md — User-guards (last-super-admin) + Select `->in()` + Seeder hard-fail + cancelAction fingerprint + AdminPanelProvider comment (WR-01/03/04 + IN-02/03 / D-4/6/7/10) (wave 3)
- [x] 10-06-PLAN.md — PAT Cache-flash (WR-06 / D-9) + edit-zonder-password regression (WR-05 / D-8) + HUB-04 SC-7 closure (D-3) (wave 4)

---

*Roadmap defined: 2026-05-14. v0.2 active milestone (Phase 9 added 2026-05-14; Phase 5 gesplitst in 5a/5b en HUB-05 toegevoegd 2026-05-14 om Snelstart-pass-through los van Mollie-OAuth te kunnen leveren). v0.1 archived in `.planning/milestones/`.*
