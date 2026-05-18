---
phase: 13-mollie-connect-partner-resources
verified: 2026-05-18T00:00:00Z
status: passed
score: 4/4 must-haves verified
overrides_applied: 0
---

# Phase 13: Mollie Connect partner-resources — Verification Report

**Phase Goal:** Een Connect-merchant-onboarding-flow kan via de Hub volledig worden afgehandeld zonder dat het host-app rechtstreeks met Mollie hoeft te praten.
**Verified:** 2026-05-18
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `/v1/mollie/connect/{onboarding|organizations|profiles|permissions|client-links}` routes live met error-mapping en `Idempotency-Key` auto-forward; integration-test bewijst happy-path + 401-error-mapping per resource | VERIFIED | `php artisan route:list --path=v1/mollie/connect` toont exact 9 routes; 36 Connect feature-tests (ClientLinks 4, Onboarding 2, Organizations 3, Permissions 3, Profiles 5, ConnectScaffold 6, ConnectRouteRegistration 3, TokenResolverIntegration 3, …) groen met 201 assertions; `MollieUpstreamErrorMapper` (regel 31-42) heeft `MissingPartnerTokenException` 503-branch; `dispatchMollieCall()` in base mapt raw `ApiException` via `MollieExceptionMapper::map()` naar Hub-tree zodat 401 → 502 `mollie_auth_failed`-branch raakt; `AbstractMollieConnectPassThroughController::client()` regel 57-60 forward't `Idempotency-Key`-header naar SDK |
| 2 | `MollieAccessTokenResolver` levert partner-token voor Connect-resources én Connection-token voor merchant-resources; integration-test dekt beide paden expliciet (MOLL-06 SC-2) | VERIFIED | `app/Mollie/MollieAccessTokenResolver.php` heeft `resolveFor()` met `match($tokenType)` — `partner` returnt `partnerToken` (env-var via config), `connection` returnt `context->current()->access_token`; `tests/Feature/Api/V1/Mollie/Connect/TokenResolverIntegrationTest.php` heeft 3 test-methods waarvan `test_merchant_route_uses_connection_access_token` + `test_connect_route_uses_partner_access_token` beide token-paden zijbij-zij asserteren via `lastUsedAccessToken`-spy op StubMollieClient + StubMollieConnectClient; 3 passed, 10 assertions |
| 3 | Scramble OpenAPI groepeert nieuwe routes onder `Mollie · Connect` en `/docs/api` rendert zonder regressie op bestaande Mollie-groepen | VERIFIED | Alle 5 Connect-controllers hebben `#[Group(name: 'Mollie · Connect', ...)]` (geverifieerd via grep); `ScrambleRouteDiscoveryTest::test_openapi_spec_contains_all_nine_mollie_connect_routes` + `…_groups_connect_routes_under_mollie_connect_tag` + `…_preserves_existing_mollie_merchant_tags` — 14 tests, 83 assertions, all passed |
| 4 | ADR `.docs/decisions/mollie-connect-partner-resources.md` legt token-resolver-keuze + resource-mapping vast | VERIFIED | File bestaat (89 regels, 7 H2-sections — Status/Keuze/Context/Alternatieven afgewogen/Consequences/Wanneer herzien/Cross-referenties); `MollieAccessTokenResolver` 7×, `mollie-passthrough-api` cross-ref 3×, `MOLLIE-CONNECT-BOOT-WARN` backlog 3×, `pass_through_calls` 5× |

