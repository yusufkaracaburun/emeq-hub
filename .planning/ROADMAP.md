# Roadmap: Emeq integration stack

**Project code:** EMEQ
**Granularity:** ~7 phases voor v0.2 (standard band, requirements-driven)
**Execution:** sequentieel — Phase 6 en Phase 7 zijn parallelliseerbaar (beide afhankelijk van Phase 5)

## Shipped Milestones

- **v0.1 (2026-05-14)** — Snelstart-SDK finale. Zie [`milestones/v0.1-ROADMAP.md`](milestones/v0.1-ROADMAP.md) · [`MILESTONES.md`](MILESTONES.md)

## Active Milestone: v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton

**Defined:** 2026-05-14
**Indicatie:** ~8-10 weken vanaf kickoff
**Master-plan:** [`.claude/plans/fancy-honking-spring.md`](../.claude/plans/fancy-honking-spring.md)

### Overview

v0.2 bouwt drie samenhangende lagen: (1) `emeq/mollie-api` SDK die `mollie/mollie-api-php` wrapt met multi-tenant credential-resolution + dual creds (API-key en OAuth), (2) Hub-skeleton met `consumers`/`accounts`/`connections`-tabellen, Sanctum-PAT-auth, Mollie Connect OAuth-broker en pass-through `/v1/mollie/*`-API, en (3) twee subscription-use-cases (Cashier-Mollie voor Emeq→Consumers + eigen subscription-laag voor Accounts→eindgebruikers via Connect). Sluit af met Naschool als eerste concrete consumer: Snelstart-verkoopfactuur op `EnrollmentConfirmed` + vrijwillige-bijdrage-checkout via Hub-Connect op school A's eigen Mollie-account.

### Phases

