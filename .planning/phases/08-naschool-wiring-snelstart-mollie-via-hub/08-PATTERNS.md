# Phase 8: Naschool wiring (Snelstart + Mollie-via-Hub) — Pattern Map

**Mapped:** 2026-05-17
**Files analyzed:** 13 (4 new + 9 modified)
**Analogs found:** 13 / 13

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Services/ConsumerOnboarding.php` (NEW) | Service | CRUD (atomic transaction) | `app/Console/Commands/HubConsumerCreate.php` (procedural Consumer+token create) | role-match — geen bestaande multi-model service |
| `app/Filament/Pages/OnboardConsumer.php` (NEW) | Filament Page | request-response (Livewire form-submit) | `app/Filament/Resources/Consumers/Pages/ListConsumers.php` (Filament Page subclass + Cache-flash) | role-match — geen bestaande standalone Page |
| `resources/views/filament/pages/onboard-consumer.blade.php` (NEW) | Blade (Filament page-view) | render | `resources/views/filament/resources/consumers/pages/list-consumers.blade.php` (Cache::pull + filament-panels::page) | exact |
| `app/Filament/Actions/StartOAuthFlowAction.php` (NEW) | Filament Action class | request-response (redirect-away) | Revoke-action in `ConnectionResource::table()` regels 150-179 (Action::make + visible + action callback met OAuthFlowRegistry) | exact role + same OAuthFlowRegistry-touchpoint |
| `app/Services/PartnerStatus.php` (NEW) | Service | read-only aggregate | `app/Filament/Widgets/ConnectionStatsWidget.php::getStats()` (descriptor-driven per-provider count) | role-match — beide leveren per-provider Connection-aggregaten |
| `app/Filament/Resources/Consumers/Schemas/ConsumerInfolist.php` (NEW — directory ontbreekt) | Filament Infolist Schema | render | `app/Filament/Resources/Accounts/Schemas/AccountInfolist.php` (configure-method pattern) | exact |
| `app/Filament/Resources/Accounts/Schemas/AccountInfolist.php` (EXTEND) | Filament Infolist Schema | render | self (toevoegen Section bovenaan existing `components([...])`) | self-extend |
| `app/Filament/Resources/Consumers/ConsumerResource.php` (EXTEND) | Filament Resource | request-response | self — bestaande Issue-PAT-action regels 162-205 is template voor nieuwe wizard-launch button | self-extend |
| `app/Filament/Resources/Accounts/AccountResource.php` (EXTEND) | Filament Resource | request-response | self — mount `StartOAuthFlowAction` via record/page actions zoals `ConnectionResource` revoke (regel 148-180) | role-match |
| `app/Filament/Resources/Connections/ConnectionResource.php` (EXTEND) | Filament Resource | request-response | self — pas zelfde `Action::make + visible + action` patroon toe als revoke (regels 150-179) | self-extend |
| `app/Providers/Filament/AdminPanelProvider.php` (EXTEND) | Filament panel provider | bootstrap | self — bestaande `->pages([Dashboard::class])` + render-hook (regel 43-86) | self-extend |
| `app/Console/Commands/HubConsumerCreate.php` (REFACTOR) | Artisan Command | CLI request-response | self — bewaar signature; vervang `Consumer::create + createToken` (regel 41-50) door `ConsumerOnboarding::onboard()`-aanroep | self-refactor |
| `resources/views/partners/index.blade.php` (EXTEND) | Blade view | render | self — bestaande `<style>` + `@foreach`-structuur uitbreiden | self-extend |
| `resources/views/partners/mollie/example.blade.php` (EXTEND) | Blade view | render | self — bestaande inline-HTML uitbreiden met domeinmodel-partial + status-widget | self-extend |
| `resources/views/partners/snelstart/example.blade.php` (EXTEND) | Blade view | render | self — bestaande inline-HTML uitbreiden | self-extend |
| `resources/views/partners/partials/_domeinmodel.blade.php` (NEW) | Blade partial | render | `resources/views/partners/index.blade.php` (inline `<style>` pattern is single-bestand-style — partial moet utility-only Tailwind v4 zijn per UI-SPEC §Component Inventory) | role-only — geen bestaande partials in `partners/` |
| `resources/views/partners/partials/_status-widget.blade.php` (NEW) | Blade partial | render | n/a — eerste Blade-status-widget in repo | n/a (zie "No analog found" sectie) |
| `routes/web.php` (touch — optionele service-injection) | route-callback | request-response | self — bestaande `/dev/partners`-callbacks regel 39-55 | self-extend |

> NB. CONTEXT.md noemt `app/Support/PartnerStatus.php`; UI-SPEC §Component Inventory plaatst hem in `app/Services/PartnerStatus.php`. Volg UI-SPEC (canonical voor file-paths): `app/Services/PartnerStatus.php`. Planner kan deze afwijking aanstippen, geen actie-blocker.

> NB. UI-SPEC noemt `app/Http/Controllers/Dev/PartnersController.php` niet — bestaat ook niet. De `/dev/partners`-routes zijn closures in `routes/web.php` regel 39-55. Planner moet kiezen: closures laten of extraheren naar controller. Aanbeveling: closures houden (chirurgisch — engineering rule), gewoon `app(PartnerStatus::class)` aanroepen.

---

## Pattern Assignments

### `app/Services/ConsumerOnboarding.php` (NEW — Service, atomic CRUD)

**Analog:** `app/Console/Commands/HubConsumerCreate.php` (procedural pattern dat geconsolideerd moet worden)

**Atomic-transaction pattern** (er bestaat nog *geen* `DB::transaction`-usage in `app/` — `grep -r "DB::transaction" app/` = 0 hits). Planner kiest het standaard-pattern; aanbevolen:

```php
use Illuminate\Support\Facades\DB;

