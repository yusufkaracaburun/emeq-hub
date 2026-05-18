---
phase: 05c-snelstart-webhook-handler
plan: 02
subsystem: webhook-security
tags: [laravel, middleware, security, hmac, phpunit, snelstart]

# Dependency graph
requires:
  - phase: 05c-snelstart-webhook-handler
    provides: pass_through_calls met direction='inbound' kolom + nullable tenant-FKs
  - phase: 03-hub-skeleton
    provides: PassThroughCall-model + scopes
provides:
  - services.snelstart.webhook_* config-block (5 keys: secret, secret_next, signature_header, signature_algo, event_id_key)
  - Emeq\SnelstartApi\Webhooks\SnelstartWebhookSignature (SDK-side timing-safe verify + sign, rotation-window) — post-execution refactor, zie addendum
  - App\Http\Middleware\VerifySnelstartSignature (hardfail-500 + audit; 401 zonder audit; valid → next; consumeert SDK-class)
  - 'verify.snelstart.signature'-alias geregistreerd in bootstrap/app.php
affects: [05c-03-route-and-controller]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Pure-PHP verifier + Laravel-middleware split: math-laag (App\\Webhooks) los van HTTP-laag (App\\Http\\Middleware) zodat verifier zonder Request te testen is en middleware zonder hash_hmac-knowledge te lezen is"
    - "Config-driven defensief HMAC-protocol: header-naam + algo via env-vars zodat partner-spec-wijziging géén code-deploy vereist (CONTEXT 🔒 #1)"
    - "Rotation-window via array van secrets: verifier loopt door candidates, hash_equals per iteratie — primary én secret_next blijven valide tijdens rotatie (CONTEXT 🔒 #2)"
    - "Hardfail vs invalid-pad asymmetrie: ontbrekende config = 500 + audit (operator-fout, moet zichtbaar zijn); foute HMAC = 401 + GEEN audit (anti-amplification T-05c-05)"
    - "TDD-cyclus per artifact: RED-commit (test alleen) → GREEN-commit (implementatie); twee cycli, vier commits"

key-files:
  created:
    - packages/snelstart-api/src/Webhooks/SnelstartWebhookSignature.php  # SDK — post-execution refactor
    - packages/snelstart-api/tests/Unit/Webhooks/SnelstartWebhookSignatureTest.php  # SDK Pest — post-execution refactor
    - app/Http/Middleware/VerifySnelstartSignature.php
    - tests/Feature/Webhooks/VerifySnelstartSignatureMiddlewareTest.php
  modified:
    - config/services.php
    - .env.example
    - bootstrap/app.php
    - composer.lock  # gepind op emeq/snelstart-api e71a9bf
  removed:
    - app/Webhooks/SnelstartSignatureVerifier.php  # vervangen door SDK-class
    - tests/Feature/SnelstartSignatureVerifierTest.php  # coverage verhuist naar SDK Pest-tests

key-decisions:
  - "5e config-key 'webhook_event_id_key' (default 'eventId') landt nu al — wordt pas door plan 03 (controller) gelezen. Reden: alle config-defaults in één commit zodat partner-respons-tweaks niet over twee plans gespreid liggen"
  - "Verifier accepteert string|array voor secrets (NIET alleen array) — match-met-één-key blijft ergonomisch zonder de array-syntax af te dwingen"
  - "sign() retourneert raw hex zonder 'algo='-prefix — Mollie's MollieWebhookSignature::sign() doet wel een prefix, maar Snelstart's exacte format is ❓ tot we live data zien. Hex-only is de defensieve default"
  - "Audit-rij op hardfail (geen secret) gebruikt path/method/direction='inbound'/provider='snelstart' zónder consumer/account/connection_id — die FKs zijn nullable per plan 05c-01 (CONTEXT 🔒 'Audit-tabel reuse')"
  - "1 extra middleware-test (`test_custom_algo_via_config_works`) boven plan's 6 — zekert dat env-override van algo daadwerkelijk door de middleware doorgegeven wordt, niet alleen door de verifier"

