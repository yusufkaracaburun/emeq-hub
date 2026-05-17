---
phase: 10
gathered: 2026-05-16
source: derived-from-roadmap-and-09-REVIEW.md
status: ready-for-planning
---

# Phase 10: Phase 9 polish — deferred review-findings · Context

**Source:** Roadmap-derived. Phase 10 is een polish-fase met perfecte broncoverage: `09-REVIEW.md` is de canonical source-of-truth. Geen ambiguïteit, geen discuss-phase nodig — alle 11 in-scope bevindingen zijn 1-op-1 mapbaar naar locked decisions.

<domain>
## Phase Boundary

**Scope:** Sluit de 11 deferred bevindingen uit `09-REVIEW.md` af (CR-02 BLOCKER + WR-01..06 + IN-01..04). CR-01 valt buiten scope — al gelandet in commit `7f86c6d`.

**Werkterrein:** `emeq-hub` repo:
- Filament v4 resources: `Consumer`/`Connection`/`Account`/`WebhookCall`/`AccountSubscription`/`CashierSubscription` (canAccess gating)
- Nieuw Hub-eigen `App\Models\WebhookCall extends Spatie\WebhookClient\Models\WebhookCall` + Eloquent-relatie
- `UserResource`-table-action + `EditUser` delete-action (last-super-admin guards)
- `WebhookCallInfolist` (exception-rendering)
- `EmeqStaffSeeder` (password-reset-pad)
- `ListConsumers`/`list-consumers.blade.php` (PAT-token transient cache)
- `AccountSubscriptionResource::cancelAction` (try/catch + fingerprint)
- `ProviderCredentialDescriptor` (`tryFor()` helper)
- Bijbehorende feature-tests in `tests/Feature/Admin/`

**Niet in scope:**
- CR-01 quick-login route-guard (al gefixt in `7f86c6d`).
- Performance/N+1 buiten de WebhookCall-relatie-fix (IN-01 lost mee).
- AdminPanelProvider `->default()` refactor (IN-03 = documentatie/comment, geen code-change).
- Nieuwe Spatie-permissions toevoegen — alleen bestaande seeder-permissions (`view-webhooks`, `view-account-subscriptions`, `view-billing`, `manage-consumers`, `manage-connections`) consulteren.

</domain>

<decisions>
## Implementation Decisions (locked)

Elke bevinding uit `09-REVIEW.md` is een locked decision. Wie afwijkt = scope-creep.

### D-1 — `canAccess()` op alle 6 niet-User-resources (uit CR-02)

- `ConsumerResource::canAccess()` → `auth()->user()?->can('manage-consumers') ?? false`
- `ConnectionResource::canAccess()` → `auth()->user()?->can('manage-connections') ?? false`
- `AccountResource::canAccess()` → permission `manage-consumers` (Accounts hangen onder Consumer-domein; staff zonder consumer-rechten ziet ook geen accounts) — bevestigen via roadmap-uitspraak "view-webhooks, view-account-subscriptions, view-billing, manage-consumers, manage-connections" → AccountResource niet expliciet, dus erven van `manage-consumers` als de meest dichtbije permission. Plan-laag mag deze afleiden zolang de seeder permissions consistent zijn.
- `WebhookCallResource::canAccess()` → `view-webhooks`
- `AccountSubscriptionResource::canAccess()` → `view-account-subscriptions`
- `CashierSubscriptionResource::canAccess()` → `view-billing`
- Voor elke resource ook: `shouldRegisterNavigation()` → `static::canAccess()` zodat het nav-item verdwijnt zonder permission.
- `UserResource` blijft zoals het is (`hasRole('super-admin')`-check).

### D-2 — Hub-eigen `App\Models\WebhookCall` (uit CR-02 + IN-01)

- Nieuwe class `App\Models\WebhookCall extends Spatie\WebhookClient\Models\WebhookCall`.
- Belongs-to `consumer()`-relatie op `consumer_id`.
- `config/webhook-client.php` → `'webhook_model' => App\Models\WebhookCall::class` (configureerbare model-binding van Spatie).
- `WebhookCallResource::getEloquentQuery()` óf table-`modifyQueryUsing(fn ($q) => $q->with('consumer'))` eager-loadt de relatie.
- `WebhookCallsTable` rendert `TextColumn::make('consumer.slug')` — géén `Consumer::find()` meer.
- Idem voor `WebhookCallInfolist` als die ook `Consumer::find()` doet.

### D-3 — Cross-Consumer-isolation test in `WebhookCallResource` (uit CR-02, sluit HUB-04 SC-7)

