<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use App\Models\WebhookCall;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Demo-data voor lokale admin-UI validatie (/admin).
 *
 * Idempotent: re-run convergeert naar dezelfde state via firstOrCreate op
 * Consumer.slug + Account.external_id; child-collections worden ge-wiped en
 * opnieuw aangemaakt voor demo-Consumers zodat counts stabiel blijven.
 *
 * Production-guard: hard-skip op `app()->isProduction()`. Geen Mollie/Snelstart
 * API-calls — alleen DB-rows.
 *
 * Run: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DEMO_CONSUMER_SLUGS = ['naschool', 'planny', 'demo-shop'];

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
        $this->seedWebhookCalls($consumers);
        $this->seedPassThroughCalls();

        $this->command?->info('DemoSeeder: '.implode(', ', array_map(fn (Consumer $c) => $c->slug, $consumers)).' geseed.');
    }

    /** @return array<int, Consumer> */
    private function seedConsumers(): array
    {
        $seeds = [
            ['slug' => 'naschool', 'name' => 'Naschool'],
            ['slug' => 'planny', 'name' => 'Planny'],
            ['slug' => 'demo-shop', 'name' => 'Demo Shop'],
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
        WebhookCall::whereIn('consumer_id', $consumerIds)->delete();
        DB::table('subscriptions')
            ->whereIn('owner_id', $consumerIds)
            ->where('owner_type', Consumer::class)
            ->delete();
    }

    /** @return array<int, Account> */
    private function seedAccounts(Consumer $consumer): array
    {
        $perConsumer = match ($consumer->slug) {
            'naschool' => [
                ['external_id' => 'school1', 'display_name' => 'Basisschool De Vlinder'],
                ['external_id' => 'school2', 'display_name' => 'CBS Het Anker'],
                ['external_id' => 'school3', 'display_name' => 'OBS De Regenboog'],
            ],
            'planny' => [
                ['external_id' => 'tenant-001', 'display_name' => 'Studio Loft'],
                ['external_id' => 'tenant-002', 'display_name' => 'Kapper Knip & Kam'],
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
                'plan' => 'naschool-license',
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
    private function seedWebhookCalls(array $consumers): void
    {
        $providers = ['mollie', 'snelstart', 'cashier'];
        $statuses = ['processed', 'pending', 'failed'];
        $directions = ['incoming', 'outgoing'];

        foreach ($consumers as $consumer) {
            for ($i = 0; $i < 4; $i++) {
                $provider = $providers[$i % count($providers)];
                $direction = $directions[$i % count($directions)];
                $status = $statuses[$i % count($statuses)];

                WebhookCall::create([
                    'name' => "{$provider}-webhook",
                    'url' => "https://hub.emeq.test/webhooks/{$provider}/{$consumer->slug}",
                    'headers' => json_encode(['User-Agent' => 'Demo/1.0', 'Content-Type' => 'application/json']),
                    'payload' => ['event' => 'demo.test', 'consumer' => $consumer->slug, 'idx' => $i],
                    'exception' => $status === 'failed' ? 'DemoException: simulated failure' : null,
                    'direction' => $direction,
                    'provider' => $provider,
                    'consumer_id' => $consumer->id,
                    'status' => $status,
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
