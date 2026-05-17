---
phase: 08-naschool-wiring-snelstart-mollie-via-hub
reviewed: 2026-05-17T15:28:13Z
fixed_at: 2026-05-17T16:30:00Z
depth: standard
files_reviewed: 27
files_reviewed_list:
  - app/Services/ConsumerOnboarding.php
  - app/Console/Commands/HubConsumerCreate.php
  - tests/Feature/Services/ConsumerOnboardingTest.php
  - tests/Feature/Console/HubConsumerCreateTest.php
  - app/Filament/Pages/OnboardConsumer.php
  - resources/views/filament/pages/onboard-consumer.blade.php
  - app/Filament/Resources/Consumers/Pages/ListConsumers.php
  - tests/Feature/Admin/OnboardConsumerTest.php
  - app/Filament/Actions/StartOAuthFlowAction.php
  - app/Filament/Resources/Connections/ConnectionResource.php
  - app/Filament/Resources/Accounts/Tables/AccountsTable.php
  - tests/Feature/Admin/StartOAuthFlowActionTest.php
  - app/Filament/Resources/Consumers/Schemas/ConsumerInfolist.php
  - app/Filament/Resources/Consumers/Pages/ViewConsumer.php
  - app/Filament/Resources/Consumers/ConsumerResource.php
  - app/Filament/Resources/Accounts/Schemas/AccountInfolist.php
  - app/Providers/Filament/AdminPanelProvider.php
  - tests/Feature/Admin/ConsumerInfolistHintTest.php
  - tests/Feature/Admin/AccountInfolistHintTest.php
  - app/Services/PartnerStatus.php
  - resources/views/partners/partials/_domeinmodel.blade.php
  - resources/views/partners/partials/_status-widget.blade.php
  - resources/views/partners/index.blade.php
  - resources/views/partners/mollie/example.blade.php
  - resources/views/partners/snelstart/example.blade.php
  - routes/web.php
  - tests/Feature/Dev/PartnerPagesTest.php
findings:
  critical: 4
  warning: 7
  info: 5
  total: 16
fix_results:
  fixed:
    - CR-01 (commit d0b0955)
    - CR-02 (commit d0b0955)
    - CR-03 (commit cc316c2)
    - CR-04 (commit 727be72)
    - WR-01 (commit cc316c2)
    - WR-02 (commit d0b0955)
    - WR-03 (commit 727be72)
    - WR-04 (commit e6b9998)
    - WR-05 (commit 2609782)
    - WR-06 (commit 2609782)
    - WR-07 (commit 727be72)
    - IN-01 (commit d0b0955) — picked up als no-cost cleanup tijdens CR-01-fix
  deferred:
    - IN-02 (auth()->user()?->can() defensive pattern — buiten scope, niet zelf een
      correctness-bug; pak op tijdens een breder RBAC-cleanup-pass)
    - IN-03 (Filament Section empty-schema fragility — wacht op v4-minor-upgrade
      die het pattern eventueel breekt; nu werkend)
    - IN-04 (HubConsumerCreate help-text clarity — cosmetic copy-edit)
    - IN-05 (provider display_label uitbreiding — vereist descriptor-schema
      uitbreiding; volgt logisch in v0.3 wanneer SnelStart-CamelCase formeel
      naar voren komt in marketing-copy)
status: resolved
---

# Phase 8: Code Review Report

**Reviewed:** 2026-05-17T15:28:13Z
**Depth:** standard
**Files Reviewed:** 27
**Status:** issues_found

## Summary

Phase 8 wires the Hub-side substrate for Naschool — onboarding service, Filament wizard, shared OAuth Action, hint Sections, and the dev partner-preview pages. The architecture decomposes cleanly: `ConsumerOnboarding` is a focused atomic service, the descriptor-driven approach in `OnboardConsumer` and `StartOAuthFlowAction` is consistent with D-04, and the dev-only routes are properly env-gated.

However, this review surfaces four BLOCKER-class defects that **break the documented user-visible contract**:

