---
quick_id: 260514-nup
description: Cleanup Phase 3 review-findings BL-02 + WR-01 + WR-03
date: 2026-05-14
status: complete
phase_3_review_resolved:
  - BL-02 (ability-validatie)
  - WR-01 (throttle:api)
  - WR-03 (unique constraint actieve Connection per provider)
key-files:
  created:
    - database/migrations/2026_05_14_151327_add_active_unique_to_connections.php
    - tests/Feature/ConnectionUniqueActiveTest.php
  modified:
    - app/Console/Commands/HubConsumerCreate.php
    - tests/Feature/Console/HubConsumerCreateTest.php
    - app/Providers/AppServiceProvider.php
    - bootstrap/app.php
commits:
  - a66e2e3
  - 92941fe
  - 1fcde28
tests: 32 passed / 1 incomplete (Phase 5b placeholder) / 71 assertions
---

# Cleanup Phase 3 review-findings — BL-02 + WR-01 + WR-03

Drie REVIEW-findings uit `03-REVIEW.md` weggewerkt voordat Phase 5b op de skeleton landt.

## Fix 1 — BL-02: ability-validatie in `hub:consumer:create` (`a66e2e3`)

Onbekende abilities (`'snelstart:reed'`) werden silent in `personal_access_tokens.abilities`
opgeslagen en faalden pas op de Sanctum ability-middleware. Nu wordt input vóór
Consumer-creation gevalideerd tegen `TokenAbilities::all()` met fail-fast exit
(`Command::INVALID`) en een lijst van geldige abilities in de error-output.

Test toegevoegd: `test_unknown_ability_is_rejected_before_consumer_creation` — bewijst
dat een typo geen DB-rij creëert en exit-code 2 terugkomt.

## Fix 2 — WR-01: `throttle:api` op `/v1/*` (`92941fe`)

`/v1/*` routes hadden geen rate-limiter — een lekt-PAT scenario kon ongeremd /v1/ping
(en straks 5b's pass-through) bombarderen. Nu:

- `RateLimiter::for('api')` gedefinieerd in `AppServiceProvider::boot()`: 60/min,
  scope = `"consumer:{id}"` voor geauthenticeerde Consumers, fallback `"ip:{ip}"`
  voor unauth. Dit matched de REVIEW-suggestie ("per-Consumer i.p.v. IP") direct.
- `bootstrap/app.php` voegt `'throttle:api'` toe aan de api-middleware-group via
  `$middleware->api(prepend: [...])`.
- Geverifieerd via `app('Illuminate\Contracts\Http\Kernel')->getMiddlewareGroups()['api']`
  → `throttle:api,Illuminate\Routing\Middleware\SubstituteBindings`.

## Fix 3 — WR-03: unique-index op actieve Connections (`1fcde28`)

Een Account kon meerdere actieve Connections per provider hebben — race-condition
voor 5b's `POST /v1/connections` provisioning. Postgres-partial-index lost dit op
zonder revoked-rows weg te gooien:

```sql
CREATE UNIQUE INDEX connections_account_id_provider_active_unique
ON connections (account_id, provider) WHERE revoked_at IS NULL
```

Three tests in `ConnectionUniqueActiveTest`:
- duplicate active → `QueryException` ✓
- replace-na-revoke werkt ✓
- cross-provider blijft toegestaan (Snelstart + Mollie op zelfde Account) ✓

Migration is forward-only (CLAUDE.md invariant). `down()` aanwezig voor lokale dev/test.

## Verification

- `php artisan test --compact` → **32 passed, 1 incomplete, 71 assertions** (was 28/63)
- Pint clean op alle gewijzigde files
- `php artisan migrate` heeft de unique-index toegevoegd aan de live dev-DB
- Resterende REVIEW-items (WR-02, WR-04 t/m WR-11) raken Phase 5b's directe pad niet
  en blijven open voor latere passes

## Self-Check: PASSED

3/3 acceptance criteria bewezen:
1. BL-02: invalid ability faalt vóór consumer-create — getest ✓
2. WR-01: throttle:api in api-middleware-group — getest via Kernel inspection ✓
3. WR-03: duplicate active connection rejected, revoke-then-replace allowed — getest ✓