return DB::transaction(function () use ($data): array {
    $consumer = Consumer::create([
        'name' => $data['name'],
        'slug' => $data['slug'],
        'webhook_callback_url' => $data['webhook_callback_url'] ?? null,
        'webhook_callback_secret' => $data['webhook_callback_secret'] ?? null,
    ]);
    $account = $consumer->accounts()->create([
        'external_id' => $data['external_id'],
        'display_name' => $data['display_name'],
    ]);
    // optional Connection + PAT...
    $token = $consumer->createToken($data['token_name'], $data['abilities']);
    return [
        'consumer' => $consumer,
        'account' => $account,
        'plain_token' => $token->plainTextToken,
    ];
});
```

**Re-use pattern uit `HubConsumerCreate::handle()` regel 41-55** (de procedurele basis die opgenomen moet worden):

```php
try {
    $consumer = Consumer::create(['slug' => $slug, 'name' => $name]);
} catch (QueryException $e) {
    $this->error("Aanmaken Consumer mislukt: {$e->getMessage()}");
    return self::FAILURE;
}

$tokenName = (string) $this->option('token-name');
$token = $consumer->createToken($tokenName, $abilities);
```

**Service-class shape — final readonly + constructor-DI** (analog: `app/Services/Snelstart/HubSnelstartCredentialResolver.php` regel 16-29):

```php
final readonly class HubSnelstartCredentialResolver implements SnelstartCredentialResolver
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function resolve(): SnelstartCredentials
    {
        return new SnelstartCredentials(
            clientKey: (string) $this->connection->client_key,
            ...
        );
    }
}
```

Wijk hier af: `ConsumerOnboarding` neemt geen ctor-args (stateless service), maar volg `final` + named-args + ::class-resolution.

**Encrypted-cast invariant** — `Consumer::$casts` regel 23-28 + `Connection::$casts` regel 69-82 hebben encryption ingebakken. Service hoeft alleen plain waardes door te zetten — encryption gebeurt automatisch bij `Model::create()`. Plain `webhook_callback_secret` retourneer je via service-return-value voor eenmalige Notification (zie no-secret-leak invariant UI-SPEC).

---

### `app/Filament/Pages/OnboardConsumer.php` (NEW — Filament Page met Wizard)

**Analog:** `app/Filament/Resources/Consumers/Pages/ListConsumers.php` (Filament Page + Cache-flash voor token-display)

**Page-class skeleton + Cache-flash pattern** (ListConsumers.php regel 1-27):

```php
namespace App\Filament\Resources\Consumers\Pages;

use App\Filament\Resources\Consumers\ConsumerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConsumers extends ListRecords
{
    protected static string $resource = ConsumerResource::class;

    protected string $view = 'filament.resources.consumers.pages.list-consumers';

    public function getSubheading(): ?string
    {
        return 'Een Consumer is een app die de Hub gebruikt — één van Emeq\'s eigen SaaS-apps (Naschool, …) ...';
    }
}
```

Voor een standalone Filament Page erf van `Filament\Pages\Page` (geen ListRecords). Pattern hieronder bestaat nog niet in repo — eerste Page-subclass:

```php
namespace App\Filament\Pages;

use Filament\Pages\Page;

class OnboardConsumer extends Page
{
    protected static string $view = 'filament.pages.onboard-consumer';
    protected static ?string $navigationGroup = 'Tenants';
    protected static ?string $title = 'Nieuwe Consumer onboarden';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-consumers') ?? false;
    }
}
```

**canAccess-gate pattern — copy-exact uit `ConsumerResource::canAccess()` regel 87-90:**

```php
public static function canAccess(): bool
{
    return auth()->user()?->can('manage-consumers') ?? false;
}
```

**Wizard-component pattern** (uit `vendor/filament/schemas/docs/05-wizards.md` regel 12-30):

```php
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;

Wizard::make([
    Step::make('Consumer')->schema([ /* TextInput name, slug, webhook_callback_url */ ]),
    Step::make('Eerste Account')->schema([ /* external_id, display_name */ ]),
    Step::make('Eerste Connection')->schema([ /* provider-radio + conditional sub-form */ ]),
    Step::make('PAT uitgeven')->schema([ /* preset Radio + custom CheckboxList */ ]),
])->submitAction(new HtmlString('<button type="submit">Token uitgeven</button>'))
```

**Provider-conditional sub-form (Step 3)** — hergebruik UI-conditional pattern uit `ConsumerResource::issuePatAction()` regel 180-184:

```php
CheckboxList::make('abilities')
    ->options(self::customAbilitiesOptions())
    ->visible(fn (Get $get): bool => $get('preset') === 'custom'),
