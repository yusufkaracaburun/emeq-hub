<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Consumers\ConsumerResource;
use App\Models\AccessRequest;
use App\Sanctum\TokenAbilities;
use App\Services\ConsumerOnboarding;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
 * Filament-wizard die een Consumer + PAT aanmaakt, atomisch via
 * App\Services\ConsumerOnboarding.
 *
 * Alleen Consumer + PAT: dat is alles wat de admin kán weten. Accounts en
 * Connections ontstaan runtime bij de Consumer — een Account via
 * `POST /v1/oauth/{provider}/init` (firstOrCreate op een external_id die alleen de
 * Consumer kent), een Connection pas nadat de eindgebruiker de OAuth-flow heeft
 * doorlopen. Key-based providers (Snelstart) gaan via `POST /v1/connections`.
 *
 * No-secret-leak invariant: plain PAT + plain webhook_callback_secret worden via
 * Cache-flash naar de ListConsumers-redirect-target geflashed; nooit bewaard als
 * public property of in wire:snapshot.
 */
class OnboardConsumer extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Koppelingen';

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

    /**
     * Koppel-aanvraag waaruit deze onboarding voortkomt (via ?from_request=).
     * Wordt na succesvol onboarden gelinkt + op 'handled' gezet.
     */
    public ?int $fromRequest = null;

    public function mount(): void
    {
        $this->form->fill($this->prefillFromAccessRequest());
    }

    /**
     * Voorvul de wizard met een koppel-aanvraag (admin → Koppel-aanvragen → Onboard).
     *
     * @return array<string, mixed>
     */
    private function prefillFromAccessRequest(): array
    {
        $id = (int) request()->query('from_request');

        if ($id <= 0) {
            return [];
        }

        $accessRequest = AccessRequest::find($id);

        if ($accessRequest === null) {
            return [];
        }

        $this->fromRequest = $accessRequest->id;

        $providers = $accessRequest->providers ?? [];

        Notification::make()
            ->title('Aanvraag van '.$accessRequest->company)
            ->body('Gevraagde integraties: '.(implode(', ', $providers) ?: '—')
                .'. Kies een PAT-preset die daarbij past; de koppeling zelf legt de Consumer.')
            ->info()
            ->send();

        return [
            'name' => $accessRequest->company,
            'slug' => Str::slug($accessRequest->company),
            'app_url' => $accessRequest->app_url,
        ];
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
                            TextInput::make('app_url')
                                ->label('App-URL')
                                ->helperText('Waar de eindgebruiker na een OAuth-connect terugkeert (root van de consumer-app).')
                                ->url()
                                ->maxLength(255),
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
                    // Géén Account- of Connection-stap. Die ontstaan runtime, bij de
                    // Consumer: een Account wordt geprovisioneerd door
                    // `POST /v1/oauth/{provider}/init` (firstOrCreate op de external_id die
                    // alleen de Consumer kent), en een Connection pas als de eindgebruiker
                    // de OAuth-flow doorloopt. Key-based providers (Snelstart) gaan via
                    // `POST /v1/connections`. Een admin die dit vooraf invult, zet een lege
                    // huls neer die de echte flow daarna toch overschrijft.
                    Step::make('PAT uitgeven')
                        ->description('Het token wordt eenmalig getoond. Bewaar het direct.')
                        ->schema([
                            Select::make('pat.preset')
                                ->label('Preset')
                                ->options(ConsumerResource::presetOptions())
                                ->native(false)
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

        [$webhookSecret, $webhookSecretAutoGenerated] = self::resolveWebhookSecret($data);
        $payload = self::buildOnboardPayload($data, $webhookSecret);

        $result = self::onboardOrNotify($payload);
        if ($result === null) {
            return;
        }

        self::flashOnboardingSecrets($result, $payload, $webhookSecret, $webhookSecretAutoGenerated, $data);

        // Sluit de loop: koppel de aanvraag aan de nieuwe Consumer + markeer afgehandeld.
        if ($this->fromRequest !== null) {
            AccessRequest::whereKey($this->fromRequest)->update([
                'consumer_id' => $result['consumer']->id,
                'status' => 'handled',
            ]);
        }

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
     * @return array<string, mixed>
     */
    private static function buildOnboardPayload(array $data, string $webhookSecret): array
    {
        $preset = $data['pat']['preset'] ?? 'custom';
        $abilities = $preset === 'custom'
            ? array_values($data['pat']['abilities'] ?? [])
            : (ConsumerResource::PAT_PRESETS[$preset]['abilities'] ?? []);

        return [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'app_url' => $data['app_url'] ?? null,
            'webhook_callback_url' => $data['webhook_callback_url'] ?? null,
            'webhook_callback_secret' => $webhookSecret,
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
