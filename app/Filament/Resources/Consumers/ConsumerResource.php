<?php

namespace App\Filament\Resources\Consumers;

use App\Filament\Resources\Consumers\Pages\EditConsumer;
use App\Filament\Resources\Consumers\Pages\ListConsumers;
use App\Filament\Resources\Consumers\Pages\ViewConsumer;
use App\Filament\Resources\Consumers\Schemas\ConsumerInfolist;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
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
    protected static string|\UnitEnum|null $navigationGroup = 'Koppelingen';

    protected static ?int $navigationSort = 1;

    public const ISSUE_PAT_ACTION = 'issuePat';

    /**
     * Preset-shape per D-03: slug-keyed map met group + label + abilities.
     * Unie van alle preset-abilities + PAT_CUSTOM_ONLY MOET TokenAbilities::all() afdekken
     * (regressie-vangnet via PatAbilityPresetsTest).
     *
     * `koppelen + read/write` bestaat omdat dát de normale consumer-token is: eerst de
     * OAuth-flow starten (integrations:manage), daarna calls doen. Zonder die preset moest
     * je voor het meest voorkomende geval naar Custom.
     *
     * Voor boekhouding staat de provider-onafhankelijke groep bovenaan: die tokens
     * noemen geen provider en blijven daarom geldig als een eindgebruiker van Exact
     * naar een ander pakket verhuist. De `exact-*`/`snelstart-*`-presets blijven voor
     * de ruwe pass-through, die wél providerspecifiek is.
     *
     * @var array<string, array{group: string, label: string, abilities: list<string>}>
     */
    public const PAT_PRESETS = [
        'accounting-read' => [
            'group' => 'Boekhouding (provider-onafhankelijk)',
            'label' => 'Read-only',
            'abilities' => [TokenAbilities::ACCOUNTING_READ],
        ],
        'accounting-write' => [
            'group' => 'Boekhouding (provider-onafhankelijk)',
            'label' => 'Read + write',
            'abilities' => [
                TokenAbilities::ACCOUNTING_READ,
                TokenAbilities::ACCOUNTING_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'accounting-connect' => [
            'group' => 'Boekhouding (provider-onafhankelijk)',
            'label' => 'Koppelen + read/write',
            'abilities' => [
                TokenAbilities::INTEGRATIONS_MANAGE,
                TokenAbilities::ACCOUNTING_READ,
                TokenAbilities::ACCOUNTING_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'exact-read' => [
            'group' => 'Exact Online',
            'label' => 'Read-only',
            'abilities' => [TokenAbilities::EXACT_READ],
        ],
        'exact-write' => [
            'group' => 'Exact Online',
            'label' => 'Read + write',
            'abilities' => [
                TokenAbilities::EXACT_READ,
                TokenAbilities::EXACT_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'exact-connect' => [
            'group' => 'Exact Online',
            'label' => 'Koppelen + read/write',
            'abilities' => [
                TokenAbilities::INTEGRATIONS_MANAGE,
                TokenAbilities::EXACT_READ,
                TokenAbilities::EXACT_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'mollie-read' => [
            'group' => 'Mollie',
            'label' => 'Read-only',
            'abilities' => [TokenAbilities::MOLLIE_READ],
        ],
        'mollie-write' => [
            'group' => 'Mollie',
            'label' => 'Read + write',
            'abilities' => [
                TokenAbilities::MOLLIE_READ,
                TokenAbilities::MOLLIE_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'mollie-connect' => [
            'group' => 'Mollie',
            'label' => 'Koppelen + read/write',
            'abilities' => [
                TokenAbilities::INTEGRATIONS_MANAGE,
                TokenAbilities::MOLLIE_READ,
                TokenAbilities::MOLLIE_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'snelstart-read' => [
            'group' => 'Snelstart',
            'label' => 'Read-only',
            'abilities' => [TokenAbilities::SNELSTART_READ],
        ],
        'snelstart-write' => [
            'group' => 'Snelstart',
            'label' => 'Read + write',
            'abilities' => [
                TokenAbilities::SNELSTART_READ,
                TokenAbilities::SNELSTART_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'snelstart-connect' => [
            'group' => 'Snelstart',
            'label' => 'Koppelen + read/write',
            'abilities' => [
                TokenAbilities::INTEGRATIONS_MANAGE,
                TokenAbilities::SNELSTART_READ,
                TokenAbilities::SNELSTART_WRITE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'integrations' => [
            'group' => 'Overig',
            'label' => 'Alleen koppelen (alle providers)',
            'abilities' => [
                TokenAbilities::INTEGRATIONS_MANAGE,
                TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            ],
        ],
        'admin' => [
            'group' => 'Overig',
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
                TextInput::make('app_url')
                    ->label('App-URL')
                    ->url()
                    ->maxLength(255)
                    ->helperText('Waar de eindgebruiker na een OAuth-connect terugkeert (root van de consumer-app). Leeg = terugval op Hub-admin.'),
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
            ->recordUrl(fn (Consumer $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()->iconButton(),
                self::issuePatAction()->iconButton(),
                DeleteAction::make()->iconButton(),
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
            RelationManagers\TokensRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            // Geen 'create': een Consumer ontstaat alleen via de onboard-wizard, die
            // ook de PAT en app_url zet. Een kale create-route leverde een Consumer op
            // die niets kon koppelen.
            'index' => ListConsumers::route('/'),
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
                Select::make('preset')
                    ->label('Preset')
                    ->options(self::presetOptions())
                    ->native(false)
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
     * Gegroepeerd per provider — de vlakke lijst liet je zoeken tussen negen opties
     * die per ability geordend waren in plaats van per taak.
     *
     * @return array<string, array<string, string>>
     */
    public static function presetOptions(): array
    {
        $options = [];
        foreach (self::PAT_PRESETS as $slug => $entry) {
            $options[$entry['group']][$slug] = $entry['label'];
        }
        $options['Overig']['custom'] = 'Custom…';

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
