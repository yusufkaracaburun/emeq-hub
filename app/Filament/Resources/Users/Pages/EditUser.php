<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (User $record, Action $action): void {
                    if ($record->id === auth()->id()) {
                        Notification::make()
                            ->title('Je kunt jezelf niet verwijderen')
                            ->danger()
                            ->send();

                        $action->halt();
                    }

                    if (
                        $record->hasRole('super-admin')
                        && User::role('super-admin')->where('id', '!=', $record->id)->count() === 0
                    ) {
                        Notification::make()
                            ->title('Kan laatste super-admin niet verwijderen')
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }
}