```

Toegepast op Step 3:

```php
Radio::make('provider')
    ->options(['mollie' => 'Mollie', 'snelstart' => 'Snelstart'])
    ->live(),
Group::make([
    TextInput::make('client_key')->required(),
    TextInput::make('subscription_key')->required(),
    TextInput::make('subscription_id')->required(),
])->visible(fn (Get $get): bool => $get('provider') === 'snelstart'),
Action::make('startMollieOAuth')
    ->visible(fn (Get $get): bool => $get('provider') === 'mollie'),
```

**Submit-handler — Cache-flash + Notification** (ConsumerResource.php regel 186-204, copy-exact):

```php
$result = $record->createToken($data['name'], $abilities);

$livewireId = $livewire->getId();
Cache::put("pat-flash:{$livewireId}", $result->plainTextToken, now()->addSeconds(60));
Cache::put("pat-flash-name:{$livewireId}", $data['name'], now()->addSeconds(60));

Notification::make()
    ->title('PAT uitgegeven — token verschijnt eenmalig bovenaan de listing')
    ->success()
    ->send();
```

Voor wizard-success: roep `app(ConsumerOnboarding::class)->onboard($data)` aan, flash plain token + plain `webhook_callback_secret` (als auto-generated), redirect via `redirect()->to(ConsumerResource::getUrl())` zodat de bestaande list-consumers Cache-pull-banner triggert.

---

### `resources/views/filament/pages/onboard-consumer.blade.php` (NEW)

**Analog:** `resources/views/filament/resources/consumers/pages/list-consumers.blade.php` (copy-exact pattern)

**Cache::pull-pattern + filament-panels::page wrapper** (regel 7-58 van list-consumers blade — zie `Bash` output hierboven). Kernblok om over te nemen voor zowel PAT-token als webhook-secret display:

```blade
@php
    $issuedToken = \Illuminate\Support\Facades\Cache::pull('pat-flash:'.$this->getId());
    $issuedName = \Illuminate\Support\Facades\Cache::pull('pat-flash-name:'.$this->getId());
@endphp
<x-filament-panels::page>
    @if ($issuedToken !== null)
        <x-filament::section icon="heroicon-o-key" icon-color="warning">
            <x-slot name="heading">PAT uitgegeven — {{ $issuedName }}</x-slot>
            <x-slot name="description">Eenmalig zichtbaar — kopieer dit token nu...</x-slot>

            <div x-data="{ copied: false, copy() { ... } }" class="space-y-3">
                <code x-ref="tokenCode" class="...">{{ $issuedToken }}</code>
                <x-filament::button color="warning" icon="heroicon-o-clipboard-document" x-on:click="copy()">
                    <span x-show="!copied">Kopieer</span>
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{ $this->content }}
</x-filament-panels::page>
```

> Wizard zit normaliter in een Filament `Form` als content of als `$this->form` rendering. Voor een Page met form-state: `{{ $this->form }}` of expliciet `{{ $this->wizardForm }}` (Filament-doc-pattern).

---

### `app/Filament/Actions/StartOAuthFlowAction.php` (NEW — Filament Action class)

**Analog:** Revoke-action in `ConnectionResource::table()` regel 150-179 (exact: `Action::make + visible + action`-callback met `OAuthFlowRegistry`)

**Action-class shape — static factory pattern** (nog geen bestaande shared Action-class, dus de pattern is de inline-action uit ConnectionResource regel 150-179):

```php
Action::make('revoke')
    ->label('Revoke')
    ->icon(Heroicon::OutlinedNoSymbol)
    ->color('danger')
    ->requiresConfirmation()
    ->modalHeading('Connection intrekken bij provider')
    ->visible(function (Connection $record): bool {
        if ($record->revoked_at !== null) {
            return false;
        }
        try {
            $descriptor = ProviderCredentialDescriptor::for($record->provider);
        } catch (\InvalidArgumentException) {
            return false;
        }
        return $descriptor->oauthFlowKey !== null;
    })
    ->action(function (Connection $record): void {
        app(OAuthFlowRegistry::class)
            ->for($record->provider)
            ->revoke($record);

        Notification::make()->title('Connection ingetrokken')->success()->send();
    }),
```

**Hergebruik dit voor StartOAuthFlowAction**:

```php
namespace App\Filament\Actions;

use App\Models\Account;
use App\Models\Connection;
use App\OAuth\OAuthFlowRegistry;
use App\Support\ProviderCredentialDescriptor;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class StartOAuthFlowAction
{
    public static function forAccount(): Action
    {
        return Action::make('startOAuthFlow')
            ->label('Koppel met provider…')
            ->icon(Heroicon::OutlinedLink)
            ->modalHeading('Provider kiezen')
            ->schema([
                Select::make('provider')
                    ->options(self::oauthCapableProviders())
                    ->required(),
            ])
            ->action(function (Account $record, array $data) {
                return self::dispatch($record, $data['provider']);
            });
    }

    public static function forConnection(): Action
    {
        return Action::make('startOAuthFlow')
            ->label('Start OAuth-koppeling')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (Connection $record): bool =>
                $record->provider === 'mollie'
                && $record->access_token === null
                && $record->revoked_at === null
            )
            ->action(function (Connection $record) {
                return self::dispatch($record->account, $record->provider, $record);
            });
    }
    // ...
}
```

**Init-flow re-use pattern — copy van `InitController::__invoke()` regel 22-51**:

```php
$state = Str::random(48);