1. The post-wizard PAT flash (CR-01) writes to a Livewire-ID-scoped cache key from `OnboardConsumer` but reads from a different Livewire-ID-scoped key on `ListConsumers`. The keys can never match because they are two different Livewire components. Staff completing the wizard will **never see the plain-text PAT** — the wizard's primary deliverable.
2. The `webhook-secret-flash:{...}` cache key (CR-02) is written by `OnboardConsumer::submit()` but read by zero call-sites; the auto-generated secret is silently lost.
3. `StartOAuthFlowAction::dispatch()` (CR-03) catches `InvalidArgumentException` only, but `OAuthFlowRegistry::for()` also throws `ProviderDisabledException` (extends `RuntimeException`) when a Pennant feature-flag disables a provider. Toggling off a provider via the documented kill-switch turns the admin Action into a 500.
4. The dev `/dev/partners/mollie/start-oauth` route (CR-04) creates a pending Connection row **before** calling `OAuthFlowRegistry::for('mollie')`. If Mollie is feature-flagged off (or any unhandled error fires), the request 500s and an orphan pending Connection is left in the DB on every retry.

Warnings concentrate on a different class of correctness gaps: a flaky time-window assertion (`now()`-based), tests that rely on incidental flash-key behavior rather than asserting end-to-end visibility, an unused/non-existent class import, and Filament canAccess gates that silently `?? false` when no user is authenticated (covering up Auth-middleware misconfigurations).

## Critical Issues

### CR-01: Plain PAT cache flash key mismatch — wizard's primary deliverable invisible to staff

**Status:** fixed (commit d0b0955)

**File:** `app/Filament/Pages/OnboardConsumer.php:234-236`, `resources/views/filament/resources/consumers/pages/list-consumers.blade.php:9-10`

**Issue:** `OnboardConsumer::submit()` writes the plain PAT and token-name to cache keys scoped by **its own** Livewire component ID:

```php
$livewireId = $this->getId();
Cache::put("pat-flash:{$livewireId}", $result['plain_token'], now()->addSeconds(60));
Cache::put("pat-flash-name:{$livewireId}", $payload['token_name'], now()->addSeconds(60));
```

Then it redirects to `ConsumerResource::getUrl()` (the `ListConsumers` page). The `list-consumers.blade.php` reads:

```php
$issuedToken = \Illuminate\Support\Facades\Cache::pull('pat-flash:'.$this->getId());
```

But `$this->getId()` inside `list-consumers.blade.php` resolves to **`ListConsumers`'s** Livewire component ID — a completely different component instantiated after the HTTP redirect. The two IDs cannot collide; the cache key will never match.

Consequence: after onboarding through the wizard, staff are redirected to a list page that pulls a non-existent cache key. The PAT they just provisioned is never displayed. It then expires after 60s, gone forever. Naschool gets a Consumer they cannot authenticate as.

The existing `ConsumerResource::issuePatAction` works because the `Action::action()` closure receives `$livewire` (the `ListConsumers` instance) and uses `$livewire->getId()` — same component that renders the blade. The wizard path is missing this bridge.

The existing tests pass because `Cache::spy()` only asserts that *some* key starting with `pat-flash:` is written — it never asserts the keys match between writer and reader. This is exactly the gap the test-design should have caught.

**Fix:** Use a stable, redirect-target-scoped key. Either:

```php
// Option A: scope to the authenticated user (one onboarding at a time per staff)
$key = 'pat-flash:user:'.auth()->id();
Cache::put($key, $result['plain_token'], now()->addSeconds(60));
// list-consumers.blade.php reads the same key:
$issuedToken = Cache::pull('pat-flash:user:'.auth()->id());

// Option B: pass a one-shot token via session flash
session()->flash('onboard.pat_token', $result['plain_token']);
session()->flash('onboard.pat_name', $payload['token_name']);
// blade reads via session()->pull(...) which is single-read by design
```

Add a regression test that submits the wizard end-to-end and asserts the resulting `ListConsumers` GET response contains the plain-text token in the HTML body.

---