- Nieuwe testmethoden in `tests/Feature/Admin/WebhookCallResourceTest`:
  - `test_cross_consumer_isolation_staff_sees_only_assigned_consumer_webhooks` (of equivalent) — bewijst dat een staff-user met alleen `view-webhooks` géén webhooks van andere Consumers ziet **OF** dat het permission-model in v0.2 alle staff alle consumers laat zien maar dat dit een geconstateerde keuze is. Lees `09-REVIEW.md` CR-02 nogmaals: planner kiest tussen (a) filter-per-staff-consumer-binding (vereist een nieuwe staff↔consumer relatie — out of scope) of (b) **expliciet** in CONTEXT.md vastleggen dat v0.2 cross-consumer-zichtbaarheid voor staff acceptabel is en de test bewijst alleen permission-gating (zonder `view-webhooks` → 403, mét → alle rijen). Planner kiest **optie b**: dit blijft consistent met de v0.2 "intern-only-staff"-aanname in `09-REVIEW.md` CR-02.
- Plan moet expliciet documenteren waarom: SC-7 ("cross-Consumer-isolatie via gefilterde queries") wordt in v0.2 ingevuld als permission-gated, niet als consumer-scoped — een latere fase (v1.0+ externe staff per Consumer) introduceert staff↔consumer-binding.

### D-4 — Last-super-admin guards (uit WR-01)

- `UsersTable::assignRole`-action: action-callback weigert wanneer
  - `$record->id === auth()->id() && $data['role'] !== 'super-admin'` (self-downgrade), OF
  - `$record->hasRole('super-admin') && $data['role'] !== 'super-admin' && User::role('super-admin')->where('id', '!=', $record->id)->count() === 0` (laatste super-admin)
- Bij geweigerd: Filament `Notification::danger()` met expliciete reden, géén `syncRoles()`.
- `EditUser`-`DeleteAction`: zelfde laatste-super-admin-guard via `->before()` of een custom `->action()` — gooit/`halts` op delete van laatste super-admin OF op self-delete.
- 2 nieuwe regression-tests:
  - `test_last_super_admin_cannot_self_downgrade` (assignRole-pad)
  - `test_last_super_admin_cannot_be_deleted` (DeleteAction-pad)

### D-5 — `WebhookCallInfolist` exception-veld unwrap (uit WR-02)

- `WebhookCallInfolist::configure()` voor `exception`-veld: vervang `->state(fn ($record): string => json_encode(...))` door:
  ```php
  TextEntry::make('exception')
      ->label('Exception')
      ->placeholder('—')
      ->columnSpanFull(),
  ```
  Filament's default rendering escapet HTML en behoudt newlines. (Optioneel `->copyable()` voor debug-flow.)

### D-6 — `assignRole`-Select server-side `->in()` + try/catch (uit WR-03)

- `UsersTable::assignRole`-action: `Select::make('role')` krijgt `->in(['super-admin', 'staff'])` validator.
- Action-callback wrapt `syncRoles()` in try/catch `\Spatie\Permission\Exceptions\RoleDoesNotExist` → `Notification::danger()` met user-friendly reden, géén 500.

### D-7 — `EmeqStaffSeeder` password-reset-pad (uit WR-04)

- Vervang `firstOrCreate(['email'=>...], ['name'=>..., 'password'=>...])` door:
  - Lookup bestaande user.
  - **Bestaand:** explicit hard-fail met `throw new RuntimeException("User {email} bestaat al — reset via tinker, niet via seeder")` **OF** explicit password-update met `forceFill(['password' => Hash::make($password)])->save()`. Planner kiest **hard-fail** (idiom voor seeder: "bootstrap, niet sync"); doc-string update vermeldt operator-instructies.
- `EmeqStaffSeederTest` krijgt `test_seeder_hard_fails_when_user_already_exists` (of de variant — gebaseerd op gekozen branch in plan).

### D-8 — `UserForm` edit-zonder-password regression-test (uit WR-05)

- Nieuwe testmethode `test_edit_user_without_password_keeps_existing_hash` in `tests/Feature/Admin/UserResourceTest`. Geen productiecode-wijziging tenzij het test-running de bug oppervlakt — dan ook switchen naar de explicit `mutateDehydratedStateUsing`-pattern.

### D-9 — PAT-token uit Livewire `wire:snapshot` halen (uit WR-06)

