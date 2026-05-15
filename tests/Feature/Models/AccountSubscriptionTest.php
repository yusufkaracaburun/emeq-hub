<?php

namespace Tests\Feature\Models;

use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_account_subscription_via_factory_in_pending_state(): void
    {
        $connection = Connection::factory()->forMollie()->create();

        $sub = AccountSubscription::factory()->forConnection($connection)->pending()->create();

        $this->assertSame('pending', $sub->status);
        $this->assertNull($sub->mollie_subscription_id);
        $this->assertSame('EUR', $sub->amount_currency);
        $this->assertSame('10.00', $sub->amount_value);
        $this->assertNull($sub->metadata);
        $this->assertNull($sub->starts_at);
        $this->assertSame($connection->id, $sub->connection_id);
        $this->assertSame($connection->account_id, $sub->account_id);
    }

    public function test_account_can_access_subscriptions_via_relation(): void
    {
        $connection = Connection::factory()->forMollie()->create();
        $account = $connection->account;

        AccountSubscription::factory()->forConnection($connection)->pending()->create();

        $subs = $account->accountSubscriptions;

        $this->assertInstanceOf(Collection::class, $subs);
        $this->assertCount(1, $subs);
        $this->assertSame('pending', $subs->first()->status);
    }

    public function test_connection_can_access_subscriptions_via_relation(): void
    {
        $connection = Connection::factory()->forMollie()->create();

        AccountSubscription::factory()->forConnection($connection)->pending()->create();
        AccountSubscription::factory()->forConnection($connection)->pending()->create();

        $subs = $connection->accountSubscriptions;

        $this->assertCount(2, $subs);
    }

    public function test_partial_unique_index_blocks_duplicate_active_mollie_subscription_id(): void
    {
        $connection = Connection::factory()->forMollie()->create();

        AccountSubscription::factory()->forConnection($connection)->active()->create([
            'mollie_subscription_id' => 'sub_duplicate_test',
        ]);

        $this->expectException(QueryException::class);

        AccountSubscription::factory()->forConnection($connection)->active()->create([
            'mollie_subscription_id' => 'sub_duplicate_test',
        ]);
    }

    public function test_partial_unique_index_allows_multiple_pending_with_null_mollie_subscription_id(): void
    {
        $connection = Connection::factory()->forMollie()->create();

        AccountSubscription::factory()->forConnection($connection)->pending()->create();
        AccountSubscription::factory()->forConnection($connection)->pending()->create();

        $this->assertSame(2, AccountSubscription::where('connection_id', $connection->id)->count());
    }

    public function test_account_id_fk_is_wired_as_cascade_on_delete(): void
    {
        // D-03 schreef voor: account_id FK cascadet, connection_id FK restrict.
        // Onder Postgres aborteert een directe Account::delete() omdat de
        // restrict-FK op connection_id de geneste cascade blokkeert. De
        // admin-flow (T-07-01-03) ruimt daarom eerst subs + connections op,
        // pas dan account — dit test bewijst dat die volgorde clean afsluit
        // én dat een sub die nog op een al-opgeschoonde connection-chain hangt
        // door de cascade op account_id verdwijnt.
        $connection = Connection::factory()->forMollie()->create();
        $account = $connection->account;

        AccountSubscription::factory()->forConnection($connection)->pending()->create();
        $this->assertSame(1, AccountSubscription::count());

        // Admin-volgorde: eerst subs, dan connections, dan account.
        AccountSubscription::where('connection_id', $connection->id)->delete();
        $connection->delete();
        $account->delete();

        $this->assertSame(0, AccountSubscription::count());

        // Postgres-only assertion: bewijst dat de account_id FK met
        // CASCADE-disposition is gedefinieerd (confdeltype 'c' = CASCADE).
        // Onder SQLite skipt deze pg_constraint-query niet beschikbaar is.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $row = DB::selectOne(
                'SELECT confdeltype FROM pg_constraint WHERE conname = ?',
                ['account_subscriptions_account_id_foreign']
            );
            $this->assertNotNull($row);
            $this->assertSame('c', $row->confdeltype);
        }
    }

    public function test_restrict_delete_connection_with_subscription_throws(): void
    {
        $connection = Connection::factory()->forMollie()->create();

        AccountSubscription::factory()->forConnection($connection)->pending()->create();

        $this->expectException(QueryException::class);

        $connection->delete();
    }
}
