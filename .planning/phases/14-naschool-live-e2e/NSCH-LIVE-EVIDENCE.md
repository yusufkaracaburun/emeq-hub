# NSCH-LIVE-EVIDENCE — Phase 14 Live E2E

**Test uitgevoerd:** YYYY-MM-DD HH:MM CEST _(in te vullen tijdens UAT)_
**Test-ouder:** _(alias bv. "test-ouder-1" — geen echte PII)_
**School (tenant):** _(bv. "school-A-uat")_
**Snelstart-env:** **TEST** (subscription-key uit test-account — géén productie-cutover)
**Mollie-mode:** test-mode (Connect op school A's test-Mollie-account)

## Pre-flight checklist

Bevestig elk item vóór de live test. Verwijzingen naar Plan-commits zijn op de Naschool feature-branch `feat/nsch-04-emeq-hub-foundation`.

- [ ] Plan 14-01 gelanded (composer.json + EmeqHubConfig) — commit-SHA's: `2e562325` (composer-deps), `5e1fb0e6` (EmeqHubConfig + tests)
- [ ] Naschool composer.lock pinst emeq/snelstart-api ≥ v0.2.0 (Phase 11 Saloon-v4-baseline) — bevestig via `cd /Users/yusufkaracaburun/Sites/localhost/school-activities-hub/backend && grep -A2 '"name": "emeq/snelstart-api"' composer.lock | grep version`. **Gepinde versie**: `v0.2.0` (ref `ce7c66c2`) ✓
- [ ] Naschool composer.lock pinst emeq/mollie-api ≥ v0.2.0 — zelfde grep-check. **Gepinde versie**: `v0.2.0` (ref `8c2c0ff1`) ✓
- [ ] Plan 14-02 gelanded (StancltenancyCredentialResolver + EmeqHubClient) — commit-SHA's: `e3e680b4` (resolver + tests), `c8ef284d` (client + tests)
- [ ] Plan 14-03 gelanded (event/listener/job/Horizon-supervisor) — commit-SHA's: `59cf1ca5` (event+listener+job+listener-test), `fa37d3d6` (job-test + horizon-supervisor)
- [ ] Hub OnboardConsumer-wizard gebruikt om Naschool-Consumer aan te maken (incl. PAT-uitgifte) — Consumer-id: ____
- [ ] Eerste Account aangemaakt voor school A — Account-id: ____
- [ ] Mollie-OAuth via StartOAuthFlowAction gestart en succesvol callback ontvangen → Mollie-Connection.access_token aanwezig
- [ ] Snelstart-Connection aangemaakt met test-env clientkey + subscription_key + subscription_id
- [ ] Naschool tenant van school A heeft `emeq_hub_account_id` + `emeq_snelstart_default_relatie_id` + `emeq_snelstart_boekingsperiode_id` gezet in tenant.data jsonb (Plan 14-02/14-03 assumptions)
- [ ] Naschool .env heeft `EMEQ_HUB_BASE_URL`, `EMEQ_HUB_PAT`, `EMEQ_HUB_NASCHOOL_ACCOUNT_ID` gezet
- [ ] Horizon draait in Naschool met `supervisor-naschool` actief — bevestig via `php artisan horizon:list`
- [ ] Test-activiteit met vrijwillige bijdrage geseed in school A's tenant-DB

## Runbook — stap-volgorde

1. Test-ouder logt in op Naschool-tenant van school A (URL: ____)
2. Schrijft kind in voor de test-activiteit met vrijwillige bijdrage van € 1,00 (laag bedrag tijdens UAT)
3. Naschool redirect naar Mollie checkout-URL (verkregen via Hub `/v1/mollie/payments`)
4. Test-ouder voltooit Mollie-test-betaling (test-iDEAL-flow)
5. Mollie POST webhook naar Hub
6. Hub signature-verifieert + audit-logt in `webhook_calls` + fan-out naar Naschool's `webhook_callback_url`
7. Naschool verwerkt callback, update enrollment-status naar `paid`, fired `EnrollmentConfirmed`-event
8. `DispatchSnelstartInvoiceSync` listener pushed `SyncEnrollmentToSnelstartJob` op `naschool`-queue
9. Horizon supervisor-naschool picks up, job calls Hub `/v1/snelstart/Verkoopfacturen` met school A's `X-Account-Id`
10. Hub doet Snelstart test-env API-call; Verkoopfactuur verschijnt in school A's Snelstart test-Mutaties

## Bewijs

### 1. Mollie-dashboard (school A's eigen Mollie test-account)
![Mollie payment](artifacts/01-mollie-payment.png)
Payment-id: `tr_____`; status `paid`; amount € ____

### 2. Naschool-UI met enrollment-status `paid`
![Naschool enrollment paid](artifacts/02-naschool-enrollment-paid.png)
Enrollment-id: `____`; verwachte status `paid`; observed `____`

### 3. Hub webhook_calls — Mollie-webhook-rij

```sql
SELECT id, provider, method, path, status_code, created_at
FROM webhook_calls
WHERE provider = 'mollie'
ORDER BY created_at DESC LIMIT 3;
```

```
(plak hier psql-output of database-query MCP-output tijdens UAT)
```

### 4. Hub pass_through_calls — Snelstart POST-rij

```sql
SELECT id, consumer_id, account_id, provider, method, path, status_code, created_at
FROM pass_through_calls
WHERE provider = 'snelstart' AND path LIKE '%Verkoopfacturen%'
ORDER BY created_at DESC LIMIT 1;
```

```
(plak hier psql-output of database-query MCP-output tijdens UAT)
```

### 5. Snelstart-Mutaties bevestiging (test-env)
![Snelstart verkoopfactuur](artifacts/04-snelstart-verkoopfactuur.png)
Factuurnummer: `NSCH-____-____`; bedrag: € ____; relatie: `____`

## Resultaat

- [ ] **SC-1** Composer-resolve (Plan 14-01) — Naschool composer require ✓ + EmeqHubConfig DI-resolvable ✓
- [ ] **SC-2** StancltenancyCredentialResolver + EmeqHubClient (Plan 14-02) — per-tenant X-Account-Id + Bearer-PAT wiring ✓
- [ ] **SC-3** EnrollmentConfirmed listener + Horizon job (Plan 14-03) — event-→listener-→job-→Hub-call-loop ✓
- [ ] **SC-4** Live E2E: ouder → Mollie → webhook → status `paid` → Snelstart-factuur
- [ ] **SC-5** Evidence vastgelegd in dit document (5 screenshots/SQL-snippets ingevuld + alle pre-flight checkboxes aangevinkt)

## Notes / Issues

_(Vrije ruimte voor afwijkingen tijdens UAT, bv. webhook-vertraging, retry-events, payload-mismatch. Bij blocker → switch naar `/gsd-plan-phase 14 --gaps`.)_

## Production-cutover follow-up

Deze E2E draait op Snelstart **test-env**. Productie-cutover (subscription-key wissel + opnieuw verifiëren) is een follow-up gekoppeld aan **Phase 12 (Snelstart productie-cert closeout)**. Deadline Phase 12: 2026-05-26. Géén Phase 14-scope.

## POC-assumptions die productie-hardening behoeven (uit Plan 14-03)

1. Vrijwillige-bijdrage bedrag is hard-coded op €25.00 in `SyncEnrollmentToSnelstartJob::buildPayload()`. Productie: lees uit Activity.contribution_amount of aparte VoluntaryContribution-model.
2. `relatie.id` = `$tenant->emeq_snelstart_default_relatie_id` (één pre-existing Snelstart-Relatie per tenant). Productie: optioneel auto-create-Relatie per ouder.
3. `boekingsperiode.id` = `$tenant->emeq_snelstart_boekingsperiode_id` (gepind per tenant). Productie: auto-discover via `GET /v1/snelstart/Boekingsperiodes`.
