<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

final class InfoModalAction
{
    public static function make(string $heading, string $body): Action
    {
        return Action::make('info')
            ->label($heading)
            ->icon(Heroicon::OutlinedInformationCircle)
            ->iconButton()
            ->color('gray')
            ->modalHeading($heading)
            ->modalContent(new HtmlString(
                '<p class="text-sm leading-6 text-gray-500 dark:text-gray-400">'.nl2br(e($body)).'</p>'
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten');
    }
}