$connection = Connection::create([
    'account_id' => $account->id,
    'provider' => 'mollie',
    'status' => 'pending',
    'oauth_state' => $state,
    'oauth_state_expires_at' => now()->addMinutes(30),
]);

$scopes = config('services.mollie.connect.scopes');
$redirectUrl = $this->registry->for('mollie')->getAuthorizationUrl($account, $scopes, $state);
```

**Livewire redirect-away** — Filament action `action()` callback retourneert `redirect()->away($redirectUrl)` (Livewire-conventie, niet de bestaande revoke-action's `void`-shape).

**Permission-gate** — niet inline doen; Filament-action `visible()` op de calling Resource roept canAccess + `can('manage-connections')` (zie `ConnectionResource::canAccess()` regel 35-38).

---

### `app/Services/PartnerStatus.php` (NEW — Read-only aggregate)

**Analog:** `app/Filament/Widgets/ConnectionStatsWidget.php::getStats()` regel 24-41

**Descriptor-driven per-provider query** (ConnectionStatsWidget.php regel 28-39 — copy-pattern):

```php
foreach (ProviderCredentialDescriptor::all() as $descriptor) {
    $base = Connection::query()->where('provider', $descriptor->key);
    $active = (clone $base)->whereNull('revoked_at')->count();
    $revoked = (clone $base)->whereNotNull('revoked_at')->count();
    $total = $active + $revoked;

    $stats[] = Stat::make(ucfirst($descriptor->key), (string) $total)
        ->description($active.' actief · '.$revoked.' revoked')
        ->color($revoked > 0 ? 'warning' : 'success');
}
```

**Per-Account-status query** (UI-SPEC §Interaction Contracts S3 regel 261):

```php
namespace App\Services;

use App\Models\Account;

final class PartnerStatus
{
    public function forProvider(string $provider): \Illuminate\Support\Collection
    {
        return Account::with(['connections' => fn ($q) => $q->where('provider', $provider)])
            ->get()
            ->map(fn (Account $a) => [
                'account' => $a,
                'connection' => $a->connections->first(),
                'status' => $this->resolveStatus($a->connections->first()),
            ]);
    }

    private function resolveStatus(?Connection $c): string
    {
        if ($c === null) return 'none';
        if ($c->revoked_at !== null) return 'revoked';
        if ($c->access_token === null && $c->client_key === null) return 'pending';
        return 'connected';
    }
}
```

**Status-state labels** uit UI-SPEC §Color "Status-widget semantic palette" regel 116-124 — gebruik Heroicon-keys + Tailwind tokens letterlijk.

---

### `app/Filament/Resources/Consumers/Schemas/ConsumerInfolist.php` (NEW)

**Analog:** `app/Filament/Resources/Accounts/Schemas/AccountInfolist.php` (regel 1-32, exact pattern)

**Copy-exact schema-class skeleton**:

```php
declare(strict_types=1);

namespace App\Filament\Resources\Consumers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ConsumerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Wat is een Consumer?')
                ->description('Eén SaaS-app die de Hub gebruikt (Naschool, Planny, externe app). Authenticeert met een Bearer-PAT. Een Consumer heeft Accounts (zijn klanten) en die Accounts hebben Connections (partner-koppelingen).')
                ->collapsed()
                ->schema([]),
            TextEntry::make('id')->label('ID'),
            TextEntry::make('name'),
            TextEntry::make('slug'),
            // ...
        ]);
    }
}
```

Daarna ConsumerResource registreren via `public static function infolist(Schema $schema): Schema { return ConsumerInfolist::configure($schema); }` (zie `AccountResource::infolist()` regel 44-47).

**Section pattern (existing, ConnectionResource regel 55-73)**:

```php
Section::make('Mollie OAuth')
    ->visible(fn (?Connection $record): bool => $record?->provider === 'mollie')
    ->columns(2)
    ->schema([
        TextEntry::make('provider')->badge()->color('success'),
        // ...
    ]),
```

Voor hint-Section is `->collapsed()` (UI-SPEC §S4 regel 211) de juiste default.

---

### `app/Filament/Resources/Accounts/Schemas/AccountInfolist.php` (EXTEND)

**Analog:** self (bestaand bestand, regel 14-30)

**Current pattern** (regel 14-30):

```php
return $schema
    ->components([
        TextEntry::make('id')->label('ID'),
        TextEntry::make('consumer.slug')->label('Consumer'),
        TextEntry::make('external_id')->label('External ID'),
        // ...
    ]);
