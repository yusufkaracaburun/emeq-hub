<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\InboundWebhookEvent;
use App\Models\PassThroughCall;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DEMO_CONSUMER_SLUGS = ['emeq', 'planny', 'naschool'];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoSeeder skipped in production.');

            return;
        }

        $consumers = $this->seedConsumers();

        $this->wipeChildData($consumers);

        foreach ($consumers as $consumer) {
            $accounts = $this->seedAccounts($consumer);

            foreach ($accounts as $account) {
                $this->seedConnections($account);
            }
        }

        $this->seedAccountSubscriptions();
        $this->seedCashierSubscriptions($consumers);
        $this->seedInboundWebhookEvents($consumers);
        $this->seedPassThroughCalls();

        $this->command?->info('DemoSeeder: '.implode(', ', array_map(fn (Consumer $c) => $c->slug, $consumers)).' geseed.');
    }

    /** @return array<int, Consumer> */
    private function seedConsumers(): array
    {
        $seeds = [
            ['slug' => 'emeq', 'name' => 'Emeq'],
            ['slug' => 'naschool', 'name' => 'Naschool'],
            ['slug' => 'planny', 'name' => 'Planny'],
        ];

        $consumers = [];

        foreach ($seeds as $seed) {
            $consumer = Consumer::firstOrCreate(
                ['slug' => $seed['slug']],
                ['name' => $seed['name']],
            );

            if (! $consumer->webhook_callback_url) {
                $consumer->forceFill([
                    'webhook_callback_url' => "https://{$seed['slug']}.test/hooks/emeq",
                    'webhook_callback_secret' => 'whsec_'.Str::random(32),
                ])->save();
            }

            $consumers[] = $consumer;
        }

        return $consumers;
    }

    /** @param array<int, Consumer> $consumers */
    private function wipeChildData(array $consumers): void
    {
        $consumerIds = array_map(fn (Consumer $c) => $c->id, $consumers);
        $accountIds = Account::whereIn('consumer_id', $consumerIds)->pluck('id')->all();

        AccountSubscription::whereIn('account_id', $accountIds)->delete();
        Connection::whereIn('account_id', $accountIds)->delete();
        PassThroughCall::whereIn('consumer_id', $consumerIds)->delete();
        InboundWebhookEvent::whereIn('consumer_id', $consumerIds)->delete();
        DB::table('subscriptions')
            ->whereIn('owner_id', $consumerIds)
            ->where('owner_type', Consumer::class)
            ->delete();
    }

    /** @return array<int, Account> */
    private function seedAccounts(Consumer $consumer): array
    {
        $perConsumer = match ($consumer->slug) {
            'emeq' => [
                ['external_id' => 'emeq_tisol', 'display_name' => 'Tisol | Emeq'],
                ['external_id' => 'emeq_bob', 'display_name' => 'Bob | Emeq'],
            ],
            'planny' => [
                ['external_id' => 'planny_xpress', 'display_name' => 'Xpress | Planny'],
            ],
            default => [
                ['external_id' => 'merchant-1', 'display_name' => 'Webwinkel Aurelius'],
            ],
        };

        $accounts = [];

        foreach ($perConsumer as $attrs) {
            $accounts[] = $consumer->accounts()->firstOrCreate(
                ['external_id' => $attrs['external_id']],
                ['display_name' => $attrs['display_name']],
            );
        }

        return $accounts;
    }

    private function seedConnections(Account $account): void
    {
        Connection::factory()
            ->for($account)
            ->forSnelstart()
            ->active()
            ->create();

        $mollieState = match ($account->id % 3) {
            0 => 'active',
            1 => 'pending',
            default => 'active',
        };

        $factory = Connection::factory()->for($account)->forMollie();
        $factory = $mollieState === 'pending' ? $factory->pending() : $factory->active();

        $factory->create();
    }

    private function seedAccountSubscriptions(): void
    {
        $mollieConnections = Connection::query()
            ->where('provider', 'mollie')
            ->where('status', 'active')
            ->get();

        if ($mollieConnections->isEmpty()) {
            return;
        }

        $states = ['active', 'pending', 'paused', 'canceled'];

        foreach ($mollieConnections as $idx => $connection) {
            $state = $states[$idx % count($states)];

            AccountSubscription::factory()
                ->forConnection($connection)
                ->{$state}()
                ->create([
                    'description' => "Vrijwillige bijdrage {$state}",
                    'amount_value' => (string) random_int(5, 50).'.00',
                ]);
        }
    }

    /** @param array<int, Consumer> $consumers */
    private function seedCashierSubscriptions(array $consumers): void
    {
        foreach ($consumers as $consumer) {
            DB::table('subscriptions')->insert([
                'name' => 'main',
                'plan' => 'emeq-license',
                'owner_id' => $consumer->id,
                'owner_type' => Consumer::class,
                'quantity' => 1,
                'tax_percentage' => 21,
                'cycle_started_at' => now()->subDays(15),
                'cycle_ends_at' => now()->addDays(15),
                'created_at' => now()->subDays(15),
                'updated_at' => now(),
            ]);
        }
    }

    /** @param array<int, Consumer> $consumers */
    private function seedInboundWebhookEvents(array $consumers): void
    {
        $rows = [
            ['provider' => 'exact', 'topic' => 'GeneralJournalEntries', 'action' => 'Update', 'status' => 200, 'outcome' => 'processed', 'fanout_status' => 'dispatched'],
            ['provider' => 'mollie', 'topic' => null, 'action' => null, 'status' => 202, 'outcome' => 'processed', 'fanout_status' => 'dispatched'],
            ['provider' => 'snelstart', 'topic' => 'Relatie.Created', 'action' => null, 'status' => 200, 'outcome' => 'unknown_tenant', 'fanout_status' => null],
            ['provider' => 'cashier', 'topic' => null, 'action' => null, 'status' => 500, 'outcome' => 'misconfigured', 'fanout_status' => null],
        ];

        foreach ($consumers as $consumer) {
            foreach ($rows as $i => $row) {
                InboundWebhookEvent::create([
                    ...$row,
                    'event_id' => $row['outcome'] === 'duplicate' ? null : "demo-{$consumer->id}-{$i}",
                    'consumer_id' => $consumer->id,
                    'request_fingerprint' => mb_substr(hash('sha256', "demo-{$consumer->id}-{$i}"), 0, 12),
                    'received_at' => now()->subMinutes($i),
                ]);
            }
        }
    }

    private function seedPassThroughCalls(): void
    {
        $consumers = Consumer::whereIn('slug', self::DEMO_CONSUMER_SLUGS)->get();

        foreach ($consumers as $consumer) {
            $account = $consumer->accounts()->first();
            $snelstartConnection = $account?->connections()->where('provider', 'snelstart')->first();
            $mollieConnection = $account?->connections()->where('provider', 'mollie')->where('status', 'active')->first();

            if ($snelstartConnection) {
                PassThroughCall::factory()
                    ->count(3)
                    ->create([
                        'consumer_id' => $consumer->id,
                        'account_id' => $account->id,
                        'connection_id' => $snelstartConnection->id,
                        'provider' => 'snelstart',
                    ]);
            }

            if ($mollieConnection) {
                PassThroughCall::factory()
                    ->count(2)
                    ->create([
                        'consumer_id' => $consumer->id,
                        'account_id' => $account->id,
                        'connection_id' => $mollieConnection->id,
                        'provider' => 'mollie',
                        'method' => 'POST',
                        'path' => 'v2/payments',
                    ]);
            }
        }
    }
}
