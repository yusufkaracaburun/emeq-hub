# Roadmap: Emeq integration stack

**Project code:** EMEQ

## Shipped Milestones

- ✅ **v0.1 — Snelstart-SDK finale** (2026-05-14) — Phase 1. Zie [`milestones/v0.1-ROADMAP.md`](milestones/v0.1-ROADMAP.md) · [`MILESTONES.md`](MILESTONES.md)
- ✅ **v0.2 — Mollie + Connect + Subscriptions + Hub-skeleton** (2026-05-17) — Phases 2-10 (11 phases incl. 5a/5b/5c). Zie [`milestones/v0.2-ROADMAP.md`](milestones/v0.2-ROADMAP.md) · [`milestones/v0.2-REQUIREMENTS.md`](milestones/v0.2-REQUIREMENTS.md) · [`MILESTONES.md`](MILESTONES.md)

## Active Milestone

**v0.3 — Productie-closure (Naschool live + risk-reductie)** — gepland 2026-05-18, kickoff via `/gsd-new-milestone v0.3`.

**Doel:** Sluit v0.2 carry-forward (Naschool live E2E + Snelstart productie-cert) en reduceer risico (Saloon v4 / 3 security advisories + Mollie Connect productie-blocker) voordat v0.4+ nieuwe providers toevoegt.

**Granulariteit:** coarse · **Phases:** 5 (11 → 15) · **Coverage:** 12/12 requirements gemapt.

### Phases

- [ ] **Phase 11: Snelstart-SDK Saloon v4 upgrade** — Saloon v3 → v4 in `emeq/snelstart-api`, Hub composer-update, 3 security advisories opgelost.
- [ ] **Phase 12: Snelstart productie-cert closeout** — Partner-respons verwerken, retry-policy vastleggen, production webhook-endpoint geregistreerd (deadline 2026-05-26).
- [ ] **Phase 13: Mollie Connect partner-resources** — Pass-through-routes voor Onboarding/Organizations/Profiles/Permissions/ClientLinks + partner-access-token-resolver.
- [ ] **Phase 14: Naschool live E2E** — Naschool-repo wiring (composer-VCS + Stancl-resolver + listener) + live checkout-flow door test-ouder.
- [ ] **Phase 15: VERIFICATION.md backfill** — Goal-backward audits voor v0.2 Phases 4, 6, 7.

## Phase Details

### Phase 11: Snelstart-SDK Saloon v4 upgrade

**Goal**: De Snelstart-SDK draait op Saloon v4 en de Hub heeft `composer audit` exit 0 zonder ignores.
**Depends on**: Nothing (v0.2 shipped baseline)
**Requirements**: SNEL-03, SNEL-04
**Success Criteria** (what must be TRUE):

  1. `emeq/snelstart-api` repo getagd v0.2.0+ met Saloon v4 dependency en groene Pest-suite (≥107 passed).
  2. Hub `composer update emeq/snelstart-api` slaagt en alle bestaande Hub-tests blijven groen (geen regressie op `/v1/snelstart/*` of `/webhooks/snelstart`).
  3. `composer audit` in de Hub retourneert exit 0 zonder `audit-ignores` in `composer.json` (3 advisories incl. SSRF-via-endpoint-override opgelost).
  4. Snelstart-SDK migratie-breaking-changes (`Connector::resolveRequestUrl()`, etc.) gedocumenteerd in SDK-CHANGELOG.

**Plans:** 3 plans

Plans:
**Wave 1**

- [ ] 11-01-PLAN.md — SDK CHANGELOG-entry voor v0.2.0 + lokale release-commit (autonomous: false, checkpoint vóór tag/push)

**Wave 2** *(blocked on Wave 1 completion)*

- [ ] 11-02-PLAN.md — Hub composer.json pin ^0.2.0 + composer audit exit 0 + Hub-PHPUnit groen

**Wave 3** *(blocked on Wave 2 completion)*

- [ ] 11-03-PLAN.md — ADR + .docs/README.md index-sync + .planning/codebase drift-fix + STATE.md closure

### Phase 12: Snelstart productie-cert closeout

**Goal**: Snelstart partner-certificering is afgesloten en de Hub is klaar voor productie-webhook-verkeer.
**Depends on**: Phase 11 (cert moet de actuele SDK-versie + Hub-config reflecteren)
**Requirements**: SNEL-05
**Success Criteria** (what must be TRUE):

  1. Snelstart-partner-respons (Gmail draft `r-8836998535038336548`) is verwerkt vóór 2026-05-26 en het antwoord op vraag #4 (retry-policy) staat als sectie in `.docs/partners/snelstart/CERT.md`.
  2. Vereiste cert-headers / endpoint-aanpassingen zijn geland in `config/snelstart.php` en doorgevoerd in de SDK-`Connector`-config; bestaande pass-through-tests blijven groen.
  3. Production webhook-endpoint is geregistreerd bij Snelstart en bevestigingsbewijs (mail-snippet of partner-portal-screenshot) ligt in `.docs/partners/snelstart/CERT.md`.

