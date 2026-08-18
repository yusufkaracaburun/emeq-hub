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

class OnboardConsumer extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Koppelingen';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Onboarden';

    protected static ?string $title = 'Nieuwe Consumer onboarden';

    protected string $view = 'filament.pages.onboard-consumer';

    /** @var array<string, mixed> */
    public array $data = [];

    public ?int $fromRequest = null;

    public function mount(): void
    {
        $this->form->fill($this->prefillFromAccessRequest());
    }

    /** @return array<string, mixed> */
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
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: bool} [secret, autoGenerated]
     */
    private static function resolveWebhookSecret(array $data): array
    {
        $webhookSecret = $data['webhook_callback_secret'] ?? null;

        if ($webhookSecret === null || $webhookSecret === '') {
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function onboardOrNotify(array $payload): ?array
    {
        try {
            return app(ConsumerOnboarding::class)->onboard($payload);
        } catch (\InvalidArgumentException $e) {
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

    /** @return array<string, string> */
    private static function customAbilitiesOptions(): array
    {
        $options = [];
        foreach (TokenAbilities::all() as $ability) {
            $options[$ability] = $ability;
        }

        return $options;
    }
}
