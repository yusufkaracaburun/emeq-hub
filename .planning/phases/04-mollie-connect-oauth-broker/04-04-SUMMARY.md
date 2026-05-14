---
phase: 04-mollie-connect-oauth-broker
plan: 04
subsystem: http-api
tags:
  - laravel
  - http
  - sanctum
  - oauth
  - phpunit
dependency-graph:
  requires:
    - 04-02 (OAuthFlowRegistry binding voor 'mollie')
    - 04-01 (OAuthFlow contract + FakeOAuthFlow)
    - Phase 3 (Consumer/Account scoping invariant; oauth_state/oauth_state_expires_at op connections)
  provides:
    - POST /v1/oauth/mollie/init (Sanctum+ability:mollie:write) — JSON {connection_id, redirect_url}
    - GET /v1/oauth/mollie/callback (publiek; state = auth) — JSON {connection_id, status:active} of 400 invalid_or_expired_state
    - Sanctum middleware-aliassen 'ability' + 'abilities' op applicatie-niveau
  affects:
    - routes/api.php (uitgebreid met OAuth-routes)
    - bootstrap/app.php (Sanctum-ability-aliassen toegevoegd)
tech-stack:
  added: []
  patterns:
    - Single-action `__invoke` controllers in `App\Http\Controllers\Api\V1\OAuth` sub-namespace
    - Sanctum-route-middleware `ability:<name>` voor PAT-ability gating
    - `firstOrFail()` op consumer-scoped Account-lookup → 404 (geen info-disclosure)
    - Publieke OAuth-callback met state-parameter als CSRF-mitigation (geen session/cookie)
key-files:
  created:
    - app/Http/Controllers/Api/V1/OAuth/InitController.php
    - app/Http/Controllers/Api/V1/OAuth/CallbackController.php
    - tests/Feature/Api/OAuth/InitTest.php
    - tests/Feature/Api/OAuth/CallbackTest.php
  modified:
    - routes/api.php
    - bootstrap/app.php
decisions:
  - D-01 honored: InitController pre-creëert pending Connection met Str::random(48) state vóór redirect
  - D-02 honored: oauth_state_expires_at = now()->addMinutes(30)
  - D-03 honored: callback-query eist status=pending + oauth_state-match + niet-expired → idempotent replay valt door naar 400
  - D-07 honored: /init is Sanctum+ability-protected, /callback is publiek met state-auth
  - D-08 honored: /init returnt JSON {redirect_url}, geen HTTP-redirect
  - Cross-Consumer-poging op /init → 404 via firstOrFail() (geen 403 → geen info-disclosure)
metrics:
  duration: ~12 min
  completed: 2026-05-14
---

# Phase 04 Plan 04: Mollie Connect OAuth InitController + CallbackController + routes + 7 feature-testpaden Summary

HTTP-laag voor Phase 4: twee single-action controllers (`InitController` Sanctum-protected, `CallbackController` publiek met state-auth) plus 7 feature-tests die ROADMAP SC-1, SC-2 en SC-5 bewijzen.

## Wat is gebouwd

**Controllers (2):**

- `app/Http/Controllers/Api/V1/OAuth/InitController.php` — Single-action `__invoke`. Valideert `account_external_id`, scope't via `$consumer->accounts()->firstOrFail()` (cross-Consumer → 404), creëert een pending `Connection` met `Str::random(48)` als `oauth_state` en 30-min TTL, vraagt `OAuthFlowRegistry::for('mollie')->getAuthorizationUrl(...)` met scopes uit `services.mollie.connect.scopes`, returnt `{connection_id, redirect_url}` als JSON-array (geen HTTP-redirect; D-08).
- `app/Http/Controllers/Api/V1/OAuth/CallbackController.php` — Single-action `__invoke`. Valideert `code` + `state`. Query'd de pending Connection op `(provider=mollie, status=pending, oauth_state=…, oauth_state_expires_at>now)`. Geen match → 400 `{error: invalid_or_expired_state}`. Match → `exchangeCode($connection, $code)` via Registry → 200 `{connection_id, status:'active'}`. Idempotent: tweede callback met dezelfde state vindt geen pending-row meer (status is inmiddels 'active') → 400.

