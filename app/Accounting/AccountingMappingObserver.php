<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Models\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class AccountingMappingObserver
{
    public function updating(Connection $connection): void
    {
        if (! $connection->isDirty('metadata')) {
            return;
        }

        $old = $this->mapping($connection->getOriginal('metadata'));
        $new = $this->mapping($connection->metadata);

        if ($old === $new) {
            return;
        }

        $user = Auth::user();
        $actor = $user !== null ? mb_strtolower(class_basename($user)).':'.$user->getAuthIdentifier() : null;

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $section) {
            $before = is_array($old[$section] ?? null) ? $old[$section] : [];
            $after = is_array($new[$section] ?? null) ? $new[$section] : [];

            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                if (($before[$key] ?? null) === ($after[$key] ?? null)) {
                    continue;
                }

                Log::info('accounting.mapping.changed', [
                    'connection_id' => $connection->getKey(),
                    'section' => (string) $section,
                    'key' => (string) $key,
                    'old' => $before[$key] ?? null,
                    'new' => $after[$key] ?? null,
                    'actor' => $actor,
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function mapping(mixed $metadata): array
    {
        $mapping = is_array($metadata) ? ($metadata['accounting_mapping'] ?? []) : [];

        return is_array($mapping) ? $mapping : [];
    }
}
