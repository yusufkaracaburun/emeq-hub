---
phase: 13-mollie-connect-partner-resources
fixed_at: 2026-05-18T00:00:00Z
review_path: .planning/phases/13-mollie-connect-partner-resources/13-REVIEW.md
iteration: 1
findings_in_scope: 8
fixed: 8
skipped: 0
status: all_fixed
---

# Phase 13: Code Review Fix Report

**Fixed at:** 2026-05-18T00:00:00Z
**Source review:** `.planning/phases/13-mollie-connect-partner-resources/13-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope: 8 (CR-01, CR-02, WR-01..WR-06)
- Fixed: 8
- Skipped: 0
- Test baseline: 184 passing -> 185 passing (CR-02-regressie toegevoegd)
- Branch: `gsd-reviewfix/13-82623` (NIET auto-gemerged naar master — per .ai/git-policy "nooit op master werken")

## Fixed Issues

### CR-01: `CreateProfileRequest` accepts missing `phone`, vendor SDK requires non-null `string`

**Files modified:** `app/Http/Requests/Api/V1/Mollie/Connect/CreateProfileRequest.php`, `tests/Feature/Api/V1/Mollie/Connect/ProfilesTest.php`
**Commit:** `b1f6f6e`
**Applied fix:** `phone` rule veranderd van `['nullable', 'string', 'max:32']` naar `['required', 'string', 'max:32']` matchend op vendor's `Mollie\Api\Http\Requests\CreateProfileRequest::__construct(string $phone)`. Plus extra rules toegevoegd voor `countriesOfActivity` + `countriesOfActivity.*`. Tests bijgewerkt: happy-path-payload bevat nu `phone`; missing-fields-assertion bevat `phone` in de error-set.

### CR-02: `MollieAccessTokenResolver` singleton freezes partner-token at boot

**Files modified:** `app/Mollie/MollieAccessTokenResolver.php`, `app/Providers/AppServiceProvider.php`, `tests/Concerns/StubsMollieConnectClient.php`, `tests/Unit/Mollie/MollieAccessTokenResolverTest.php`
**Commit:** `22d4eff`
**Applied fix:** Constructor accepteert nu `Closure|string|null` voor `$partnerToken`; bij string/null wordt deze backwards-compat in een Closure gewrapt. AppServiceProvider geeft `static fn (): ?string => $app['config']->get('services.mollie.partner_access_token')` mee zodat elke `resolveFor('partner')`-call config opnieuw leest — vereist voor long-running workers (Horizon, octane) waar env-rotatie zonder container-restart moet doorwerken. `StubsMollieConnectClient::setPartnerToken()` doet geen `forgetInstance` meer (workaround verdwijnt: het code-smell uit CR-02 wat de bug verraadde). Regressie-test `test_partner_token_reflects_config_changes_without_rebind` toegevoegd.

### WR-01: `CreateClientLinkRequest` missing `owner.locale` (and `address.region`)

**Files modified:** `app/Http/Requests/Api/V1/Mollie/Connect/CreateClientLinkRequest.php`
**Commit:** `da8f8b3`
**Applied fix:** `'owner.locale' => ['nullable', 'string', 'max:10']` en `'address.region' => ['nullable', 'string', 'max:255']` toegevoegd — beide velden zijn optioneel in vendor's `Owner` (`?string $locale = null`) en `OwnerAddress` (`?string $region = null`). Verified door beide vendor-classes te lezen onder `vendor/mollie/mollie-api-php/src/Http/Data/`.

### WR-02 + WR-03: `handle()` resolves partner-token twice + `client()` swallows `MissingPartnerTokenException` semantics

**Files modified:** `app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php`
**Commit:** `cd6babf` (gegroepeerd — beide vragen om dezelfde refactor in dezelfde file)
**Applied fix:** Partner-token wordt nu één keer per request gelezen, opgeslagen op `private ?string $resolvedPartnerToken` op de abstract base, en in stap 3 van `handle()` vóór de SDK-call. Zowel `client()` als de audit-fingerprint lezen daarna van dezelfde gecachte waarde — `partner_token_fingerprint` matched gegarandeerd de upstream-token (forensische traceability). `client()` enforce't WR-03's contract op type-systeem-niveau: `@throws MissingPartnerTokenException` gedocumenteerd + expliciete `null`-guard die throwt als `client()` buiten een `handle()`-frame wordt aangeroepen (i.p.v. impliciete bubble-through naar `handle()`'s catch-all). Token-missing-path: setter blijft NULL, `handle()`-SDK-call gooit zelf `MissingPartnerTokenException` zodat `MollieUpstreamErrorMapper` het 503-pad raakt.

### WR-04: `MollieAccessTokenResolverTest` asserts wrong substring

**Files modified:** `tests/Unit/Mollie/MollieAccessTokenResolverTest.php`
**Commit:** `4ccd497`
**Applied fix:** `expectExceptionMessage('snelstart')` vervangen door `expectExceptionMessage('Unknown token type: snelstart')` zodat een toekomstige typo in het message-format de assertion alsnog laat falen.

### WR-05: `PassThroughCallFactory` default vs Connect-base never sets `direction`

**Files modified:** `app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php`
**Commit:** `6f5922d`
**Applied fix:** `'direction' => 'outbound'` expliciet toegevoegd aan `PassThroughCall::create()`-payload in Connect-base. Matched de factory-default + voorkomt dat `$model->direction`-reads NULL teruggeven vóór reload. Phase-5a merchant-base heeft dezelfde latent issue maar zit buiten scope van deze fix-ronde.

### WR-06: `ConnectRouteRegistrationTest` count assertion brittle

**Files modified:** `tests/Feature/Api/V1/Mollie/Connect/ConnectRouteRegistrationTest.php`
**Commit:** `1d432b7`
**Applied fix:** `assertSame(count($expected), count($actual), ...)` verwijderd. De bestaande per-tuple `assertContains`-loop bewaakt het echte contract (alle 9 verwachte routes aanwezig). Toekomstige auto-HEAD-verbs of `Route::match`-additions laten de test niet meer onnodig falen.

## Skipped Issues

Geen — alle 8 in-scope findings zijn succesvol gefixt.

## Out-of-scope Info Findings

Per scope-policy NIET geadresseerd in deze ronde:

- **IN-01:** PassThroughCall casts() voor `token_type` + `partner_token_fingerprint` — optioneel, geen functioneel issue.
- **IN-02:** `StubMollieConnectClient::setIdempotencyKey($key)` untyped `mixed`-parameter — dead ternary in `is_string()`-check. Lower priority, raakt alleen test-helper.
- **IN-03:** `MissingConnectionContextException`-message bevat middleware-class-name. Niet bezorgd, info-only.
- **IN-04:** Duplicatie `resourceToArray`/`collectionToArray` tussen Connect-base en merchant-base — extract-trait-refactor, expliciet uitgesteld per D-03.

## Workflow

- Worktree: `/tmp/sv-13-reviewfix-XGhyWB` (verwijderd na completion)
- Branch: `gsd-reviewfix/13-82623` (NIET auto-gemerged naar master — preserved voor user-review)
- Test-baseline pre-fix: 184 passing (Phase 13 verifier)
- Test-baseline post-fix: 185 passing (+ CR-02 regressie-test)
- Recovery-sentinel: opgeruimd

## Merge-handoff

Per `.ai/git-policy`: nooit op master werken zonder approval. Branch `gsd-reviewfix/13-82623` bevat 7 commits klaar voor manual merge:

```bash
# Vanuit master root:
git merge --ff-only gsd-reviewfix/13-82623
git branch -d gsd-reviewfix/13-82623
```

Voor full-test-confirm vóór merge:

```bash
git checkout gsd-reviewfix/13-82623
php artisan test --compact --filter='Mollie|PassThroughCall|Scramble'
# verwacht: 185 passing, 1 incomplete
```

---

_Fixed: 2026-05-18T00:00:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
