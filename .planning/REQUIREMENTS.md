# Requirements: Emeq integration stack (v0.1)

**Defined:** 2026-05-14
**Core Value:** Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, en beide live in één concrete Naschool-feature.

## v1 Requirements

Requirements voor v0.1-release (~4 weken). Elke vereiste mapt naar één roadmap-fase.

### Snelstart SDK

- [ ] **SNEL-01**: Snelstart-SDK fase-4 Pest-crash opgelost; alle tests (≥30) groen lokaal
- [ ] **SNEL-02**: Snelstart-SDK gepusht naar `github.com:yusufkaracaburun/emeq-snelstart-api` met upstream-tracking

### Mollie SDK

- [ ] **MOLL-01**: Mollie-SDK skeleton (`spatie/package-skeleton-laravel`-based) + ServiceProvider + `MollieCredentialResolver`-contract aanwezig in `packages/mollie-api/`
- [ ] **MOLL-02**: Mollie API-key authenticator + Saloon `MollieConnector` + error-hierarchie gemapt op Mollie's `{status, title, detail, field}`-format; ≥10 unit-tests groen
- [ ] **MOLL-03**: Resources: Payments (create/read/cancel), Customers (read/create), PaymentMethods (list), Refunds (create/read) — alle met requests + tests
- [ ] **MOLL-04**: `MollieWebhookVerifier` met shared-secret signature-validation + queueable optie; tests dekken happy + invalid-sig paths

### Naschool wiring

- [ ] **NSCH-01**: `backend/composer.json` heeft VCS-repository-entries voor beide SDKs; `StancltenancyCredentialResolver` voor Snelstart én Mollie geïmplementeerd + gebonden in `AppServiceProvider`
- [ ] **NSCH-02**: `SyncEnrollmentToSnelstartJob` (event-handler op `EnrollmentConfirmed`) maakt verkoopfactuur aan in Snelstart's test-omgeving; smoke-test groen op school1 demo-seed
- [ ] **NSCH-03**: Mollie checkout-flow op één activiteit met vrijwillige bijdrage: `CreateMolliePaymentForEnrollmentAction` → checkout-URL → webhook → enrollment-status update; end-to-end smoke handmatig doorlopen in test-mode

## v2 Requirements

Deferred to v0.2+. Tracked but not in current roadmap.

### Hub-platform (`emeq/hub`)

- **HUB-H0**: Rebuild `emeq/moneybird` als pure SDK (`emeq/moneybird-api`)
- **HUB-H2**: `Consumer` model + Sanctum PAT auth + CLI provisioning command
- **HUB-H3**: `Account` + `Connection` models — multi-provider OAuth storage met encrypted tokens
- **HUB-H4**: OAuth-broker per provider — `OAuthFlow` contract
- **HUB-H5**: Pass-through REST API `/v1/{provider}/{path:.*}` met audit log

### Mollie Connect

- **MOLL-CONNECT**: OAuth2 Mollie-Connect-flow voor 3rd-party Mollie-account-access

### Snelstart Saloon v4

- **SNEL-V4**: Upgrade Saloon v3 → v4 (3 ignored security advisories oplossen, o.a. SSRF via endpoint-override)

## Out of Scope

Expliciet uitgesloten. Niet re-adden zonder herziening van het master-plan.

| Feature | Reason |
|---------|--------|
| Snelstart `Dto/` + `Resources/` (32 resource-classes) | `RawSnelstartRequest` + OData QueryBuilder dekt alle 96 endpoints; codegen pas wanneer Hub typed responses nodig heeft |
| DTO-codegen vanuit OpenAPI specs | Geen `emeq/hub` typed-response consumers in v0.1; verschoven naar later |
| `emeq/exact-api`, `emeq/moneybird-api`, `emeq/ibanity-api`, `emeq/stripe-api` | Derde+ providers; wachten tot Mollie+Snelstart-pattern gevalideerd in productie |
| Mollie OAuth2 Connect (3rd-party access) | v0.1 = SaaS-apps in eigen Mollie-account, API-key auth volstaat |
| Snelstart fases 6-9 (resources, webhook-handler, host-app-wiring in SDK) | Webhook-handling verhuist naar Hub; resources niet nodig naast OData QueryBuilder |
| `emeq/hub` H0-H5 implementatie | v0.2-werk; staat los in `.docs/todos/hub-roadmap.md` |
| Commerciële Hub-features (billing, public docs, self-service onboarding) | Pas in latere milestone na v0.1 |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| SNEL-01 | Phase 1 | Pending |
| SNEL-02 | Phase 1 | Pending |
| MOLL-01 | Phase 2 | Pending |
| MOLL-02 | Phase 2 | Pending |
| MOLL-03 | Phase 3 | Pending |
| MOLL-04 | Phase 3 | Pending |
| NSCH-01 | Phase 4 | Pending |
| NSCH-02 | Phase 4 | Pending |
| NSCH-03 | Phase 5 | Pending |

**Coverage:**
- v1 requirements: 9 total
- Mapped to phases: 9
- Unmapped: 0 ✓

---
*Requirements defined: 2026-05-14*
*Last updated: 2026-05-14 after initial definition*