**Routes:** `routes/api.php` — Binnen de bestaande `auth:sanctum`-group is `POST /oauth/mollie/init` toegevoegd met `ability:mollie:write` als nested group; daarbuiten is `GET /oauth/mollie/callback` toegevoegd als publieke route. Naamgeving `api.oauth.mollie.{init,callback}` matcht bestaande dot-conventie. `/v1`-prefix komt van `apiPrefix` in `bootstrap/app.php`.

**Sanctum-middleware-aliassen:** `bootstrap/app.php` heeft nu `'ability' => CheckForAnyAbility::class` en `'abilities' => CheckAbilities::class` in de middleware-alias-tabel. Zonder die binding faalt de route-resolution met "Target class [ability] does not exist" (zie Deviations).

**Tests (7):**

- `tests/Feature/Api/OAuth/InitTest.php` — drie scenario's:
  1. `test_init_creates_pending_connection_and_returns_redirect_url` — MOLLIE_WRITE-PAT → 200 + JSON-struct + pending-Connection in DB.
  2. `test_init_without_ability_returns_403` — MOLLIE_READ-PAT → `assertForbidden()` (Sanctum ability-middleware).
  3. `test_init_with_cross_consumer_account_returns_404` — Consumer A POST'd met external_id van Consumer B → `assertNotFound()` (firstOrFail).
- `tests/Feature/Api/OAuth/CallbackTest.php` — vier scenario's:
  1. `test_callback_exchanges_code_when_state_matches` — happy path; verifieert `status='active'`, `oauth_state=null`, `access_token` begint met `access_test_fake_` (FakeOAuthFlow-binding).
  2. `test_callback_with_invalid_state_returns_400` — tampered state → 400 + body `{error:'invalid_or_expired_state'}`.
  3. `test_callback_with_expired_state_returns_400` — pending+expired Connection → 400.
  4. `test_second_callback_with_same_state_returns_400` — eerste callback OK, tweede met identieke state → 400 (D-03 idempotency).

Beide test-files binden `MollieConnectOAuthFlow::class → FakeOAuthFlow::class` in `setUp()` zodat de Registry's `$container->make(MollieConnectOAuthFlow::class)` de fake retourneert — geen echte Mollie-HTTP-calls in feature-tests.

## Commit-trail

| Task | Type   | Hash    | Bericht                                                                              |
| ---- | ------ | ------- | ------------------------------------------------------------------------------------ |
| 1    | feat   | 698c7f4 | feat(04-04): InitController + CallbackController + routes voor Mollie Connect OAuth  |
| 2    | test   | 7f58793 | test(04-04): InitTest (3 paden) + CallbackTest (4 paden) — bewijst SC-1/SC-2/SC-5    |

## Verificatie

- `php artisan route:list --path=oauth/mollie` → 2 routes (POST init, GET callback).
- `php artisan test --compact --filter='InitTest|CallbackTest'` → 7 passed / 16 assertions / 480 ms.
- `php artisan test --compact` (volledige suite) → **127 passed / 376 assertions / 1 incomplete / 0 failures**. De `incomplete` is pre-existing (markTestIncomplete in `tests/Feature/Api/SanctumAbilityTest`).

## ROADMAP success-criteria gedekt

| ROADMAP SC | Beschrijving                                          | Bewezen door                                                            |
| ---------- | ----------------------------------------------------- | ----------------------------------------------------------------------- |
| SC-1       | /init pre-creëert Connection + returnt redirect_url   | InitTest test 1 — `assertJsonStructure(['connection_id','redirect_url'])` + `assertDatabaseHas(connections, status=pending)` |
| SC-2       | Callback ruilt code in voor tokens + writes encrypted | CallbackTest test 1 — `status=active`, `access_token` begint met fake-prefix; encryption-cast leunt op Phase 3-invariant + `ConnectionEncryptionTest` (al groen) |
| SC-5       | Tampered state → 400                                  | CallbackTest tests 2 + 3 + 4 — invalid/expired/replay-state alle drie 400 |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Sanctum-ability-middleware-aliassen ontbraken**

