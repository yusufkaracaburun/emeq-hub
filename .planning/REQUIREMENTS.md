# Requirements: Emeq integration stack — v0.3

**Defined:** 2026-05-18
**Milestone:** v0.3 Productie-closure (Naschool live + risk-reductie)
**Core Value:** Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, beide via één Hub geconsumeerd, multi-tenant + encrypted-at-rest + audit-logged + admin-managed. v0.3 sluit Naschool-E2E + reduceert risico voordat v0.4+ nieuwe providers toevoegt.

## v1 Requirements

Requirements voor v0.3. Elk mapt naar exact één phase in `ROADMAP.md`.

### Naschool live integratie

- [ ] **NSCH-04**: Naschool-repo (`school-activities-hub/backend`) krijgt composer-VCS-entries voor `emeq/snelstart-api` + `emeq/mollie-api` en installeert clean (`composer update --no-cache`) zonder auth.
- [ ] **NSCH-05**: `StancltenancyCredentialResolver` is geïmplementeerd in de Naschool-repo en levert Snelstart-clientkey per tenant uit Stancl-tenancy-context (mirror van bestaande Mollie-tenant-resolver).
- [ ] **NSCH-06**: `EnrollmentConfirmed`-listener dispatcht `SyncEnrollmentToSnelstartJob` via Horizon (op `naschool`-connection-id), incl. failed-job-retention + Sentry-error-bridge.
- [ ] **NSCH-07**: Live E2E door test-ouder: vrijwillige-bijdrage checkout via Mollie (Connect) → Mollie-webhook → enrollment-status update → Snelstart-verkoopfactuur (Hub pass-through) → tenant ziet eindstaat in Naschool-UI. Bewijs in `NSCH-LIVE-EVIDENCE.md` (screenshots + Hub `pass_through_calls`-rij + Snelstart-Mutaties-bevestiging).

### Snelstart-SDK upgrade

- [x] **SNEL-03**: `emeq/snelstart-api` upgrade Saloon v3 → v4. SDK Pest-suite blijft groen (≥107 passed); breaking changes (`Connector::resolveRequestUrl()` etc.) gemigreerd; SDK-repo getagd `v0.2.0` of hoger.
- [x] **SNEL-04**: 3 ignored security advisories in `composer.json` (SSRF via endpoint-override + 2 anderen) zijn opgelost en `composer audit` retourneert exit 0 zonder ignores in de Hub.

### Snelstart productie-cert

- [ ] **SNEL-05** (`SNEL-CERT-CLOSE`): Snelstart partner-respons (Gmail draft `r-8836998535038336548`, deadline 2026-05-26) verwerkt: vraag #4 retry-policy ❓ beantwoord in `.docs/partners/snelstart/CERT.md`; eventuele cert-headers/endpoint-config in Hub `config/snelstart.php` aanwezig; production webhook-endpoint geregistreerd bij Snelstart.

### Mollie Connect partner-resources

- [x] **MOLL-05**: Pass-through-routes voor Mollie Connect partner-resources beschikbaar onder `/v1/mollie/connect/*`: Onboarding-status, Organizations, Profiles, Permissions, ClientLinks. Volgt pass-through-pattern uit Phase 5a (ADR `mollie-passthrough-api.md`), incl. idempotency-forward, error-mapping en Scramble OpenAPI-groep `Mollie · Connect`.
- [x] **MOLL-06**: `MollieAccessTokenResolver` ondersteunt org-access-tokens vs partner-access-tokens correct — Connect-resources gebruiken het partner-token, niet het Connection-access-token. Integration-test bewijst beide paden.

### Verification-debt closure

- [x] **VERIF-01**: VERIFICATION.md (goal-backward audit) gegenereerd voor v0.2 Phase 4 (Mollie Connect OAuth-broker) via gsd-verifier subagent met SUMMARY-fallback als startbewijs (Phase 4 heeft geen ACCEPTANCE.md — Notable deviations-sectie verantwoordt fallback). 100% van Phase-4 success-criteria gedekt of expliciet als deferred geadministreerd.
- [x] **VERIF-02**: VERIFICATION.md gegenereerd voor v0.2 Phase 6 (Cashier-Mollie use-case A).
- [x] **VERIF-03**: VERIFICATION.md gegenereerd voor v0.2 Phase 7 (Account-level subscriptions use-case B).

## Future Requirements (v0.4+)

