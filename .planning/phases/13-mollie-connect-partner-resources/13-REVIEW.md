---
phase: 13-mollie-connect-partner-resources
reviewed: 2026-05-18T00:00:00Z
depth: standard
files_reviewed: 32
files_reviewed_list:
  - app/Exceptions/Mollie/MissingConnectionContextException.php
  - app/Exceptions/Mollie/MissingPartnerTokenException.php
  - app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php
  - app/Http/Controllers/Api/V1/Mollie/Connect/ClientLinksController.php
  - app/Http/Controllers/Api/V1/Mollie/Connect/OnboardingController.php
  - app/Http/Controllers/Api/V1/Mollie/Connect/OrganizationsController.php
  - app/Http/Controllers/Api/V1/Mollie/Connect/PermissionsController.php
  - app/Http/Controllers/Api/V1/Mollie/Connect/ProfilesController.php
  - app/Http/Requests/Api/V1/Mollie/Connect/CreateClientLinkRequest.php
  - app/Http/Requests/Api/V1/Mollie/Connect/CreateProfileRequest.php
  - app/Models/PassThroughCall.php
  - app/Mollie/MollieAccessTokenResolver.php
  - app/Providers/AppServiceProvider.php
  - app/Support/Mollie/MollieUpstreamErrorMapper.php
  - config/services.php
  - database/factories/PassThroughCallFactory.php
  - database/migrations/2026_05_18_120000_add_token_type_to_pass_through_calls_table.php
  - routes/api.php
  - tests/Concerns/StubsMollieConnectClient.php
  - tests/Feature/Api/V1/Mollie/Connect/ClientLinksTest.php
  - tests/Feature/Api/V1/Mollie/Connect/ConnectControllerScaffoldTest.php
  - tests/Feature/Api/V1/Mollie/Connect/ConnectRouteRegistrationTest.php
  - tests/Feature/Api/V1/Mollie/Connect/OnboardingTest.php
  - tests/Feature/Api/V1/Mollie/Connect/OrganizationsTest.php
  - tests/Feature/Api/V1/Mollie/Connect/PermissionsTest.php
  - tests/Feature/Api/V1/Mollie/Connect/ProfilesTest.php
  - tests/Feature/Api/V1/Mollie/Connect/StubMollieConnectClient.php
  - tests/Feature/Api/V1/Mollie/Connect/TokenResolverIntegrationTest.php
  - tests/Feature/Api/V1/Mollie/StubMollieClient.php
  - tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php
  - tests/Unit/Mollie/MollieAccessTokenResolverTest.php
  - tests/Unit/Mollie/MollieUpstreamErrorMapperPartnerTokenTest.php
findings:
  critical: 2
  warning: 6
  info: 4
  total: 12
status: issues_found
---

# Phase 13: Code Review Report

**Reviewed:** 2026-05-18T00:00:00Z
**Depth:** standard
**Files Reviewed:** 32
**Status:** issues_found

## Summary

Phase 13 adds Mollie Connect partner-resources (`/v1/mollie/connect/*`) with a separate
controller hierarchy, a partner-access-token resolver, an extended upstream-error mapper,
and a `pass_through_calls.token_type` column. The split between Connect-base and
merchant-base (D-03) is clean — Connect controllers never touch `MollieConnectionContext`
or `Account` and the resolver fails closed when `MOLLIE_PARTNER_ACCESS_TOKEN` is missing.
Tests cover both token paths symmetrically (MOLL-06 SC-2) and the 503 mapping for missing
partner-tokens is unit-tested without breaking existing Phase-5a mappings.

Two real correctness issues stand out:

1. **`CreateProfileRequest` accepts a payload without `phone`, but the vendor SDK's
   `CreateProfileRequest::__construct` takes `string $phone` (non-nullable).** Submitting a
   POST without `phone` produces a PHP `TypeError` inside the SDK, which falls through to
   the catch-all `mollie_unknown` (502) instead of a clean 422. This is a Form Request
   that fails to enforce the SDK's own contract.
2. **The pass-through audit row is written via `request_fingerprint` on the un-redacted
   request body**, but `CreateClientLinkRequest` collects PII (email, full address). The
   12-char SHA256 truncation is fine, but the same body is also forwarded verbatim to
   Mollie under partner-token auth without consent boundary checks — combined with the
   resolver caching `partner_token` for the lifetime of the singleton, a stale-credential
   deploy could keep authenticating to Mollie with a rotated token while the env-var has
   changed.