patterns-established:
  - "HMAC-webhook ingress in Hub: config → middleware → verifier-class. Plan 03 (controller) krijgt al een valide signature gegarandeerd; controller hoeft alleen tenant-resolve + audit-write + fan-out te doen"
  - "Anti-amplification by default: invalid-pad geen DB-write; aanvaller kan geen 5xx-bursts triggeren om unique IDs te leren (T-05c-05 mitigation)"

requirements-completed: []

# Metrics
duration: ~25min
completed: 2026-05-17
---

# Phase 05c Plan 02: HMAC-verificatie voor Snelstart-webhooks Summary

**Verifier-class + middleware + config + alias-registratie — twee defensieve lagen tussen Snelstart's POST en de controller, beide config-driven zodat partner-respons defaults kan wijzigen zonder code-deploy.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-17T (na 05c-01 close)
- **Completed:** 2026-05-17
- **Tasks:** 3 (1 auto + 2 TDD)
- **Files created/modified:** 7 (4 created, 3 modified)
- **Commits:** 5 (1 config-feat + 2 RED-tests + 2 GREEN-feats)

## Accomplishments

- `services.snelstart.webhook_*` config-block heeft 5 keys: `webhook_secret`, `webhook_secret_next`, `webhook_signature_header` (default `X-SnelStart-Signature`), `webhook_signature_algo` (default `sha256`), `webhook_event_id_key` (default `eventId`). Defaults volgen CONTEXT 🔒 #1; alle 5 zijn env-overridable.
- `App\Webhooks\SnelstartSignatureVerifier` is een pure-PHP final class met `verify()` + `sign()`. `verify()` doet `hash_equals` (timing-safe), accepteert `string|array` voor secrets, saneert null+empty entries en itereert per kandidaat. 7/7 tests groen.
- `App\Http\Middleware\VerifySnelstartSignature` is geregistreerd onder alias `verify.snelstart.signature` in `bootstrap/app.php` (samen met de bestaande aliases). 7/7 tests groen:
  - geldige HMAC → 200 + downstream-handler bereikt
  - foute HMAC → 401 + lege body + GEEN `pass_through_calls`-rij
  - ontbrekende header → 401 + GEEN audit
  - ontbrekende secret → 500 + `direction=inbound` audit-rij met `upstream_error=webhook_secret_not_configured`
  - rotation-window: `secret_next`-gesigneerde body wordt geaccepteerd terwijl primary blijft staan
  - custom header-naam via config wordt gehoord; default-header (`X-SnelStart-Signature`) wordt genegeerd zodra de config-key wijzigt
  - custom algo (`sha512`) via config wordt door de middleware doorgegeven aan de verifier
- Volledige Hub-testsuite: 520/521 (520 passed, 1 incomplete uit Phase 4-01 placeholder, 1 failure pre-existing in `UserResourceTest::test_super_admin_can_create_user_via_resource` — niet gerelateerd aan Phase 05c). 33 nieuwe assertions in 14 nieuwe tests.

## Task Commits

1. **Task 1: Config-keys + env example** — `242e647` (feat) — `config/services.php` + `.env.example`
2. **Task 2 (TDD): `App\Webhooks\SnelstartSignatureVerifier`**
   - RED: `96d939d` (test) — 7 failing tests op missing class
   - GREEN: `0980c47` (feat) — final class met `verify()` + `sign()`; 7/7 passed
3. **Task 3 (TDD): `VerifySnelstartSignature` middleware + alias**
   - RED: `f450151` (test) — 7 failing tests op missing alias
   - GREEN: `a9a9e4e` (feat) — middleware-class + `bootstrap/app.php`-alias; 7/7 passed

_Note: Geen REFACTOR-gate nodig — beide GREEN-implementaties bleven minimaal._