- **Found during:** Task 2, eerste test-run.
- **Issue:** `php artisan test` faalde op `Target class [ability] does not exist`. Sanctum v4 levert `CheckAbilities` + `CheckForAnyAbility` middleware, maar Laravel 11+ app-skeleton aliast die niet auto — ze moeten expliciet in `bootstrap/app.php` worden gewired. Het plan + PATTERNS gingen ervan uit dat `ability:mollie:write` "out-of-the-box werkt", maar deze repo had dat nog niet geregistreerd (de bestaande `ConnectionController`/`PassThroughController` doen handmatige `abort_unless($has, 403, 'insufficient_ability')`-checks i.p.v. middleware).
- **Fix:** `bootstrap/app.php` uitgebreid met twee imports (`CheckAbilities`, `CheckForAnyAbility`) en twee alias-entries:
  ```php
  'abilities' => CheckAbilities::class,
  'ability' => CheckForAnyAbility::class,
  ```
- **Files modified:** `bootstrap/app.php`
- **Commit:** `7f58793` (samengevoegd met Task 2's test-commit; de tests vereisten de alias om te kunnen draaien)

Geen architecturele wijziging: dit is de canonieke Sanctum-v4 setup zoals beschreven in de officiële Laravel/Sanctum docs (`Route::middleware('ability:...')`). Geen impact buiten OAuth-routes — bestaande handmatige `abort_unless`-checks in `Connection/Account/PassThrough`-controllers blijven werken zonder wijziging.

### Auth Gates

Geen — Task 2 vereiste geen externe authenticatie (FakeOAuthFlow ving de Mollie-call op).

### Threat-model coverage

Alle zeven STRIDE-items uit het plan zijn ofwel via tests bewezen (T-04-04-01 tampering, T-04-04-04 replay, T-04-04-05 cross-Consumer, T-04-04-06 ability-bypass) of expliciet als `accept`/buiten-scope gemarkeerd (T-04-04-07 open-redirect, T-04-04-03 HTTPS-redirect_uri).

## Bekende beperkingen

- `services.mollie.connect.scopes` is statisch (D-10) — per-Consumer scope-differentiation komt in v1.0+.
- `MOLLIE_CONNECT_*` env-vars moeten in `.env` aanwezig zijn voor productie-init; bij testen levert `FakeOAuthFlow` de redirect-url zonder die config te raken.
- `bootstrap/app.php`'s nieuwe ability-aliassen kunnen door toekomstige routes hergebruikt worden (bv. een `ability:mollie:read` route die `ConnectionController::show` zou kunnen vervangen door route-middleware in plaats van controller-aborts).

## Threat Flags

Geen nieuwe trust-boundaries buiten wat in het plan's `<threat_model>` staat.

## Self-Check: PASSED

Verificatie van claims:

- `app/Http/Controllers/Api/V1/OAuth/InitController.php` — FOUND
- `app/Http/Controllers/Api/V1/OAuth/CallbackController.php` — FOUND
- `tests/Feature/Api/OAuth/InitTest.php` — FOUND
- `tests/Feature/Api/OAuth/CallbackTest.php` — FOUND
- `routes/api.php` — MODIFIED (InitController + CallbackController imports + 2 routes)
- `bootstrap/app.php` — MODIFIED (Sanctum-aliassen)
- Commit `698c7f4` — FOUND (Task 1)
- Commit `7f58793` — FOUND (Task 2)
- Tests: 127/127 passed (1 pre-existing incomplete)
- Routes: `php artisan route:list --path=oauth/mollie` toont 2 routes