### CR-02: webhook-secret-flash cache key is write-only — auto-generated secret silently lost

**Status:** fixed (commit d0b0955)

**File:** `app/Filament/Pages/OnboardConsumer.php:238-240`

**Issue:** When staff leave the webhook-secret field blank, `OnboardConsumer::submit()` auto-generates a 48-char secret (line 201) and stores it in cache:

```php
if ($webhookSecretAutoGenerated || ! empty($data['webhook_callback_secret'])) {
    Cache::put("webhook-secret-flash:{$livewireId}", $webhookSecret, now()->addSeconds(60));
}
```

A `grep -rn "webhook-secret-flash"` across `app/` and `resources/` returns exactly one match — this write. Zero reads. The auto-generated secret is encrypted and stored on the Consumer row, then the only plain-text copy expires from cache after 60 seconds. The staff who triggered the onboarding has no way to retrieve it, and the consuming SaaS-app cannot sign its webhook callbacks without it.

This is the same Livewire-ID mismatch as CR-01 *and* a missing render-site rolled into one defect.

**Fix:** Either render the secret on the redirect target (after fixing the key scope per CR-01), or document that the secret is non-recoverable and add a rotate-action. The plan promised "wordt eenmalig getoond na opslaan" — currently it is shown zero times.

```php
// Same scope-fix as CR-01:
$key = 'webhook-secret-flash:user:'.auth()->id();
Cache::put($key, $webhookSecret, now()->addSeconds(60));

// And add a render-block in list-consumers.blade.php that pulls and shows it.
```

Add a regression test that asserts the post-redirect `ListConsumers` response actually contains the plain webhook secret when auto-generated.

---

### CR-03: StartOAuthFlowAction::dispatch() misses ProviderDisabledException — Pennant kill-switch becomes 500

**Status:** fixed (commit cc316c2)

**File:** `app/Filament/Actions/StartOAuthFlowAction.php:102-112`

**Issue:** The dispatch catches `InvalidArgumentException` only:

```php
try {
    $flow = app(OAuthFlowRegistry::class)->for($provider);
} catch (InvalidArgumentException) {
    Notification::make()
        ->title('Geen OAuth-flow beschikbaar')
        ...
}
```

But `OAuthFlowRegistry::for()` (`app/OAuth/OAuthFlowRegistry.php:34-36`) throws `ProviderDisabledException` when Pennant has disabled the provider:

```php
if (! Feature::active("provider-{$provider}-enabled")) {
    throw new ProviderDisabledException($provider);
}
```

`ProviderDisabledException extends RuntimeException` — NOT `InvalidArgumentException`. So when an operator toggles off Mollie via the documented kill-switch (CLAUDE.md "Feature-flags / kill-switch" section), the staff-facing admin action raises an uncaught exception and returns a 500. The action visibility filter doesn't gate on the feature-flag either, so the button stays visible even while the underlying flow is disabled.

**Fix:** Catch both exception types and consider hiding the action when the feature is off:

```php
use App\OAuth\Exceptions\ProviderDisabledException;

try {
    $flow = app(OAuthFlowRegistry::class)->for($provider);
} catch (InvalidArgumentException|ProviderDisabledException $e) {
    Notification::make()
        ->title($e instanceof ProviderDisabledException
            ? "Provider {$provider} is tijdelijk uitgeschakeld"
            : 'Geen OAuth-flow beschikbaar')
        ->body($e->getMessage())
        ->warning()
        ->send();

    return back();
}
```

Also extend `oauthCapableProviders()` to filter on `Feature::active(...)` so the dropdown only lists enabled providers. Add a regression test that asserts the action degrades gracefully when Pennant returns inactive for the provider.

---

### CR-04: Dev OAuth-init route creates orphan pending Connection on every failed retry

**Status:** fixed (commit 727be72)

**File:** `routes/web.php:64-82`

**Issue:** The `/dev/partners/mollie/start-oauth` route creates the pending Connection *before* calling the registry:

```php
$state = Str::random(48);
$account->connections()->create([           // ← row inserted unconditionally
    'provider' => 'mollie',
    'status' => 'pending',
    'oauth_state' => $state,
    'oauth_state_expires_at' => now()->addMinutes(30),
]);

$scopes = config('services.mollie.connect.scopes');
$url = app(OAuthFlowRegistry::class)->for('mollie')->getAuthorizationUrl(...);  // ← can throw
```

If `OAuthFlowRegistry::for('mollie')` throws `ProviderDisabledException` (Pennant flag off) or `getAuthorizationUrl()` raises any other error (config missing, network, etc.), the route 500s and the row stays in the DB. Every retry by the dev appends another orphan pending Connection. Compounding effect: a 30-minute TTL on `oauth_state_expires_at` means these accumulate for half an hour minimum, polluting the partner-preview status widget and per-provider totals.

The action is also not wrapped in a `DB::transaction` and does not handle the `ProviderDisabledException` differently from a generic 500.

**Fix:** Reverse the order (build authorize URL first, then insert the row), or wrap both steps in a transaction:

```php
$state = Str::random(48);
$scopes = config('services.mollie.connect.scopes');

try {
    $flow = app(OAuthFlowRegistry::class)->for('mollie');
    $url = $flow->getAuthorizationUrl($account, $scopes, $state);
} catch (\Throwable $e) {
    abort(503, 'Mollie OAuth flow niet beschikbaar: '.$e->getMessage());
}

$account->connections()->create([
    'provider' => 'mollie',
    'status' => 'pending',
    'oauth_state' => $state,
    'oauth_state_expires_at' => now()->addMinutes(30),
]);

return redirect()->away($url);
```

This matches the cleaner order that `StartOAuthFlowAction::dispatch()` *almost* follows (it still has the same orphan-row risk if `getAuthorizationUrl` throws after the create — apply the same fix there too).

## Warnings

### WR-01: Flaky time-window assertion in StartOAuthFlowActionTest

**Status:** fixed (commit cc316c2)

**File:** `tests/Feature/Admin/StartOAuthFlowActionTest.php:202-208`

**Issue:** The test asserts the `oauth_state_expires_at` falls between `now()->addMinutes(29)` and `now()->addMinutes(31)`. Both `now()` calls in the assertion are re-evaluated separately from the `now()` call inside `dispatch()`, and with a slow CI runner (>~1s drift after creating Account + Connection factories + bind FakeOAuthFlow), the window can fail. The assertion treats the boundary as inclusive while `Carbon::between()` is inclusive — usually OK — but the unbounded `now()` calls remain a flake source.

**Fix:** Use `Carbon::setTestNow()` or `Date::setTestNow()` at the start of the test to freeze the clock:

```php
\Illuminate\Support\Facades\Date::setTestNow('2026-05-17 12:00:00');

StartOAuthFlowAction::dispatch($account, 'mollie');

$connection = Connection::where('account_id', $account->id)->first();
$this->assertEquals(
    now()->addMinutes(30)->format('Y-m-d H:i:s'),
    $connection->oauth_state_expires_at->format('Y-m-d H:i:s'),
);
```

The same pattern applies to `PartnerPagesTest::test_mollie_dev_oauth_route_creates_pending_connection` (line 259).

---

### WR-02: OnboardConsumer happy-path test doesn't assert plain PAT is rendered post-redirect

**Status:** fixed (commit d0b0955)

**File:** `tests/Feature/Admin/OnboardConsumerTest.php:158-201`

**Issue:** `test_happy_path_provisions_all_artifacts` covers DB-row creation but never asserts the plain-text PAT becomes visible on the redirect target. The two no-leak tests (`test_plain_token_not_visible_after_dismiss`, `test_plain_webhook_secret_not_visible_after_submit`) only assert *which key* was written, never that the reader-blade pulls the same key. Both pass while CR-01 + CR-02 are broken — false-positive coverage.

**Fix:** Add an end-to-end test that follows the redirect and asserts the token appears in the `ListConsumers` HTML body:

```php
public function test_happy_path_renders_plain_token_on_redirect_target(): void
{
    $this->actAsStaffWithPermission();

    Livewire::test(OnboardConsumer::class)
        ->fillForm([... happy path ...])
        ->call('submit');

    $consumer = Consumer::where('slug', 'naschool-test')->first();
    $rawToken = $consumer->tokens()->first(); // hashed - we need the plain

    // The wizard only knows the plain token at submit-time; the test must
    // intercept it via the Cache::put spy OR by mocking ConsumerOnboarding.
    // Then GET the redirect target and assert the token appears.
    $response = $this->get(ConsumerResource::getUrl());
    $response->assertSee($plainToken);
}
```

---

### WR-03: PartnerStatus N+1 test is asserting an upper bound that can mask regressions

**Status:** fixed (commit 727be72)

**File:** `tests/Feature/Dev/PartnerPagesTest.php:87-108`

**Issue:** The N+1 test asserts `count($queries) <= 2`. The current implementation in `PartnerStatus::forProvider()` does exactly 2 queries (1 for `Account::query()->get()`, 1 for eager-loaded `connections`). A future change that introduces a third query (e.g., eager-loading `consumer` for the Blade template) silently slips through. The assertion should be exact (`assertSame(2, ...)`) and reviewed when intentionally increased.

Also: the test does not cover the `totalsForProvider()` method's query-count, which calls `forProvider()` *twice* if not careful — it currently calls it once but the warning serves as a tripwire.

**Fix:**

```php
$this->assertSame(2, count($queries), 'PartnerStatus::forProvider() moet exact 2 queries doen');
```

And add a separate test for `totalsForProvider()` query-count.

---

### WR-04: PartnerStatus returns all Accounts globally — leaks cross-Consumer Account presence to /dev/partners

**Status:** fixed (commit e6b9998)

**File:** `app/Services/PartnerStatus.php:32-42`

**Issue:** `forProvider()` does `Account::query()->with(...)->get()` with no Consumer-scope. The `/dev/partners` page is `local`/`testing`-only so production exposure is OK, but the totals shown (e.g., "Mollie: 1/2 Accounts gekoppeld") count Accounts from *every* Consumer in the dev DB, not just the demo Naschool Account. Combined with the route-level seed of a single Naschool Account, the "totals" become misleading the moment a second Consumer gets seeded.

This isn't a security issue in dev, but the per-provider widget is documented as "live koppel-status (dev-omgeving)" — the global aggregation contradicts the per-Account-row rendering directly below it.

**Fix:** Either scope to the demo Consumer or document that the totals are global:

```php
public function forProvider(string $provider, ?string $consumerSlug = null): Collection
{
    return Account::query()
        ->when($consumerSlug, fn ($q) => $q->whereHas('consumer', fn ($qq) => $qq->where('slug', $consumerSlug)))
        ->with(['connections' => fn ($q) => $q->where('provider', $provider)])
        ->get()
        ->map(...);
}
```

---

### WR-05: OnboardConsumer::submit() makes no attempt to handle wizard-mid Connection-creation failure

**Status:** fixed (commit 2609782)

**File:** `app/Filament/Pages/OnboardConsumer.php:217-229`

**Issue:** The submit catches `\Throwable`, calls `report($e)`, and shows a generic "Er ging iets mis. Probeer opnieuw of bekijk Horizon-logs." notification. Two issues:

1. The catch swallows `InvalidArgumentException` thrown by `ConsumerOnboarding::assertAbilitiesWhitelisted()`. That exception carries a precise message ("Onbekende abilities: …") that the CLI happily surfaces to operators (`HubConsumerCreate::handle` line 35). Filament staff only sees "Er ging iets mis" — a usability regression from CLI parity.

2. After the catch, the wizard does not reset `$webhookSecretAutoGenerated` or restore form state. A retry could submit fresh data with stale auto-gen flags.

**Fix:**