```

**Extension** — prepend Section bovenaan `components([...])`-array (D-07 + UI-SPEC §S4 regel 209-211):

```php
use Filament\Schemas\Components\Section;

return $schema->components([
    Section::make('Wat is een Account?')
        ->description('Een klant van een Consumer (bv. school A bij Naschool). Niet de individuele eindgebruiker/ouder.')
        ->collapsed()
        ->schema([]),
    TextEntry::make('id')->label('ID'),
    // ... rest unchanged
]);
```

---

### `app/Filament/Resources/Consumers/ConsumerResource.php` (EXTEND)

**Analog:** self — bestaande Issue-PAT-action regel 162-205

**Current action-binding pattern** (regel 131-139):

```php
->recordActions([
    EditAction::make(),
    self::issuePatAction(),
])
```

**Extension** — Onboard-wizard wordt **standalone Page** (UI-SPEC §S1, `app/Filament/Pages/OnboardConsumer.php`), niet een ConsumerResource-action. Wel: `ListConsumers::getHeaderActions()` krijgt een link-action *"Onboarden"* die naar de Page redirected (zie ListConsumers.php regel 21-26, `CreateAction::make()` is template):

```php
protected function getHeaderActions(): array
{
    return [
        \Filament\Actions\Action::make('onboard')
            ->label('Onboarden')
            ->icon(Heroicon::OutlinedSparkles)
            ->url(OnboardConsumer::getUrl()),
        CreateAction::make(),
    ];
}
```

**Infolist registratie** (Resource heeft nu geen `infolist()`-method — toevoegen analoog aan `AccountResource::infolist()` regel 44-47):

```php
public static function infolist(Schema $schema): Schema
{
    return ConsumerInfolist::configure($schema);
}
```

Plus `'view' => ViewConsumer::route('/{record}')` in `getPages()` regel 149-155 als infolist-page nodig is (op dit moment heeft Consumer alleen list/create/edit).

---

### `app/Filament/Resources/Accounts/AccountResource.php` (EXTEND)

**Analog:** self + revoke-action pattern uit `ConnectionResource::table()` regel 148-180

**Current state** (regel 49-52): geen `recordActions` op de table.

**Extension** — mount `StartOAuthFlowAction::forAccount()` als record-action en als `ViewAccount`-header-action:

```php
public static function table(Table $table): Table
{
    return AccountsTable::configure($table);
}
```

Wijziging gaat in `app/Filament/Resources/Accounts/Tables/AccountsTable.php` (lees-en-pas-aan; pattern komt uit `ConnectionResource::table()` `->recordActions([ViewAction::make(), Action::make('revoke')->...])` regel 148-180):

```php
->recordActions([
    ViewAction::make(),
    StartOAuthFlowAction::forAccount(),
])
```

Pluk `canAccess()`-pattern: AccountResource gebruikt `'manage-consumers'`, maar StartOAuthFlowAction zelf vereist `'manage-connections'` per CONTEXT.md `<code_context>` regel 168. Action's `visible()`-callback checkt expliciet:

```php
->visible(fn (): bool => auth()->user()?->can('manage-connections') ?? false)
```

---

### `app/Filament/Resources/Connections/ConnectionResource.php` (EXTEND)

**Analog:** self — bestaande revoke-action regel 148-180 (copy-pattern)

**Current `recordActions`** (regel 148-180):

```php
->recordActions([
    ViewAction::make(),
    Action::make('revoke')
        ->label('Revoke')
        ->visible(function (Connection $record): bool {
            if ($record->revoked_at !== null) return false;
            try {
                $descriptor = ProviderCredentialDescriptor::for($record->provider);
            } catch (\InvalidArgumentException) {
                return false;
            }
            return $descriptor->oauthFlowKey !== null;
        })
        ->action(function (Connection $record): void { /* registry->revoke */ }),
])
```

**Extension** — voeg `StartOAuthFlowAction::forConnection()` toe vóór `Action::make('revoke')`:

```php
->recordActions([
    ViewAction::make(),
    StartOAuthFlowAction::forConnection(),
    Action::make('revoke')->... // existing, unchanged
])
```

`visible()`-check binnen StartOAuthFlowAction::forConnection() (UI-SPEC §S2 regel 171) — exact:

```php
->visible(fn (Connection $record): bool =>
    $record->provider === 'mollie'
    && $record->access_token === null
    && $record->revoked_at === null
)
```

---

### `app/Providers/Filament/AdminPanelProvider.php` (EXTEND)

**Analog:** self — regel 27-89

**Current pages registration** (regel 42-45):

```php
->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
->pages([
    Dashboard::class,
])
```

`discoverPages()` betekent de nieuwe `OnboardConsumer` wordt **automatisch** opgepikt — geen expliciete `->pages([...])`-toevoeging nodig. Wel `OnboardConsumer::$navigationGroup = 'Tenants'` setten (zie ConsumerResource regel 29).

**Nav-group tooltip pattern** — bestaand `renderHook` (regel 67-86) is template voor extra render-hooks. Filament v4 nav-group description:

```php
use Filament\Navigation\NavigationGroup;