Several smaller issues around test coverage gaps, missing Form Request fields, and dead
helper methods are also flagged below.

## Critical Issues

### CR-01: `CreateProfileRequest` allows missing `phone`, vendor SDK requires it (`string` non-null)

**File:** `app/Http/Requests/Api/V1/Mollie/Connect/CreateProfileRequest.php:27`
**Issue:**
The Form Request has `'phone' => ['nullable', 'string', 'max:32']`. However, the vendor's
`Mollie\Api\Http\Requests\CreateProfileRequest::__construct` (see
`vendor/mollie/mollie-api-php/src/Http/Requests/CreateProfileRequest.php:42`) declares
`string $phone` — non-nullable. The vendor's `CreateProfileRequestFactory::create()`
passes `$this->payload('phone')` which returns `null` when the key is absent.

Submitting a POST `/v1/mollie/connect/profiles` without `phone` produces:
- Hub Form Request validation passes (phone is nullable)
- Controller calls `$client->profiles->create($r->validated())`
- Vendor factory calls `new CreateProfileRequest(..., null, ...)` → PHP `TypeError`
- `TypeError` is **not** a `MollieApiException`, so `dispatchMollieCall()` does **not**
  map it; it bubbles to `handle()`'s outer `catch (Throwable)`, which routes through
  `MollieUpstreamErrorMapper::mapException()` → catch-all branch → `mollie_unknown` (502)
- Consumer sees a misleading 502 with `upstream_status: 0` for what is actually a
  client-side validation error (should be 422)

Worse, `ProfilesTest::test_post_profile_with_missing_required_fields_returns_422` does
**not** include `phone` in the missing-fields assertion (line 146 asserts only `name`,
`website`, `email`), so this gap is invisible in test output.

**Fix:**
```php
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'website' => ['required', 'url', 'max:255'],
        'email' => ['required', 'email'],
        'phone' => ['required', 'string', 'max:32'],   // vendor SDK requires string
        'description' => ['nullable', 'string', 'max:500'],
        'businessCategory' => ['nullable', 'string'],
        'mode' => ['nullable', 'string', 'in:live,test'],
        'countriesOfActivity' => ['nullable', 'array'],
        'countriesOfActivity.*' => ['string', 'size:2'],
    ];
}
```

Also update `ProfilesTest::test_post_profile_with_missing_required_fields_returns_422` to
assert `phone` is in the validation-error set.

---

### CR-02: `MollieAccessTokenResolver` is a singleton with the partner-token frozen at boot — env rotation requires container rebuild

**File:** `app/Providers/AppServiceProvider.php:38-41` + `app/Mollie/MollieAccessTokenResolver.php:11-14`
**Issue:**
The resolver is registered as a `singleton` with `partner_access_token` injected by value
into a `private readonly ?string $partnerToken` field at construct time. Once the first
HTTP request resolves the singleton, the partner-token is captured for the lifetime of
the container (process). For long-running workers (Horizon, queue:work, octane), rotating
`MOLLIE_PARTNER_ACCESS_TOKEN` in env requires a full restart — there is no signal,
the resolver will keep authenticating to Mollie with the old token until the worker
restarts.

This is also why `StubsMollieConnectClient::setPartnerToken()` has to call
`$this->app->forgetInstance(MollieAccessTokenResolver::class)` after every config-mutate
in tests: without it, the singleton serves stale data. That's a code-smell that the
production runtime has the same bug.

Combined with the broader "tokens encrypted at rest" invariant (`.ai/rules/global.md`),
operators expect token-rotation to take effect on next request. Today, it does not.

**Fix:** Either bind as `bind` (per-resolve) — cheap, since the resolver only reads
config and a scoped context — or close over `config()` inside the singleton so the value
is re-read on each `resolveFor()` call:
```php
$this->app->singleton(MollieAccessTokenResolver::class, fn (Application $app) => new MollieAccessTokenResolver(
    $app->make(MollieConnectionContext::class),
    fn (): ?string => $app['config']->get('services.mollie.partner_access_token'),
));
```
Then change `MollieAccessTokenResolver` to invoke the closure inside `resolveFor('partner')`.

A test would: `config()->set(...)` → call resolver again → see new value, without
`forgetInstance`. The existence of `setPartnerToken()`'s manual `forgetInstance` is the
test-time tell that this is a latent bug.

## Warnings

### WR-01: `CreateClientLinkRequest` is missing the `locale` field on owner (vendor SDK accepts it)

