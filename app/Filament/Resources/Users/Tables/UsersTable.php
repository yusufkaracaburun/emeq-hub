<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/*
 * Plan 09-10: UserResource table.
 *
 * Kolommen: email, roles (Spatie pluck), created_at.
 * Custom record-action `assignRole` synced ÉÉN rol op de User via Spatie
 * `syncRoles([$role])` — per D-05 ontwerp is een User altijd super-admin OF staff,
 * niet beide. `syncRoles` is daarom de juiste semantic (assignRole zou stacken).
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('roles')
                    ->label('Rollen')
                    ->state(fn (User $record): string => $record->roles->pluck('name')->join(', ')),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                Action::make('assignRole')
                    ->label('Wijs rol toe')
                    ->icon('heroicon-o-shield-check')
                    ->iconButton()
                    ->schema([
                        Select::make('role')
                            ->label('Rol')
                            ->options([
                                'super-admin' => 'Super admin',
                                'staff' => 'Staff',
                            ])
                            ->in(['super-admin', 'staff'])
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        if ($record->id === auth()->id() && $data['role'] !== 'super-admin') {
                            Notification::make()
                                ->title('Je kunt jezelf niet downgraden')
                                ->danger()
                                ->send();

                            return;
                        }

                        if (
                            $record->hasRole('super-admin')
                            && $data['role'] !== 'super-admin'
                            && User::role('super-admin')->where('id', '!=', $record->id)->count() === 0
                        ) {
                            Notification::make()
                                ->title('Kan laatste super-admin niet downgraden')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $record->syncRoles([$data['role']]);
                        } catch (RoleDoesNotExist) {
                            Notification::make()
                                ->title('Onbekende rol')
                                ->body('De geselecteerde rol bestaat niet.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Rol toegewezen')
                            ->body("{$record->email} → {$data['role']}")
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
