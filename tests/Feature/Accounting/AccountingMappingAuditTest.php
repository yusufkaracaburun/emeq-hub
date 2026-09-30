<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AccountingMappingAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $metadata */
    private function connection(array $metadata): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();

        return Connection::factory()->forExact()->for($account)->create(['metadata' => $metadata]);
    }

    public function test_logs_one_line_per_changed_mapping_key(): void
    {
        $connection = $this->connection([
            'division' => 1,
            'accounting_mapping' => [
                'gl_accounts' => ['omzet' => '8000', 'kosten' => '4000'],
                'journals' => ['sales' => '70'],
            ],
        ]);
        $user = User::factory()->create();
        $this->actingAs($user);
        Log::spy();

        $connection->metadata = [
            'division' => 2,
            'accounting_mapping' => [
                'gl_accounts' => ['omzet' => '8100', 'kosten' => '4000', 'huisvesting' => '4300'],
                'journals' => [],
            ],
        ];
        $connection->save();

        $expected = [
            ['gl_accounts', 'omzet', '8000', '8100'],
            ['gl_accounts', 'huisvesting', null, '4300'],
            ['journals', 'sales', '70', null],
        ];

        foreach ($expected as [$section, $key, $old, $new]) {
            Log::shouldHaveReceived('info')->with('accounting.mapping.changed', [
                'connection_id' => $connection->getKey(),
                'section' => $section,
                'key' => $key,
                'old' => $old,
                'new' => $new,
                'actor' => 'user:'.$user->getKey(),
            ])->once();
        }

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message): bool => $message === 'accounting.mapping.changed')
            ->times(3);
    }

    public function test_logs_nothing_when_mapping_is_unchanged(): void
    {
        $connection = $this->connection([
            'division' => 1,
            'accounting_mapping' => ['gl_accounts' => ['omzet' => '8000']],
        ]);
        Log::spy();

        $connection->metadata = [
            'division' => 2,
            'accounting_mapping' => ['gl_accounts' => ['omzet' => '8000']],
        ];
        $connection->save();

        Log::shouldNotHaveReceived('info', ['accounting.mapping.changed', \Mockery::any()]);
    }

    public function test_actor_is_null_without_an_authenticated_user(): void
    {
        $connection = $this->connection([]);
        Log::spy();

        $connection->metadata = ['accounting_mapping' => ['gl_accounts' => ['_default' => '4999']]];
        $connection->save();

        Log::shouldHaveReceived('info')->with('accounting.mapping.changed', [
            'connection_id' => $connection->getKey(),
            'section' => 'gl_accounts',
            'key' => '_default',
            'old' => null,
            'new' => '4999',
            'actor' => null,
        ])->once();
    }
}