```php
} catch (\InvalidArgumentException $e) {
    // Domain-validation errors carry actionable messages.
    Notification::make()
        ->title('Validatie mislukt')
        ->body($e->getMessage())
        ->danger()
        ->send();
    return;
} catch (\Throwable $e) {
    report($e);
    Notification::make()
        ->title('Onboarden mislukt')
        ->body('Onverwachte fout — bekijk Horizon-logs.')
        ->danger()
        ->send();
    return;
}
```

---

### WR-06: Provider radio in OnboardConsumer wizard required but submit silently no-ops on null

**Status:** fixed (commit 2609782)

**File:** `app/Filament/Pages/OnboardConsumer.php:131-135`, `275-297`

**Issue:** `Radio::make('connection.provider')->required()` enforces selection in the Wizard step UI, but `buildConnectionPayload()` returns `null` when no provider was selected, which `ConsumerOnboarding::onboard()` then silently treats as "no Connection requested." If the validation is ever bypassed (Filament v4 wizard step-skipping has known edge cases; an `Action::execute()` outside the form-flow path; a future schema change), the staff will see a Consumer + Account created with zero Connection and no warning. This breaks the wizard's documented contract of "Consumer + Account + Connection + PAT" atomic onboarding.

**Fix:** Add a server-side guard in `submit()`:

```php
$connectionData = self::buildConnectionPayload($data['connection'] ?? []);
if ($connectionData === null) {
    Notification::make()
        ->title('Stap 3 onvolledig: kies een provider.')
        ->danger()
        ->send();
    return;
}
```

Or have `ConsumerOnboarding::onboard()` throw when `external_id` is set but `connection` is missing, since the wizard's contract is "every Account-creation implies a Connection."

---

### WR-07: `_status-widget.blade.php` `optional()` use on already-nullsafe access is a no-op wrapper that hides intent

**Status:** fixed (commit 727be72)

**File:** `resources/views/partners/partials/_status-widget.blade.php:26,35`

**Issue:** `optional($entry['connection']?->revoked_at)->format('Y-m-d H:i')` — the `?->` already short-circuits on null. The `optional()` wrapper adds nothing but extra reflection overhead per row. More importantly, calling `->format()` on a `?Carbon` via `?->` returns `null` cleanly; with `optional()->format()`, a non-null `revoked_at` of an unexpected type (string from a future raw-query refactor) would still call `format` on the object and crash silently elsewhere.

**Fix:**

```php
'text' => 'revoked at '.($entry['connection']?->revoked_at?->format('Y-m-d H:i') ?? 'unknown'),
$expiresAt = $entry['connection']?->expires_at?->format('Y-m-d H:i');
```

## Info

### IN-01: Unused import of non-existent class `App\Models\PersonalAccessToken`

**Status:** fixed (commit d0b0955) — opgepakt als no-cost cleanup tijdens CR-01-fix in dezelfde testfile

**File:** `tests/Feature/Admin/OnboardConsumerTest.php:11`

**Issue:** `use App\Models\PersonalAccessToken;` — this class does not exist in the codebase (`find app -name "PersonalAccessToken*"` returns nothing). PHP's `use` is lazily resolved so the file still works, but the import is dead code that will confuse the next reader and break a future linter pass (PHPStan strict).

**Fix:** Remove the unused import. The test elsewhere correctly uses `Laravel\Sanctum\PersonalAccessToken` (see `tests/Feature/Services/ConsumerOnboardingTest.php:13`).

---

### IN-02: `auth()->user()?->can(...) ?? false` masks unauthenticated requests reaching guarded resources

**Status:** deferred — defensive pattern, niet zelf een correctness-bug. Past beter in een breder RBAC-cleanup-pass (5+ callsites).

**File:** `app/Filament/Resources/Consumers/ConsumerResource.php:92`, `app/Filament/Resources/Connections/ConnectionResource.php:38`, `app/Filament/Pages/OnboardConsumer.php:72`, `app/Filament/Actions/StartOAuthFlowAction.php:71,83`

