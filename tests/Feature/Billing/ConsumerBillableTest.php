<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Billable;
use Tests\TestCase;

class ConsumerBillableTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumer_uses_billable_trait(): void
    {
        $this->assertContains(
            Billable::class,
            class_uses_recursive(Consumer::class),
            'Consumer-model mist Billable-trait — D-03 niet ingelost.',
        );
    }

    public function test_consumer_subscribed_returns_false_when_no_subscription(): void
    {
        $consumer = Consumer::factory()->create();

        $this->assertFalse($consumer->subscribed('main'));
    }

    public function test_consumer_subscriptions_relation_returns_empty_collection(): void
    {
        $consumer = Consumer::factory()->create();

        $this->assertCount(0, $consumer->subscriptions);
    }

    public function test_subscriptions_table_polymorphic_owner_uses_consumer_class(): void
    {
        $consumer = Consumer::factory()->create();

        DB::table('subscriptions')->insert([
            'name' => 'main',
            'plan' => 'naschool-license',
            'owner_id' => $consumer->id,
            'owner_type' => Consumer::class,
            'quantity' => 1,
            'tax_percentage' => 21,
            'cycle_started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $consumer->refresh();

        $this->assertSame(1, $consumer->subscriptions()->count());
        $this->assertSame('naschool-license', $consumer->subscriptions->first()->plan);
    }

    public function test_consumer_mollie_mandate_id_returns_null_when_no_mandate(): void
    {
        $consumer = Consumer::factory()->create();

        $this->assertNull($consumer->mollieMandateId());
        $this->assertSame('mollie_mandate_id', $consumer->getMollieMandateIdColumn());
    }
}
