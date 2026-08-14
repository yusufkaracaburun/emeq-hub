<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Integrations\Exact\Accounting\ExactMappingDeriver;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExactMappingDeriverTest extends TestCase
{
    use RefreshDatabase;

    private function connectionWithMirror(): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();
        $connection = Connection::factory()->forExact()->for($account)->create();

        $rows = [
            ['kind' => 'vat', 'code' => '3', 'native_id' => '3', 'label' => 'BTW hoog excl', 'attrs' => ['percentage' => 21.0]],
            ['kind' => 'vat', 'code' => '4', 'native_id' => '4', 'label' => 'BTW hoog incl', 'attrs' => ['percentage' => 21.0]],
            ['kind' => 'vat', 'code' => '1', 'native_id' => '1', 'label' => 'BTW laag excl', 'attrs' => ['percentage' => 9.0]],
            ['kind' => 'vat', 'code' => '0', 'native_id' => '0', 'label' => 'BTW 0%', 'attrs' => ['percentage' => 0.0]],
            ['kind' => 'vat', 'code' => '6', 'native_id' => '6', 'label' => 'BTW verlegd hoog', 'attrs' => ['percentage' => 21.0]],
            ['kind' => 'vat', 'code' => '7', 'native_id' => '7', 'label' => 'BTW verlegd laag', 'attrs' => ['percentage' => 9.0]],
            ['kind' => 'journal', 'code' => '80', 'native_id' => '80', 'label' => 'Verkoopboek', 'attrs' => ['type' => 20]],
            ['kind' => 'journal', 'code' => '70', 'native_id' => '70', 'label' => 'Inkoopboek', 'attrs' => ['type' => 22]],
            ['kind' => 'gl', 'code' => '8000', 'native_id' => 'gl-8000', 'label' => 'Omzet', 'attrs' => []],
            ['kind' => 'gl', 'code' => '4000', 'native_id' => 'gl-4000', 'label' => 'Kosten', 'attrs' => []],
        ];

        foreach ($rows as $row) {
            ConnectionAccountingRef::query()->create(['connection_id' => $connection->getKey(), ...$row]);
        }

        return $connection;
    }

    public function test_derives_default_mapping_from_mirror(): void
    {
        $connection = $this->connectionWithMirror();

        app(ExactMappingDeriver::class)->deriveAndStore($connection);

        $mapping = $connection->fresh()->metadata['accounting_mapping'];

        // BTW op percentage, voorkeur exclusief (code 3, niet de inclusief 4); verlegd
        // apart afgeleid uit het label naar reverse_charge:tarief (6/7).
        $this->assertSame(
            ['21' => '3', '9' => '1', '0' => '0', 'reverse_charge:21' => '6', 'reverse_charge:9' => '7'],
            $mapping['vat_codes'],
        );
        // Dagboek op Type: 20 → verkoop, 22 → inkoop.
        $this->assertSame(['sales' => '80', 'purchase' => '70'], $mapping['journals']);
        // GL-default: 8xxx omzet/sales_default, 4xxx kosten/purchase_default/_default.
        $this->assertSame(
            ['omzet' => '8000', 'sales_default' => '8000', 'kosten' => '4000', 'purchase_default' => '4000', '_default' => '4000'],
            $mapping['gl_accounts'],
        );
    }

    public function test_does_not_overwrite_existing_override(): void
    {
        $connection = $this->connectionWithMirror();
        $connection->metadata = ['accounting_mapping' => [
            'gl_accounts' => ['omzet' => 'handmatig-gekozen'],
            'vat_codes' => ['21' => '4'],
        ]];
        $connection->save();

        app(ExactMappingDeriver::class)->deriveAndStore($connection);

        $mapping = $connection->fresh()->metadata['accounting_mapping'];

        // Override blijft; alleen ontbrekende keys aangevuld.
        $this->assertSame('handmatig-gekozen', $mapping['gl_accounts']['omzet']);
        $this->assertSame('4000', $mapping['gl_accounts']['kosten']);
        $this->assertSame('4', $mapping['vat_codes']['21']);
        $this->assertSame('1', $mapping['vat_codes']['9']);
    }

    public function test_leaves_missing_default_empty_instead_of_guessing(): void
    {
        // Mirror kent alleen een 8xxx-rekening (geen 4xxx) → purchase_default mag niet
        // geraden worden op de omzetrekening; een fout grootboek is precies de bug (#60).
        $account = Account::factory()->for(Consumer::factory()->create())->create();
        $connection = Connection::factory()->forExact()->for($account)->create();

        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => 'gl',
            'code' => '8000',
            'native_id' => 'gl-8000',
            'label' => 'Omzet',
            'attrs' => [],
        ]);

        app(ExactMappingDeriver::class)->deriveAndStore($connection);

        $mapping = $connection->fresh()->metadata['accounting_mapping'];

        $this->assertSame('8000', $mapping['gl_accounts']['sales_default']);
        $this->assertArrayNotHasKey('purchase_default', $mapping['gl_accounts']);
    }
}
