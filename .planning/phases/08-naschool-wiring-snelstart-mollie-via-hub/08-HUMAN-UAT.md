---
status: partial
phase: 08-naschool-wiring-snelstart-mollie-via-hub
source: [08-VERIFICATION.md]
started: "2026-05-17T15:50:00Z"
updated: "2026-05-17T15:50:00Z"
---

## Current Test

[awaiting human testing]

## Tests

### 1. End-to-end Mollie checkout via Hub-Connect op school A's eigen Mollie-account (SC-3)

expected: Betaling-rij zichtbaar in school A's Mollie test-dashboard met juiste amount + reference; geen rij in Emeq's eigen Mollie-dashboard. Vereist Naschool-repo wiring (Mollie-call in Naschool-controller) + live OAuth-koppeling op fysiek Mollie test-account voor school A + manuele ouder-doorloop. Hub-side substrate staat klaar (`/v1/mollie/*` pass-through Phase 5a + `StartOAuthFlowAction` voor OAuth-roundtrip + `OnboardConsumer`-wizard voor initial-koppel).
result: [pending]

### 2. Webhook → Hub → Naschool-callback → enrollment-status `paid` (SC-4)

expected: Na succesvolle Mollie-betaling: Mollie POST naar Hub webhook → Hub signature-verifies + audit-logs → fan-out naar Naschool's `webhook_callback_url` met HMAC-signature → Naschool callback-handler updatet enrollment-status naar `paid` zonder handmatige interventie binnen ~5s. Hub-side fan-out (Spatie webhook-server, Phase 5a) bestaat en is getest; round-trip vereist Naschool's webhook-callback endpoint + signature-verify.
result: [pending]

### 3. EnrollmentConfirmed → Snelstart-verkoopfactuur via Hub-pass-through (SC-2 + NSCH-02)

expected: Naschool dispatched `SyncEnrollmentToSnelstartJob` op `EnrollmentConfirmed`; job POSTs naar Hub `/v1/snelstart/Verkoopfacturen` met `X-Account-Id` (school A) → Hub resolved school A's Snelstart-Connection.client_key + subscription_key + subscription_id → verkoopfactuur aangemaakt in Snelstart test-env, zichtbaar in Snelstart-UI of via API-GET met juiste relatie + bedrag. NSCH-02 + SC-2 zijn volledig Naschool-repo werk; out_of_scope_per_D-03. Hub-side substrate (Phase 5b pass-through + onboard-wizard voor Snelstart-credentials) staat klaar.
result: [pending]

### 4. Composer-resolve van emeq/snelstart-api + emeq/mollie-api in Naschool backend (SC-1)

expected: `composer install` in `school-activities-hub/backend/` resolved publieke VCS-repos zonder GitHub-auth-token; lock-file vermeldt beide SDK-packages; `composer show emeq/snelstart-api emeq/mollie-api` toont beide. Vereist Naschool composer.json edits + run in Naschool repo; out_of_scope_per_D-03.
result: [pending]

### 5. End-to-end smoke-runbook gedocumenteerd (SC-5)

expected: Handmatige doorloop van NSCH-01 + NSCH-03 happy paths is genoteerd in `.docs/` van Naschool-repo OF in een gedeeld document, met stap-volgorde + screenshots / cURL-snippets. Shared deliverable — Naschool-repo werk + e2e-validatie van bovenstaande items 1-4.
result: [pending]

## Summary

total: 5
passed: 0
issues: 0
pending: 5
skipped: 0
blocked: 0

## Gaps
