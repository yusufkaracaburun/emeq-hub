<?php

declare(strict_types=1);

namespace App\Webhooks\Exact;

use App\Webhooks\CanonicalEvent;
use App\Webhooks\Contracts\ResolvesCanonicalEvent;

/**
 * Exact beschrijft z'n events met een `Topic`. De Hub abonneert op precies de
 * topics uit `config('services.exact.webhook_topics')` — vandaag BankEntries en
 * CashEntries — dus verder gaat deze tabel niet.
 *
 * `Action` (Create/Update/Delete) blijft in `data`: de canonieke naam zegt dát er
 * iets veranderde, niet wat. Zodra een consumer op verwijderingen moet kunnen
 * reageren is dat een eigen event-naam, geen extra veld hier.
 */
final class ExactEventResolver implements ResolvesCanonicalEvent
{
    public function resolve(array $payload): ?string
    {
        $topic = $payload['Content']['Topic'] ?? $payload['Topic'] ?? null;

        return match ($topic) {
            'BankEntries' => CanonicalEvent::BANK_STATEMENT_CHANGED,
            'CashEntries' => CanonicalEvent::CASH_STATEMENT_CHANGED,
            default => null,
        };
    }
}
