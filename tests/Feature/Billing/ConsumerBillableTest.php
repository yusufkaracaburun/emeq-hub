<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Billable;
use Tests\TestCase;

/**
 * SUB-01 / D-03: bewijst dat de Cashier-Mollie `Billable`-trait correct op
 * `App\Models\Consumer` is geland en zonder errors door Cashier's
 * subscription-API kan worden bevraagd voor een Consumer zonder billing-state.
 *
 * NIET op `Account` (D-04 — uitsluiting) — daarvoor géén tests in dit plan.
 */
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

    /**
     * `mollieMandateId()` is een pure accessor op de `mollie_mandate_id`-kolom
     * — geen Mollie-API-call. Voor een verse Consumer zonder die kolom is de
     * waarde `null`. Bewijst dat de Billable-trait accessor-only methodes
     * niet crashen op een Consumer zonder billing-state.
     *
     * NB: `mollieCustomerId()` is bewust NIET getest hier omdat die method bij
     * een lege kolom direct `createAsMollieCustomer()` aanroept (live Mollie-
     * API-hit). Dat gedrag wordt getest in plan 06-05 met een stub-binding.
     */
    public function test_consumer_mollie_mandate_id_returns_null_when_no_mandate(): void
    {
        $consumer = Consumer::factory()->create();

        $this->assertNull($consumer->mollieMandateId());
        $this->assertSame('mollie_mandate_id', $consumer->getMollieMandateIdColumn());
    }
}
