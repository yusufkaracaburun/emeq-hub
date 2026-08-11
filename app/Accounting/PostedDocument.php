<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Enums\DocumentType;
use Carbon\CarbonImmutable;

/**
 * Een document zoals het in de boekhouding stáát.
 *
 * Bewust een ander type dan {@see FinancialDocument}: dat is wat je stuurt, dit is wat
 * er ligt. De verschillen zijn echt — een geboekt document heeft een partner-identiteit
 * en een boekstuknummer, en mist de bijlagen en de vrije `category`-hint die alleen bij
 * het schrijven bestaan. Eén type voor allebei zou op elk veld een "geldt alleen bij
 * lezen/schrijven"-slag om de arm nodig hebben.
 *
 * `id` is ondoorzichtig. `externalId` is jóuw sleutel, teruggelezen uit de provenance
 * die de Hub bij het boeken meeschreef — leeg voor documenten die buiten de Hub om zijn
 * ingevoerd.
 */
final readonly class PostedDocument
{
    /**
     * @param  list<PostedDocumentLine>  $lines
     */
    public function __construct(
        public string $id,
        public DocumentType $type,
        public array $lines,
        public ?string $number = null,
        public ?string $externalId = null,
        public ?CarbonImmutable $issueDate = null,
        public ?CarbonImmutable $dueDate = null,
        public ?string $reference = null,
        public ?string $partyId = null,
        public ?string $partyName = null,
        public ?string $journal = null,
        public string $currency = 'EUR',
    ) {}

    /**
     * Netto totaal uit de regels, niet uit een header-veld van de partner.
     *
     * Dat is bewust: het regelbedrag is wat de Hub zelf gestuurd heeft en wat elke
     * provider levert, terwijl een header-totaal per pakket een andere betekenis heeft
     * (met/zonder btw, in valuta of in administratie-valuta).
     */
    public function netTotal(): float
    {
        return round(array_sum(array_map(
            static fn (PostedDocumentLine $line): float => $line->amount,
            $this->lines,
        )), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'number' => $this->number,
            'external_id' => $this->externalId,
            'issue_date' => $this->issueDate?->format('Y-m-d'),
            'due_date' => $this->dueDate?->format('Y-m-d'),
            'reference' => $this->reference,
            'party' => [
                'id' => $this->partyId,
                'name' => $this->partyName,
            ],
            'journal' => $this->journal,
            'currency' => $this->currency,
            'net_total' => $this->netTotal(),
            'lines' => array_map(
                static fn (PostedDocumentLine $line): array => $line->toArray(),
                $this->lines,
            ),
        ];
    }
}
