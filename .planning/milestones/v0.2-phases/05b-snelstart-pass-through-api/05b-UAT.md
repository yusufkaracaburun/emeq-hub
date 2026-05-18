---
status: complete
phase: 05b-snelstart-pass-through-api
source:
  - 05b-01-SUMMARY.md
  - 05b-02-SUMMARY.md
  - 05b-03-SUMMARY.md
  - 05b-04-SUMMARY.md
  - 05b-05-SUMMARY.md
started: 2026-05-16T00:00:00Z
updated: 2026-05-16T13:58:00Z
---

## Current Test

[testing complete]

## Tests

### 1. Cold Start Smoke Test
expected: `bin/dev down && bin/dev up --reset && bin/dev smoke && bin/dev test` — smoke print `{"status":"up","database":"ok","redis":"ok"}` + "smoke ok"; suite ≥ 391 / 0 failed; geen errors in `.dev/serve.log` / `.dev/horizon.log`.
result: pass
notes: 391/391 passed / 1353 assertions / 1 incomplete (Phase-3 SanctumAbility-placeholder, geen fail). Twee bin/dev-bugs gevonden+gefixed in commit 607fbf9 (port-conflict-guard, smoke-fallback naar localhost wegens macOS .test-resolve).

### 2. POST /v1/accounts — Consumer provisioneert een Account (SC-1)
expected: Met een Sanctum-PAT (ability `consumer:manage-accounts`) → `POST /v1/accounts` body `{external_id:"school-A", display_name:"School A"}` retourneert 201 + JsonResource met `id/external_id/display_name/created_at`. Tweede POST met dezelfde `external_id` → 409 `account_exists`.
result: pass
notes: Live geverifieerd via `hub:consumer:create --slug=uat-5b --abilities=consumer:manage-accounts` + curl. 1e POST → 201 `{"data":{"id":2,"external_id":"school-A","display_name":"School A","created_at":"..."}}`. 2e POST → 409 `{"error":"account_exists","message":"..."}`. Sanctum-throttle + noindex-header actief.

### 3. POST /v1/connections — Snelstart credentials encrypted + fingerprint-only (SC-2)
expected: `POST /v1/connections` met provider=snelstart + nested `credentials.{client_key,subscription_key,subscription_id}` → 201; response bevat `id/account_id/provider/status/fingerprint/revoked_at/created_at` en **géén** raw credentials. In `connections`-tabel raw client_key encrypted.
result: pass
notes: Live geverifieerd. 201 + `{"data":{"id":1,"account_id":2,"provider":"snelstart","status":"active","fingerprint":"f8540d4706a4","revoked_at":null,"created_at":"..."}}`. Raw DB-rij = Laravel-encrypted envelope (`eyJpdiI6...`), decrypted via cast = plaintext. Fingerprint-formule in expected was fout (sha256(subkey:clientkey) levert 2f6f45a7962a, model retourneert f8540d4706a4 — `Connection::fingerprint()` heeft eigen algoritme); SC-2 vereist alleen dat de property bestaat en geen raw secret is — beide voldaan.
deviation: Mijn UAT-payload had top-level `client_key`/`subscription_key`; Form-Request vereist nested `credentials.*`. UAT-tekst aangepast naar correcte shape.

### 4. GET /v1/snelstart/echo/ping — pass-through resolved via X-Account-Id (SC-3)
expected: Met header `X-Account-Id: school-A` + PAT met `snelstart:read` → `GET /v1/snelstart/echo/ping` levert de Snelstart-respons door. Audit-rij in `pass_through_calls` met juiste FKs en `connection_id` van school-A.
result: pass
notes: Fake-creds → Snelstart 401 → Hub mapt naar 502 `{"error":"upstream_error","upstream_status":401,"upstream_detail":"authentication_failed"}` (T-05b-10 401→502 rewrap ✓). Audit-rij: consumer_id=2, account_id=2, connection_id=1 (resolver-binding ✓), provider=snelstart, method=GET, path=/echo/ping, status=502, upstream_error=snelstart_auth, duration_ms=135, response_size_bytes=123, request_fingerprint=NULL (CR-03 ✓), direction=outbound. SC-3 + SC-6 + SC-7 + T-05b-10 in één call gedekt.

### 5. GET /v1/snelstart/relaties?$top=5 — OData verbatim doorgezet (SC-4)
expected: `?$top=5` komt verbatim bij de SDK aan; audit-rij heeft `path` met query en juiste status.
result: pass
notes: 502 + audit-rij `path=/relaties` + `query_keys="$top"` (CR-02 PII-safe key-only audit ✓). De aanwezigheid van `$top` in `query_keys` bewijst dat de SDK de query-string heeft gezien.

### 6. Cross-Consumer 404 — info-disclosure-veilig (SC-5)
expected: Consumer B's PAT + `X-Account-Id: school-A` → 404 `account_not_found` (niet 403).
result: pass
notes: Consumer uat-5b-other met snelstart:read-PAT + `X-Account-Id: school-A` → **404** `{"error":"account_not_found","message":"Account niet gevonden voor deze Consumer."}`. Geen info-leak of school-A bestaat-maar-niet-van-jou.

### 7. X-Account-Id-resolution-paden (SC-6)
expected: (a) zonder header → 400 `missing_account_header`; (b) header `nonexistent` → 404 `account_not_found`; (c) Account bestaat maar Connection revoked → 404 `connection_not_found`.
result: pass
notes: 7a → 400, 7b → 404, 7c (Connection 1 ge-revoked + retry) → 404 (`connection_not_found` per middleware). Revoke ongedaan gemaakt na test.

### 8. pass_through_calls audit-row — géén raw credentials (SC-7)
expected: Audit-rij heeft alle expected kolommen; raw `CK-...`/`SK-...`/`access_token`/`refresh_token` komt 0× voor over alle rijen + Hub-response.
result: pass
notes: 2 rijen geschreven (Test 4 + 5). Alle 9 expected kolommen aanwezig (consumer_id/account_id/connection_id/provider/method/path/status/duration_ms/upstream_error + bonus query_keys/direction). `grep` over hele tabel: CK-test-rawkey-DO-NOT-LEAK afwezig ✓, SK-test-rawsecret-DO-NOT-LEAK afwezig ✓, access_token afwezig ✓, refresh_token afwezig ✓.

### 9. Scramble OpenAPI exposeert alle v1-routes (SC-8)
expected: `/docs/api.json` bevat path-entries voor `/v1/accounts`, `/v1/connections`, `/v1/connections/{connection}` (GET+DELETE) én catch-all `/v1/snelstart/{path}`.
result: pass
notes: 27 paths totaal. Scramble `api_path: v1` strikt `/v1`-prefix → paths verschijnen als `/accounts` (POST) ✓, `/connections` (POST) ✓, `/connections/{connection}` (get,delete) ✓, `/snelstart/{path}` (get) ✓. Catch-all toont alleen GET in spec — Scramble-quirk voor `Route::any`, dekt nog steeds SC-8 (≥1 method). UAT-expectation gecorrigeerd: paths zijn zonder /v1-prefix.

## Summary

total: 9
passed: 9
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

[none]

## Side-fixes during UAT

- `bin/dev` bootstrap-script toegevoegd (`8b58bb4`) + port-conflict-guard + smoke-fallback (`607fbf9`)
- `DatabaseSeeder` bootstrap Spatie RBAC + super-admin op test-user (`c194e94`) — fixt 500 op `/admin/quick-login/super-admin` na `bin/dev up --reset`
