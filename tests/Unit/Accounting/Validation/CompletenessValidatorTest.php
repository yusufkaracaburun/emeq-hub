<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Validation;

use App\Accounting\Validation\Severity;
use App\Accounting\Validation\Validators\CompletenessValidator;
use PHPUnit\Framework\TestCase;

class CompletenessValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'type' => 'sales_invoice',
            'external_id' => 'invoice-1',
            'issue_date' => '2026-08-13',
            'party' => [
                'role' => 'debtor',
                'name' => 'Voorbeeld Debiteur B.V.',
                'kind' => 'company',
                'external_id' => 'debtor-1',
                'chamber_of_commerce' => '12345678',
            ],
            'lines' => [['description' => 'Advieswerk', 'amount' => 100.0, 'tax_rate' => 21]],
        ];
    }

    public function test_a_complete_document_produces_no_finding(): void
    {
        $this->assertSame([], (new CompletenessValidator)->validate($this->document()));
    }

    public function test_an_empty_draft_is_not_silently_bookable(): void
    {
        $findings = (new CompletenessValidator)->validate([]);

        $bySeverity = [];

        foreach ($findings as $finding) {
            $bySeverity[$finding->severity->value][] = $finding->code;
        }

        $this->assertSame(
            ['document.type.missing', 'document.party.missing', 'document.lines.missing'],
            $bySeverity['error'] ?? [],
        );

        $this->assertSame(
            ['document.external_id.missing', 'document.issue_date.missing'],
            $bySeverity['warning'] ?? [],
        );

        foreach ($findings as $finding) {
            if ($finding->severity === Severity::Warning) {
                $this->assertFalse($finding->blocking, "{$finding->code} should not be blocking");
            } else {
                $this->assertTrue($finding->blocking, "{$finding->code} should be blocking");
            }
        }
    }

    public function test_a_draft_without_the_fields_added_at_booking_time_stays_valid(): void
    {
        $document = $this->document();
        unset($document['external_id'], $document['issue_date']);

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertCount(2, $findings);

        foreach ($findings as $finding) {
            $this->assertSame(Severity::Warning, $finding->severity);
            $this->assertFalse($finding->blocking);
        }
    }

    public function test_a_document_without_lines_is_an_error(): void
    {
        $document = $this->document();
        $document['lines'] = [];

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertCount(1, $findings);
        $this->assertSame('document.lines.missing', $findings[0]->code);
        $this->assertSame(Severity::Error, $findings[0]->severity);
        $this->assertTrue($findings[0]->blocking);
    }

    public function test_a_type_outside_the_canonical_set_is_an_error(): void
    {
        $document = $this->document();
        $document['type'] = 'not_a_type';

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertCount(1, $findings);
        $this->assertSame('document.type.unknown', $findings[0]->code);
        $this->assertSame('not_a_type', $findings[0]->current);
        $this->assertStringContainsString('sales_invoice', (string) $findings[0]->suggestion);
        $this->assertTrue($findings[0]->blocking);
    }

    public function test_a_party_without_a_name_is_an_error(): void
    {
        $document = $this->document();
        $document['party'] = ['role' => 'debtor', 'kind' => 'company', 'external_id' => 'debtor-1', 'chamber_of_commerce' => '12345678'];

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertCount(1, $findings);
        $this->assertSame('document.party.name.missing', $findings[0]->code);
        $this->assertTrue($findings[0]->blocking);
    }

    public function test_a_party_role_outside_the_canonical_set_is_an_error(): void
    {
        $document = $this->document();
        $document['party']['role'] = 'supplier';

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertCount(1, $findings);
        $this->assertSame('document.party.role.unknown', $findings[0]->code);
        $this->assertTrue($findings[0]->blocking);
    }

    public function test_a_missing_party_role_is_a_warning_and_not_blocking(): void
    {
        $document = $this->document();
        unset($document['party']['role']);

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertCount(1, $findings);
        $this->assertSame('document.party.role.missing', $findings[0]->code);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
        $this->assertFalse($findings[0]->blocking);
    }

    public function test_a_line_missing_what_the_booking_needs_is_an_error_per_field(): void
    {
        $document = $this->document();
        $document['lines'] = [
            ['description' => 'Advieswerk', 'amount' => 100.0, 'tax_rate' => 21],
            ['amount' => 50.0],
        ];

        $findings = (new CompletenessValidator)->validate($document);

        $codes = array_map(fn ($finding): string => $finding->code, $findings);
        $paths = array_map(fn ($finding): string => $finding->path, $findings);

        $this->assertSame(['document.line.description.missing', 'document.line.tax_rate.missing'], $codes);
        $this->assertSame(['lines.1.description', 'lines.1.tax_rate'], $paths);
    }

    public function test_a_missing_party_kind_is_a_warning_and_not_blocking(): void
    {
        $document = $this->document();
        unset($document['party']['kind']);

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertCount(1, $findings);
        $this->assertSame('document.party.kind.missing', $findings[0]->code);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
        $this->assertFalse($findings[0]->blocking);
    }

    public function test_a_missing_party_external_id_is_a_warning_and_not_blocking(): void
    {
        $document = $this->document();
        unset($document['party']['external_id']);

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertCount(1, $findings);
        $this->assertSame('document.party.external_id.missing', $findings[0]->code);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
        $this->assertFalse($findings[0]->blocking);
    }

    public function test_a_company_party_without_chamber_of_commerce_or_vat_number_is_a_warning(): void
    {
        $document = $this->document();
        unset($document['party']['chamber_of_commerce']);

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertCount(1, $findings);
        $this->assertSame('document.party.identifier.missing', $findings[0]->code);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
        $this->assertFalse($findings[0]->blocking);
    }

    public function test_a_company_party_with_a_vat_number_but_no_chamber_of_commerce_produces_no_identifier_finding(): void
    {
        $document = $this->document();
        unset($document['party']['chamber_of_commerce']);
        $document['party']['vat_number'] = 'NL000099998B57';

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertSame([], $findings);
    }

    public function test_a_person_party_without_chamber_of_commerce_or_vat_number_produces_no_identifier_finding(): void
    {
        $document = $this->document();
        unset($document['party']['chamber_of_commerce']);
        $document['party']['kind'] = 'person';

        $findings = (new CompletenessValidator)->validate($document);

        $this->assertSame([], $findings);
    }

    public function test_an_ocr_draft_summary_is_not_mistaken_for_a_document(): void
    {
        $findings = (new CompletenessValidator)->validate([
            'subtotal' => 100.0,
            'tax_total' => 21.0,
            'total' => 121.0,
        ]);

        $codes = array_map(fn ($finding): string => $finding->code, $findings);

        $this->assertContains('document.lines.missing', $codes);
    }
}