- [ ] **Phase 2: emeq/mollie-api foundation** — SDK skeleton + multi-tenant resolver + dual creds + Pest-suite groen
- [ ] **Phase 3: Hub-skeleton** — `consumers`/`accounts`/`connections`-tabellen + Sanctum-PAT-auth + Consumer-routing
- [ ] **Phase 4: Mollie Connect OAuth-broker** — provider-agnostisch `OAuthFlow`-contract + `MollieConnectOAuthFlow` + encrypted token-storage
- [ ] **Phase 5: SDK Resources + Webhooks + Pass-through API** — Payments/Customers/PaymentMethods/Refunds/Mandates/Subscriptions + Connect-webhook verifier + `/v1/mollie/*` audit-logged
- [ ] **Phase 6: Cashier-Mollie integratie (use-case A)** — Emeq → Consumers billing op Emeq's eigen Mollie
- [ ] **Phase 7: Account-level subscriptions (use-case B)** — Accounts → eindgebruikers via Connect + Mandates + Subscriptions
- [ ] **Phase 8: Naschool wiring** — composer-wiring + Snelstart Stancl-resolver + `SyncEnrollmentToSnelstartJob` + Mollie checkout-flow via Hub-Connect

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
**Goal:** Een werkende Hub-app met multi-tenant data-model en Consumer-authenticatie waarop de OAuth-broker en pass-through API in latere fasen kunnen landen.
**Depends on:** Nothing (parallel met Phase 2 mogelijk, maar in praktijk sequential na Phase 2)
**Requirements:** HUB-01
**Working repo:** `emeq-hub` (deze repo)
**Context:**
- `consumers`-tabel: SaaS-app-registraties (Naschool, Planny, derde-partijen) — `id`, `name`, `slug`, timestamps
- `accounts`-tabel: klanten van die SaaS-apps (school A, vereniging C) — `consumer_id` + `external_id` uniek samen, `display_name`
- `connections`-tabel: per-provider credentials per Account — `account_id`, `provider`, `access_token` (encrypted), `refresh_token` (encrypted), `expires_at`, `scopes` JSON, `metadata` JSON
- Sanctum-PAT voor Consumer-auth; Consumers krijgen Personal Access Tokens met provider-scope-abilities
- Migrations forward-only (PROJECT.md invariant); geen `down()` in prod-pad
- Eloquent `encrypted` cast op `access_token`, `refresh_token`, en — voor toekomstige providers — `client_key`
**Success Criteria** (what must be TRUE):
  1. `php artisan migrate:fresh --seed` levert demo-Consumer ("naschool"), demo-Account (school1) en lege `connections`-tabel
  2. Een Consumer kan een Sanctum-PAT verkrijgen en authenticeren tegen een `/v1/ping`-smoke-endpoint met `Authorization: Bearer …`
  3. Een Connection bewaard met test-token toont nooit raw token in `php artisan tinker` `->toArray()` output zonder expliciete decrypt-call
  4. Cross-Consumer query-poging (Consumer A's PAT → Account van Consumer B) faalt met 403/404 voor route-level scoping check
**Plans:** TBD

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
  1. Een Account kan via `GET /v1/oauth/mollie/authorize?account=…` doorlinken naar Mollie's authorization-URL met juiste `client_id`, `state`, en `redirect_uri`
  2. Callback op `/v1/oauth/mollie/callback?code=…&state=…` ruilt authorization-code in voor `access_token` + `refresh_token` en bewaart die encrypted op de juiste Connection
  3. Een Connection met `expires_at` < 5 minuten triggert automatische refresh (`refreshToken()`) en updatet `access_token`/`expires_at` zonder dat de pass-through-API een 401 ziet
  4. `OAuthFlow`-contract heeft een tweede dummy-implementatie (test-fixture, niet productie) die laat zien dat het pattern niet Mollie-specifiek is
  5. Tampered `state`-parameter (CSRF-check) wordt afgewezen met 400
**Plans:** TBD

#### Phase 5: SDK Resources + Webhooks + Pass-through API
**Goal:** Een werkende end-to-end pass-through: Consumer doet HTTP-call naar Hub, Hub resolved Connection, SDK doet Mollie-call, response stroomt terug — voor alle 6 in-scope resources, inclusief inkomende webhook-verificatie.
**Depends on:** Phase 2 (SDK foundation), Phase 3 (Hub-skeleton tabellen), Phase 4 (OAuth-broker resolved access_token)
**Requirements:** MOLL-03, MOLL-04, HUB-03
**Working repo:** `packages/mollie-api/` (Resources + DTOs + WebhookVerifier) + `emeq-hub` (`/v1/mollie/*` controllers + audit-log)
**Context:**
- MOLL-03 Resources: Payments (create/read/cancel), Customers (read/create), PaymentMethods (list), Refunds (create/read), Mandates (list/get/revoke), Subscriptions (create/read/cancel) — alle 6 in één fase omdat ze dezelfde wrapping-pattern delen
- `Idempotency-Key` auto-injectie op writes via Mollie's `IdempotencyKeyGeneratorContract`
- MOLL-04: `MollieWebhookVerifier` met HMAC-SHA256 (`Mollie-Signature` header) namens platform-secret; Connect-flow betekent platform-signed (niet per-Connection-signed)
- HUB-03: pass-through `/v1/mollie/*` met Bearer Consumer-PAT → Account → Connection.access_token → SDK-call; audit in `webhook_calls`-tabel (inkomend Consumer-request + uitgaand Mollie-call); fan-out via `spatie/laravel-webhook-client` queueable
- `dedoc/scramble` genereert OpenAPI op `/docs/api`
- Mollie-docs in `.docs/partners/mollie/` moeten gelinkt staan voor elk endpoint dat geïmplementeerd wordt (geen verzonnen velden)
**Success Criteria** (what must be TRUE):
  1. Een Consumer kan `POST /v1/mollie/payments` doen met Bearer PAT + Account-ID en krijgt een Mollie-checkout-URL terug die door Mollie als test-mode geldig wordt geaccepteerd
  2. Alle 6 resources (Payments, Customers, PaymentMethods, Refunds, Mandates, Subscriptions) zijn callable via pass-through API en hun pad in `/docs/api` OpenAPI-spec
  3. Een inkomende Mollie Connect-webhook met geldige `Mollie-Signature` wordt geaccepteerd en gerouteerd; tampered signature retourneert 400 en wordt niet doorgegeven aan Consumer-callback
  4. Elke pass-through-call schrijft één regel in `webhook_calls` met Consumer-ID, Account-ID, Connection-ID-fingerprint, request-summary en response-status
  5. Twee identieke `POST /v1/mollie/payments` met dezelfde idempotency-key retourneren één Mollie-payment-ID (geen duplicate)
**Plans:** TBD

#### Phase 6: Cashier-Mollie integratie (use-case A)
**Goal:** Emeq factureert zijn eigen Consumers (Naschool, Planny) recurring via Emeq's eigen Mollie-account met de Cashier-Mollie pattern.
**Depends on:** Phase 5 (SDK Mandates + Subscriptions wrapping productie-klaar)
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
  3. Cashier-billing en Connect-pass-through (Phase 5) draaien naast elkaar in dezelfde request-cycle zonder credential-cross-contamination tussen Emeq's eigen Mollie-key en Account-Connection-tokens
  4. Een failed-payment (test-mode forced fail) triggert Cashier's retry-flow zonder dat de subscription direct gecancelled wordt
**Plans:** TBD

#### Phase 7: Account-level subscriptions (use-case B)
**Goal:** Accounts factureren hun eindgebruikers via hun eigen Mollie-account (via Connect) met een multi-tenant subscription-laag bovenop Mollie's Subscriptions + Mandates API.
**Depends on:** Phase 5 (SDK Mandates + Subscriptions + Connect-webhook verifier). Parallelliseerbaar met Phase 6.
**Requirements:** SUB-02
**Working repo:** `emeq-hub` (`AccountSubscription`-model + service-laag + webhook-handlers)
**Context:**
- Cashier is single-tenant — eigen `AccountSubscription`-model nodig voor multi-tenant state per `Account`+`Connection`
- Service-laag wrapt Mollie Subscriptions + Mandates via SDK uit Phase 2/5
- Webhook-updates van Mollie Connect (uit Phase 5 webhook-pipeline) routeren naar `AccountSubscription`-state-machine
- Edge cases die in tests gedekt moeten: revoked mandate → subscription paused, failed retry → state transition, customer-deleted on Mollie's side → graceful degrade
**Success Criteria** (what must be TRUE):
  1. Een Account kan via Hub-API een `AccountSubscription` aanmaken voor een van zijn eindgebruikers; resulteert in een Mollie Subscription op het eigen Mollie-account van dat Account (via de juiste Connection)
  2. Een Mollie Connect webhook over een mandate-revoke transitioneert de `AccountSubscription` naar `paused` zonder dat Hub direct cancellt
  3. Twee Accounts met elk een eigen `AccountSubscription` op dezelfde test-eindgebruiker (verschillende email) hebben volledig gescheiden state — geen cross-Account-data in queries vanuit Account A
  4. Tests dekken create/cancel/webhook-update happy paths + ≥3 edge cases (revoked mandate, failed retry, deleted customer)
**Plans:** TBD

#### Phase 8: Naschool wiring (Snelstart + Mollie-via-Hub)
**Goal:** Naschool als eerste concrete Consumer: Snelstart-verkoopfactuur op `EnrollmentConfirmed` + vrijwillige-bijdrage-checkout via Hub-Connect op school A's eigen Mollie-account, end-to-end smoke-getest.
**Depends on:** Phase 5 (pass-through API + webhook-fan-out), Phase 4 (Connect-broker voor school A's Mollie-koppeling). Phase 6/7 niet vereist voor checkout-flow zelf (eindgebruiker doet één betaling, geen subscription).
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
**Plans:** TBD
**UI hint:** yes

### Progress

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 2. emeq/mollie-api foundation | 0/8 | Not started | - |
| 3. Hub-skeleton | 0/0 (TBD) | Not started | - |
| 4. Mollie Connect OAuth-broker | 0/0 (TBD) | Not started | - |
| 5. SDK Resources + Webhooks + Pass-through API | 0/0 (TBD) | Not started | - |
| 6. Cashier-Mollie integratie | 0/0 (TBD) | Not started | - |
| 7. Account-level subscriptions | 0/0 (TBD) | Not started | - |
| 8. Naschool wiring | 0/0 (TBD) | Not started | - |

### Coverage

| Requirement | Phase | Why this phase |
|-------------|-------|----------------|
| MOLL-01 | Phase 2 | SDK foundation is dependency voor alles wat erop volgt |
| MOLL-02 | Phase 4 | OAuth-broker scope; samen met HUB-02 één coherent pakket |
| MOLL-03 | Phase 5 | Alle 6 resources delen wrapping-pattern; samen geleverd |
| MOLL-04 | Phase 5 | Webhook-verifier is randvoorwaarde voor pass-through fan-out |
| HUB-01 | Phase 3 | Skeleton is dependency voor alles wat naar `connections` schrijft |
| HUB-02 | Phase 4 | `OAuthFlow`-contract komt logisch samen met eerste Mollie-implementatie |
| HUB-03 | Phase 5 | Pass-through API kan pas live na SDK Resources + webhook-verifier |
| SUB-01 | Phase 6 | Cashier-Mollie heeft SDK Mandates + Subscriptions uit Phase 5 nodig |
| SUB-02 | Phase 7 | Account-subs hebben SDK Subscriptions + Connect-webhooks uit Phase 5 nodig |
| NSCH-01 | Phase 8 | Naschool-consumerwerk geclusterd in één wiring-fase |
| NSCH-02 | Phase 8 | Snelstart-job is onafhankelijk van Mollie maar samen Naschool-eindstand |
| NSCH-03 | Phase 8 | End-to-end smoke vereist alle Hub-fasen 2-5 live |

**Coverage:** 12/12 v1-requirements gemapped naar exact één fase. Geen orphans.

## Backlog (v0.3+)

Verzamelpunt voor ideeën die nog geen milestone hebben. Bij milestone-kickoff worden relevante items uit deze sectie naar de active milestone gepromoveerd.

- Snelstart Saloon v3 → v4 upgrade (3 ignored security advisories oplossen, o.a. SSRF via endpoint-override)
- Andere providers wanneer Mollie+Snelstart in productie gevalideerd: `emeq/moneybird-api`, `emeq/exact-api`, `emeq/ibanity-api`, `emeq/stripe-api`
- OAuth Connect-implementaties voor providers die in v0.2 alleen contract-level zijn gedekt (Snelstart-OAuth, Exact-OAuth, Ibanity-OAuth)
- DTO-codegen vanuit OpenAPI specs voor providers die typed-response consumers nodig hebben
- Hub commerciële features: public billing-flow voor derde-partij Consumers (`HUB-BILLING`), public docs-site `docs.hub.emeq.nl` (`HUB-DOCS`), self-service onboarding (`HUB-ONBOARDING`)

---

*Roadmap defined: 2026-05-14. v0.2 active milestone. v0.1 archived in `.planning/milestones/`.*
