<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Settings\ExactSettings;
use App\Settings\MollieSettings;
use App\Settings\SnelstartSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Beheer partner-integratie-credentials in de DB i.p.v. .env. Secrets worden
 * encrypted opgeslagen (zie ExactSettings/MollieSettings::encrypted()).
 * SettingsHydrationServiceProvider hydrateert config('services.*') hiermee.
 */
class ManageIntegrationSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Beheer';

    protected static ?string $navigationLabel = 'Integratie-instellingen';

    protected static ?string $title = 'Integratie-instellingen';

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
        $mollie = app(MollieSettings::class);
        $snelstart = app(SnelstartSettings::class);

        $this->form->fill([
            'exact_client_id' => $exact->client_id,
            'exact_client_secret' => $exact->client_secret,
            'exact_redirect_uri' => $exact->redirect_uri,
            'exact_webhook_secret' => $exact->webhook_secret,
            'exact_auth_base_url' => $exact->auth_base_url,
            'exact_api_base_url' => $exact->api_base_url,
            'mollie_connect_client_id' => $mollie->connect_client_id,
            'mollie_connect_client_secret' => $mollie->connect_client_secret,
            'mollie_connect_redirect_uri' => $mollie->connect_redirect_uri,
            'mollie_partner_access_token' => $mollie->partner_access_token,
            'snelstart_webhook_secret' => $snelstart->webhook_secret,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Exact Online')
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
                Section::make('Mollie Connect')
                    ->description('Mollie Connect OAuth-app-credentials. Client secret wordt encrypted opgeslagen.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('mollie_connect_client_id')->label('Connect Client ID')->maxLength(255),
                        TextInput::make('mollie_connect_redirect_uri')->label('Connect Redirect URI')->maxLength(255),
                        TextInput::make('mollie_connect_client_secret')->label('Connect Client secret')->password()->revealable()->maxLength(255),
                        TextInput::make('mollie_partner_access_token')->label('Partner access token')->password()->revealable()->maxLength(255),
                    ]),
                Section::make('Snelstart')
                    ->description('App-breed webhook-signing-secret. clientKey/subscriptionKey zijn per-Connection.')
                    ->schema([
                        TextInput::make('snelstart_webhook_secret')->label('Webhook secret')->password()->revealable()->maxLength(255),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $exact = app(ExactSettings::class);
        $exact->client_id = (string) $data['exact_client_id'];
        $exact->client_secret = (string) $data['exact_client_secret'];
        $exact->redirect_uri = (string) $data['exact_redirect_uri'];
        $exact->webhook_secret = (string) $data['exact_webhook_secret'];
        $exact->auth_base_url = (string) $data['exact_auth_base_url'];
        $exact->api_base_url = (string) $data['exact_api_base_url'];
        $exact->save();

        $mollie = app(MollieSettings::class);
        $mollie->connect_client_id = (string) $data['mollie_connect_client_id'];
        $mollie->connect_client_secret = (string) $data['mollie_connect_client_secret'];
        $mollie->connect_redirect_uri = (string) $data['mollie_connect_redirect_uri'];
        $mollie->partner_access_token = (string) $data['mollie_partner_access_token'];
        $mollie->save();

        $snelstart = app(SnelstartSettings::class);
        $snelstart->webhook_secret = (string) $data['snelstart_webhook_secret'];
        $snelstart->save();

        Notification::make()->title('Integratie-instellingen opgeslagen')->success()->send();
    }
}
