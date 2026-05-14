<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionUniqueActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_active_snelstart_connection_for_same_account_is_rejected(): void
    {
        $account = Account::factory()->create();

        Connection::factory()->forSnelstart()->create(['account_id' => $account->id]);

        $this->expectException(QueryException::class);

        Connection::factory()->forSnelstart()->create(['account_id' => $account->id]);
    }

    public function test_second_connection_allowed_after_first_revoked(): void
    {
        $account = Account::factory()->create();

        $first = Connection::factory()->forMollie()->create(['account_id' => $account->id]);
        $first->update(['revoked_at' => now()]);

        $second = Connection::factory()->forMollie()->create(['account_id' => $account->id]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($second->revoked_at);
    }

    public function test_different_providers_on_same_account_are_allowed(): void
    {
        $account = Account::factory()->create();

        $snelstart = Connection::factory()->forSnelstart()->create(['account_id' => $account->id]);
        $mollie = Connection::factory()->forMollie()->create(['account_id' => $account->id]);

        $this->assertSame('snelstart', $snelstart->provider);
        $this->assertSame('mollie', $mollie->provider);
    }
}
