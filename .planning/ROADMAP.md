# Roadmap: Emeq integration stack (v0.1)

**Project code:** EMEQ-V01
**Defined:** 2026-05-14
**Granularity:** coarse (5 phases — boven de standaard 3-5 bovenkant, gerechtvaardigd doordat fases 4+5 in een derde repo werken)
**Execution:** sequentieel (config.parallelization=false)

## Overview

Twee Saloon-gebaseerde Laravel SDK-packages (`emeq/snelstart-api`, `emeq/mollie-api`) afronden en beide live krijgen in Naschool voor één concrete feature elk. Phase 1 stabiliseert de bestaande Snelstart-SDK (Pest-crash + push naar upstream). Phases 2 en 3 bouwen de Mollie-SDK volgens hetzelfde pattern (skeleton + auth + connector, dan resources + webhook). Phases 4 en 5 wiren beide SDKs in de Naschool-backend (buiten deze workspace) met respectievelijk de Snelstart-verkoopfactuur-flow en de Mollie-checkout-flow. Phase N+1 start pas als N's success criteria gehaald zijn.

## Phases

**Phase Numbering:**
- Integer phases (1-5): geplande v0.1-mijlpaal-werk
- Decimal phases (X.Y): gereserveerd voor urgent ingevoegde fixes (nog geen)

- [ ] **Phase 1: Snelstart-SDK finalize** — Pest-crash debuggen, tests groen, push naar GitHub met upstream-tracking
- [ ] **Phase 2: Mollie-SDK foundation** — Nieuwe repo aanmaken, skeleton + ServiceProvider + auth + connector
- [ ] **Phase 3: Mollie-SDK resources + webhooks** — Payments/Customers/PaymentMethods/Refunds resources + webhook-verifier
- [ ] **Phase 4: Naschool wiring — Snelstart** — Composer-wiring + Stancl-tenancy resolvers + verkoopfactuur-flow live op school1
- [ ] **Phase 5: Naschool wiring — Mollie + flow-test** — Mollie checkout op één activiteit, webhook → enrollment-status, end-to-end smoke

## Phase Details

### Phase 1: Snelstart-SDK finalize
**Goal**: De Snelstart-SDK draait lokaal met groene tests en is publiek beschikbaar op GitHub met upstream-tracking, klaar als referentie-pattern voor de Mollie-SDK.
**Depends on**: Niets (eerste fase)
**Requirements**: SNEL-01, SNEL-02
**Working repo**: `packages/snelstart-api/` (sub-repo, geregistreerd in `planning.sub_repos`)
**Context**:
- Fases 1-3 zijn al gepusht. Fases 4 (`669204d`) en 5 (`76e0797`) zijn **lokaal gecommit maar niet gepusht**; de feature-branch heeft géén upstream-tracking ondanks dat `origin` geconfigureerd is.
- Pest-crash zit in fase-4 (connector + error hierarchy). Strategie volgens master-plan: `MockClient`-pipeline droppen, `getRequestException()` + exception-factories direct unit-testen.
- Branching: werk op een feature-branch in de SDK-repo, niet direct op main. Pas mergen wanneer tests groen zijn.

**Success Criteria** (what must be TRUE):
  1. `./vendor/bin/pest` in `packages/snelstart-api/` exit-code 0 met ≥30 groene tests (geen skip, geen crash)
  2. `git log origin/main..HEAD` in de SDK-repo retourneert leeg — fase-4 + fase-5 commits staan op GitHub
  3. `git branch -vv` toont upstream-tracking ingesteld (`[origin/main]`) op de main-branch
  4. De SDK is `composer require`-baar via VCS-repository-entry door een derde Laravel-project (smoke: `composer require emeq/snelstart-api:dev-main` slaagt zonder VCS-auth)

**Plans:** 3 plans
- [ ] 01-01-PLAN.md — Diagnose Pest-crash op feature-branch (root-cause vastleggen, geen code-fix)
- [ ] 01-02-PLAN.md — Fix Pest-crash + uitbreiden SnelstartConnectorTest met directe getRequestException-coverage (≥30 groene tests)
- [ ] 01-03-PLAN.md — Merge + push naar GitHub met upstream-tracking (checkpoint) + VCS-smoke-test vanuit derde directory

