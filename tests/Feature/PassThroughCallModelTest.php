<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PassThroughCallModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_row_with_relations(): void
    {
        $call = PassThroughCall::factory()->create();

        $this->assertInstanceOf(Consumer::class, $call->consumer);
        $this->assertInstanceOf(Account::class, $call->account);
        $this->assertInstanceOf(Connection::class, $call->connection);
    }

    public function test_does_not_track_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('pass_through_calls', 'updated_at'));
    }

    public function test_connection_id_survives_connection_delete(): void
    {
        $call = PassThroughCall::factory()->create();
        $connectionId = $call->connection_id;

        $this->assertNotNull($connectionId);

        Connection::query()->whereKey($connectionId)->delete();

        $call->refresh();

        $this->assertNull($call->connection_id);
    }
}