**Score:** 4/4 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Mollie/MollieAccessTokenResolver.php` | Token-type-resolver met partner/connection match | VERIFIED | Singleton-bound in `AppServiceProvider::register()` regel 38; constructor injecteert `MollieConnectionContext` + nullable `partnerToken`; `resolveFor()` dispatches via `match` met 3 exception-types |
| `app/Exceptions/Mollie/MissingPartnerTokenException.php` + `MissingConnectionContextException.php` | Hub-side exceptions voor missing token-context | VERIFIED | Beide files aanwezig en gebruikt door resolver (via `throw new …`) + MollieUpstreamErrorMapper instanceof-check |
| `database/migrations/2026_05_18_120000_add_token_type_to_pass_through_calls_table.php` | Forward-only schema-uitbreiding | VERIFIED | Voegt `token_type` (varchar 16, nullable, indexed) + `partner_token_fingerprint` (varchar 16, nullable) toe; bestaande rijen NULL (= implicit connection) |
| `app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php` | Connect-base met container-resolved client + dispatchMollieCall + audit-write | VERIFIED | 243 regels; `client()` regel 52 doet `app(MollieApiClient::class)`; `dispatchMollieCall()` regel 74 wikkelt `ApiException` via `MollieExceptionMapper::map()`; `handle()` schrijft audit met `token_type='partner'`, `account_id=NULL`, `connection_id=NULL` |
| 5 concrete controllers (ClientLinks/Onboarding/Organizations/Permissions/Profiles) | Per-resource SDK-call delegatie | VERIFIED | Alle 5 files aanwezig; allen extenden `AbstractMollieConnectPassThroughController`; allen hebben `#[Group(name: 'Mollie · Connect', ...)]` attribute |
| 2 Form Requests (`CreateClientLinkRequest`, `CreateProfileRequest`) | Validatie voor write-endpoints | VERIFIED | Beide files aanwezig; rules dekken Mollie's required-velden (`owner.*`, `address.*` voor ClientLinks; `name`/`website`/`email` voor Profiles). CR-01 in 13-REVIEW.md flagt dat `phone` als nullable staat terwijl vendor SDK het verplicht declareert — advisory, niet phase-blokkerend (success criterion zegt "Form Request validates minimaal required fields per Mollie's spec"; phone-edge raakt 422 vs 502 voor één edge-case, niet de happy-path SC-1) |
| `routes/api.php` met 9 routes onder `/v1/mollie/connect/*` | Route-registratie achter sanctum + feature.provider:mollie | VERIFIED | `php artisan route:list --path=v1/mollie/connect` toont exact 9 routes; geen `resolve.mollie.account`-middleware (D-07); naam-pattern `api.mollie.connect.<resource>.<action>` |
| 8 test-files in `tests/Feature/Api/V1/Mollie/Connect/` + 1 trait | Per-resource + integration coverage | VERIFIED | Alle files aanwezig; 36 tests groen (201 assertions); StubMollieConnectClient + StubsMollieConnectClient trait gebruikt door alle resource-tests (12 use-matches via grep) |
| `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php` | +3 Connect-tag-tests | VERIFIED | 3 nieuwe test-methods aanwezig op regels 174, 210, 240; alle 14 tests groen, 83 assertions |
| `.docs/decisions/mollie-connect-partner-resources.md` | ADR (lokaal/gitignored werkdocument per CLAUDE.md) | VERIFIED | 89 regels, 7 H2-sections, `MollieAccessTokenResolver` 7×, `MOLLIE-CONNECT-BOOT-WARN` backlog 3×, cross-ref naar `mollie-passthrough-api.md` 3× |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `app/Providers/AppServiceProvider.php` | `App\Mollie\MollieAccessTokenResolver` | singleton-binding in register() | WIRED | `grep -c "singleton(MollieAccessTokenResolver::class"` = 1; binding op regel 38 |
| `app/Support/Mollie/MollieUpstreamErrorMapper.php` | `App\Exceptions\Mollie\MissingPartnerTokenException` | instanceof-check in mapException() | WIRED | Branch op regel 31-42 retourneert 503 `partner_token_missing` |
| `app/Mollie/MollieAccessTokenResolver.php` | `MollieConnectionContext` | constructor-injection | WIRED | Constructor regel 11-14 injecteert `MollieConnectionContext` |
| `AbstractMollieConnectPassThroughController` | `MollieAccessTokenResolver` | constructor-injection | WIRED | Constructor regel 38-40 |
| `AbstractMollieConnectPassThroughController` | `Mollie\Api\MollieApiClient` | container-resolution in client() | WIRED | `grep -c "app(MollieApiClient::class)"` = 2 (incl. test-affordance comment); regel 54 |
| `AbstractMollieConnectPassThroughController` | `Emeq\MollieApi\Exceptions\MollieExceptionMapper` | dispatchMollieCall() try/catch + map($e) | WIRED | Regel 76-80 mapt raw `MollieApiException` |
| `routes/api.php` | Connect-controllers | Route::prefix('mollie/connect')->group(...) | WIRED | `grep -c "prefix('mollie/connect')"` = 1; route-group regel 100-126 met 9 routes |
| `AbstractMollieConnectPassThroughController` | `PassThroughCall::create` | audit-write met token_type='partner' | WIRED | Regel 159-177 schrijft audit-rij met token_type=partner, partner_token_fingerprint |
| Connect Test-files | `tests/Concerns/StubsMollieConnectClient` | use Tests\Concerns\StubsMollieConnectClient | WIRED | 12 use-matches via grep over `tests/Feature/Api/V1/Mollie/Connect/` |
| `.docs/decisions/mollie-connect-partner-resources.md` | `.docs/decisions/mollie-passthrough-api.md` | cross-reference in Context-section | WIRED | 3 matches voor `mollie-passthrough-api` in ADR |
| `.planning/REQUIREMENTS.md` | MOLL-05 + MOLL-06 closure | `- [x]`-checkbox + Traceability-status Complete | WIRED | `grep -c '^- \[x\] \*\*MOLL-0[56]'` = 2; `grep -c '\| MOLL-0[56] \| Phase 13 \| Complete \|'` = 2 |