**Plans**: TBD

### Phase 13: Mollie Connect partner-resources

**Goal**: Een Connect-merchant-onboarding-flow kan via de Hub volledig worden afgehandeld zonder dat het host-app rechtstreeks met Mollie hoeft te praten.
**Depends on**: Nothing (parallelliseerbaar met Phase 11/12)
**Requirements**: MOLL-05, MOLL-06
**Success Criteria** (what must be TRUE):

  1. `/v1/mollie/connect/{onboarding|organizations|profiles|permissions|client-links}` routes zijn live met dezelfde error-mapping en `Idempotency-Key` auto-forward als Phase 5a; integration-test bewijst happy-path + 401-error-mapping per resource.
  2. `MollieAccessTokenResolver` levert het juiste token-type per resource: partner-access-token voor Connect-resources, Connection-access-token voor merchant-resources. Een integration-test dekt beide paden expliciet.
  3. Scramble OpenAPI-output groepeert de nieuwe routes onder `Mollie · Connect` en `/docs/api` rendert ze zonder regressie op bestaande Mollie-groepen.
  4. ADR (extension van `mollie-passthrough-api.md` of nieuwe `mollie-connect-partner-resources.md`) legt de token-resolver-keuze + resource-mapping vast.

**Plans**: TBD

### Phase 14: Naschool live E2E

**Goal**: Een echte test-ouder maakt via de Naschool-UI een vrijwillige bijdrage en het bewijs van end-to-end-flow (Mollie checkout → webhook → enrollment-status → Snelstart-verkoopfactuur) is vastgelegd.
**Depends on**: Phase 11 (stabiele SDK voor Naschool composer-update), Phase 12 (cert closed voor productie-Snelstart-call)
**Requirements**: NSCH-04, NSCH-05, NSCH-06, NSCH-07
**Success Criteria** (what must be TRUE):

  1. `school-activities-hub/backend/composer.json` bevat VCS-entries voor `emeq/snelstart-api` + `emeq/mollie-api` en `composer update --no-cache` slaagt fresh zonder auth.
  2. `StancltenancyCredentialResolver` in Naschool levert Snelstart-clientkey per tenant uit Stancl-tenancy-context, met test-bewijs (unit of feature-test in Naschool-repo).
  3. `EnrollmentConfirmed`-listener dispatcht `SyncEnrollmentToSnelstartJob` op de `naschool` Horizon-connection met failed-job-retention en Sentry-error-bridge actief.
  4. Test-ouder doorloopt live de vrijwillige-bijdrage checkout-flow: Mollie-payment slaagt → Mollie-webhook landt op Hub → enrollment-status update propageert naar Naschool-UI → Snelstart-verkoopfactuur verschijnt in de Snelstart-Mutaties van de juiste tenant.
  5. `NSCH-LIVE-EVIDENCE.md` bevat screenshots, een Hub `pass_through_calls`-rij-snippet en Snelstart-Mutaties-bevestiging die SC-4 staaft.

**Plans**: TBD
**UI hint**: yes

### Phase 15: VERIFICATION.md backfill

**Goal**: De v0.2 verification-debt voor Phases 4, 6 en 7 is gesloten met formele goal-backward audit-artifacts.
**Depends on**: Nothing (puur doc-werk op shipped code)
**Requirements**: VERIF-01, VERIF-02, VERIF-03
**Success Criteria** (what must be TRUE):

  1. `.planning/milestones/v0.2/phases/04-mollie-connect-oauth-broker/VERIFICATION.md` bestaat, gegenereerd via `/gsd-verify-work` met de Phase-4 ACCEPTANCE-file als startbewijs, en dekt 100% van de Phase-4 success-criteria (of administreert deferred items expliciet).
  2. `.planning/milestones/v0.2/phases/06-cashier-mollie-use-case-a/VERIFICATION.md` bestaat met dezelfde criteria voor Phase 6.
  3. `.planning/milestones/v0.2/phases/07-account-level-subscriptions-use-case-b/VERIFICATION.md` bestaat met dezelfde criteria voor Phase 7.
  4. Geen code-changes in dit phase; eventuele bevindingen worden als follow-up-TODOs in STATE.md gelogd of als quick-tasks ge-spawned, niet inline gefixed.

**Plans**: TBD

## Progress

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 11. Snelstart-SDK Saloon v4 upgrade | 0/3 | Planned | - |
| 12. Snelstart productie-cert closeout | 0/? | Not started | - |
| 13. Mollie Connect partner-resources | 0/? | Not started | - |
| 14. Naschool live E2E | 0/? | Not started | - |
| 15. VERIFICATION.md backfill | 0/? | Not started | - |

