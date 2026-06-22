<?php

declare(strict_types=1);

namespace App\Filament\Books\RelationManagers;

use App\Books\Models\Attachment;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Bijlagen op Invoice/Bill. Gedeeld door beide resources. Bestanden op een
 * private disk (financiële documenten, AVG); download streamt met auth (het
 * paneel is al gate'd op boekhouder/super-admin). Size/mime/uploader vult het
 * Attachment-model zelf in.
 */
class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Bijlagen';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')
                ->label('Bestand')
                ->disk('local')
                ->visibility('private')
                ->directory('books/attachments')
                ->storeFileNamesIn('original_name')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->maxSize(10240)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->columns([
                TextColumn::make('original_name')
                    ->label('Bestand')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('size')
                    ->label('Grootte')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 0, ',', '.').' KB')
                    ->alignEnd(),

                TextColumn::make('uploader.name')
                    ->label('Door')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Toegevoegd')
                    ->dateTime('d-m-Y H:i'),
            ])
            ->headerActions([
                CreateAction::make()->label('Bijlage toevoegen'),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (Attachment $record): StreamedResponse => Storage::disk($record->disk)
                        ->download($record->path, $record->original_name)),

                DeleteAction::make(),
            ]);
    }
}
