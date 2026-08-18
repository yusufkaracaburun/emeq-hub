<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final readonly class ConsumerOnboarding
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     consumer: Consumer,
     *     account: Account|null,
     *     connection: Connection|null,
     *     plain_token: string,
     *     plain_webhook_callback_secret: string|null,
     * }
     */
    public function onboard(array $data): array
    {
        $this->assertAbilitiesWhitelisted($data['abilities'] ?? []);

        return DB::transaction(function () use ($data): array {
            $consumer = Consumer::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'app_url' => $data['app_url'] ?? null,
                'webhook_callback_url' => $data['webhook_callback_url'] ?? null,
                'webhook_callback_secret' => $data['webhook_callback_secret'] ?? null,
            ]);

            $account = null;
            if (isset($data['external_id'])) {
                $account = $consumer->accounts()->create([
                    'external_id' => $data['external_id'],
                    'display_name' => $data['display_name'] ?? null,
                ]);
            }

            $connection = null;
            if ($account !== null && isset($data['connection']) && is_array($data['connection'])) {
                $connectionData = $data['connection'];
                $connectionData['status'] = $connectionData['status'] ?? 'pending';

                $connection = $account->connections()->create($connectionData);
            }

            $token = $consumer->createToken($data['token_name'], $data['abilities']);

            if (! empty($data['__force_failure'])) {
                throw new RuntimeException('forced failure inside DB::transaction');
            }

            return [
                'consumer' => $consumer,
                'account' => $account,
                'connection' => $connection,
                'plain_token' => $token->plainTextToken,
                'plain_webhook_callback_secret' => $data['webhook_callback_secret'] ?? null,
            ];
        });
    }

    /** @param  array<int, string>  $abilities */
    private function assertAbilitiesWhitelisted(array $abilities): void
    {
        $invalid = array_values(array_diff($abilities, TokenAbilities::all()));

        if ($invalid !== []) {
            throw new InvalidArgumentException('Onbekende abilities: '.implode(', ', $invalid));
        }
    }
}