$panel->navigationGroups([
    NavigationGroup::make('Tenants')
        ->label('Tenants')
        ->extraSidebarAttributes(['title' => 'SaaS-apps (Consumer) → hun klanten (Account) → partner-koppelingen (Connection)']),
    NavigationGroup::make('Integraties'),
    NavigationGroup::make('Abonnementen'),
    NavigationGroup::make('Beheer'),
]);
```

(UI-SPEC §S4 regel 210 noemt fallback via render-hook indien Filament v4 `description()` op `NavigationGroup` niet beschikbaar is — planner moet `Filament\Navigation\NavigationGroup`-API checken; zie `vendor/filament/panels/src/Navigation/NavigationGroup.php`.)

**`->default()`-safety reminder** (regel 32 comment) — niet wijzigen.

---

### `app/Console/Commands/HubConsumerCreate.php` (REFACTOR)

**Analog:** self — bestaande command regel 20-58 (signature behouden)

**Current handle()-body** (regel 20-58):

```php
public function handle(): int
{
    $slug = (string) $this->option('slug');
    $name = (string) $this->option('name');
    // ... validation ...

    try {
        $consumer = Consumer::create(['slug' => $slug, 'name' => $name]);
    } catch (QueryException $e) {
        $this->error("Aanmaken Consumer mislukt: {$e->getMessage()}");
        return self::FAILURE;
    }

    $tokenName = (string) $this->option('token-name');
    $token = $consumer->createToken($tokenName, $abilities);

    $this->info("Consumer aangemaakt: id={$consumer->id}, ...");
    $this->warn("Plain-text token (toon eenmalig): {$token->plainTextToken}");
    return self::SUCCESS;
}
```

**Refactor — delegate naar `ConsumerOnboarding`**:

```php
public function handle(ConsumerOnboarding $onboarding): int
{
    // validation unchanged (regel 22-39)
    try {
        $result = $onboarding->onboard([
            'name' => $name,
            'slug' => $slug,
            'token_name' => (string) $this->option('token-name'),
            'abilities' => $abilities,
            // no account/connection in CLI happy-path — preserve current scope
        ]);
    } catch (QueryException $e) {
        $this->error("Aanmaken Consumer mislukt: {$e->getMessage()}");
        return self::FAILURE;
    }

    $this->info("Consumer aangemaakt: id={$result['consumer']->id}, slug={$result['consumer']->slug}");
    $this->warn("Plain-text token (toon eenmalig): {$result['plain_token']}");
    return self::SUCCESS;
}
```

**Signature stable** — `--slug`, `--name`, `--abilities`, `--token-name` blijven exact zoals regel 12-16. Bestaande tests slagen ongewijzigd.

---

### `resources/views/partners/index.blade.php` (EXTEND)

**Analog:** self — regel 1-46 (huidige inline-`<style>`-aanpak)

**Current structure** (regel 22-44):

```blade
<h1>Partner previews</h1>
<p class="lede">...</p>

<ul>
    @foreach ($providers as $provider)
        @php $view = "partners.{$provider}.example"; @endphp
        <li>
            @if (view()->exists($view))
                <a href="{{ route('dev.partners.preview', $provider) }}">...</a>
            @else
                <span class="missing">...</span>
            @endif
        </li>
    @endforeach
</ul>
```

**Extension** — voeg `@include('partners.partials._domeinmodel')` toe vóór `<ul>`. Migreer per UI-SPEC §Design System regel 44 de inline `<style>` naar Tailwind v4 utility-classes (`max-w-3xl mx-auto px-4 py-12`, `text-2xl font-semibold`, etc.). UI-SPEC §Spacing regel 56-63 geeft de exacte tokens.

Per-card status-totaal (UI-SPEC §S3 regel 200):

```blade
@php $status = app(\App\Services\PartnerStatus::class)->forProvider($provider); @endphp
<div class="text-sm text-gray-500">
    {{ ucfirst($provider) }}: {{ $status->where('status', 'connected')->count() }}/{{ $status->count() }} Accounts gekoppeld
</div>
```

---

### `resources/views/partners/mollie/example.blade.php` (EXTEND)

**Analog:** self — bestaande page regel 1-57

**Current structure** — `<h1>` + `<p>` + `<h2>Use-cases</h2>` etc. (regel 13-55).

**Extensions** (UI-SPEC §S3 regel 187-201):

1. Domeinmodel-blokje boven `<h2>Use-cases</h2>`: `@include('partners.partials._domeinmodel')`.
2. Nieuwe sectie `<h2>Koppelen via OAuth Connect</h2>` met 3 stappen (canonical copy uit UI-SPEC regel 189-191).
3. CTA-button (amber, UI-SPEC §Color reserved-for #6):
   ```blade
   <a href="{{ route('dev.partners.preview', 'mollie') }}/start-oauth"
      class="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
       Start OAuth-flow
   </a>
   ```
   (Route-callback in `routes/web.php` moet `redirect()->away()` doen via `InitController`-logica voor een pre-selected demo-Account.)
4. Status-widget partial: `@include('partners.partials._status-widget', ['provider' => 'mollie'])`.

---

### `resources/views/partners/snelstart/example.blade.php` (EXTEND)

**Analog:** self — regel 1-50

**Current structure** — `<h1>` + 2 `<p>`-paragraphs + `<h2>Screenshots</h2>` etc. (regel 13-49).

**Extensions** (UI-SPEC §S3 regel 193-196):

1. Domeinmodel-blokje boven `<h2>Use-cases</h2>` of na de tweede `<p>`.
2. `<h2>Koppelen via credential-form</h2>` met 3 stappen + cURL-snippet (canonical regel 196).
3. Status-widget partial (zoals Mollie).

cURL-pattern (exact uit UI-SPEC regel 196):

```bash
curl -X POST {APP_URL}/v1/connections \
  -H "Authorization: Bearer {PAT}" \
  -H "Content-Type: application/json" \
  -d '{"account_external_id":"school1","provider":"snelstart","client_key":"…","subscription_key":"…","subscription_id":"…"}'