**File:** `app/Http/Requests/Api/V1/Mollie/Connect/CreateClientLinkRequest.php:23-37`
**Issue:**
The vendor's `Owner` data-object accepts an optional `?string $locale` field (see
`vendor/mollie/mollie-api-php/src/Http/Data/Owner.php:18`). The Form Request does not
allow `owner.locale`, so a consumer who wants to pass an explicit locale will get a 422
on a field Mollie supports. This is a silent feature reduction.

Same applies to `address.region` (vendor `OwnerAddress::$region` is optional).

**Fix:** Add `'owner.locale' => ['nullable', 'string', 'max:10']` and
`'address.region' => ['nullable', 'string', 'max:255']`.

---

### WR-02: `AbstractMollieConnectPassThroughController::handle()` resolves `partner_token` twice per request (once in `client()`, once for audit fingerprint)

**File:** `app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php:52-63, 150-157`
**Issue:**
`client()` calls `$this->tokenResolver->resolveFor('partner')` to set the access token on
the SDK client. After the SDK call, lines 150-157 call `resolveFor('partner')` **again**
to compute the audit fingerprint. With the singleton bug from CR-02 this is consistent,
but it is still a redundant call. More importantly:

- If the env-var is **rotated between the SDK call and the audit-write** (impossible with
  the current singleton, but the moment CR-02 is fixed, this is a real race), the audit
  row's `partner_token_fingerprint` may not match the token actually sent upstream.

This breaks forensic traceability — the whole point of the fingerprint column.

**Fix:** Capture the token once at the start of `handle()`, fingerprint it immediately,
and pass the resolved string to `client()`:
```php
$partnerFingerprint = null;
$partnerToken = null;
try {
    $partnerToken = $this->tokenResolver->resolveFor('partner');
    $partnerFingerprint = substr(hash('sha256', $partnerToken), 0, 12);
} catch (MissingPartnerTokenException) {
    // 503 path — audit row krijgt NULL fingerprint
}
// ... later, when building the client, use $partnerToken if non-null
```

---

### WR-03: `client()` swallows `MissingPartnerTokenException` semantics by piggybacking on `handle()`'s catch-all

**File:** `app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php:52-63`
**Issue:**
The docblock says "MissingPartnerTokenException bubble't door naar handle()'s exception-pad
zodat MollieUpstreamErrorMapper het 503-pad raakt." That works, but it depends on the
exception being thrown **inside** the `$sdkCall` closure passed to `handle()`. If a future
refactor calls `$this->client($request)` outside that closure (e.g., for caching, or in
the auth-guard step before the closure runs), the exception will leak as an unhandled 500
since neither `dispatchMollieCall()` nor any other catch is around `client()` itself.

The pattern is brittle and not type-system-enforced.

**Fix:** Make `client()` return `?MollieApiClient` and have it return `null` (with the
exception captured locally) when the token is missing, so callers can branch explicitly.
Or: explicitly wrap `client()` in `dispatchMollieCall()` and have `dispatchMollieCall()`
catch `MissingPartnerTokenException` as well, with a clear "this is a Hub-side, not
upstream" tag.

---

### WR-04: `MollieAccessTokenResolverTest::test_resolve_unknown_token_type_throws_invalid_argument` asserts wrong substring

**File:** `tests/Unit/Mollie/MollieAccessTokenResolverTest.php:73-77`
**Issue:**
```php
$this->expectExceptionMessage('snelstart');
$resolver->resolveFor('snelstart');
```

The resolver's `match` default throws `"Unknown token type: snelstart"`. Asserting on the
substring `'snelstart'` is fine, but:
- A future change that renames token types (e.g., `'snelstart' → 'snelstart-clientkey'`)
  could pass this assertion while breaking the contract.
- More importantly, the substring `'snelstart'` is part of the input string — if a typo
  in the production code reverses the message format, the test still passes.

**Fix:** Assert on the full canonical message, or at minimum `"Unknown token type"`:
```php
$this->expectExceptionMessage('Unknown token type: snelstart');
```

---

### WR-05: `PassThroughCallFactory::definition()` defaults `direction => 'outbound'` but the new Connect base never sets `direction` explicitly

**File:** `app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php:159-177` + `database/factories/PassThroughCallFactory.php:25`
**Issue:**
The migration adds `$table->string('direction', 10)->default('outbound')` so the DB
fills in the column when the controller omits it. But:
- The Connect-base never sets `direction` — relies on DB default
- The Phase-5a merchant-base also never sets `direction`
- The factory sets `'direction' => 'outbound'` explicitly