## Phases (Shipped)

<details>
<summary>✅ v0.1 Snelstart-SDK finale (Phase 1) — SHIPPED 2026-05-14</summary>

- [x] Phase 1: Snelstart-SDK finalize (3/3 plans) — completed 2026-05-14

</details>

<details>
<summary>✅ v0.2 Mollie + Connect + Subscriptions + Hub-skeleton (Phases 2-10) — SHIPPED 2026-05-17</summary>

- [x] Phase 2: emeq/mollie-api foundation (8/8 plans) — completed 2026-05-14
- [x] Phase 3: Hub-skeleton (5/5 plans) — completed 2026-05-14
- [x] Phase 4: Mollie Connect OAuth-broker (5/5 plans) — completed 2026-05-14
- [x] Phase 5a: Mollie SDK Resources + Webhooks + Pass-through API (6/6 plans) — completed 2026-05-15
- [x] Phase 5b: Snelstart-pass-through API (5/5 plans) — completed 2026-05-16; verifier-pass 2026-05-17
- [x] Phase 5c: Snelstart webhook-handler (5/5 plans) — completed 2026-05-17
- [x] Phase 6: Cashier-Mollie integratie (use-case A) (8/8 plans) — completed 2026-05-15
- [x] Phase 7: Account-level subscriptions (use-case B) (8/8 plans) — completed 2026-05-15
- [x] Phase 8: Naschool wiring (Hub-side per D-03) (5/5 plans) — completed 2026-05-17
- [x] Phase 9: Filament admin-UI voor Emeq-medewerkers (11/11 plans) — completed 2026-05-16
- [x] Phase 10: Phase 9 polish — deferred review-findings (6/6 plans) — completed 2026-05-16

</details>

## Backlog (v0.4+)

Verzamelpunt voor ideeën die nog geen milestone hebben. Bij milestone-kickoff worden relevante items uit deze sectie naar de active milestone gepromoveerd.

- Andere providers wanneer Mollie+Snelstart in productie gevalideerd: `emeq/moneybird-api`, `emeq/exact-api`, `emeq/ibanity-api`, `emeq/stripe-api`, `emeq/bizcuit-api`
- **`emeq/bizcuit-api`** — Bizcuit SDK (NL boekhouden/banking) — OpenAPI docs op https://app.bizcuit.nl/openapi/documentation/getting-started.html. Volgt SDK-pattern uit `packages/`-conventie. Trigger: zodra een host-app Bizcuit-integratie nodig heeft. User-captured 2026-05-17.
- OAuth Connect-implementaties voor providers die in v0.2 alleen contract-level zijn gedekt (Snelstart-OAuth, Exact-OAuth, Ibanity-OAuth)
- DTO-codegen vanuit OpenAPI specs voor providers die typed-response consumers nodig hebben
- Hub commerciële features: `HUB-BILLING` (public billing-flow voor derde-partij Consumers), `HUB-DOCS` (public docs-site `docs.hub.emeq.nl`), `HUB-ONBOARDING` (self-service onboarding)
- **`HUB-AUDIT`** — admin-acties audit-log via `spatie/laravel-activitylog` (Phase 9 out-of-scope) — pas als compliance of incident-respons het vereist
- **`SCRAMBLE-NESTED-GROUPS`** — Echte hiërarchische groepering in `/docs/api`. v0.2 gebruikt platte per-resource groepen met `Mollie · {Resource}`-prefix (Scramble v0.13 + Stoplight Elements 8.4 honoreren geen `x-tagGroups`). Pad: (1) tags blijven per-resource via `#[Group]`; (2) custom middleware op `docs/api.json` injecteert `x-tagGroups` post-serialisatie; (3) `docs.blade.php` overzetten van Stoplight Elements naar Redoc. Trigger: zodra 5+ providers of resource-lijst per provider voorbij ~10 endpoints groeit.
- **`BRAIN-AUDIT-CI`** — `laramint/laravel-brain` promoveren tot dev-dep + `bin/audit-pennant-gates.php` als blokkerende CI-check. Spike-validatie op 2026-05-17 toonde 21/21 SDK-routes met correcte `feature.provider:{provider}` gate. Trigger: 3e SDK toegevoegd, of 2e dev op de repo, of v1.0+ commercieel. Install-recept: `.docs/stack/architecture-audit.md`.

---

*Roadmap last updated: 2026-05-18 — v0.3 milestone-kickoff via `/gsd-new-milestone v0.3`. v0.3 active milestone toegevoegd met 5 phases (11-15) en 12/12 requirement-coverage. v0.1 en v0.2 archived in `.planning/milestones/`.*