**Issue:** Five `canAccess` / `visible` callbacks use `auth()->user()?->can('…') ?? false`. The Filament panel sits behind `Authenticate` middleware (AdminPanelProvider line 78), so `auth()->user()` should never be null in this code path. The `?? false` defensively returns "not authorized" instead of surfacing the Auth-middleware misconfiguration — useful in theory, harmful in practice when a future refactor accidentally removes Authenticate middleware and every page silently 403s without any error log.

**Fix:** Either:

- Use `Gate::allows('manage-consumers')` which throws a clear error when no user is resolved, OR
- Add a `\LogicException("auth()->user() should not be null after Authenticate middleware")` guard before the `?->` chain.

Low priority — not a current correctness issue, just a robustness gap.

---

### IN-03: `ConsumerInfolist` Section uses `->schema([])` empty slot — a label/description-only Section is fragile

**Status:** deferred — werkt vandaag, tripwire-test verifieert het. Pak op als Filament v4-minor upgrade het pattern breekt.

**File:** `app/Filament/Resources/Consumers/Schemas/ConsumerInfolist.php:23-26`, `app/Filament/Resources/Accounts/Schemas/AccountInfolist.php:17-20`

**Issue:** Filament v4 Sections with an empty `->schema([])` render as collapsed-but-empty containers. The pattern works *today* (verified by `ConsumerInfolistHintTest::test_view_consumer_page_renders_hint_section_heading_and_body`), but it is leaning on internal rendering quirks. A future Filament minor upgrade that adds "skip empty sections" would silently remove the hint section entirely. The `isCollapsed:  true` assertion (literal double-space) further illustrates how brittle this is.

**Fix:** Add a `Placeholder` or `TextEntry` with a `->hidden()` placeholder to materialize the schema, or use Filament's `Note`/`Hint` component if v4 ships one. Document in `.docs/decisions/` why the hint-Section pattern was chosen if it stays.

---

### IN-04: `HubConsumerCreate` `--abilities=*` default falls back to ADMIN, but help-text says "(default: *)"

**Status:** deferred — cosmetic help-text-edit, gedrag al gevalideerd.

**File:** `app/Console/Commands/HubConsumerCreate.php:15,74-76`

**Issue:** The signature documents `{--abilities=* : Comma-separated of meermaals (default: *)}` but the resolver returns `[TokenAbilities::ADMIN]` (a single-element list, value `'*'`) when no `--abilities` is passed. The help-text-vs-behavior is consistent only if the reader knows ADMIN == `'*'`. A staff reading the help would expect "all abilities, expanded out", not "the single wildcard ability".

This is matched correctly by the test `test_creates_consumer_with_default_admin_ability` (line 30: `assertSame(['*'], $token->abilities)`), so behavior is intentional. Just a clarity issue.

**Fix:** Update the signature help-text:

```php
{--abilities=* : Comma-separated of meermaals (default: * — single ADMIN wildcard)}
```

---

### IN-05: Multiple `ucfirst($provider)` for display labels — not i18n-ready and inconsistent with provider config

**Status:** deferred — vereist `ProviderCredentialDescriptor` schema-uitbreiding (display_label-field + config-rij update). Logischer in v0.3 wanneer SnelStart-branding scherper geformaliseerd wordt.

**File:** `app/Filament/Pages/OnboardConsumer.php:259`, `app/Filament/Actions/StartOAuthFlowAction.php:47`, `resources/views/partners/index.blade.php:33,36`, `resources/views/partners/partials/_status-widget.blade.php:43`

**Issue:** Provider labels are derived via `ucfirst($descriptor->key)` in multiple sites, producing "Mollie" / "Snelstart". But the canonical brand is "SnelStart" (CamelCase per their docs and used in `resources/views/partners/snelstart/example.blade.php:10`). The dev pages now show two different brand-spellings depending on which template renders.

**Fix:** Add a `display_label` field to `ProviderCredentialDescriptor` and config:

```php
// config/hub-providers.php
'snelstart' => [
    'display_label' => 'SnelStart',
    ...
],
```

Then replace `ucfirst($descriptor->key)` with `$descriptor->displayLabel` everywhere.

---

_Reviewed: 2026-05-17T15:28:13Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