This means a downstream `$model->direction` access pre-save will return `null` (model
isn't using DB defaults via `useCurrent`-style cast), which can break code that reads
the model before reloading. None of the current Connect tests exercise this (they only
use `assertDatabaseHas`), so a regression is invisible.

**Fix:** Set `'direction' => 'outbound'` in the Connect base's `PassThroughCall::create()`
call to match the factory and merchant-base policy explicitly. Code-as-documentation.

---

### WR-06: `ConnectRouteRegistrationTest::test_all_nine_connect_routes_are_registered_under_v1_mollie_connect_prefix` count assertion is brittle against `Route::any`-style additions

**File:** `tests/Feature/Api/V1/Mollie/Connect/ConnectRouteRegistrationTest.php:65-69`
**Issue:**
The test asserts `count($expected) === count($actual)`. If Laravel ever expands a
HEAD/OPTIONS verb into the route list (which it does for HEAD by default), or if a
future addition uses `Route::match`, this count will drift. The test would then fail
even though the contract (the 9 routes exist) is intact.

**Fix:** Drop the equality on `count(...)` and use only the per-tuple `assertContains`
loop. Or filter only `GET`/`POST` from the loop above already (which it does, but
`HEAD` is implied — Laravel auto-adds HEAD for every GET). Check by running locally and
seeing whether actual contains HEAD entries; if so, this test is flaky-by-design.

## Info

### IN-01: `PassThroughCall` model's `casts()` lacks types for new `token_type` + `partner_token_fingerprint`

**File:** `app/Models/PassThroughCall.php:66-74`
**Issue:**
Both new columns are string-typed in the migration; no cast is strictly needed. But the
model PHPDoc / Fillable list now includes them while `casts()` does not. Adding
`'token_type' => 'string'` is redundant but communicates intent. Lower priority — no
functional issue.

**Fix:** Optional — leave as-is, or document on the migration that these are nullable
strings consumed verbatim.

---

### IN-02: `StubMollieConnectClient::setIdempotencyKey` parameter is untyped (`mixed`)

**File:** `tests/Feature/Api/V1/Mollie/Connect/StubMollieConnectClient.php:51`
**Issue:**
```php
public function setIdempotencyKey($key): self
```
No type-hint, no parameter docblock. `StubMollieClient.php:58` has the same untyped
signature — both mirror the vendor parent's untyped signature for compatibility. That's
defensible but the `is_string($key) ? $key : (string) $key` ternary on line 53 is dead:
the right branch only fires for non-strings, which `(string) $key` then converts. The
result is the same as `(string) $key` unconditionally.

**Fix:** `$this->lastIdempotencyKey = (string) $key;`

---

### IN-03: `MissingConnectionContextException` references middleware by name in user-facing message

**File:** `app/Exceptions/Mollie/MissingConnectionContextException.php:12-16`
**Issue:**
`'MollieConnectionContext is leeg — geen current Connection gezet. Roep
ResolveMollieAccount-middleware aan voor deze route.'`

This message ends up in logs and potentially in error responses (depending on how the
mapper handles it — currently `MollieUpstreamErrorMapper` has no branch for
`MissingConnectionContextException`, so it hits the catch-all 502 with a sanitized
message). The internal middleware-class-name in the message is fine for `.docs/`
debugging but leaks implementation detail if it ever escapes.

**Fix:** Move the implementation hint to a separate logger-only context, keep the
exception message to "Mollie Connection context not initialised for this route".

---

### IN-04: `AbstractMollieConnectPassThroughController` has substantial duplication with `AbstractMolliePassThroughController`

**File:** `app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php` (185-242 = `resourceToArray` + `collectionToArray`)
**Issue:**
The two `resourceToArray()` and `collectionToArray()` methods are byte-for-byte identical
to the merchant-base versions (lines 134-185 in `AbstractMolliePassThroughController.php`).
The ability-guard + 415-guard + audit-write skeleton in `handle()` is also ~80% duplicated.
D-03 explicitly allows this duplication, but the code does **not** add a comment pointing
to a shared trait or interface for future deduplication — meaning a fix to one base may
silently diverge from the other.

There is precedent for this: `MollieUpstreamErrorMapper` was extended for both bases —
they correctly share the mapper. The next step (sharing the serialization helpers via a
trait) would be a small refactor.

**Fix:** Optional — extract `resourceToArray` / `collectionToArray` into a
`SerialisesMollieResources` trait, used by both abstract bases. Keeps D-03's hierarchy
split while eliminating the byte-duplication risk.

---

_Reviewed: 2026-05-18T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
