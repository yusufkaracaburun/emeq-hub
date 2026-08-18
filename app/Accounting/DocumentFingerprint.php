<?php

declare(strict_types=1);

namespace App\Accounting;

final class DocumentFingerprint
{
    public static function for(FinancialDocument $document): string
    {
        return hash('sha256', self::canonicalPayload($document));
    }

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
            'attachments' => array_map(static fn (Attachment $attachment): array => [
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mimeType,
                'content_sha256' => hash('sha256', $attachment->content),
            ], $document->attachments),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
