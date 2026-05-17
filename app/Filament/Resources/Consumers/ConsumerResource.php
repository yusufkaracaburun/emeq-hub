<?php

namespace App\Filament\Resources\Consumers;

use App\Filament\Resources\Consumers\Pages\CreateConsumer;
use App\Filament\Resources\Consumers\Pages\EditConsumer;
use App\Filament\Resources\Consumers\Pages\ListConsumers;
use App\Filament\Resources\Consumers\Pages\ViewConsumer;
use App\Filament\Resources\Consumers\Schemas\ConsumerInfolist;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class ConsumerResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Tenants';

    protected static ?int $navigationSort = 1;

    public const ISSUE_PAT_ACTION = 'issuePat';

    /**
     * Preset-shape per D-03: slug-keyed map met label + abilities.
     * Unie van alle preset-abilities + PAT_CUSTOM_ONLY MOET TokenAbilities::all() afdekken
     * (regressie-vangnet via PatAbilityPresetsTest).
     *
     * @var array<string, array{label: string, abilities: list<string>}>
     */
    public const PAT_PRESETS = [
        'mollie-read' => [
            'label' => 'Mollie read-only',
            'abilities' => [TokenAbilities::MOLLIE_READ],
        ],
        'mollie-write' => [
            'label' => 'Mollie read+write',
            'abilities' => [
                TokenAbilities::MOLLIE_READ,
                TokenAbilities::MOLLIE_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'snelstart-read' => [
            'label' => 'Snelstart read-only',
            'abilities' => [TokenAbilities::SNELSTART_READ],
        ],
        'snelstart-write' => [
            'label' => 'Snelstart read+write',
            'abilities' => [
                TokenAbilities::SNELSTART_READ,
                TokenAbilities::SNELSTART_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'admin' => [
            'label' => 'Admin',
            'abilities' => [TokenAbilities::ADMIN],
        ],
    ];

    /**
     * Abilities die NIET in een preset zitten en alleen via custom-mode uitgereikt worden.
     *
     * @var list<string>
     */
    public const PAT_CUSTOM_ONLY = [
        TokenAbilities::BILLING_READ,
        TokenAbilities::BILLING_WRITE,
    ];

    protected static ?string $model = Consumer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-consumers') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('webhook_callback_url')
                    ->label('Webhook callback-URL')
                    ->url()
                    ->maxLength(255)
                    ->helperText('Endpoint waar de Hub partner-events naartoe POSTed. Leeg = geen fan-out.'),
                TextInput::make('webhook_callback_secret')
                    ->label('Webhook callback-secret')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('Encrypted at rest. Laat leeg om bestaande secret te behouden.'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ConsumerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('accounts_count')
                    ->label('Accounts')
                    ->counts('accounts')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('connections_count')
                    ->label('Connections')
                    ->counts('connections')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::issuePatAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AccountsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsumers::route('/'),
            'create' => CreateConsumer::route('/create'),
            'view' => ViewConsumer::route('/{record}'),
            'edit' => EditConsumer::route('/{record}/edit'),
        ];
    }

    /**
     * Issue-PAT table-action (D-03): modal met preset-radio + custom-mode-CheckboxList.
     * Submit → $consumer->createToken() + chain naar viewPatToken-action voor copy-paste UX.
     */
    private static function issuePatAction(): Action
    {
        return Action::make(self::ISSUE_PAT_ACTION)
            ->label('Issue PAT')
            ->icon(Heroicon::OutlinedKey)
            ->modalHeading('Issue Personal Access Token')
            ->modalSubmitActionLabel('Issue')
            ->schema([
                TextInput::make('name')
                    ->label('Token name')
                    ->required()
                    ->maxLength(255),
                Radio::make('preset')
                    ->label('Preset')
                    ->options(self::presetRadioOptions())
                    ->default('mollie-read')
                    ->required()
                    ->live(),
                CheckboxList::make('abilities')
                    ->label('Abilities')
                    ->options(self::customAbilitiesOptions())
                    ->required()
                    ->visible(fn (Get $get): bool => $get('preset') === 'custom'),
            ])
            ->action(function (Consumer $record, array $data, $livewire): void {
                $abilities = $data['preset'] === 'custom'
                    ? array_values($data['abilities'] ?? [])
                    : self::PAT_PRESETS[$data['preset']]['abilities'];

                $result = $record->createToken($data['name'], $abilities);

                // D-9 (WR-06): plain token gaat via server-side Cache flash naar de blade-view.
                // De blade Cache::pull't beide keys one-shot bij de eerstvolgende render —
                // het token verschijnt nooit in Livewire's wire:snapshot of in Alpine x-data.
                $livewireId = $livewire->getId();
                Cache::put("pat-flash:{$livewireId}", $result->plainTextToken, now()->addSeconds(60));
                Cache::put("pat-flash-name:{$livewireId}", $data['name'], now()->addSeconds(60));

                Notification::make()
                    ->title('PAT uitgegeven — token verschijnt eenmalig bovenaan de listing')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, string>
     */
    private static function presetRadioOptions(): array
    {
        $options = [];
        foreach (self::PAT_PRESETS as $slug => $entry) {
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