```

---

### `routes/web.php` (TOUCH — closures passend houden)

**Analog:** self — regel 39-55

**Current dev-routes pattern** (regel 39-55):

```php
Route::get('/dev/partners', function () {
    $providers = array_keys(config('hub-providers', []));
    return response()->view('partners.index', ['providers' => $providers]);
})->name('dev.partners.index');

Route::get('/dev/partners/{provider}', function (string $provider) {
    abort_unless(array_key_exists($provider, config('hub-providers', [])), 404);
    $view = "partners.{$provider}.example";
    abort_unless(view()->exists($view), 404, "...");
    return response()->view($view);
})->name('dev.partners.preview');
```

**Extension** — optioneel inject service in view-data:

```php
Route::get('/dev/partners/{provider}', function (string $provider, \App\Services\PartnerStatus $status) {
    abort_unless(array_key_exists($provider, config('hub-providers', [])), 404);
    return response()->view("partners.{$provider}.example", [
        'provider' => $provider,
        'accountStatus' => $status->forProvider($provider),
    ]);
})->name('dev.partners.preview');
```

Alternatief: blade gebruikt `app(\App\Services\PartnerStatus::class)` inline — geen route-wijziging. Engineering-rule "chirurgisch wijzigen" prefereert inline-resolve in blade.

---

## Shared Patterns

### Authentication / Authorization

**Source:** `app/Filament/Resources/Consumers/ConsumerResource.php` regel 87-95

**Apply to:** Alle nieuwe Filament-classes (`OnboardConsumer`, `StartOAuthFlowAction`-visible callbacks)

```php
public static function canAccess(): bool
{
    return auth()->user()?->can('manage-consumers') ?? false;
}

public static function shouldRegisterNavigation(): bool
{
    return static::canAccess();
}
```

Permission-keys (uit `database/seeders/EmeqStaffSeeder.php` regel 32-33):
- `manage-consumers` — Consumer/Account-Resource access + onboard-wizard
- `manage-connections` — ConnectionResource + StartOAuthFlowAction
- `manage-staff` — UserResource only (super-admin)

### Encrypted-cast invariant (no-secret-leak)

**Source:** `app/Models/Consumer.php` regel 23-28 + `app/Models/Connection.php` regel 69-82

**Apply to:** `ConsumerOnboarding` service, wizard step 1 + 3, partner-pages status-widget

```php
// Consumer
protected function casts(): array
{
    return ['webhook_callback_secret' => 'encrypted'];
}

// Connection
protected function casts(): array
{
    return [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'client_key' => 'encrypted',
        'subscription_key' => 'encrypted',
        ...
    ];
}
```

Plain secrets verschijnen alleen via Cache-flash-pattern (zie ConsumerResource regel 191-204 + list-consumers.blade.php regel 7-58). Status-widget mag alleen `fingerprint()` lezen (Connection.php regel 47-64) — nooit raw `client_key` etc.

### Cache-flash one-shot token display

**Source:** `app/Filament/Resources/Consumers/ConsumerResource.php` regel 191-198 + `resources/views/filament/resources/consumers/pages/list-consumers.blade.php` regel 7-58

**Apply to:** Onboard-wizard stap 4 (PAT) + stap 1 (webhook_callback_secret, indien auto-generated)

```php
// Filament action callback
$livewireId = $livewire->getId();
Cache::put("pat-flash:{$livewireId}", $token->plainTextToken, now()->addSeconds(60));
Cache::put("pat-flash-name:{$livewireId}", $name, now()->addSeconds(60));

// Blade view
@php $issuedToken = Cache::pull('pat-flash:'.$this->getId()); @endphp
```

Cache-TTL 60s + pull (read+delete in één call) garandeert: token ÉÉN keer in HTML, daarna leeg. Niet in `wire:snapshot`, niet in Alpine `x-data`.

### Provider-descriptor-driven dispatch

**Source:** `config/hub-providers.php` + `app/Support/ProviderCredentialDescriptor.php` (regel 36-60)

**Apply to:** `StartOAuthFlowAction.oauthCapableProviders()`, `PartnerStatus`, wizard step-3 conditional sub-form, status-widget

```php
foreach (ProviderCredentialDescriptor::all() as $descriptor) {
    if ($descriptor->oauthFlowKey === null) continue; // skip key-based providers
    // ...
}