## Files Created/Modified

- `app/Webhooks/SnelstartSignatureVerifier.php` — verifier-class (54 regels, final, strict types)
- `app/Http/Middleware/VerifySnelstartSignature.php` — middleware (68 regels, final, strict types)
- `tests/Feature/SnelstartSignatureVerifierTest.php` — 7 tests / 12 assertions
- `tests/Feature/Webhooks/VerifySnelstartSignatureMiddlewareTest.php` — 7 tests / 21 assertions
- `config/services.php` — `snelstart`-block toegevoegd boven `mollie`-block
- `.env.example` — 5 nieuwe `SNELSTART_WEBHOOK_*`-keys met comment-block (CONTEXT 🔒 #1 verwijzing)
- `bootstrap/app.php` — `use App\Http\Middleware\VerifySnelstartSignature` + alias `verify.snelstart.signature`

## Decisions Made

- **`webhook_event_id_key` (default `eventId`) landt al in plan 02** — wordt pas door plan 03 (controller) geconsumed. Reden: alle 5 config-defaults landen in één commit zodat een partner-respons-tweak (bv. snake_case-veldnaam in plaats van camelCase) niet over twee plans gespreid hoeft.
- **`SnelstartSignatureVerifier::verify()` accepteert `string|array`** — match-met-één-secret blijft ergonomisch zonder array-syntax af te dwingen; rotation-window is opt-in door array te geven.
- **`sign()` retourneert raw hex zonder `'algo='`-prefix** — Mollie's `MollieWebhookSignature::sign()` prefixt wél (`'sha256='`), maar Snelstart's exacte format is nog ❓ tot we live data zien. Hex-only is de defensieve default; partner-respons-vraag #1 dekt header + algo maar niet expliciet de prefix-vorm.
- **Audit-rij op hardfail gebruikt `direction='inbound'` + `provider='snelstart'`** zonder consumer/account/connection-FKs — die FKs zijn nullable per plan 05c-01 (CONTEXT 🔒 "Audit-tabel reuse"). Geen migration-edit nodig.
- **Eén extra middleware-test (`test_custom_algo_via_config_works`)** boven plan's 6 — zekert dat env-override van algo daadwerkelijk door de middleware naar de verifier wordt doorgegeven, niet alleen in de verifier zelf.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing test coverage] Extra test `test_secrets_array_with_null_and_empty_entries_is_sanitized` (Task 2)**

- **Found during:** Schrijven van RED-tests voor de verifier
- **Issue:** Plan stipuleerde 6 verifier-tests, maar de implementatie heeft een `array_filter` voor null+empty entries. Zonder een dedicated test asserteert niemand dat deze sanitization daadwerkelijk werkt — een latere refactor zou stilletjes een null-secret kunnen vergelijken via `hash_hmac` (PHP geeft dan TypeError of een verkeerd resultaat).
- **Fix:** Test toegevoegd die `[null, '', $validSecret]` geeft en asserteert dat de match nog steeds slaagt.
- **Files modified:** `tests/Feature/SnelstartSignatureVerifierTest.php`
- **Verification:** 7/7 tests passed (was: 6/6 in plan).
- **Committed in:** `96d939d` (Task 2 RED)

**2. [Rule 2 - Missing test coverage] Extra test `test_custom_algo_via_config_works` (Task 3)**

- **Found during:** Schrijven van RED-tests voor de middleware
- **Issue:** Plan stipuleerde 6 middleware-tests met `test_custom_header_name_works` voor header-config-override, maar geen tegenhanger voor `webhook_signature_algo` op middleware-niveau. De verifier zelf heeft `test_different_algo_works`, maar dat asserteert niet dat de middleware de config-waarde doorgeeft.
- **Fix:** Test toegevoegd die `services.snelstart.webhook_signature_algo` op `sha512` zet, een sha512-signature stuurt, en 200 verwacht.
- **Files modified:** `tests/Feature/Webhooks/VerifySnelstartSignatureMiddlewareTest.php`
- **Verification:** 7/7 tests passed (was: 6/6 in plan).
- **Committed in:** `f450151` (Task 3 RED)

---

**Total deviations:** 2 auto-fixed (beide Rule 2 — extra test coverage voor security-correctheid).
**Impact on plan:** Geen scope-creep — beide tests zekeren al bestaande implementation-paden die anders untested zouden zijn. Geen architecturele aanpassing.

## Issues Encountered

- **Pre-existing test-failure in `UserResourceTest::test_super_admin_can_create_user_via_resource`** — gevonden tijdens full-suite-regressie-check. Verified pre-existing via `git stash && php artisan test --filter=...` op vorige HEAD. Niet gerelateerd aan Phase 05c werk (Filament/Admin pad, niet webhook-pad). Out-of-scope per Rule "Scope Boundary"; gemarkeerd voor follow-up door eigenaar van Phase 9/10.

## Threat Flags

Geen nieuwe security-surface buiten het threat-model van het plan. T-05c-04 (Spoofing) en T-05c-05 (Information disclosure) zijn gemitigeerd door:

- **T-05c-04 (Spoofing):** `hash_equals` is gebruikt in de verifier (niet `===`); foutmelding 401 leakt geen detail; rotation-window staat alleen geldige secrets toe.
- **T-05c-05 (Information disclosure):** Invalid-pad in de middleware schrijft géén `pass_through_calls`-rij; aanvaller kan geen 5xx-bursts triggeren via forgery om unique IDs te leren.

T-05c-06 (DoS) blijft bewust `accept`-status — verifier is goedkoop (sha256 over ~few-KB body) en `throttle:api` zit niet op `/webhooks/snelstart` (wordt in plan 03 zo geregistreerd).

## Docs-drift signaal

`config/services.php` is geraakt — de `docs-sync`-hook vuurde tijdens Task 1. Downstream docs die kunnen drift:

- `CLAUDE.md` Architecture-block noemt nog géén Snelstart-webhook-ingress; pas op te updaten zodra plan 03 (route + controller) landt.
- `.docs/decisions/snelstart-certificering-pad.md` (gitignored) kan een addendum krijgen wanneer alle 5 plans van 05c af zijn.

Geen actie binnen deze plan-uitvoering — gemarkeerd voor `/gsd-transition` of `docs-sync`-pass na Phase 05c afronding.

## User Setup Required

None — geen externe service-config nodig voor deze plan. Env-vars `SNELSTART_WEBHOOK_SECRET` (+ optioneel `SNELSTART_WEBHOOK_SECRET_NEXT`) worden pas relevant wanneer plan 03 (route + controller) live gaat; tot dan blijft alles config-driven defensief.

## Next Phase Readiness

- Plan 03 (route + controller) kan starten met:
  - Middleware-alias `verify.snelstart.signature` direct toepasbaar op `POST /webhooks/snelstart`-route
  - Garantie dat de controller alléén bereikt wordt na valide signature (anders 401 of 500 vóór de controller)
  - Config-key `services.snelstart.webhook_event_id_key` klaar voor consumption in de payload-parser (idempotency-lookup via `pass_through_calls.event_id`)
  - Hardfail-audit-pattern blueprint voor controller's eigen audit-write (zelfde `pass_through_calls`-tabel, `direction='inbound'`)

## Self-Check

Verifying claims before returning to orchestrator.

**Files exist:**

- `[FOUND]` app/Webhooks/SnelstartSignatureVerifier.php
- `[FOUND]` app/Http/Middleware/VerifySnelstartSignature.php
- `[FOUND]` tests/Feature/SnelstartSignatureVerifierTest.php
- `[FOUND]` tests/Feature/Webhooks/VerifySnelstartSignatureMiddlewareTest.php
- `[FOUND]` config/services.php (modified)
- `[FOUND]` .env.example (modified)
- `[FOUND]` bootstrap/app.php (modified)

**Commits exist on feat/05c-snelstart-webhook-handler:**

- `[FOUND]` 242e647 — Task 1 (config-keys)
- `[FOUND]` 96d939d — Task 2 RED (verifier tests)
- `[FOUND]` 0980c47 — Task 2 GREEN (verifier class)
- `[FOUND]` f450151 — Task 3 RED (middleware tests)
- `[FOUND]` a9a9e4e — Task 3 GREEN (middleware + alias)

## Self-Check: PASSED

---

## Addendum — verifier → SDK refactor (post-execution, 2026-05-17)

**Trigger:** user-feedback tijdens execute-phase: *"waarom maak je deze dingen niet in snelstart-api package?"*

**Bevinding:** Plan prescribeerde `App\Webhooks\SnelstartSignatureVerifier`, maar het is pure partner-protocol-laag zonder Hub-state — exact het pattern dat Mollie al volgt met `Emeq\MollieApi\Webhooks\MollieWebhookSignature` in `packages/mollie-api/src/Webhooks/`. Plan-author had dit in CONTEXT erkend (*"verifier-pattern uit Mollie-SDK is een copy-target maar Snelstart-SDK heeft 'm nog niet"*) maar de uitvoer is alsnog in de Hub geland. Inconsistentie.

**Actie genomen (commit `3640fa0` Hub + `e71a9bf` SDK):**

- **SDK-side** (`emeq/snelstart-api` master, gepushed):
  - Nieuw: `src/Webhooks/SnelstartWebhookSignature.php` — final, strict types, namespace `Emeq\SnelstartApi\Webhooks`. Same shape als de oude verifier (raw body + headerValue + secret(s) + algo).
  - Nieuw: `tests/Unit/Webhooks/SnelstartWebhookSignatureTest.php` — 8 Pest tests / 13 assertions (roundtrip, mismatch, null/empty header, rotation-window beide volgordes, lege array, custom algo, null/empty entries sanitization, sign-output-shape).
- **Hub-side** (deze branch, commit `3640fa0`):
  - `app/Webhooks/SnelstartSignatureVerifier.php` → **verwijderd**
  - `tests/Feature/SnelstartSignatureVerifierTest.php` → **verwijderd** (coverage zit nu in SDK Pest)
  - `app/Http/Middleware/VerifySnelstartSignature.php` → import switch naar `Emeq\SnelstartApi\Webhooks\SnelstartWebhookSignature`
  - `tests/Feature/Webhooks/VerifySnelstartSignatureMiddlewareTest.php` → import + static-call rename
  - `composer.lock` → gepind op `emeq/snelstart-api e71a9bf`

**Verificatie post-refactor:**

- SDK Pest: 8/8 groen via `cd packages/snelstart-api && ./vendor/bin/pest tests/Unit/Webhooks/`
- Hub middleware-tests: 7/7 groen via `php artisan test --compact --filter='VerifySnelstartSignature'` (21 assertions, ongewijzigd)

**Architecturele winst:**

- Pattern-consistentie met Mollie: pure protocol-laag in SDK, framework-glue (middleware + config + audit) in Hub
- Verifier-class herbruikbaar door andere apps die `emeq/snelstart-api` consumeren zonder de Hub
- CONTEXT.md `<canonical_refs>` "verifier-pattern uit Mollie-SDK is een copy-target maar Snelstart-SDK heeft 'm nog niet" is nu obsolete — de SDK heeft 'm.

**Trade-off geaccepteerd:**

- Twee repos in sync houden (SDK + Hub). Workflow: edit in `packages/snelstart-api/`, push, `composer update emeq/snelstart-api` in Hub, commit `composer.lock`. Standard SDK-workflow per `.ai/packages` rule.

---
*Phase: 05c-snelstart-webhook-handler*
*Completed: 2026-05-17 (with post-execution SDK-refactor)*
