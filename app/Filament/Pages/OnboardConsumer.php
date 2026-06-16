<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Provider;
use App\Filament\Resources\Consumers\ConsumerResource;
use App\Sanctum\TokenAbilities;
use App\Services\ConsumerOnboarding;
use App\Support\ProviderCredentialDescriptor;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Plan 08-02 — Filament Consumer-onboard-wizard (D-04, UI-SPEC S1).
 *
 * Standalone Filament Page met 4-staps Wizard die de Phase-3 onboarding-flow
 * (Consumer → Account → Connection → PAT) atomisch uitvoert via de
 * App\Services\ConsumerOnboarding-service (PLAN 08-01).
 *
 * No-secret-leak invariant: plain PAT + plain webhook_callback_secret worden
 * via Cache-flash naar de ListConsumers-redirect-target geflashed; never bewaard
 * als public property of in wire:snapshot.
 *
 * Stap 3 is descriptor-driven via ProviderCredentialDescriptor::all() —
 * een nieuwe provider toevoegen vereist alleen een config-row, geen code-edit
 * in deze wizard.
 */
class OnboardConsumer extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Tenants';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Onboarden';

    protected static ?string $title = 'Nieuwe Consumer onboarden';

    protected string $view = 'filament.pages.onboard-consumer';

    /**
     * Form-state container — Filament bindt het Wizard-form aan deze property.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-consumers') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        // Géén los menu-item: onboarden start via de "Onboarden"-actie op de
        // Consumers-lijst (ListConsumers). De page blijft via die knop bereikbaar.
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Consumer')
                        ->description('De SaaS-app die de Hub gaat aanroepen.')
                        ->schema([
                            TextInput::make('name')
                                ->label('Naam')
                                ->placeholder('Naschool')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('slug')
                                ->label('Slug')
                                ->helperText('Lowercase, dashes. Bv. naschool.')
                                ->required()
                                ->maxLength(255)
                                ->unique('consumers', 'slug')
                                ->validationMessages([
                                    'unique' => 'Deze slug bestaat al — kies een andere.',
                                ]),
                            TextInput::make('webhook_callback_url')
                                ->label('Webhook callback-URL')
                                ->helperText('Endpoint waar de Hub partner-events naartoe POSTed. Optioneel — vul later in.')
                                ->url()
                                ->maxLength(255),
                            TextInput::make('webhook_callback_secret')
                                ->label('Webhook callback-secret')
                                ->helperText('Wordt eenmalig getoond na opslaan. Daarna alleen rotate-able.')
                                ->password()
                                ->revealable()
                                ->maxLength(255),
                        ]),
                    Step::make('Eerste Account')
                        ->description('Een klant van deze Consumer (bv. een school).')
                        ->schema([
                            TextInput::make('external_id')
                                ->label('Externe ID')
                                ->helperText('De identifier die deze Consumer voor deze klant gebruikt (bv. school1).')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('display_name')
                                ->label('Weergavenaam')
                                ->placeholder('School A')
                                ->required()
                                ->maxLength(255),
                        ]),
                    Step::make('Eerste Connection')
                        ->description('De partner-koppeling voor deze Account.')
                        ->schema([
                            Radio::make('connection.provider')
                                ->label('Provider')
                                ->options(self::providerOptions())
                                ->live()
                                ->required(),
                            // Snelstart-branch: 3 credential-velden (descriptor-driven, key-based provider)
                            Group::make([
                                TextInput::make('connection.client_key')
                                    ->label('Client key')
                                    ->password()
                                    ->revealable()
                                    ->required()
                                    ->helperText('Door SnelStart uitgegeven aan de eindklant. Tokens worden encrypted opgeslagen.'),
                                TextInput::make('connection.subscription_key')
                                    ->label('Subscription key')
                                    ->password()
                                    ->revealable()
                                    ->required(),
                                TextInput::make('connection.subscription_id')
                                    ->label('Subscription ID')
                                    ->required(),
                            ])->visible(fn (Get $get): bool => $get('connection.provider') === Provider::Snelstart->value),
                            // Mollie-branch: alleen helper-text. OAuth-roundtrip gebeurt na wizard-completion
                            // via StartOAuthFlowAction op de Account-detailpagina (D-04 UX-split, zie PLAN 08-02).
                            Group::make([
                                Text::make('Start Mollie OAuth-koppeling — je wordt naar Mollie gestuurd. Na goedkeuring keer je terug in deze wizard.'),
                            ])->visible(fn (Get $get): bool => $get('connection.provider') === Provider::Mollie->value),
                        ]),
                    Step::make('PAT uitgeven')
                        ->description('Het token wordt eenmalig getoond. Bewaar het direct.')
                        ->schema([
                            Radio::make('pat.preset')
                                ->label('Preset')
                                ->options(self::patPresetOptions())
                                ->default('admin')
                                ->required()
                                ->live(),
                            CheckboxList::make('pat.abilities')
                                ->label('Abilities')
                                ->options(self::customAbilitiesOptions())
                                ->visible(fn (Get $get): bool => $get('pat.preset') === 'custom'),
                            TextInput::make('pat.token_name')
                                ->label('Token-naam')
                                ->default('onboard-default')
                                ->required()
                                ->maxLength(255),
                        ]),
                ])
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button type="submit" wire:click="submit" size="md">
                            Token uitgeven
                        </x-filament::button>
                    BLADE))),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $connectionPayload = self::buildConnectionPayload($data['connection'] ?? []);

        // WR-06: server-side guard. Filament's Wizard valideert
        // Radio::make('connection.provider')->required() in de UI-flow, maar als
        // die validatie wordt overgeslagen (Filament-v4 step-skipping edge-case,
        // Action::execute() buiten form-flow, future schema-change) zou de wizard
        // anders silent een Consumer + Account zonder Connection neerzetten.
        if ($connectionPayload === null) {
            Notification::make()
                ->title('Stap 3 onvolledig')
                ->body('Kies een provider voor de eerste Connection.')
                ->danger()
                ->send();

            return;
        }

        [$webhookSecret, $webhookSecretAutoGenerated] = self::resolveWebhookSecret($data);
        $payload = self::buildOnboardPayload($data, $connectionPayload, $webhookSecret);

        $result = self::onboardOrNotify($payload);
        if ($result === null) {
            return;
        }

        self::flashOnboardingSecrets($result, $payload, $webhookSecret, $webhookSecretAutoGenerated, $data);

        Notification::make()
            ->title('Consumer onboarded — PAT eenmalig zichtbaar bovenaan de listing')
            ->success()
            ->send();

        $this->redirect(ConsumerResource::getUrl());
    }

    /**
     * Resolved de webhook-callback-secret uit de form-state, of genereert er een.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: bool} [secret, autoGenerated]
     */
    private static function resolveWebhookSecret(array $data): array
    {
        $webhookSecret = $data['webhook_callback_secret'] ?? null;

        if ($webhookSecret === null || $webhookSecret === '') {
            // Auto-generate met dezelfde entropy als Phase-4 oauth_state (T-08-02-06).
            return [Str::random(48), true];
        }

        return [$webhookSecret, false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $connectionPayload
     * @return array<string, mixed>
     */
    private static function buildOnboardPayload(array $data, array $connectionPayload, string $webhookSecret): array
    {
        $preset = $data['pat']['preset'] ?? 'admin';
        $abilities = $preset === 'custom'
            ? array_values($data['pat']['abilities'] ?? [])
            : (ConsumerResource::PAT_PRESETS[$preset]['abilities'] ?? []);

        return [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'webhook_callback_url' => $data['webhook_callback_url'] ?? null,
            'webhook_callback_secret' => $webhookSecret,
            'external_id' => $data['external_id'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'connection' => $connectionPayload,
            'token_name' => $data['pat']['token_name'],
            'abilities' => $abilities,
        ];
    }

    /**
     * Roept de onboarding-service aan en vertaalt failures naar Notifications.
     * Returnt null als het onboarden mislukte (caller stopt dan).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function onboardOrNotify(array $payload): ?array
    {
        try {
            return app(ConsumerOnboarding::class)->onboard($payload);
        } catch (\InvalidArgumentException $e) {
            // WR-05: domein-validatie-fouten (bv. "Onbekende abilities: …" uit
            // ConsumerOnboarding::assertAbilitiesWhitelisted) dragen een
            // actionable message. CLI laat die zien (HubConsumerCreate); de
            // wizard moet datzelfde doen i.p.v. een generieke "Er ging iets mis".
            Notification::make()
                ->title('Validatie mislukt')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Onboarden mislukt')
                ->body('Onverwachte fout — bekijk Horizon-logs.')
                ->danger()
                ->send();

            return null;
        }
    }

    /**
     * No-secret-leak: plain values gaan eenmalig via Cache-flash naar de
     * ListConsumers-redirect-target (60s TTL + Cache::pull = read+delete).
     * De Page-instance houdt zelf NIETS bij (geen public property = geen wire:snapshot leak).
     *
     * CR-01/CR-02 fix: scope op auth()->id() in plaats van $this->getId() — de
     * wizard (OnboardConsumer) en de redirect-target (ListConsumers) zijn twee
     * verschillende Livewire-componenten met verschillende ID's, dus een
     * Livewire-ID-gescopete key zou nooit matchen. Eén onboarding tegelijk per
     * staff-user, dus user-id als scope is botsing-vrij in praktijk.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    private static function flashOnboardingSecrets(array $result, array $payload, string $webhookSecret, bool $webhookSecretAutoGenerated, array $data): void
    {
        $userId = auth()->id();
        Cache::put("pat-flash:user:{$userId}", $result['plain_token'], now()->addSeconds(60));
        Cache::put("pat-flash-name:user:{$userId}", $payload['token_name'], now()->addSeconds(60));

        if ($webhookSecretAutoGenerated || ! empty($data['webhook_callback_secret'])) {
            Cache::put("webhook-secret-flash:user:{$userId}", $webhookSecret, now()->addSeconds(60));
        }
    }

    /**
     * Descriptor-driven provider-keuze (Stap 3). Nieuwe provider = nieuwe config-row.
     *
     * @return array<string, string>
     */
    private static function providerOptions(): array
    {
        $options = [];
        foreach (ProviderCredentialDescriptor::all() as $descriptor) {
            $options[$descriptor->key] = ucfirst($descriptor->key);
        }

        return $options;
    }

    /**
     * Bouw de Connection-payload voor ConsumerOnboarding::onboard().
     *
     * Snelstart → status='active' met 3 encrypted credential-velden.
     * Mollie    → pending stub zonder access_token; OAuth gebeurt later via
     *             StartOAuthFlowAction::forAccount() (PLAN 08-03, UX-split per D-04).
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>|null
     */
    private static function buildConnectionPayload(array $connection): ?array
    {
        $provider = $connection['provider'] ?? null;
        if ($provider === null) {
            return null;
        }

        if ($provider === Provider::Snelstart->value) {
            return [
                'provider' => Provider::Snelstart->value,
                'status' => 'active',
                'client_key' => $connection['client_key'] ?? null,
                'subscription_key' => $connection['subscription_key'] ?? null,
                'subscription_id' => $connection['subscription_id'] ?? null,
            ];
        }

        // Mollie pending stub — OAuth-state komt later via StartOAuthFlowAction.
        return [
            'provider' => $provider,
            'status' => 'pending',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function patPresetOptions(): array
    {
        $options = [];
        foreach (ConsumerResource::PAT_PRESETS as $slug => $entry) {
            $options[$slug] = $entry['label'];
        }
        $options['custom'] = 'Custom...';

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function customAbilitiesOptions(): array
    {
        $options = [];
        foreach (TokenAbilities::all() as $ability) {
            $options[$ability] = $ability;
        }

        return $options;
    }
}