// Single provider:
$descriptor = ProviderCredentialDescriptor::for($provider);
if ($descriptor->oauthFlowKey !== null) {
    // OAuth flow available
}
```

**Invariant**: nieuwe provider = nieuwe `config/hub-providers.php`-row, geen code-change in onboard-wizard, StartOAuthFlowAction, of status-widget.

### Notification feedback

**Source:** `app/Filament/Resources/Consumers/ConsumerResource.php` regel 200-203 + `ConnectionResource.php` regel 175-178

**Apply to:** Alle Filament-action success/failure callbacks

```php
Notification::make()
    ->title('PAT uitgegeven — token verschijnt eenmalig bovenaan de listing')
    ->success()
    ->send();

Notification::make()
    ->title('Geen OAuth-flow beschikbaar')
    ->body('Provider {provider} heeft geen OAuth-koppeling...')
    ->warning()
    ->send();
```

UI-SPEC §Copywriting Contract (regel 153-157, 172-173) heeft de canonical Nederlandse copy.

### OAuth-flow registry usage

**Source:** `app/Http/Controllers/Api/V1/OAuth/InitController.php` regel 22-51 + `ConnectionResource::table` revoke regel 170-178

**Apply to:** `StartOAuthFlowAction.dispatch()`-logic + new `partners/mollie/example` CTA route-callback

```php
$state = Str::random(48);
$connection = Connection::create([
    'account_id' => $account->id,
    'provider' => 'mollie',
    'status' => 'pending',
    'oauth_state' => $state,
    'oauth_state_expires_at' => now()->addMinutes(30),
]);
$scopes = config('services.mollie.connect.scopes');
$redirectUrl = app(OAuthFlowRegistry::class)->for('mollie')->getAuthorizationUrl($account, $scopes, $state);
return redirect()->away($redirectUrl);
```

**State TTL**: 30 minuten (InitController regel 41). Wizard sessie-restoration moet binnen die window blijven — UI-SPEC §S1 regel 245 noemt `state`-parameter als wizard-session-id-carrier.

### Heroicon usage

**Source:** `app/Filament/Resources/**/*.php` (e.g. `ConsumerResource::issuePatAction()` regel 166: `Heroicon::OutlinedKey`)

**Apply to:** Alle nieuwe Filament-actions + Wizard-step-icons

```php
use Filament\Support\Icons\Heroicon;

->icon(Heroicon::OutlinedLink)        // StartOAuthFlowAction
->icon(Heroicon::OutlinedKey)         // PAT-related
->icon(Heroicon::OutlinedBuildingOffice) // Consumer (ConsumerResource regel 85)
->icon(Heroicon::OutlinedUsers)       // Account (AccountResource regel 32)
->icon(Heroicon::OutlinedSparkles)    // Onboard (suggested)
```

Status-widget Heroicon-keys in Blade: `heroicon-o-check-circle`, `heroicon-o-clock`, `heroicon-o-x-circle`, `heroicon-o-minus-circle` (UI-SPEC §Color regel 119-123).

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `resources/views/partners/partials/_status-widget.blade.php` | Blade partial | render | Geen bestaande partial-pattern in `resources/views/partners/`; eerste van zijn soort. Planner volgt UI-SPEC §Color "Status-widget semantic palette" (regel 116-124) + RESEARCH-driven Tailwind v4 utility-classes. |
| `resources/views/partners/partials/_domeinmodel.blade.php` | Blade partial | render | Canonical copy uit UI-SPEC §S3 regel 184-186 letterlijk overnemen; geen analoog blok-met-3-bullets in repo. |
| `app/Filament/Pages/OnboardConsumer.php` standalone Page | Filament Page | request-response | Geen bestaande standalone `Filament\Pages\Page`-subclass in `app/Filament/Pages/` (alleen Dashboard via vendor). Wizard-component is gedocumenteerd in `vendor/filament/schemas/docs/05-wizards.md`. |
| `DB::transaction` voor multi-model create | service-pattern | transactional | `grep -r "DB::transaction" app/` = 0 hits. Eerste transactional service in repo. Planner kiest closure-based `DB::transaction(fn () => ...)`-pattern (Laravel default). |

---

## Metadata

**Analog search scope:**
- `app/Filament/` (Resources, Widgets, Pages-dir scan)
- `app/Http/Controllers/Api/V1/OAuth/`
- `app/OAuth/` (Registry, Mollie/, Contracts/)
- `app/Services/` (Snelstart/)
- `app/Support/` (ProviderCredentialDescriptor + Snelstart/Mollie subdirs)
- `app/Console/Commands/`
- `app/Models/` (Consumer, Connection casts + fillable)
- `app/Providers/Filament/`
- `resources/views/partners/` + `resources/views/filament/`
- `routes/web.php`
- `config/hub-providers.php`
- `database/seeders/` (permission-keys)
- `vendor/filament/schemas/docs/05-wizards.md` (Wizard reference)

**Files scanned:** ~24 application files + 1 vendor-doc + 2 config/seeder files

**Pattern extraction date:** 2026-05-17
