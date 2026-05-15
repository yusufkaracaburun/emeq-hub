---
status: partial
phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
source: [05a-VERIFICATION.md]
started: 2026-05-15T09:15:00Z
updated: 2026-05-15T09:15:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Browser-render /docs/api Scramble UI
expected: Alle 22 Mollie-routes + 3 OAuth-routes + 3 webhook-routes verschijnen met working "Try it out"-buttons. Payments + Customers + PaymentLinks tonen edit-baar request-body schema.
why_human: Scramble UI render kan niet headless gevalideerd worden zonder echte browser; OpenAPI-paths zijn wel programmatisch bevestigd (ScrambleRouteDiscoveryTest 11/11 groen) maar UI-rendering + Try-it-out-functionaliteit zelf zijn visueel-interactief.
result: [pending]

### 2. Real Mollie testmode webhook hit naar /webhooks/mollie/{connection_id}
expected: Mollie's next-gen subscription-webhook (X-Mollie-Signature, JSON body) verifieert correct, anti-spoofing-fetch slaagt, fan-out POST verschijnt op een test-Consumer-callback (bv. https://webhook.site). Audit-rij in `webhook_calls` heeft geen exception.
why_human: Vereist een live Mollie testmode account + Connect-koppeling + publiek bereikbare ngrok-/Caddy-tunnel. SDK-pad is via MollieWebhookSignature-helper getest met stubs maar de eind-tot-eind validatie tegen Mollie's eigen signer is niet geautomatiseerd.
result: [pending]

### 3. Concrete POST /v1/mollie/payments → ouder doorloopt Mollie test-mode → webhook ontvangen
expected: Naschool-scenario (NSCH-03 dependency): Consumer-PAT met `mollie:write` + Account-id van een test-school stuurt POST payment → ontvangt `_links.checkout.href` → ouder doorloopt Mollie test-modus → Mollie post webhook naar Hub → fan-out naar Naschool-callback succesvol.
why_human: End-to-end smoke met echte Mollie test-omgeving is buiten scope van Phase 5a (valt onder Phase 8 NSCH-03) maar bewijst SC-1 + SC-3 + SC-4 hard.
result: [pending]

## Summary

total: 3
passed: 0
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps
