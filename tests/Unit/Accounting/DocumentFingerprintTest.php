<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Accounting\DocumentFingerprint;
use App\Accounting\FinancialDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentFingerprintTest extends TestCase
{
    /** @param  array<string, mixed>  $overrides */
    private function document(array $overrides = []): FinancialDocument
    {
        return FinancialDocument::fromArray(array_merge([
            'type' => 'sales_invoice',
            'external_id' => 'INV-1',
            'number' => '2026-001',
            'issue_date' => '2026-06-16',
            'due_date' => '2026-07-16',
            'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'vat_number' => 'NL000099998B57'],
            'lines' => [
                ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21, 'category' => 'omzet'],
                ['description' => 'Reiskosten', 'amount' => 50, 'tax_rate' => 9],
            ],
        ], $overrides));
    }

    public function test_identical_documents_hash_identically(): void
    {
        $this->assertSame(
            DocumentFingerprint::for($this->document()),
            DocumentFingerprint::for($this->document()),
        );
    }

    public function test_the_hash_is_a_sha256_hex_digest(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', DocumentFingerprint::for($this->document()));
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('meaningfulChanges')]
    public function test_a_meaningful_change_changes_the_hash(array $overrides): void
    {
        $this->assertNotSame(
            DocumentFingerprint::for($this->document()),
            DocumentFingerprint::for($this->document($overrides)),
        );
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function meaningfulChanges(): array
    {
        return [
            'ander bedrag' => [['lines' => [
                ['description' => 'Consultancy', 'amount' => 201, 'tax_rate' => 21, 'category' => 'omzet'],
                ['description' => 'Reiskosten', 'amount' => 50, 'tax_rate' => 9],
            ]]],
            'ander btw-tarief' => [['lines' => [
                ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 9, 'category' => 'omzet'],
                ['description' => 'Reiskosten', 'amount' => 50, 'tax_rate' => 9],
            ]]],
            'andere omschrijving' => [['lines' => [
                ['description' => 'Advies', 'amount' => 200, 'tax_rate' => 21, 'category' => 'omzet'],
                ['description' => 'Reiskosten', 'amount' => 50, 'tax_rate' => 9],
            ]]],
            'andere grootboek-hint' => [['lines' => [
                ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21, 'category' => 'kosten'],
                ['description' => 'Reiskosten', 'amount' => 50, 'tax_rate' => 9],
            ]]],
            'regel verwijderd' => [['lines' => [
                ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21, 'category' => 'omzet'],
            ]]],
            'ander doctype' => [['type' => 'credit_note']],
            'andere partij' => [['party' => ['role' => 'debtor', 'name' => 'Andere BV']]],
            'ander factuurnummer' => [['number' => '2026-002']],
            'andere factuurdatum' => [['issue_date' => '2026-06-17']],
            'andere vervaldatum' => [['due_date' => '2026-08-16']],
            'andere valuta' => [['currency' => 'USD']],
            'prijzen inclusief btw' => [['prices_include_tax' => true]],
            'andere referentie' => [['reference' => 'PO-123']],
        ];
    }

    public function test_reordering_lines_changes_the_hash(): void
    {
        $reordered = $this->document(['lines' => [
            ['description' => 'Reiskosten', 'amount' => 50, 'tax_rate' => 9],
            ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21, 'category' => 'omzet'],
        ]]);

        $this->assertNotSame(DocumentFingerprint::for($this->document()), DocumentFingerprint::for($reordered));
    }

    public function test_equivalent_float_notations_hash_identically(): void
    {
        $a = $this->document(['lines' => [
            ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21, 'category' => 'omzet'],
            ['description' => 'Reiskosten', 'amount' => 50, 'tax_rate' => 9],
        ]]);
        $b = $this->document(['lines' => [
            ['description' => 'Consultancy', 'amount' => 200.00, 'tax_rate' => 21.0, 'category' => 'omzet'],
            ['description' => 'Reiskosten', 'amount' => 50.000, 'tax_rate' => 9.00],
        ]]);

        $this->assertSame(DocumentFingerprint::for($a), DocumentFingerprint::for($b));
    }

    public function test_attachment_content_affects_the_hash_without_being_embedded(): void
    {
        $withA = $this->document(['attachments' => [
            ['filename' => 'f.pdf', 'mime_type' => 'application/pdf', 'content' => base64_encode(str_repeat('A', 100_000))],
        ]]);
        $withB = $this->document(['attachments' => [
            ['filename' => 'f.pdf', 'mime_type' => 'application/pdf', 'content' => base64_encode(str_repeat('B', 100_000))],
        ]]);

        $this->assertNotSame(DocumentFingerprint::for($withA), DocumentFingerprint::for($withB));
        $this->assertNotSame(DocumentFingerprint::for($this->document()), DocumentFingerprint::for($withA));
        $this->assertLessThan(2000, strlen(DocumentFingerprint::canonicalPayload($withA)));
    }

    public function test_attachment_filename_is_part_of_the_identity(): void
    {
        $content = base64_encode('same-bytes');

        $one = $this->document(['attachments' => [['filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'content' => $content]]]);
        $two = $this->document(['attachments' => [['filename' => 'b.pdf', 'mime_type' => 'application/pdf', 'content' => $content]]]);

        $this->assertNotSame(DocumentFingerprint::for($one), DocumentFingerprint::for($two));
    }
}