### Phase 2: Mollie-SDK foundation
**Goal**: De `emeq/mollie-api` SDK heeft een leefbaar skeleton met API-key-auth en een werkende Saloon-connector, gespiegeld op het Snelstart-pattern.
**Depends on**: Phase 1 (referentie-pattern moet groen draaien)
**Requirements**: MOLL-01, MOLL-02
**Working repo**: `packages/mollie-api/` (nieuw te clonen na repo-create)
**Context**:
- GitHub-repo `yusufkaracaburun/emeq-mollie-api` bestaat **nog niet**. Eerste sub-stap: `gh repo create yusufkaracaburun/emeq-mollie-api --public --description "Saloon-based Laravel SDK for Mollie payments"`.
- Skeleton via `spatie/package-skeleton-laravel`. Namespace `Emeq\MollieApi\`.
- `ApiKeyAuthenticator` volgt het pattern van `packages/snelstart-api/src/Auth/ClientKeyAuthenticator.php`.
- `MollieConnector` volgt het pattern van `packages/snelstart-api/src/Http/SnelstartConnector.php`.
- Mollie's error-format `{status, title, detail, field}` — exception-hiërarchie maps daar op (zie master-plan B4).

**Success Criteria** (what must be TRUE):
  1. `gh repo view yusufkaracaburun/emeq-mollie-api` slaagt — de repo bestaat publiek op GitHub
  2. `composer install` in `packages/mollie-api/` slaagt; `php artisan package:discover` vindt `MollieServiceProvider`
  3. `./vendor/bin/pest` retourneert ≥10 groene tests die `ApiKeyAuthenticator`, `MollieConnector` en error-mapping dekken
  4. Een test bewijst dat een Mollie 4xx-response met `{status, title, detail, field}` een specifieke `MollieValidationException` met `field`-property oplevert
  5. Een test bewijst dat de Bearer-token header (`Authorization: Bearer test_xxx`) op elke request gezet wordt

**Plans**: TBD

### Phase 3: Mollie-SDK resources + webhooks
**Goal**: De Mollie-SDK kan de endpoints aanspreken die Naschool nodig heeft, en kan inkomende webhooks signature-verifiëren.
**Depends on**: Phase 2
**Requirements**: MOLL-03, MOLL-04
**Working repo**: `packages/mollie-api/`
**Context**:
- Resources zijn dunne wrappers (request-classes), géén codegen vanuit OpenAPI — consistent met de Snelstart-keuze om `Dto/`+`Resources/` leeg te laten waar het kan.
- Endpoints: Payments (create/read/cancel), Customers (read/create), PaymentMethods (list), Refunds (create/read). Niets meer — alleen wat de Naschool ouder-betaling-flow raakt.
- `MollieWebhookVerifier` gebruikt **shared secret** signature-validation (HMAC-style), géén OAuth-state — Mollie Connect is uit scope voor v0.1.
- Totaal-testdrempel inclusief fase 2: ≥15 tests (auth + connector + resources + webhook-verify).

**Success Criteria** (what must be TRUE):
  1. `./vendor/bin/pest` in `packages/mollie-api/` retourneert ≥15 groene tests over auth + connector + resources + webhook-verifier
  2. Elke resource-klasse heeft minstens één test die zijn HTTP-method, path en query/body-shape verifieert
  3. `MollieWebhookVerifier::verify()` retourneert true voor een correct-signed payload en false voor een tampered payload (twee dedicated tests)
  4. Fase 3-commits zijn gepusht naar `origin/main` van `emeq-mollie-api` met upstream-tracking actief

**Plans**: TBD

### Phase 4: Naschool wiring — Snelstart
**Goal**: Naschool kan via de SDK een verkoopfactuur in Snelstart's test-omgeving aanmaken bij een `EnrollmentConfirmed`-event, draaiend op een lokale demo-seed.
**Depends on**: Phase 1 (Snelstart-SDK live + composer-installeerbaar)
**Requirements**: NSCH-01, NSCH-02
**Working repo**: `/Users/yusufkaracaburun/Sites/localhost/school-activities-hub/backend/` — **BUITEN deze workspace**
**Context**:
- VCS-repository-entries in `backend/composer.json` voor beide SDK-repos (publiek, geen private-token).
- Stancl-tenancy: credentials leven in `Tenant->settings()` (central `tenant_admin` DB). `StancltenancyCredentialResolver` voor Snelstart implementeert `SnelstartCredentialResolver`-contract en haalt de actieve-tenant-instellingen op.
- `SyncEnrollmentToSnelstartJob` is event-handler op `EnrollmentConfirmed`. Maakt verkoopfactuur in Snelstart's **test-omgeving**, niet productie.
- Verificatie van success criteria gebeurt door artisan-commands te draaien in het school-activities-hub backend-project, niet hier.

**Success Criteria** (what must be TRUE):
  1. `composer show emeq/snelstart-api` in `school-activities-hub/backend/` retourneert de geïnstalleerde versie (composer-wiring werkt)
  2. `docker compose exec backend php artisan test --filter=Snelstart` retourneert groen — minstens één feature-test bewijst dat de credential-resolver de actieve-tenant-instellingen leest
  3. Op een verse `php artisan migrate:fresh --seed` (school1 demo-seed) triggert `EnrollmentConfirmed::dispatch(...)` de job en verschijnt er een verkoopfactuur in Snelstart's test-omgeving (handmatige verificatie in Snelstart-UI of via API-GET)
  4. `AppServiceProvider` bindt `SnelstartCredentialResolver` → `StancltenancyCredentialResolver` zichtbaar in `php artisan about` of via `php artisan tinker` resolution-check

**Plans**: TBD

### Phase 5: Naschool wiring — Mollie + flow-test
**Goal**: Een ouder kan in Naschool één activiteit met vrijwillige bijdrage afrekenen via Mollie test-mode, en de enrollment-status verandert correct op basis van de Mollie-webhook.
**Depends on**: Phase 3 (Mollie-SDK volledig) + Phase 4 (Naschool wiring-pattern + resolver-binding-DSL al gelegd)
**Requirements**: NSCH-03
**Working repo**: `/Users/yusufkaracaburun/Sites/localhost/school-activities-hub/backend/` — **BUITEN deze workspace**
**Context**:
- Mollie-credential-resolver volgt hetzelfde Stancl-tenancy-pattern als Snelstart (binnen Phase 4 gelegd, hier hergebruikt).
- `CreateMolliePaymentForEnrollmentAction` → checkout-URL → ouder doorloopt Mollie test-mode → webhook bij `webhookUrl` → `MolliePaymentController` verifieert signature → fetcht payment-status → update enrollment.
- End-to-end smoke is handmatig (Mollie test-mode UI in browser), niet geautomatiseerd.
- Verificatie van success criteria gebeurt opnieuw in het school-activities-hub backend-project.

**Success Criteria** (what must be TRUE):
  1. `docker compose exec backend php artisan test --filter=Mollie` retourneert groen — feature-test dekt de happy-path van `CreateMolliePaymentForEnrollmentAction`
  2. Een geconfigureerde test-tenant kan in browser-flow doorklikken van activiteit-detail → Mollie checkout-URL → test-payment "paid" → terug naar Naschool met enrollment-status `paid`
  3. Een webhook-call met geldige signature update de enrollment-status; een webhook-call met ongeldige signature wordt afgewezen (HTTP 4xx, geen status-mutatie) — beide handmatig of via `curl` reproduceerbaar
  4. De flow gebruikt Mollie **test-keys** uitsluitend; geen live-keys raken de codebase of logs

**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Snelstart-SDK finalize | 0/3 | Not started | - |
| 2. Mollie-SDK foundation | 0/TBD | Not started | - |
| 3. Mollie-SDK resources + webhooks | 0/TBD | Not started | - |
| 4. Naschool wiring — Snelstart | 0/TBD | Not started | - |
| 5. Naschool wiring — Mollie + flow-test | 0/TBD | Not started | - |

## Coverage

✓ All 9 v1 requirements mapped to exactly one phase
✓ No orphaned requirements
✓ No duplicates across phases

| Requirement | Phase |
|-------------|-------|
| SNEL-01 | Phase 1 |
| SNEL-02 | Phase 1 |
| MOLL-01 | Phase 2 |
| MOLL-02 | Phase 2 |
| MOLL-03 | Phase 3 |
| MOLL-04 | Phase 3 |
| NSCH-01 | Phase 4 |
| NSCH-02 | Phase 4 |
| NSCH-03 | Phase 5 |

---
*Roadmap defined: 2026-05-14*
