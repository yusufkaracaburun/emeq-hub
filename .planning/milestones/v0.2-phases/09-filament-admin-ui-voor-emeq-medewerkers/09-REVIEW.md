---
phase: 09
reviewed: 2026-05-16T00:00:00Z
depth: standard
files_reviewed: 53
files_reviewed_list:
  - app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php
  - app/Filament/Resources/AccountSubscriptions/Pages/ListAccountSubscriptions.php
  - app/Filament/Resources/AccountSubscriptions/Pages/ViewAccountSubscription.php
  - app/Filament/Resources/Accounts/AccountResource.php
  - app/Filament/Resources/Accounts/Pages/ListAccounts.php
  - app/Filament/Resources/Accounts/Pages/ViewAccount.php
  - app/Filament/Resources/Accounts/Schemas/AccountInfolist.php
  - app/Filament/Resources/Accounts/Tables/AccountsTable.php
  - app/Filament/Resources/CashierSubscriptions/CashierSubscriptionResource.php
  - app/Filament/Resources/CashierSubscriptions/Pages/ListCashierSubscriptions.php
  - app/Filament/Resources/CashierSubscriptions/Pages/ViewCashierSubscription.php
  - app/Filament/Resources/CashierSubscriptions/Schemas/CashierSubscriptionInfolist.php
  - app/Filament/Resources/CashierSubscriptions/Tables/CashierSubscriptionsTable.php
  - app/Filament/Resources/Connections/ConnectionResource.php
  - app/Filament/Resources/Connections/Pages/ListConnections.php
  - app/Filament/Resources/Connections/Pages/ViewConnection.php
  - app/Filament/Resources/Consumers/ConsumerResource.php
  - app/Filament/Resources/Consumers/Pages/CreateConsumer.php
  - app/Filament/Resources/Consumers/Pages/EditConsumer.php
  - app/Filament/Resources/Consumers/Pages/ListConsumers.php
  - app/Filament/Resources/Users/Pages/CreateUser.php
  - app/Filament/Resources/Users/Pages/EditUser.php
  - app/Filament/Resources/Users/Pages/ListUsers.php
  - app/Filament/Resources/Users/Schemas/UserForm.php
  - app/Filament/Resources/Users/Tables/UsersTable.php
  - app/Filament/Resources/Users/UserResource.php
  - app/Filament/Resources/WebhookCalls/Pages/ListWebhookCalls.php
  - app/Filament/Resources/WebhookCalls/Pages/ViewWebhookCall.php
  - app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php
  - app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php
  - app/Filament/Resources/WebhookCalls/WebhookCallResource.php
  - app/Filament/Widgets/ConnectionStatsWidget.php
  - app/Models/Connection.php
  - app/Models/Consumer.php
  - app/Models/User.php
  - app/Providers/AppServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Support/ProviderCredentialDescriptor.php
  - bootstrap/providers.php
  - config/hub-providers.php
  - database/migrations/2026_05_19_000001_add_audit_columns_to_webhook_calls_table.php
  - database/seeders/EmeqStaffSeeder.php
  - resources/views/filament/resources/consumers/pages/list-consumers.blade.php
  - resources/views/partners/index.blade.php
  - resources/views/partners/mollie/example.blade.php
  - routes/web.php
  - tests/Feature/Admin/AccountResourceTest.php
  - tests/Feature/Admin/AccountSubscriptionResourceTest.php
  - tests/Feature/Admin/AccountSubscriptionStateActionsTest.php
  - tests/Feature/Admin/CashierSubscriptionResourceTest.php
  - tests/Feature/Admin/ConnectionFingerprintTest.php
  - tests/Feature/Admin/ConnectionRevokeActionTest.php
  - tests/Feature/Admin/ConsumerTokenActionTest.php
  - tests/Feature/Admin/EmeqStaffSeederTest.php
findings:
  critical: 2
  warning: 6
  info: 4
  total: 12
status: issues_found
---

# Phase 9: Code Review Report

**Reviewed:** 2026-05-16
**Depth:** standard
**Files Reviewed:** 53
**Status:** issues_found

## Summary

Phase 9 levert een Filament v4 admin-paneel met 7 resources, RBAC via Spatie permission, een config-driven `ProviderCredentialDescriptor`-laag en een audit-kolommen-migratie op `webhook_calls`. De security-kritische invarianten (encryption-at-rest, `#[Hidden]`-schermen, manager-only state-flips, OAuth-gated revoke, no-secret-leak-tests, super-admin-gated UserResource) zijn correct geïmplementeerd en bewezen door 52 nieuwe feature-tests.

