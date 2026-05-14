<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Consumer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumerAccountScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_consumers_can_have_same_external_id(): void
    {
        $consumerA = Consumer::factory()->create(['slug' => 'naschool']);
        $consumerB = Consumer::factory()->create(['slug' => 'planny']);

        $accountA = $consumerA->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'Naschool School 1',
        ]);

        $accountB = $consumerB->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'Planny School 1',
        ]);

        $this->assertNotSame($accountA->id, $accountB->id);
        $this->assertSame('school1', $accountA->external_id);
        $this->assertSame('school1', $accountB->external_id);
    }

    public function test_consumer_a_cannot_query_consumer_b_account_via_scope(): void
    {
        $consumerA = Consumer::factory()->create();
        $consumerB = Consumer::factory()->create();

        $consumerB->accounts()->create([
            'external_id' => 'b-only-school',
            'display_name' => 'Belongs to B',
        ]);

        $leaked = Account::query()
            ->where('consumer_id', $consumerA->id)
            ->where('external_id', 'b-only-school')
            ->first();

        $this->assertNull($leaked);
    }

    public function test_consumer_relation_query_only_returns_own_accounts(): void
    {
        $consumerA = Consumer::factory()->create();
        $consumerB = Consumer::factory()->create();

        $consumerA->accounts()->create(['external_id' => 'a-school', 'display_name' => 'A']);
        $consumerB->accounts()->create(['external_id' => 'b-school', 'display_name' => 'B']);

        $accountsForA = $consumerA->accounts()->get();

        $this->assertCount(1, $accountsForA);
        $this->assertSame('a-school', $accountsForA->first()->external_id);
    }

    public function test_duplicate_external_id_within_same_consumer_fails(): void
    {
        $consumer = Consumer::factory()->create();

        $consumer->accounts()->create([
            'external_id' => 'duplicate-key',
            'display_name' => 'First',
        ]);

        $this->expectException(QueryException::class);

        $consumer->accounts()->create([
            'external_id' => 'duplicate-key',
            'display_name' => 'Second',
        ]);
    }
}
