<?php

namespace Tests\Feature;

use App\Models\PassThroughCall;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassThroughCallInboundScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_scope_filters_correctly(): void
    {
        PassThroughCall::factory()->count(2)->create();
        PassThroughCall::factory()->inbound()->create();

        $this->assertSame(1, PassThroughCall::inbound()->count());
        $this->assertSame(2, PassThroughCall::outbound()->count());
    }

    public function test_inbound_audit_row_allows_null_tenant(): void
    {
        $call = PassThroughCall::factory()
            ->inbound()
            ->create([
                'consumer_id' => null,
                'account_id' => null,
                'connection_id' => null,
            ]);

        $fresh = $call->fresh();

        $this->assertNull($fresh->consumer_id);
        $this->assertNull($fresh->account_id);
        $this->assertNull($fresh->connection_id);
        $this->assertSame('inbound', $fresh->direction);
        $this->assertNotNull($fresh->event_id);
    }

    public function test_duplicate_provider_event_id_is_rejected(): void
    {
        $first = PassThroughCall::factory()
            ->inbound()
            ->create([
                'provider' => 'snelstart',
                'event_id' => 'evt-duplicate',
            ]);

        $caught = null;
        try {
            PassThroughCall::factory()
                ->inbound()
                ->create([
                    'provider' => 'snelstart',
                    'event_id' => 'evt-duplicate',
                ]);
        } catch (QueryException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected unique-violation on duplicate (provider, event_id).');
        $this->assertSame(1, PassThroughCall::where('event_id', 'evt-duplicate')->count());
        $this->assertNotNull($first->fresh());
    }
}