Twee defecten verdienen blockerend gewicht: (1) de dev-only `/admin/quick-login`-route gebruikt `! app()->isProduction()` als guard, wat in elke non-production-omgeving (inclusief `APP_ENV=staging`, preview-deploys op Laravel Cloud, of een misconfigured tenant-server) een complete authentication-bypass naar admin-paneel oplevert; en (2) de `WebhookCallResource`-laag rendert `consumer_id`-rijen volledig zonder tenant-restrictie maar weergeeft Consumer-slug via per-row `Consumer::find()` lookups — staff-gebruikers met alleen `view-webhooks`-permission zien dus cross-Consumer-data ondanks dat dit nergens als success-criterium is gevalideerd.

Daarnaast: zes warnings rond foot-guns en correctheid (laatste-super-admin downgrade-risk, exception-veld dubbel-gecodeerd in infolist, `dehydrateStateUsing` op leeg password-veld hasht een lege string als de form niet correct guard, `last_super_admin`-veld in audit ontbreekt, Issue-PAT modal accepteert `custom` zonder enige preset-validatie, en cross-Consumer-isolation is niet getest in `WebhookCallResource`). Plus vier info-items rond N+1, dode pattern-imports en code-style.

## Critical Issues

### CR-01: `/admin/quick-login`-route bypasst auth in alle non-production-omgevingen

**File:** `routes/web.php:23-33`

**Issue:**
De dev-only quick-login-route doet `Auth::login(User::role($role)->first())` en redirect naar `/admin`, gegate met `! app()->isProduction()`. `app()->isProduction()` retourneert `true` uitsluitend wanneer `APP_ENV === 'production'`. Iedere andere waarde — `local`, `testing`, `staging`, `preview`, `review`, of een typo zoals `Production` — opent een ongeauthenticeerde GET-only auth-bypass naar de **eerste** seeded super-admin van de DB.

Concreet risico:
1. Laravel Cloud preview-deploys gebruiken vaak `APP_ENV=staging` of een per-PR-omgeving — als de seeder ooit op zo'n omgeving draait met productie-achtige data, kan **iedereen op het internet** met de preview-URL als super-admin inloggen door simpelweg `/admin/quick-login` op te roepen. Geen CSRF nodig (GET), geen credential.
2. De route is via `Route::get(...)`, dus crawlers, bots en gerichte scans pikken hem op.
3. `User::role('super-admin')->first()` levert de bootstrap-super-admin op die via `EmeqStaffSeeder` met `EMEQ_STAFF_SEED_EMAIL` is gemaakt — exact de admin met meeste rechten.

De bestaande `PreventRequestForgery`-middleware in `AdminPanelProvider` mitigeert dit niet: de route leeft in `routes/web.php`, niet in het Filament-panel, en wordt automatisch gewrapped in de standaard `web`-middleware-group (session + CSRF voor POST, niet voor GET).

**Fix:**
Strikter guard plus rate-limit. Optie A (sterk):

```php
if (app()->environment('local')) {
    Route::get('/admin/quick-login/{role?}', function (string $role = 'super-admin') {
        // ... bestaande body
    })->middleware('throttle:5,1')->name('admin.quick-login');
}
```

Optie B (extra defensive — vereis ook een `.env`-feature-flag):

```php
if (app()->environment('local') && env('ENABLE_QUICK_LOGIN') === '1') {
    // ...
}
```

Documenteer expliciet in `.env.example` dat `ENABLE_QUICK_LOGIN` nooit op preview/staging gezet mag worden. Hetzelfde geldt voor `/dev/partners` (`routes/web.php:38-51`) — minder gevoelig (alleen view-render), maar dezelfde `isProduction()`-pattern; meeneem in dezelfde fix voor consistency.

**Bewijs / impact:**
- Laravel docs (`Application::isProduction()`): "Determine if the application is in the production environment" — retourneert alleen true bij `production`.
- `routes/web.php:23` gebruikt `! app()->isProduction()` als enige guard — bevestigt het probleem.
- `EmeqStaffSeeder` heeft géén `app()->isProduction()`-bescherming (SUMMARY-doc bevestigt dit als bewuste keuze "moet juist in productie 1× kunnen draaien voor bootstrap"), dus de seeded super-admin bestaat ook in preview/staging DBs.