- `ListConsumers` Livewire-component: `public ?array $lastIssuedPat = null` verdwijnt.
- Issue-PAT-action zet token via `Cache::put("pat-flash:{$livewire->getId()}", $plainToken, 60)` en dispatcht `pat-issued`-event.
- Blade `list-consumers.blade.php` consumes `Cache::pull("pat-flash:{$id}")` **one-shot** (geen Alpine `x-data` met token, geen Livewire-property meer).
- Test: bestaande `ConsumerTokenActionTest` moet groen blijven. Optioneel `test_plain_token_not_in_livewire_snapshot` als smoke (assertNotContains plain-token in HTTP-response).

### D-10 — `AccountSubscriptionResource::cancelAction` try/catch + fingerprint (uit IN-02)

- Wrap `AccountSubscriptionManager::cancel()` in try/catch `\Throwable`:
  ```php
  } catch (\Throwable $e) {
      report($e);
      Notification::make()
          ->title('Cancel-actie mislukt')
          ->body('Zie logs — fingerprint: '.substr(hash('sha256', $e->getMessage()), 0, 12))
          ->danger()
          ->send();
      return;
  }
  ```
- Geen `$e->getMessage()` in user-facing notification. Idem voor pause/resume-actions als die ook raw Mollie-exceptions kunnen tonen (planner checkt `AccountSubscriptionStateActionsTest` om scope te bepalen).

### D-11 — `ProviderCredentialDescriptor::tryFor()` helper (uit IN-04)

- Nieuwe static `public static function tryFor(string $provider): ?self { try { return self::for($provider); } catch (InvalidArgumentException) { return null; } }`.
- `Connection::fingerprint()` (`app/Models/Connection.php:48-65`) gebruikt `tryFor($this->provider)?->fingerprintField` — geen inline try/catch meer.
- Bestaande `ConnectionFingerprintTest` moet groen blijven (gedrag identiek).

### Claude's Discretion

- Volgorde van plans/waves (wave-1 fundament `WebhookCall`-model, wave-2 alle Resource `canAccess()`-checks parallel, wave-3 tests, wave-4 polish).
- Wel/geen aparte plan voor IN-03 (`AdminPanelProvider->default()` comment) — mag bij IN-04 of laatste polish-plan.
- Wel/geen aparte testfile per nieuwe assertie of bundelen in bestaande `*ResourceTest`.
- Of `WebhookCallInfolist` ook `Consumer::find()` gebruikt en in dezelfde plan-task valt als `WebhookCallsTable`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning of implementing.**

### Phase 9 Review (source-of-truth)
- `.planning/phases/09-filament-admin-ui-voor-emeq-medewerkers/09-REVIEW.md` — alle 11 in-scope bevindingen met exacte regelnummers, fix-snippets en bewijs.
- `.planning/phases/09-filament-admin-ui-voor-emeq-medewerkers/09-CONTEXT.md` — Phase 9 success criteria (D-05 permission-model + HUB-04 SC-7).
- `.planning/phases/09-filament-admin-ui-voor-emeq-medewerkers/09-11-ACCEPTANCE.md` — HUB-04 SC-1..SC-10 acceptance baseline.

### Code (te wijzigen)
- `app/Filament/Resources/Consumers/ConsumerResource.php` — `manage-consumers` canAccess
- `app/Filament/Resources/Connections/ConnectionResource.php` — `manage-connections` canAccess
- `app/Filament/Resources/Accounts/AccountResource.php` — canAccess (permission TBD per D-1)
- `app/Filament/Resources/WebhookCalls/WebhookCallResource.php` — `view-webhooks` canAccess + modifyQueryUsing eager-load
- `app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php` — vervang `Consumer::find()` door `consumer.slug` relatie-kolom
- `app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php` — exception-veld unwrap + (mogelijk) `consumer.slug` relatie
- `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php` — `view-account-subscriptions` canAccess + cancelAction try/catch + fingerprint
- `app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php` — `view-billing` canAccess
- `app/Filament/Resources/Users/Tables/UsersTable.php` — `->in()` validator + last-super-admin-guard
- `app/Filament/Resources/Users/Pages/EditUser.php` — DeleteAction last-super-admin-guard
- `app/Filament/Resources/Consumers/Pages/ListConsumers.php` + `resources/views/filament/resources/consumers/pages/list-consumers.blade.php` — PAT-token via `Cache::pull()` one-shot
- `app/Models/WebhookCall.php` (**nieuw**) — `extends Spatie\WebhookClient\Models\WebhookCall` + `consumer()` belongs-to
- `app/Models/Connection.php` — `fingerprint()` gebruikt `ProviderCredentialDescriptor::tryFor()`
- `app/Support/ProviderCredentialDescriptor.php` — nieuwe `tryFor()` static
- `config/webhook-client.php` — `webhook_model` → `App\Models\WebhookCall::class`
- `database/seeders/EmeqStaffSeeder.php` — hard-fail-bij-existing-user-pad
- `tests/Feature/Admin/WebhookCallResourceTest.php` — 1+ cross-Consumer-isolation tests
- `tests/Feature/Admin/UserResourceTest.php` — `test_edit_user_without_password_keeps_existing_hash` + last-super-admin-guards
- `tests/Feature/Admin/EmeqStaffSeederTest.php` — bestaand-user-pad
- `tests/Feature/Admin/AccountSubscriptionResourceTest.php` — cancelAction-exception-pad (optioneel)

