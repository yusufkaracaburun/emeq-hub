<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Http\Middleware\EnsureIdempotency;

/**
 * Semantische vingerafdruk van een canoniek document: identiek document → identieke
 * hash, inhoudelijk gewijzigd document → andere hash.
 *
 * Bewust gescheiden van de raw-body-hash in {@see EnsureIdempotency}.
 * Die hasht de HTTP-bytes en beantwoordt "is dit exact hetzelfde request?"; deze hasht
 * de bedrijfsbetekenis en beantwoordt "is dit hetzelfde document?". Sleutelvolgorde,
 * whitespace en `200` versus `200.00` mogen het antwoord op de tweede vraag niet
 * veranderen.
 *
 * **Regelvolgorde telt mee.** Exact kent geen update-pad voor een geboekte entry, dus
 * omgekeerde regels zijn een andere boeking en geen equivalente weergave. Niet
 * "oplossen" met een sort.
 */
final class DocumentFingerprint
{
    public static function for(FinancialDocument $document): string
    {
        return hash('sha256', self::canonicalPayload($document));
    }

    /**
     * De genormaliseerde JSON waarover gehasht wordt. Publiek omdat een mismatch
     * anders niet te debuggen is zonder de klasse open te breken.
     */
    public static function canonicalPayload(FinancialDocument $document): string
    {
        return (string) json_encode([
            'type' => $document->type->value,
            'external_id' => $document->externalId,
            'number' => $document->number,
            'reference' => $document->reference,
            'currency' => $document->currency,
            'prices_include_tax' => $document->pricesIncludeTax,
            'issue_date' => $document->issueDate->format('Y-m-d'),
            'due_date' => $document->dueDate?->format('Y-m-d'),
            'party' => [
                'role' => $document->party->role,
                'name' => $document->party->name,
                'vat_number' => $document->party->vatNumber,
                'iban' => $document->party->iban,
                'external_id' => $document->party->externalId,
            ],
            'lines' => array_map(static fn (FinancialDocumentLine $line): array => [
                'description' => $line->description,
                'amount' => self::money($line->amount),
                'tax_rate' => self::money($line->taxRate),
                'tax_treatment' => $line->taxTreatment->value,
                'quantity' => $line->quantity === null ? null : self::money($line->quantity),
                'unit_price' => $line->unitPrice === null ? null : self::money($line->unitPrice),
                'category' => $line->category,
                'cost_center' => $line->costCenter,
                'cost_unit' => $line->costUnit,
            ], $document->lines),
            // Inhoud als hash: de bijlage telt mee voor de identiteit, maar de
            // hash-input mag niet meegroeien met een payload van 1,4 MB.
            'attachments' => array_map(static fn (Attachment $attachment): array => [
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mimeType,
                'content_sha256' => hash('sha256', $attachment->content),
            ], $document->attachments),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Bedragen op twee decimalen als string: `200`, `200.0` en `200.00` zijn hetzelfde
     * bedrag, maar hebben een verschillende float-representatie in JSON.
     */
    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
