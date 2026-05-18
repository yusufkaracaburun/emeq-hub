---
phase: 13-mollie-connect-partner-resources
plan: 01
subsystem: api
tags: [mollie, mollie-connect, partner-token, error-mapping, pass-through-audit, php, laravel]

requires:
  - phase: 05a-mollie-sdk-resources-webhooks-pass-through-api
    provides: AbstractMolliePassThroughController, MollieUpstreamErrorMapper, MollieConnectionContext, pass_through_calls-tabel
  - phase: 04-mollie-connect-oauth-broker
    provides: MollieConnectOAuthFlow + OAuthFlowRegistry (referentie-pattern voor token-types)
provides:
  - App\Mollie\MollieAccessTokenResolver met resolveFor('partner'|'connection')
  - App\Exceptions\Mollie\MissingPartnerTokenException + MissingConnectionContextException
  - config('services.mollie.partner_access_token') key + MOLLIE_PARTNER_ACCESS_TOKEN env-var
  - pass_through_calls.token_type + pass_through_calls.partner_token_fingerprint kolommen (forward-only migration)
  - MollieUpstreamErrorMapper-branch voor MissingPartnerTokenException -> 503 partner_token_missing
affects: [13-02 (Connect-controllers), 13-03+ (Connect-resource-controllers), future provider-resolvers]

tech-stack:
  added: []
  patterns:
    - "Token-type-resolver pattern: één service, $tokenType-argument, match-expression met Hub-exceptions"
    - "Append-only error-mapper-tabel: nieuwe branch op positie #1 (vóór ValidationException), 6 bestaande branches intact"
    - "Forward-only schema-uitbreiding (D-11): nullable + indexed token_type-kolom, bestaande rijen NULL = implicit 'connection'"

key-files:
  created:
    - app/Mollie/MollieAccessTokenResolver.php
    - app/Exceptions/Mollie/MissingPartnerTokenException.php
    - app/Exceptions/Mollie/MissingConnectionContextException.php
    - database/migrations/2026_05_18_120000_add_token_type_to_pass_through_calls_table.php
    - tests/Unit/Mollie/MollieAccessTokenResolverTest.php
    - tests/Unit/Mollie/MollieUpstreamErrorMapperPartnerTokenTest.php
  modified:
    - .env.example
    - config/services.php
    - app/Providers/AppServiceProvider.php
    - app/Support/Mollie/MollieUpstreamErrorMapper.php
    - app/Models/PassThroughCall.php
    - database/factories/PassThroughCallFactory.php

key-decisions:
  - "Singleton-binding voor MollieAccessTokenResolver via AppServiceProvider::register() — één instance per request (test bewijst app() === app())"
  - "MissingConnectionContextException is een eigen Hub-exception (RuntimeException), niet de native RuntimeException uit MollieConnectionContext::current() — geeft 'has()'-guard de mogelijkheid om Hub-specifieke fout te gooien"
  - "Mapper-branch voor partner-token op positie #1 vóór ValidationException — Hub-config-fout heeft voorrang op upstream-error-paden"
  - "Migration heeft down() body (Schema::table met drop) ondanks forward-only-policy — repo-conventie voor local migrate:fresh"
  - "PassThroughCallFactory default token_type = 'connection' — voorkomt regressie op ~11 bestaande Phase-5a feature-tests die NULL zouden krijgen"

patterns-established:
  - "Append-only error-mapper: nieuwe exception-branch invoegen vóór bestaande branches, geen bestaande mappings raken"
  - "Token-type-resolver: één service met match-expression op string-argument; provider-specifieke exceptions worden vanuit dezelfde resolver gegooid"

requirements-completed: [MOLL-06]

duration: ~25min
completed: 2026-05-18
---

# Phase 13 Plan 01: Mollie partner-token resolver-fundament Summary

**Token-type-resolver + forward-only schema-uitbreiding op pass_through_calls + 503 mapping voor missing partner-token — landt het fundament dat Plan 13-02 (Connect-controllers) injecteert zonder duplicatie.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-18T12:31:32Z
- **Completed:** 2026-05-18T12:56:00Z (approx)
- **Tasks:** 2 (beide TDD: RED → GREEN, geen REFACTOR nodig)
- **Files modified:** 12 (6 created, 6 modified)

