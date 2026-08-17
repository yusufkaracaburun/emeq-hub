<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Request-scoped verzamelaar voor niet-blokkerende meldingen tijdens een boeking
 * (bv. `relation.created`) — landt als `warnings[]` op het boek-antwoord. Scoped
 * i.p.v. singleton gebonden (zie AppServiceProvider) zodat Octane niets laat lekken
 * tussen requests; {@see AccountingSyncRunner::push()} flusht 'm aan het begin van
 * elke push.
 *
 * `context` draagt de machine-leesbare kant (relatie-GUID, aangeleverde/gevonden
 * naam) zodat de consumer niet op de vrije `message`-tekst hoeft te parsen — zelfde
 * verdeling als `Finding::current`/`suggestion`.
 */
final class BookingWarnings
{
    /**
     * @var list<array{code: string, message: string, context: array<string, mixed>}>
     */
    private array $items = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function add(string $code, string $message, array $context = []): void
    {
        $this->items[] = ['code' => $code, 'message' => $message, 'context' => $context];
    }

    /**
     * @return list<array{code: string, message: string, context: array<string, mixed>}>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function flush(): void
    {
        $this->items = [];
    }
}
