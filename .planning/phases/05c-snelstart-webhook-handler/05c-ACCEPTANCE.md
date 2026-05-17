# Phase 5c Acceptance Gate — HUB-06 Snelstart webhook-handler

**Phase:** 5c-snelstart-webhook-handler
**Status:** ACCEPTED
**Date:** 2026-05-17
**Branch:** `feat/05c-snelstart-webhook-handler`

## Success Criteria coverage (uit ROADMAP §Phase 5c)

| SC | Description | Plan | Status |
|----|-------------|------|--------|
| SC-1 | Valid HMAC + known administratieId → 200 + audit-row direction=inbound + job dispatched | 05c-03 (`test_valid_webhook_with_known_administratie_dispatches_job`), 05c-05 (`test_sc_1_valid_known_administratie_dispatches_forward_job`) | Done |
| SC-2 | Invalid HMAC → 401, empty body, geen audit-row | 05c-02 (SDK Pest `SnelstartWebhookSignatureTest` + middleware-tests), 05c-03 (`test_invalid_signature_returns_401_without_audit`), 05c-05 (`test_sc_2_invalid_signature_returns_401_without_audit`) | Done |
| SC-3 | Onbekende administratieId + valid HMAC → 200 + NULL-tenant audit + geen fan-out | 05c-03 (`test_unknown_administratie_returns_200_with_null_tenant_audit`), 05c-05 (`test_sc_3_unknown_administratie_returns_200_with_null_tenant_audit`) | Done |
| SC-4 | Idempotency event_id 2× → 200 + dup-audit (event_id=NULL) + 1 originele job | 05c-03 (`test_idempotent_duplicate_event_id_does_not_redispatch`), 05c-05 (`test_sc_4_idempotent_event_id_does_not_redispatch`) | Done |
| SC-5 | Cross-Consumer-isolation gegarandeerd | 05c-03 (`test_cross_consumer_isolation_routes_to_correct_consumer`), 05c-05 (`test_sc_5_cross_consumer_isolation_routes_to_correct_consumer`) | Done |

## Plans completed

| # | Plan | Summary | Key commits |
|---|------|---------|-------------|
| 1 | 05c-01 (schema) | `pass_through_calls` inbound-kolommen (direction/event_id) + `connections.administratie_id` + model/factory-updates | `47ea287`, `ef02980`, `46854e1`, `5b3e943`, `59d0f3d`, `226c82e` |
| 2 | 05c-02 (HMAC-verifier + middleware → SDK) | Verifier + middleware in `emeq/snelstart-api` SDK auto-aliased; 5 config-keys onder `snelstart.webhook.*`; Hub-side alleen `bootstrap/app.php` import + composer-pin | `242e647`, `96d939d`, `0980c47`, `f450151`, `a9a9e4e`, `3640fa0` |
| 3 | 05c-03 (route + controller) | `POST /webhooks/snelstart` + `SnelstartWebhookController` single-action (malformed/dup/unknown/happy) + 7 PHPUnit feature-tests | `207ed1b`, `f999b4e` |
| 4 | 05c-04 (forward-job + Horizon) | `ForwardSnelstartWebhookToConsumerJob` (queue `webhooks`, anti-correlation) + Horizon `supervisor-webhooks` + 5 PHPUnit feature-tests | `be50c94`, `d18b414`, `4ba10d8` |
| 5 | 05c-05 (E2E + ADR + tracking) | `SnelstartWebhookEndToEndTest` (5/5 SC's, 35 assertions) + ADR `.docs/decisions/snelstart-webhook-ingress.md` (gitignored) + REQUIREMENTS/ROADMAP/STATE/EPICS sync | `a0365a7` + close-out commits |

## Open Aannames (uit 05c-CONTEXT.md)

Vier van vijf ❓-aannames zijn 2026-05-17 partner-bevestigd (🔒); allemaal config-driven gebouwd, partner-respons-tweaks zijn env-only:

- 🔒 #1 HMAC-header-naam + algo → bevestigd `X-SnelStart-Signature` + `HMAC-SHA256`; configureerbaar via `SNELSTART_WEBHOOK_SIGNATURE_HEADER` + `SNELSTART_WEBHOOK_SIGNATURE_ALGO`
- 🔒 #2 Secret-lifecycle → Claude-pick (partner liet keuze open); rotation-window via `SNELSTART_WEBHOOK_SECRET` + `SNELSTART_WEBHOOK_SECRET_NEXT`
- 🔒 #3 Tenant-routing-veld → bevestigd `administratieId` UUID-string; eenmalige rename-migratie + parser-aanpassing als veldnaam ooit afwijkt
- ❓ #4 Retry-policy — **2026-05-17 partner heeft nog niet geantwoord**. Defensieve aanname blijft live (5× exponential backoff); idempotency-unique-index al aanwezig (geen rework op happy-path). OData-safety-net polling-job is captured als optionele follow-up — activatie afhankelijk van partner-respons. Tracked in `.docs/todos/` indien retry-policy harder blijkt; verifier-run + Phase 9 admin-replay zijn de fallback-routes.
- 🔒 #5 Event-typen → bevestigd `Relatie.*` + `Verkoopfactuur.*` minimaal; MVP forwardt alles, opt-in-registratie deferred

## Test-baseline-snapshot

- **Volledige Hub-suite (`php artisan test --compact`):** 524 tests / 523 passed / 1801 assertions / 1 incomplete (Phase 3-03 SanctumAbility placeholder) / 1 pre-existing failure (`UserResourceTest::test_super_admin_can_create_user_via_resource` — Phase 9/10 owner, out-of-scope per plans 02/03/04/05).
- **E2E acceptance-suite (`php artisan test --filter=SnelstartWebhookEndToEndTest`):** 5/5 passed / 35 assertions / ~1s. Eerste-run-groen bewijst dat plan 02/03/04 samen sluitend zijn (geen integration-gap gevonden).
- **Plan-02 SDK Pest (`packages/snelstart-api`):** 8/8 SnelstartWebhookSignature-tests groen (SDK-side coverage; Hub-side alleen middleware-tests 7/7).
- **Mollie/Cashier regressie:** 13/13 Mollie-webhook tests groen + 19/19 webhook-block-suite groen — geen kruising.

## Acceptance prerequisites — alle 6 voldaan

- [x] Snelstart-partner-respons ontvangen op Gmail-draft `r-8836998535038336548` (4/5 ❓ bevestigd 2026-05-17)
- [x] ❓-aannames → 🔒 omgezet in `05c-CONTEXT.md` (history bewaard); #4 blijft defensief gedocumenteerd
- [x] `/gsd-execute-phase 5c` uitgevoerd (5/5 plans)
- [x] 5/5 SC's bewezen via groene `SnelstartWebhookEndToEndTest`-suite
- [x] ADR `.docs/decisions/snelstart-webhook-ingress.md` geland (gitignored — Hub-internal artifact)
- [x] HUB-06 mapping → Complete in `REQUIREMENTS.md` + ROADMAP-row

**Status: ACCEPTED 2026-05-17.**

## Next

`/gsd-verify-work 5c` voor verifier-pass (sluit eventuele claim-vs-evidence-drift dicht); daarna phase-merge of `/gsd-ship`.