Severity: BLOCKER — directe auth-bypass naar super-admin-account in elke niet-`production` deployment.

---

### CR-02: WebhookCallResource gating mist en `consumer_id`-lookup gebruikt geen relatie + filtering

**File:** `app/Filament/Resources/WebhookCalls/WebhookCallResource.php:24-60` + `app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php:42-46`

**Issue:**
`WebhookCallResource` heeft géén `canAccess()`-override of permission-check op basis van Spatie's `view-webhooks`-permission (welke wel in `EmeqStaffSeeder` aan beide rollen is toegekend). Het is alleen indirect ge-gate via `User::canAccessPanel()` — i.e. iedere staff-user kan alle webhook-rijen zien, ongeacht of de `view-webhooks` permission daadwerkelijk gemodelleerd is in de RBAC-controle. Dit ondermijnt het hele permission-model: D-05's `view-webhooks` permission staat in de seeder maar wordt nergens enforced. Hetzelfde geldt voor `view-account-subscriptions`, `view-billing`, `manage-consumers` en `manage-connections` — geen enkele Resource consulteert deze permissions.

Tweede deel-probleem in dezelfde laag: `WebhookCallsTable::configure()` rendert `consumer_id` via `Consumer::find($record->consumer_id)?->slug`. Dat doet een extra SELECT-query **per rij** in plaats van via Eloquent-relatie (Spatie's `WebhookCall`-model heeft géén `consumer()` belongs-to). Naast N+1 (out-of-scope-perf), is dit functioneel verkeerd: er is geen tenant-restrictie op queries, dus elke staff-user ziet alle inkomende/uitgaande webhooks van **alle Consumers**, inclusief Consumer-IDs in cleartext. Voor een v0.2-instantie met intern-only-staff is dat aanvaardbaar, maar het is een afwijking van het document-geclaimde "cross-Consumer-isolatie via gefilterde queries" (CONTEXT.md success-criterium 7).

**Fix:**
1. Voeg `canAccess()` toe op alle resources met de juiste Spatie permission:

```php
// In WebhookCallResource (en variant per resource):
public static function canAccess(): bool
{
    return auth()->user()?->can('view-webhooks') ?? false;
}

public static function shouldRegisterNavigation(): bool
{
    return static::canAccess();
}
```

Per resource: `manage-consumers` voor ConsumerResource (`canCreate/canEdit/canDelete` ook achter dit hangen), `manage-connections` voor ConnectionResource (revoke-action), `view-webhooks` voor WebhookCallResource, `view-account-subscriptions` voor AccountSubscriptionResource (pause/resume/cancel achter `manage-subscriptions`-permission als die nog moet komen), `view-billing` voor CashierSubscriptionResource.

2. Voeg een `consumer()`-relatie toe op een Hub-eigen WebhookCall-subclass óf wijzig de migratie/seeder zodat de tabel een `slug`-column krijgt (denormalisatie) zodat per-row find verdwijnt. Korte-termijn fix:

```php
TextColumn::make('consumer.slug')
    ->label('Consumer')
    ->placeholder('—'),
```

vereist dat Hub een `App\Models\WebhookCall` extends Spatie's model met een `consumer()` belongs-to. Eager-loaden via `->modifyQueryUsing(fn ($q) => $q->with('consumer'))` op Resource-level.

3. Voeg `tests/Feature/Admin/WebhookCallResourceTest::test_cross_consumer_isolation_*` toe — equivalent van `ListAccountSubscriptionsTest::test_list_with_other_consumer_account_external_id_returns_empty_list` — om SC-7 te valideren.

**Bewijs / impact:**
- `WebhookCallResource.php` heeft géén `canAccess()`-method (zoekend op `canAccess` in alle resources behalve `UserResource`: 0 hits).
- `EmeqStaffSeeder.php:26-32` definieert vijf permissions die nergens worden gecontroleerd → permission-model is dead code voor v0.2.
- `09-CONTEXT.md` success-criterium 7: "WebhookCallResource toont direction-/provider-/status-filters (vereist 09-01-migratie) en cross-Consumer-isolatie via gefilterde queries" — niet bewezen door een test.
- `09-11-ACCEPTANCE.md` claimt HUB-04 SC-1..SC-10 bewezen, maar SC-7 mist een tenant-scope-assertie in `WebhookCallResourceTest`.

Severity: BLOCKER — D-05 permission-model is geen no-op-stub maar een gepubliceerde authorization-claim. Geen enforcement = false security claim én SC-7 acceptance gat. (De auth-bypass via "alle staff ziet alle Consumers" is minder ernstig dan CR-01 omdat staff sowieso al panel-toegang heeft; vandaar BLOCKER voor het missende permission-enforcement, niet voor de horizontale data-exposure zelf.)

---

## Warnings

### WR-01: UserResource laat super-admin zichzelf naar `staff` downgrade — bricks panel

**File:** `app/Filament/Resources/Users/Tables/UsersTable.php:52-72`

**Issue:**
Het `assignRole` table-action roept `$record->syncRoles([$data['role']])` aan op iedere User, inclusief de huidige ingelogde super-admin. Als de enige super-admin in de DB zichzelf van rol `super-admin` naar `staff` switcht, verliest die meteen `manage-staff`-permission, kan UserResource niet meer bereiken (gate fail), en er is geen herstelpad behalve een nieuwe `EmeqStaffSeeder`-run met env-vars of een directe DB-mutatie. Geen confirmation, geen check-of-laatste, geen self-protection.

Vergelijkbaar risico bij `DeleteAction` op `EditUser` — een super-admin kan zichzelf of de laatste super-admin verwijderen.

**Fix:**
Action-level guard die (1) niet toestaat dat current_user zichzelf downgrade, en (2) niet toestaat dat de laatste super-admin gedegradeerd wordt:

```php
->action(function (User $record, array $data): void {
    if ($record->id === auth()->id() && $data['role'] !== 'super-admin') {
        Notification::make()->title('Je kunt jezelf niet downgraden')->danger()->send();
        return;
    }

    if ($record->hasRole('super-admin') && $data['role'] !== 'super-admin'
        && User::role('super-admin')->where('id', '!=', $record->id)->count() === 0) {
        Notification::make()->title('Kan laatste super-admin niet downgraden')->danger()->send();
        return;
    }

    $record->syncRoles([$data['role']]);
    // ...
});
```

Idem voor `EditUser`-DeleteAction (`before` callback met dezelfde laatste-super-admin-check).

**Bewijs / impact:**
Geen unit-test dekt deze flow. Voor v0.2-internal-use waarschijnlijk operationeel oplosbaar, maar voor productie-rollout naar derde-partijen is dit een eenmalige fout-onomkeerbaar.

---

### WR-02: WebhookCallInfolist dubbel-encodeert `exception`-veld

**File:** `app/Filament/Resources/WebhookCalls/Schemas/WebhookCallInfolist.php:42-46`

**Issue:**
```php
TextEntry::make('exception')
    ->state(fn ($record): string => $record->exception
        ? json_encode($record->exception, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : '—')
```

Spatie's `webhook_calls.exception`-kolom is `text` (geen JSON, geen array — zie `database/migrations/2026_05_13_223628_create_webhook_calls_table.php`). `json_encode("Stack trace …")` produceert `"\"Stack trace …\""`: een string-letteral met escaped quotes en escaped newlines, niet pretty-printed multiline JSON. Voor admins die uitzondering willen debuggen wordt het onleesbaar.

**Fix:**
```php
TextEntry::make('exception')
    ->label('Exception')
    ->placeholder('—')
    ->columnSpanFull(),
```

Filament's default TextEntry rendert `$record->exception` direct als HTML-geëscapete string. Voor multiline-display gebruik `->markdown()` of `->html(false)` (default).

**Bewijs / impact:**
Render-fout met hoge debug-frictie. Codequalité.

---

### WR-03: `assignRole`-action accepteert geen `null`/onbekende rol — geen validatie tegen Spatie-rol-bestaan

**File:** `app/Filament/Resources/Users/Tables/UsersTable.php:52-72`

**Issue:**
`Select::make('role')->options(['super-admin' => ..., 'staff' => ...])` is hardcoded. Als iemand via DevTools de form-state aanpast met `role: 'admin'` of `role: ''`, dan roept `syncRoles(['admin'])` Spatie's `RoleDoesNotExist`-exception aan (of stille no-op afhankelijk van versie). De action heeft géén try/catch — een onverwachte rol crasht de actie met 500. Verder: Filament's frontend-validatie is **niet** server-side authoritative voor select-options; alleen `->in()`-validator is dat.

**Fix:**
```php
Select::make('role')
    ->label('Rol')
    ->options(['super-admin' => 'Super admin', 'staff' => 'Staff'])
    ->in(['super-admin', 'staff'])
    ->required(),
```

Plus een try/catch rond `syncRoles` met een gebruiker-vriendelijke notification.

**Bewijs / impact:**
Slechts foot-gun + 500-mogelijkheid. Vooral handig om met CR-02 mee te bundelen (alle resources missen ability/permission-validatie aan server-side).

---

### WR-04: `EmeqStaffSeeder` upgrade van bestaande user is silent — wachtwoord wordt niet hersteld

**File:** `database/seeders/EmeqStaffSeeder.php:60-64`

**Issue:**
```php
$user = User::firstOrCreate(
    ['email' => $email],
    ['name' => 'Emeq Super Admin', 'password' => Hash::make($password)],
);
$user->assignRole($superAdmin);
```

Als er al een User bestaat met `$email`, wordt het `password`-veld **niet** geüpdate. De productie-bootstrap-flow kan dus onbedoeld een oud wachtwoord houden ondanks dat `EMEQ_STAFF_SEED_PASSWORD` met een nieuwe waarde gerund wordt — een operator die "ik wil even mijn wachtwoord resetten" doet, denkt dat het werkt maar het wachtwoord blijft de oude `Hash::make(...)`. Tegelijk: als de password env-var iets als `EMEQ_STAFF_SEED_PASSWORD=secret` is met de werkelijke shell-leesbare waarde, dan zit deze in command-history en in de seed-process-environment.

**Fix:**
Twee opties: (1) explicit password-reset (`updateOrCreate` of `firstOrCreate` + `forceFill(['password' => Hash::make($password)])->save()`), of (2) hard-fail met expliciete "user al exists — gebruik artisan tinker voor password-reset" foutmelding.

Aanvullend: `env()` lees ik live (niet `config()`), wat in productie met config-cache nog steeds werkt voor seeders maar fragile is. Documentatie in `.env.example` of `.docs/deployment.md` zou helpen.

**Bewijs / impact:**
Silent operatie-fout met security-overlap (oude wachtwoord blijft geldig).

---

### WR-05: UserForm `dehydrateStateUsing(Hash::make)` zonder filled-guard zou lege strings hashen — werkt nu door volgordeafhankelijkheid

**File:** `app/Filament/Resources/Users/Schemas/UserForm.php:36-42`

**Issue:**
```php
TextInput::make('password')
    ->password()
    ->revealable()
    ->maxLength(255)
    ->dehydrateStateUsing(fn ($state) => Hash::make((string) $state))
    ->dehydrated(fn ($state) => filled($state))
    ->required(fn (string $operation): bool => $operation === 'create'),
```

De `dehydrateStateUsing` callback hashet onvoorwaardelijk; `dehydrated(filled)` voorkomt dat het lege resultaat naar de model gaat. Werkt in Filament v4 omdat `dehydrated` na `dehydrateStateUsing` wordt gecheckt. Maar dit is volgorde-afhankelijk en bracht subtle bugs in vorige Filament-versies. De idiomatische pattern is `dehydrated(fn ($state) => filled($state))` met `dehydrateStateUsing(fn ($state) => Hash::make((string) $state))` in **die volgorde** OF gewoon:

```php
->mutateDehydratedStateUsing(fn ($state) => filled($state) ? Hash::make((string) $state) : null)
->dehydrated(fn ($state) => filled($state))
```

(beide guards expliciet). De test `UserResourceTest::test_super_admin_can_create_user_via_resource` dekt het happy path; geen test voor "edit zonder password" — er is dus geen regressie-vangnet als de Filament-volgorde-semantiek ooit verschuift.

**Fix:**
Voeg een edit-test toe die bewijst dat een lege password-field op edit de bestaande hash bewaart:

```php
public function test_edit_user_without_password_keeps_existing_hash(): void
{
    $user = User::factory()->create(['password' => Hash::make('original')]);
    // ... edit-form met password = ''
    $this->assertTrue(Hash::check('original', $user->fresh()->password));
}
```

**Bewijs / impact:**
Theoretisch — werkt nu, maar geen testdekking. Lichte coding-style-warning + missing test.

---

### WR-06: Plain-token in Livewire-component-state — Livewire snapshot eindigt in HTTP-response-payload

**File:** `app/Filament/Resources/Consumers/Pages/ListConsumers.php:14` + `resources/views/filament/resources/consumers/pages/list-consumers.blade.php:25-46`

**Issue:**
`public ?array $lastIssuedPat = null;` is een Livewire-property → wordt geserialiseerd in `wire:snapshot` op de root component-tag én rendered in Alpine `x-data` via `@js($this->lastIssuedPat['token'])`. Het token zit dus twee keer in de HTML-response (signed Livewire snapshot + Alpine state).

Praktische risico's:
1. Browser-extensies, screen-recorders, proxy-loggers en monitoring-agents die HTTP-responses sample zien het token in cleartext.
2. Bij elke Livewire-roundtrip (bv. een filter-change op de table terwijl `lastIssuedPat` nog niet dismissed is) wordt het token opnieuw in beide payloads gestuurd.
3. `dismissIssuedPat()` is een gewone publieke Livewire-method — geen authorize-check. Een ander tabblad/sessie zou theoretisch met een gestolen sessie-cookie de dismiss-method kunnen aanroepen, maar zonder nieuwe lastIssuedPat te zetten is dat geen verdere exposure.

Conceptueel acceptabel (de admin die de token uitgaf zit er sowieso bij), maar nog veiliger: render alleen via Filament `Notification` met copyable-block, en behoud het token in een **transient** server-side cache (e.g. `Cache::put("pat-flash:{$adminId}:{$consumerId}", $token, now()->addSeconds(60))`) die de blade via `Cache::pull(...)` (one-shot) leest. Zo eindigt het token niet in Livewire-component-state.

**Fix:**
Alternative idiomatisch pattern voor v0.3:

```php
// In issuePatAction action():
Cache::put("pat-flash:{$livewire->getId()}", $result->plainTextToken, 60);
$livewire->dispatch('pat-issued', name: $data['name']);

// Custom view leest via Cache::pull (one-shot, server-side)
```

Geen blocker voor v0.2 — admin-paneel is interne tool met geadviseerde browser-hygiene, maar documenteren in de ADR + open een backlog-item.

**Bewijs / impact:**
Livewire's snapshot-encryption beschermt tegen client-side mutatie maar NIET tegen lees-toegang. Iedereen die `view-source:` op de pagina doet ziet het token tot het gedismisst wordt.

---

## Info

### IN-01: WebhookCallsTable en WebhookCallInfolist hebben N+1 op `Consumer::find()` per rij

**File:** `app/Filament/Resources/WebhookCalls/Tables/WebhookCallsTable.php:42-46`

**Issue:**
Per-row `Consumer::find($record->consumer_id)?->slug` triggert N+1 (één extra query per webhook-rij). Spatie's `WebhookCall`-model heeft géén Hub-eigen `consumer()` belongs-to dus eager-load via `->with('consumer')` op de Resource-laag werkt niet zonder een eigen model-subclass.

Performance is out-of-scope voor v1 review, maar dit hangt samen met CR-02 (oplossen via Hub `App\Models\WebhookCall extends Spatie's class + ->belongsTo()` lost beide op).

**Fix:**
Zie CR-02. Tegelijk fixen.

---

### IN-02: `AccountSubscriptionResource::cancelAction` mist een `try` rond Mollie-side cancel

**File:** `app/Filament/Resources/AccountSubscriptions/AccountSubscriptionResource.php:229-264`

**Issue:**
`AccountSubscriptionManager::cancel()` (`app/Billing/Account/AccountSubscriptionManager.php:96-108`) roept `Mollie::client()->subscriptions->cancelForId(...)` aan als `mollie_subscription_id` niet null is. Bij netwerk-timeout, Mollie 5xx of expired access-token gooit Mollie een `ApiException` — vangbaar door Filament's `Throwable`-catch, maar de current notification toont `$e->getMessage()` welke gevoelige Mollie-API-response-data kan bevatten (HTTP-body, request-URL met query-string, etc.). Beter: log de exception met fingerprint en toon een algemene "Mollie-cancel mislukt — zie logs" notification.

**Fix:**
```php
} catch (Throwable $e) {
    report($e);
    Notification::make()
        ->title('Cancel-actie mislukt')
        ->body('Zie Sentry/logs voor details — fingerprint: '.substr(hash('sha256', $e->getMessage()), 0, 12))
        ->danger()
        ->send();
}
```

**Bewijs / impact:**
Code-style — geen acute leak omdat Mollie's exception-messages doorgaans geen secrets bevatten. Wel best-practice voor consistency met `.ai/rules/global.md` ("Raw secrets verschijnen nooit in logs, exception-messages, of error responses").

---

### IN-03: `AdminPanelProvider` heeft impliciete `default()` panel-status — interactie met test-omgeving

**File:** `app/Providers/Filament/AdminPanelProvider.php:30`

**Issue:**
`->default()` markeert admin als Filament's default-panel. Combineerd met `->path('admin')` zorgt dit ervoor dat `/admin/login` route registratie zo werkt als de tests verwachten. Maar `->default()` heeft als bijwerking dat `Filament::auth()` (zonder panel-id) deze guard pakt. Voor toekomstige consumer-portal-panels (v1.0+) is dit `->default()` een footgun — eerste registered = default. Documenteer dit, of refactor naar expliciete panel-pickup in de roadmap-fase.

**Fix:**
Comment toevoegen, en in een toekomstige fase die een tweede panel introduceert `->default()` verplaatsen. Geen acute actie.

---

### IN-04: `ProviderCredentialDescriptor::for()` ontbreekt PHPDoc op `tryFor()`/recoverable-failure-pattern

**File:** `app/Support/ProviderCredentialDescriptor.php:36-60`

**Issue:**
De `for()`-static throwt op onbekende provider, maar `Connection::fingerprint()` (`app/Models/Connection.php:48-65`) doet `try { for(...) } catch (InvalidArgumentException) { return null; }` — wat een ad-hoc "try variant" inline is. Een expliciete `tryFor(string $provider): ?self` op de descriptor-class zou expressiever zijn en zou de try/catch in `Connection`-model overbodig maken. Plus: de `array<string, mixed>` typing in `config()`-lookup laat `encrypted_fields` zónder type-check door, wat een PHP-fatal kan worden als `config/hub-providers.php` ooit geknoeid wordt. Defensive-style suggestie: `is_array($cfg['encrypted_fields'] ?? null) ? $cfg['encrypted_fields'] : []` (al ondervangen door PHPDoc, niet door runtime).

**Fix:**
```php
public static function tryFor(string $provider): ?self
{
    try {
        return self::for($provider);
    } catch (InvalidArgumentException) {
        return null;
    }
}
```

Niet acute — `try/catch` in `Connection::fingerprint()` werkt.

---

## Samenvatting

- **Critical:** 2 (CR-01 quick-login auth-bypass in non-production, CR-02 missende permission-enforcement + cross-Consumer-isolation-gap in WebhookCallResource)
- **Warning:** 6 (last-super-admin downgrade, exception-veld dubbel-encoded, role-validatie, seeder password niet-resetten, password-form testgat, plain-token in Livewire-state)
- **Info:** 4 (N+1 webhook-consumer-lookup, exception-message leak in Mollie-cancel, AdminPanelProvider `default()`-footgun, descriptor `tryFor()`-helper)
- **Total:** 12

### Top-3 prioriteit voor `/gsd:code-review --fix`

1. **CR-01** — `routes/web.php:23-33` quick-login guard verstrengen naar `app()->environment('local')` + rate-limit + env-flag, plus identiek voor `/dev/partners` (regel 38-51). Geen extra tests nodig — bestaande test-suite blokkeert geen non-`local`-omgevingen.
2. **CR-02** — `canAccess()`/`shouldRegisterNavigation()`-checks op alle 6 resources die D-05 permissions claimen (`view-webhooks`, `view-account-subscriptions`, `view-billing`, `manage-consumers`, `manage-connections`) + nieuwe feature-test `WebhookCallResourceTest::test_cross_consumer_isolation_*` om SC-7 daadwerkelijk te bewijzen. Vereist tegelijk een Hub `App\Models\WebhookCall` extends Spatie's class met `consumer()`-belongs-to om N+1 weg te halen.
3. **WR-01** — UserResource `assignRole`-action + `EditUser`-DeleteAction guards tegen laatste-super-admin-downgrade/delete. Snel te fixen + 2 nieuwe regression-tests.

WR-02 t/m WR-06 + alle IN-items zijn voor follow-up commits — niet ship-blockerend voor HUB-04 maar wel onderdeel van de "Phase 9 done"-kwaliteitsclaim.

---

_Reviewed: 2026-05-16_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
