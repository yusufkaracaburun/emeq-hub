<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Accounting\Exact\ExactMappingDeriver;
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

        // BTW op percentage, voorkeur exclusief (code 3, niet de inclusief 4).
        $this->assertSame(['21' => '3', '9' => '1', '0' => '0'], $mapping['vat_codes']);
        // Dagboek op Type: 20 → verkoop, 22 → inkoop.
        $this->assertSame(['sales' => '80', 'purchase' => '70'], $mapping['journals']);
        // GL-default: 8xxx omzet, 4xxx kosten/_default.
        $this->assertSame(['omzet' => '8000', 'kosten' => '4000', '_default' => '4000'], $mapping['gl_accounts']);
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
}
