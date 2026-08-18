<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\ManualJournals\Pages;

use App\Books\Enums\JournalEntryType;
use App\Books\Services\ManualJournalPoster;
use App\Filament\Books\Resources\ManualJournals\ManualJournalResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateManualJournal extends CreateRecord
{
    protected static string $resource = ManualJournalResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $lines = collect($data['lines'] ?? [])
            ->values()
            ->map(fn (array $line): array => [
                'account_id' => (int) $line['account_id'],
                'type' => JournalEntryType::from((string) $line['type']),
                'amount' => (int) $line['amount'],
                'description' => $line['description'] ?? null,
            ])
            ->all();

        try {
            return app(ManualJournalPoster::class)->post($lines, [
                'posted_at' => $data['posted_at'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['data.lines' => $e->getMessage()]);
        }
    }
}