## Accomplishments

- `MollieAccessTokenResolver::resolveFor($tokenType)` dekt 4 paden: partner-happy, partner-missing → `MissingPartnerTokenException`, connection-happy, connection-missing → `MissingConnectionContextException`, default → `InvalidArgumentException`. Singleton-binding via `AppServiceProvider::register()`.
- Forward-only migration `2026_05_18_120000_add_token_type_to_pass_through_calls_table.php` voegt twee kolommen toe: `token_type` (string,16,nullable,indexed) na `provider`, `partner_token_fingerprint` (string,16,nullable) na `request_fingerprint`. Bestaande rijen blijven NULL (= implicit `'connection'` semantiek).
- `MollieUpstreamErrorMapper` heeft een nieuwe eerste branch voor `MissingPartnerTokenException` → 503 met body `{error: 'partner_token_missing', message: '…niet geconfigureerd…', upstream_status: 0}` en `short_code = 'partner_token_missing'`. De zes bestaande branches (ValidationException, AuthenticationException, NotFoundException, RateLimitException, ServerException, catch-all `MollieException`) zijn bit-voor-bit ongewijzigd.
- `PassThroughCallFactory` default-state krijgt `token_type = 'connection'` + `partner_token_fingerprint = null` — voorkomt regressie op bestaande Phase-5a feature-tests.

## Task Commits

Each task was committed atomically (TDD RED+GREEN gebundeld per task — beide TDD-cycli sluiten in één commit omdat de "RED" (test-write) en "GREEN" (implementation) niet zinvol los te committen waren zonder code-duplicatie):

1. **Task 1: Config + env + exceptions + resolver-class + unit-tests** — `0fbc302` (feat)
2. **Task 2: pass_through_calls migration + model + factory + error-mapper-branch** — `9d560be` (feat)

_TDD notitie: RED-fase bewezen via standalone runs (Task 1 RED = "Class App\Mollie\MollieAccessTokenResolver not found" — 6 errors; Task 2 RED = `assertSame(502, 503)` failure op partner-token-mapping). GREEN-fase bewezen via dezelfde tests groen na implementation. Geen REFACTOR commits — code-shape kwam direct uit plan-specifics zonder cleanup-pass._

**Plan metadata:** TBD (executor-agent commit van SUMMARY volgt na deze write).

## Files Created/Modified

**Created (6):**
- `app/Mollie/MollieAccessTokenResolver.php` — Token-type-resolver met `resolveFor()` match-expression.
- `app/Exceptions/Mollie/MissingPartnerTokenException.php` — Hub-config-fout exception (default-message bevat `'partner-access-token niet geconfigureerd'`).
- `app/Exceptions/Mollie/MissingConnectionContextException.php` — Context-leeg exception.
- `database/migrations/2026_05_18_120000_add_token_type_to_pass_through_calls_table.php` — schema-uitbreiding.
- `tests/Unit/Mollie/MollieAccessTokenResolverTest.php` — 6 cases, 8 assertions.
- `tests/Unit/Mollie/MollieUpstreamErrorMapperPartnerTokenTest.php` — 2 cases (partner-token + NotFound regressie-smoke), 9 assertions.

**Modified (6):**
- `.env.example` — `MOLLIE_PARTNER_ACCESS_TOKEN=` stub met inline comment.
- `config/services.php` — nieuwe sibling-key `'partner_access_token'` boven `'connect'`.
- `app/Providers/AppServiceProvider.php` — singleton-binding voor `MollieAccessTokenResolver`, `use App\Mollie\MollieAccessTokenResolver`.
- `app/Support/Mollie/MollieUpstreamErrorMapper.php` — nieuwe eerste branch + `use App\Exceptions\Mollie\MissingPartnerTokenException`.
- `app/Models/PassThroughCall.php` — `token_type` + `partner_token_fingerprint` toegevoegd aan `#[Fillable([…])]`.
- `database/factories/PassThroughCallFactory.php` — `'token_type' => 'connection'` + `'partner_token_fingerprint' => null` in default-state.