Acknowledged maar niet in v0.3-roadmap. Carry-forward staat in [`ROADMAP.md`](ROADMAP.md) Backlog-sectie.

### Provider-uitbreiding

- **PROV-MONEYBIRD**: `emeq/moneybird-api` SDK + Hub pass-through.
- **PROV-EXACT**: `emeq/exact-api` SDK + OAuth2-flow registratie in `OAuthFlowRegistry`.
- **PROV-IBANITY**: `emeq/ibanity-api` SDK (PSD2/banking) + OAuth2.
- **PROV-STRIPE**: `emeq/stripe-api` SDK + multi-tenant resolver.
- **PROV-BIZCUIT**: `emeq/bizcuit-api` SDK volgens OpenAPI docs (https://app.bizcuit.nl/openapi/documentation/getting-started.html).

### Hub commerciële features

- **HUB-BILLING**: Public billing-flow voor derde-partij Consumers (Cashier-Mollie pattern uit v0.2 Phase 6 hergebruikt).
- **HUB-DOCS**: Public docs-site `docs.hub.emeq.nl` (Scramble + Redoc).
- **HUB-ONBOARDING**: Self-service Consumer-onboarding incl. Sanctum-PAT-issuance.
- **HUB-AUDIT**: Admin-acties audit-log via `spatie/laravel-activitylog`.

### Tooling / kwaliteit

- **SCRAMBLE-NESTED-GROUPS**: Hiërarchische groepering in `/docs/api` (trigger: 5+ providers).
- **BRAIN-AUDIT-CI**: `laramint/laravel-brain` als blokkerende CI-check (trigger: 3+ SDKs of v1.0 commercieel).

## Out of Scope

Expliciet uitgesloten van v0.3 om scope-creep te voorkomen.

| Feature | Reden |
|---------|-------|
| Nieuwe provider-SDKs (Moneybird/Exact/Ibanity/Stripe/Bizcuit) | v0.3 is productie-closure; eerst Naschool live + risk-reductie. Provider-uitbreiding pas v0.4+ wanneer Snelstart + Mollie productie-gevalideerd zijn. |
| Commerciële Hub-features (BILLING/DOCS/ONBOARDING/AUDIT) | Vereist 2+ concrete derde-partij Consumer-leads. Tot die er zijn blijft de Hub interne tooling. |
| DTO-codegen vanuit OpenAPI specs | Snelstart `Dto/` + `Resources/` blijven leeg; consumers gebruiken `RawSnelstartRequest` + OData QueryBuilder. Pas wanneer Hub typed responses nodig heeft. |
| Cashier-Mollie upstream-PR | Bij compat-issues: fork-and-update of zelf bouwen; geen upstream-PR-pad. |
| Naschool's volledige financiële module | Alleen vrijwillige-bijdrage checkout-flow + Snelstart-verkoopfactuur-flow als POC; geen full ledger, multi-currency, of tax-rule-engine. |
| Snelstart-OAuth / Exact-OAuth / Ibanity-OAuth | Provider-agnostisch `OAuthFlow`-contract is bewezen via `FakeOAuthFlow` (v0.2 Phase 4); concrete implementaties pas wanneer een SDK + Account-side credentials beschikbaar zijn. |
| Mollie Connect productie-onboarding van derde-partij merchants | `MOLL-CONNECT-RES` dekt de pass-through-routes; werkelijke partner-onboarding-UI/-flow valt buiten v0.3. |

## Traceability

Welke phase elke requirement levert.

| Requirement | Phase | Status |
|-------------|-------|--------|
| SNEL-03 | Phase 11 | Complete |
| SNEL-04 | Phase 11 | Complete |
| SNEL-05 | Phase 12 | Pending |
| MOLL-05 | Phase 13 | Complete |
| MOLL-06 | Phase 13 | Complete |
| NSCH-04 | Phase 14 | Pending |
| NSCH-05 | Phase 14 | Pending |
| NSCH-06 | Phase 14 | Pending |
| NSCH-07 | Phase 14 | Pending |
| VERIF-01 | Phase 15 | Complete |
| VERIF-02 | Phase 15 | Complete |
| VERIF-03 | Phase 15 | Complete |

**Coverage:**

- v1 requirements: 12 total
- Mapped to phases: 12 ✓
- Unmapped: 0

---
*Requirements defined: 2026-05-18*
*Last updated: 2026-05-18 — VERIF-01/02/03 closed via Phase 15 (4 plans, gsd-verifier subagent).*
