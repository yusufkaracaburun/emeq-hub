<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                EditAction::make(),
                Action::make('assignRole')
                    ->label('Wijs rol toe')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        Select::make('role')
                            ->label('Rol')
                            ->options([
                                'super-admin' => 'Super admin',
                                'staff' => 'Staff',
                            ])
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $record->syncRoles([$data['role']]);

                        Notification::make()
                            ->title('Rol toegewezen')
                            ->body("{$record->email} → {$data['role']}")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