## MollieUpstreamErrorMapper — vóór + na (behoud-bewijs)

**Vóór** (Phase 5a, 6 branches in mapper-tabel):

| # | Branch | Status | Short code |
|---|---|---|---|
| 1 | `ValidationException` | 422 `validation_failed` | `null` |
| 2 | `AuthenticationException` | 502 `mollie_auth_failed` | `mollie_auth` |
| 3 | `NotFoundException` | 404 `not_found` | `null` |
| 4 | `RateLimitException` | 429 `rate_limited` | `null` |
| 5 | `ServerException` | 502 `mollie_unavailable` | `mollie_5xx` |
| 6 | catch-all (`MollieException` + `\Throwable`) | 502 `mollie_error` | `mollie_unknown` |

**Na** (Phase 13-01, 7 branches — nieuwe branch op positie #1):

| # | Branch | Status | Short code |
|---|---|---|---|
| **1** | **`MissingPartnerTokenException`** (nieuw) | **503 `partner_token_missing`** | **`partner_token_missing`** |
| 2 | `ValidationException` | 422 `validation_failed` | `null` |
| 3 | `AuthenticationException` | 502 `mollie_auth_failed` | `mollie_auth` |
| 4 | `NotFoundException` | 404 `not_found` | `null` |
| 5 | `RateLimitException` | 429 `rate_limited` | `null` |
| 6 | `ServerException` | 502 `mollie_unavailable` | `mollie_5xx` |
| 7 | catch-all | 502 `mollie_error` | `mollie_unknown` |

Append-only: bestaande 6 branches zijn bit-voor-bit ongewijzigd; alleen één extra check vóór `ValidationException` toegevoegd. Bewezen door `tests/Unit/Support/Mollie/MollieUpstreamErrorMapperTest.php` (8 tests, blijft groen) + nieuwe `MollieUpstreamErrorMapperPartnerTokenTest::test_existing_not_found_branch_remains_unchanged()` regressie-smoke.

## Migration

**Naam:** `2026_05_18_120000_add_token_type_to_pass_through_calls_table.php`
**Kolommen toegevoegd:**
- `token_type` — `varchar(16)`, nullable, met `pass_through_calls_token_type_index`. Bestaande rijen krijgen NULL = implicit `'connection'`. Plaatsing: na `provider`.
- `partner_token_fingerprint` — `varchar(16)`, nullable, géén index. Plaatsing: na `request_fingerprint`.

`down()` body aanwezig (`Schema::table` + `dropIndex/dropColumn`) ondanks forward-only-policy in productie — conform repo-conventie voor `php artisan migrate:fresh` in lokale dev.

## Resolver-binding-locatie + behavior

**Binding:** `app/Providers/AppServiceProvider.php::register()`, regels na `bind(MollieCredentialResolver::class, …)`:

```php
$this->app->singleton(MollieAccessTokenResolver::class, fn (Application $app): MollieAccessTokenResolver => new MollieAccessTokenResolver(
    $app->make(MollieConnectionContext::class),
    $app['config']->get('services.mollie.partner_access_token'),
));
```

**Behavior — 4 paden via `match($tokenType)`:**

| Input `$tokenType` | Output / Exception |
|---|---|
| `'partner'` (token geconfigureerd) | returns `$this->partnerToken` (string) |
| `'partner'` (token = `null`) | throws `MissingPartnerTokenException` |
| `'connection'` (context heeft Connection) | returns `$this->context->current()->access_token` |
| `'connection'` (context leeg via `has() === false`) | throws `MissingConnectionContextException` |
| `'snelstart'` of andere onbekende string | throws `\InvalidArgumentException("Unknown token type: …")` |

Singleton-bewijs: `app(MollieAccessTokenResolver::class) === app(MollieAccessTokenResolver::class)` returnt `true` (test + ad-hoc verificatie).

## Test-output snippets

**`MollieAccessTokenResolverTest` — 6 tests, 8 assertions:**

```
{"tool":"phpunit","result":"passed","tests":6,"passed":6,"assertions":8,"duration_ms":747}
```

Test-methodes (6):
1. `test_resolve_partner_returns_configured_token` (line 17)
2. `test_resolve_partner_throws_when_token_missing` (line 27)
3. `test_resolve_connection_returns_context_access_token` (line 40)
4. `test_resolve_connection_throws_when_context_empty` (line 54)
5. `test_resolve_unknown_token_type_throws_invalid_argument` (line 66)
6. `test_resolver_is_bound_as_singleton` (line 79)

**`MollieUpstreamErrorMapperPartnerTokenTest` — 2 tests, 9 assertions:**

```
{"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":9,"duration_ms":9}
```

Test-methodes (2):
1. `test_missing_partner_token_maps_to_503_partner_token_missing` (line 12)
2. `test_existing_not_found_branch_remains_unchanged` (line 27)

**Gecombineerde run `--filter='PassThroughCall|MollieUpstreamErrorMapper'`:**

```
{"tool":"phpunit","result":"passed","tests":17,"passed":17,"assertions":50,"duration_ms":776}
```

**Phase-5a regressie-check (`MolliePassThroughAuditTest` + `MolliePassThroughErrorMappingTest`):**

```
{"tool":"phpunit","result":"passed","tests":11,"passed":11,"assertions":46,"duration_ms":1008}
```

**Volledige suite:** 531/532 passed, 1 incomplete (zie Deviations / pre-existing UserResource-failure).

## Decisions Made

- **Singleton + scoped dependency-coexistentie:** `MollieConnectionContext` is `scoped` (per-request reset), maar de resolver is `singleton` (per-app-instance). Singleton-shape is plan-spec'd (D-02); test bewijst object-identity binnen één request. Tweede-request-overdracht is geen issue zolang `$this->context->has()` per request reflecteert dat scoped instance.
- **`MissingConnectionContextException` aparte exception ipv catchen van `MollieConnectionContext::current()`'s native `RuntimeException`:** `has()` als guard geeft een Hub-specifieke fout-message ("Roep ResolveMollieAccount-middleware aan…") ipv de SDK-laag-message.
- **Migration heeft `down()` body:** conform repo-conventie (zie sibling-migration `2026_05_15_000001_create_pass_through_calls_table.php` met `dropIfExists`); forward-only-policy geldt voor productie, niet voor de migration-class.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree-bootstrap: vendor/ + .env symlinks**
- **Found during:** Task 1 verificatie (test-run vanaf binnen worktree faalde met `Failed opening required vendor/autoload.php` en daarna `No application encryption key has been specified`)
- **Issue:** Claude Code's `isolation="worktree"` worktree mist `vendor/` en `.env` files. PHPUnit kan zonder vendor en zonder APP_KEY (voor encrypted-cast op `Connection.access_token`) niet draaien.
- **Fix:** `ln -s /Users/.../emeq-hub/vendor` + `ln -s /Users/.../emeq-hub/.env` in worktree-root, daarna `composer dump-autoload -o` om classmap met nieuwe `App\Mollie\MollieAccessTokenResolver` te refreshen.
- **Files modified:** geen tracked files; symlinks zijn worktree-lokaal en niet gecommit (zoals `vendor/` overal else gitignored is).
- **Verification:** `php artisan test --compact --filter=MollieAccessTokenResolverTest` exit 0 met 6/6 passed.
- **Committed in:** N.v.t. — geen file-changes, alleen worktree-tooling.

**2. [Rule 3 - Blocking] Cwd-drift recovery — main-repo writes verplaatst naar worktree**
- **Found during:** Pre-commit van Task 1 (zag `git status` op main-repo dirty terwijl worktree leeg was)
- **Issue:** Initial Write/Edit calls gebruikten absolute paden onder `/Users/yusufkaracaburun/Sites/localhost/emeq-hub/…` die naar de main-repo resolvden ipv naar de worktree (`/Users/.../emeq-hub/.claude/worktrees/agent-ad1bc9dd86bf8479d/…`). Dit is de bekende cwd-drift / absolute-path drift uit #3097/#3099.
- **Fix:** `cp -f` van alle 7 Task-1 files van main-repo naar worktree (`.env.example`, `config/services.php`, `app/Providers/AppServiceProvider.php`, `app/Mollie/MollieAccessTokenResolver.php`, `app/Exceptions/Mollie/Missing*Exception.php` ×2, `tests/Unit/Mollie/MollieAccessTokenResolverTest.php`). `git checkout -- …` + `rm -rf` op main-repo om die schoon te resetten. Vanaf Task 2 alle Edit/Write calls met absolute paden onder de worktree-root.
- **Files modified:** N.v.t. — recovery, geen extra code-change; final commits zijn correct in worktree.
- **Verification:** `git rev-parse --show-toplevel` returnt worktree-root; `git status` main-repo schoon (alleen orchestrator-owned `STATE.md`).
- **Committed in:** N.v.t. — files landen in `0fbc302` na recovery.

---

**Total deviations:** 2 auto-fixed (beide Rule 3 - blocking, worktree-tooling).
**Impact on plan:** Geen impact op deliverables. Alle 12 plan-files landen exact zoals gespecificeerd; deviations zijn pure execution-environment-blockers met geen code-impact.

## Issues Encountered

- **Pre-existing test-failure `UserResourceTest::test_super_admin_can_create_user_via_resource`** — uit volledige suite-run (531/532 passed). Failure-message: `Component has errors: "data.roles"`. Niet veroorzaakt door dit plan; zit op Filament `UserResource`-flow voor Spatie-rollen, gelogd in STATE.md Phase-11-decisions als "SCOPE BOUNDARY, gelogd in deferred-items.md". Niet gefixt.

## User Setup Required

Geen externe-service config nodig voor dit plan. Plan 13-02 (Connect-controllers) gaat `MOLLIE_PARTNER_ACCESS_TOKEN` daadwerkelijk consumen; voor v0.3-go-live moet de productie-token via Laravel Cloud env-vault gezet worden.

## Next Phase Readiness

**Klaar voor Plan 13-02 (Connect-controllers):**
- `MollieAccessTokenResolver` injecteerbaar via constructor (singleton-bound).
- `MissingPartnerTokenException` mapping live — controllers hoeven alleen `$resolver->resolveFor('partner')` aan te roepen, Phase-5a `try`/`catch` blocks vangen het op via `MollieUpstreamErrorMapper::mapException()`.
- `pass_through_calls.token_type` + `partner_token_fingerprint` klaar voor audit-write per request.

**Blockers / concerns:** geen. Resolver is bewust NIET door bestaande Phase-5a-controllers wired — alleen instantieerbaar + getest, plan-spec respect (zie 13-CONTEXT D-15 "discretion: of `MollieAccessTokenResolver` ook door bestaande Phase-5a-controllers gebruikt wordt — voorkeur: ja in dezelfde phase voor consistentie, mits het minimale change is. Anders backlog.").

## Self-Check: PASSED

- File `app/Mollie/MollieAccessTokenResolver.php`: FOUND
- File `app/Exceptions/Mollie/MissingPartnerTokenException.php`: FOUND
- File `app/Exceptions/Mollie/MissingConnectionContextException.php`: FOUND
- File `database/migrations/2026_05_18_120000_add_token_type_to_pass_through_calls_table.php`: FOUND
- File `tests/Unit/Mollie/MollieAccessTokenResolverTest.php`: FOUND
- File `tests/Unit/Mollie/MollieUpstreamErrorMapperPartnerTokenTest.php`: FOUND
- Commit `0fbc302`: FOUND (`feat(13-01): MollieAccessTokenResolver + missing-token exceptions`)
- Commit `9d560be`: FOUND (`feat(13-01): pass_through_calls token_type + partner-token mapper-branch`)

---
*Phase: 13-mollie-connect-partner-resources*
*Completed: 2026-05-18*