### Stack
- Filament v4: `Resource::canAccess()` + `Resource::shouldRegisterNavigation()` — gebruik exact deze API's, geen middleware-laag.
- Spatie webhook-client `webhook_model` config-binding: zie `config/webhook-client.php` na `vendor/spatie/laravel-webhook-client/config/webhook-client.php`.
- Spatie permission: `auth()->user()->can('view-webhooks')`-pattern (al gebruikt in `EmeqStaffSeeder`).

### Project rules
- `CLAUDE.md` — Hub-architectuur, security-invarianten (geen raw tokens in logs/state, fingerprint-only).
- `.ai/rules/global.md` — secrets in cleartext nooit in HTTP-response. WR-06 (PAT-token in Livewire-snapshot) is direct hier tegen.
- `.ai/rules/engineering.md` — chirurgisch wijzigen; geen "verbetering" naast de scope.

</canonical_refs>

<specifics>
## Specific Ideas

- Plan-volgorde-suggestie (4 waves):
  - **Wave 1 (fundament):** `App\Models\WebhookCall` + `config/webhook-client.php` binding + `WebhookCallResource` eager-load. Onafhankelijk; alle canAccess-plans hangen er **niet** van af, maar de WebhookCallResource-canAccess-plan kan beter na wave 1 zodat 1 PR het hele webhook-pad raakt.
  - **Wave 2 (canAccess gating):** 6 resources × `canAccess()` + `shouldRegisterNavigation()` — parallel-ladable. Tests per resource: assert `$staff->givePermissionTo($perm)` enabled, `revoke` → 403.
  - **Wave 3 (user-guards + WR/IN):** Last-super-admin guards + Select `->in()` + EmeqStaffSeeder hard-fail + Infolist exception unwrap + cancelAction try/catch + descriptor `tryFor()`. Parallel-able na wave 2.
  - **Wave 4 (token-hygiene + regression-tests):** PAT-token via `Cache::pull()` + alle nieuwe regression-tests + volledige test-suite-groen-check.
- HUB-04 SC-7 wordt **expliciet** in WebhookCallResourceTest gesloten met permission-gating (D-1 + D-3). Plan moet dit als acceptance-criterium opnemen.
- IN-03 (AdminPanelProvider `->default()` comment) is een 1-regel-doc-toevoeging — bundelt logisch met IN-04 of een polish-plan.

</specifics>

<deferred>
## Deferred Ideas

- Per-Consumer staff↔consumer binding (v1.0+ multi-tenant-staff). HUB-04 SC-7 wordt in v0.2 ingevuld via permission-gating; consumer-scoped staff-views zijn een latere fase.
- `AdminPanelProvider->default()` refactor naar expliciete panel-pickup — IN-03 levert alleen een comment, geen code-change.
- Filament-v4 `mutateDehydratedStateUsing`-pattern voor `UserForm` password — alleen omschrijven als de regression-test in D-8 de huidige `dehydrateStateUsing` als correct bewijst. Anders patchen.
- N+1-audit op andere Resources (AccountResource consumer-relatie etc.) — out of scope; alleen WebhookCallsTable raakt deze fase.

</deferred>

<scope_fence>
## Scope Fence

**In scope:** CR-02 + WR-01..06 + IN-01..04 (11 items) uit `09-REVIEW.md` plus bijbehorende tests die HUB-04 SC-7 sluiten.

**Out of scope (escalate als nodig):**
- Nieuwe Spatie-permissions definiëren (e.g. `manage-subscriptions` voor pause/resume) — gebruik bestaande seeder-permissions.
- Refactor van `AccountResource` permission-strategie (D-1 hangt er aan).
- Performance-werk buiten de Hub-eigen WebhookCall-relatie.
- Wijzigingen aan Phase 9 plans of summaries (Phase 9 is closed).

</scope_fence>

---

*Phase: 10-phase-9-polish-deferred-review-findings*
*Context derived: 2026-05-16 (no /gsd:discuss-phase — roadmap + 09-REVIEW.md are the locked source)*