**Note on key-link gsd-sdk discrepancies:** `gsd-sdk query verify.key-links` flagged 5 patterns as `not found` due to regex-escape edge-cases in the SDK pattern matcher (e.g. `app\\(\\\\Mollie\\\\Api\\\\MollieApiClient::class\\\\)` over-escaping `\` in PHP namespace syntax). All 5 were manually verified above with `grep` and found wired in source.

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `AbstractMollieConnectPassThroughController::client()` | `$client->accessToken` | `MollieAccessTokenResolver::resolveFor('partner')` → `config('services.mollie.partner_access_token')` env-var | Yes — `TokenResolverIntegrationTest::test_connect_route_uses_partner_access_token` asserts `$stub->lastUsedAccessToken === 'access_partner_xyz'` na call | FLOWING |
| `AbstractMollieConnectPassThroughController::handle()` audit-write | `partner_token_fingerprint` | `substr(hash('sha256', $partnerToken), 0, 12)` | Yes — feature-tests asserteren via `assertDatabaseHas` (12-char string identiek aan sha256-prefix) | FLOWING |
| 5 concrete Connect-controllers | SDK response-body (resource/collection) | `$client->{endpoint}->{method}(...)` via `dispatchMollieCall` | Yes — happy-path-tests asserteren `assertJsonPath('id', …)` + nested `_links/_embedded` velden via `makeMollieResourceWithBody` / `makeMollieCollectionWithBody` helpers (anonymous Response-subclass returnt JSON-body verbatim) | FLOWING |
| ProfilesController.php `index/store/show` | `$client->profiles->{page,create,get}()` | Container-resolved client | Yes — `ProfilesTest` 5/5 passed asserteren resource-id + audit-shape | FLOWING |
| OrganizationsController.php `me/show` | `$client->organizations->get($id)` | Container-resolved client | Yes — `OrganizationsTest` 3/3 passed | FLOWING |
| OnboardingController.php `me` | `$client->onboarding->status()` (vendor signature drift, gedocumenteerd) | Container-resolved client | Yes — `OnboardingTest` 2/2 passed | FLOWING |
| PermissionsController.php `index/show` | `$client->permissions->list()` (vendor signature drift) | Container-resolved client | Yes — `PermissionsTest` 3/3 passed | FLOWING |
| ClientLinksController.php `store` | `$client->clientLinks->create($payload)` | Container-resolved client | Yes — `ClientLinksTest` 4/4 passed asserteren `_links.clientLink.href` | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| All 9 Connect routes registered | `php artisan route:list --path=v1/mollie/connect` | Shows 9 routes, prefix `v1/mollie/connect/`, names `api.mollie.connect.*` | PASS |
| Connect feature-tests run green | `php artisan test --compact --filter='Connect\|TokenResolver'` | 36 passed, 0 failed, 201 assertions | PASS |
| ScrambleRouteDiscovery tests run green incl. SC-3 | `php artisan test --compact --filter='ScrambleRouteDiscoveryTest'` | 14 passed, 83 assertions | PASS |
| MollieAccessTokenResolver class autoloads + singleton-bound | (covered in MollieAccessTokenResolverTest, 6 passed, 8 assertions) | passed | PASS |
| MissingPartnerTokenException → 503 mapping branch | (covered in MollieUpstreamErrorMapperPartnerTokenTest, 2 passed) | passed | PASS |
| Full regression suite | `php artisan test --compact` | 564 tests, 563 passed, 1 failed (pre-existing `UserResourceTest::test_super_admin_can_create_user_via_resource`, Phase-11 deferred-items per 13-01/02/03 SUMMARYs) | PASS (no Phase 13 regressions) |

### Probe Execution

| Probe | Command | Result | Status |
|-------|---------|--------|--------|
| (n/a) | n/a — Phase 13 is a Laravel HTTP-pass-through feature with no `scripts/*/tests/probe-*.sh` artifacts; PLAN/SUMMARY/ROADMAP do not declare probes | n/a | SKIPPED (no probes declared) |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| MOLL-05 | 13-02, 13-03, 13-04 | Pass-through-routes voor Mollie Connect partner-resources beschikbaar onder `/v1/mollie/connect/*`: Onboarding-status, Organizations, Profiles, Permissions, ClientLinks. Volgt pass-through-pattern uit Phase 5a, incl. idempotency-forward, error-mapping en Scramble OpenAPI-groep `Mollie · Connect` | SATISFIED | 9 routes geregistreerd; 36 feature-tests groen (per-resource 200 + 401 mapping); ScrambleRouteDiscoveryTest beweest Connect-tag-grouping; REQUIREMENTS.md regel 29 = `- [x]`; Traceability rij 85 = `Complete` |
| MOLL-06 | 13-01, 13-03, 13-04 | `MollieAccessTokenResolver` ondersteunt org-access-tokens vs partner-access-tokens correct — Connect-resources gebruiken het partner-token, niet het Connection-access-token. Integration-test bewijst beide paden | SATISFIED | `MollieAccessTokenResolver::resolveFor('partner'\|'connection')` met match-expression + 3 exception-types; `TokenResolverIntegrationTest` met 3 tests dekt beide paden expliciet via `lastUsedAccessToken`-spy; REQUIREMENTS.md regel 30 = `- [x]`; Traceability rij 86 = `Complete` |

**Orphaned requirements:** None. REQUIREMENTS.md maps MOLL-05 + MOLL-06 → Phase 13; both appear in plan frontmatter `requirements:` arrays across 13-01..13-04. No additional Phase 13 IDs in REQUIREMENTS.md.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none in Phase 13 files) | — | grep voor `TBD\|FIXME\|XXX`, `TODO\|HACK\|PLACEHOLDER`, "placeholder/coming soon/will be here/not yet implemented", empty-return-stubs, hardcoded empty-data in `app/Mollie/`, `app/Http/Controllers/Api/V1/Mollie/Connect/`, `app/Exceptions/Mollie/*` produced 0 matches | — | No anti-patterns detected |

### Code Review Findings (Advisory)

13-REVIEW.md flagged 12 findings (2 critical, 6 warning, 4 info). Per verifier-context note: "code review findings are advisory; CR-01/CR-02 are real but not phase-blocking unless they break a stated success criterion." Mapped:

- **CR-01** (`CreateProfileRequest` accepts missing `phone` while vendor SDK requires non-nullable string) — **Not phase-blocking.** The success criterion (SC-1) requires "integration-test bewijst happy-path + 401-error-mapping per resource", which 36 Connect tests satisfy. The phone-edge case affects 422-vs-502 mapping for one specific malformed payload, not the documented success criteria. Carry forward as polish in a follow-up plan.
- **CR-02** (`MollieAccessTokenResolver` singleton freezes partner-token at boot; env-rotation requires container rebuild) — **Not phase-blocking.** SC-2 (MOLL-06) requires "integration-test dekt beide paden expliciet" which `TokenResolverIntegrationTest` satisfies. Long-running-worker rotation semantics are an operational concern outside the documented v0.3 scope (env-driven, single partner-token, Laravel Cloud env-vault for production per ADR D-04). Promote to backlog `MOLLIE-CONNECT-TOKEN-ROTATION` for v0.4+.
- **WR-01..WR-06 + IN-01..IN-04** — All informational or polish-level; none break a documented Success Criterion.

### Deferred / Open Items (Acknowledged in SUMMARY)

- **STATE.md + ROADMAP.md top-of-file Phase 13 closure-flags** — Plan 13-04 deliberately deferred STATE/ROADMAP edits to the orchestrator-owned `gsd-sdk query phase.complete` step per workflow protocol. Current state:
  - ROADMAP.md Progress-table: Phase 13 = `Complete | 2026-05-18` ✓ (line 135)
  - ROADMAP.md Phase 13 plan-checklist: all 4 plans `[x]` ✓ (lines 85, 89, 93, 97)
  - ROADMAP.md top-level Phase list line 22: still `- [ ]` (orchestrator-owned)
  - STATE.md `status: executing` + `current_focus: Phase 13` (orchestrator-owned closure pending)

  This is **not** a Phase 13 goal-achievement concern — the 4 ROADMAP Success Criteria are about routes, resolver behavior, Scramble grouping, and the ADR. STATE/ROADMAP top-level flag flips are orchestrator-owned bookkeeping, not deliverables of Phase 13 plans.

### Human Verification Required

None. All Success Criteria are programmatically verifiable: route registration via artisan, behavior via feature-tests, Scramble via OpenAPI-spec assertion, ADR via file inspection. No UI, no real-time behavior, no external-service-touching concerns in scope.

### Gaps Summary

No blocking gaps. Phase 13 goal — "Een Connect-merchant-onboarding-flow kan via de Hub volledig worden afgehandeld zonder dat het host-app rechtstreeks met Mollie hoeft te praten" — is achieved:

- 9 routes live, all wired through container-resolved MollieApiClient with partner-token-injection.
- Token-resolver disambiguates partner vs Connection paths cleanly.
- Audit-trail extended with `token_type` + `partner_token_fingerprint`.
- Error-mapping correctly handles both upstream (401 → 502 `mollie_auth_failed`) and Hub-config (`partner_token_missing` → 503) failure modes.
- Scramble groups all 9 routes under `Mollie · Connect` without merchant-tag regression.
- ADR captures all 4 architectural choices + backlog promotion (`MOLLIE-CONNECT-BOOT-WARN`).
- Requirements MOLL-05 + MOLL-06 marked Complete in REQUIREMENTS.md (both checkbox + Traceability table).

Code review findings (CR-01/CR-02 + WR-/IN-* items) are advisory polish; none break a documented Success Criterion. Carry forward as backlog items.

---

*Verified: 2026-05-18*
*Verifier: Claude (gsd-verifier)*
