<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Provider;
use App\Integrations\Exact\Settings\ExactSettings;
use App\Integrations\Itheorie\Settings\ItheorieSettings;
use App\Settings\ProviderSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManageIntegrationSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Beheer';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Providers';

    protected static ?string $title = 'Providers';

    protected string $view = 'filament.pages.manage-integration-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public function mount(): void
    {
        $exact = app(ExactSettings::class);
        $itheorie = app(ItheorieSettings::class);
        $providers = app(ProviderSettings::class);

        $state = [
            'itheorie_username' => $itheorie->username,
            'itheorie_password' => $itheorie->password,
            'itheorie_reseller' => $itheorie->reseller,
            'itheorie_base_url' => $itheorie->base_url,
            'exact_client_id' => $exact->client_id,
            'exact_client_secret' => $exact->client_secret,
            'exact_redirect_uri' => $exact->redirect_uri,
            'exact_webhook_secret' => $exact->webhook_secret,
            'exact_auth_base_url' => $exact->auth_base_url,
            'exact_api_base_url' => $exact->api_base_url,
        ];

        foreach (Provider::cases() as $provider) {
            $state["enabled_{$provider->value}"] = $providers->isEnabled($provider->value);
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Integraties')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs($this->providerTabs()),
            ])
            ->statePath('data');
    }

    /** @return list<Tab> */
    private function providerTabs(): array
    {
        $tabs = [$this->exactTab(), $this->itheorieTab()];

        foreach (Provider::cases() as $provider) {
            if ($provider === Provider::Exact || $provider === Provider::Itheorie) {
                continue;
            }

            $tabs[] = Tab::make($provider->getLabel())
                ->icon(Heroicon::OutlinedClock)
                ->badge('Binnenkort')
                ->schema([
                    $this->availabilitySection($provider),
                    Section::make('Binnenkort beschikbaar')
                        ->description("De {$provider->getLabel()}-integratie kun je nog niet in de Hub configureren. We werken eraan."),
                ]);
        }

        return $tabs;
    }

    private function exactTab(): Tab
    {
        return Tab::make('Exact Online')
            ->icon(Heroicon::OutlinedBuildingOffice2)
            ->schema([
                $this->availabilitySection(Provider::Exact),
                Section::make()
                    ->description('App-credentials uit het Exact App Center. Secrets worden encrypted opgeslagen.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('exact_client_id')->label('Client ID')->maxLength(255),
                        TextInput::make('exact_redirect_uri')->label('Redirect URI')->maxLength(255),
                        TextInput::make('exact_client_secret')->label('Client secret')->password()->revealable()->maxLength(255),
                        TextInput::make('exact_webhook_secret')->label('Webhook secret')->password()->revealable()->maxLength(255),
                        TextInput::make('exact_auth_base_url')->label('Auth base URL')->maxLength(255)->placeholder('https://start.exactonline.nl'),
                        TextInput::make('exact_api_base_url')->label('API base URL')->maxLength(255)->placeholder('https://start.exactonline.nl'),
                    ]),
            ]);
    }

    private function itheorieTab(): Tab
    {
        return Tab::make('iTheorie')
            ->schema([
                $this->availabilitySection(Provider::Itheorie),
                Section::make()
                    ->description('Broker-inlog van Emeq zelf. Er is geen koppeling per klant: de Hub koopt namens één reseller-overeenkomst in. Het wachtwoord wordt encrypted opgeslagen.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('itheorie_username')->label('LENS-ID')->maxLength(255),
                        TextInput::make('itheorie_password')->label('Wachtwoord')->password()->revealable()->maxLength(255),
                        TextInput::make('itheorie_reseller')->label('Reseller (ULID of KvK-nummer)')->maxLength(64),
                        TextInput::make('itheorie_base_url')->label('API base URL')->maxLength(255)->placeholder('https://itheorie.nl/api/connect'),
                    ]),
            ]);
    }

    private function availabilitySection(Provider $provider): Section
    {
        return Section::make('Beschikbaarheid')
            ->description("Staat deze schakelaar uit, dan bestaat {$provider->getLabel()} niet voor consumers. Hij verdwijnt van de koppelpagina en /v1/{$provider->value}/* antwoordt met 503. Geldt vanaf het eerstvolgende verzoek, zonder deploy.")
            ->schema([
                Toggle::make("enabled_{$provider->value}")
                    ->label('Beschikbaar voor klanten')
                    ->inline(false),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $providers = app(ProviderSettings::class);
        $enabled = $providers->enabled;

        foreach (Provider::cases() as $provider) {
            $enabled[$provider->value] = (bool) ($data["enabled_{$provider->value}"] ?? false);
        }

        $providers->enabled = $enabled;
        $providers->save();

        $exact = app(ExactSettings::class);
        $exact->client_id = (string) $data['exact_client_id'];
        $exact->client_secret = (string) $data['exact_client_secret'];
        $exact->redirect_uri = (string) $data['exact_redirect_uri'];
        $exact->webhook_secret = (string) $data['exact_webhook_secret'];
        $exact->auth_base_url = (string) $data['exact_auth_base_url'];
        $exact->api_base_url = (string) $data['exact_api_base_url'];
        $exact->save();

        $itheorie = app(ItheorieSettings::class);
        $itheorie->username = (string) $data['itheorie_username'];
        $itheorie->password = (string) $data['itheorie_password'];
        $itheorie->reseller = (string) $data['itheorie_reseller'];
        $itheorie->base_url = (string) $data['itheorie_base_url'];
        $itheorie->save();

        Notification::make()->title('Providers opgeslagen')->success()->send();
    }
}
