<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Atomic onboarding-service: maakt Consumer + (optioneel) Account + (optioneel)
 * Connection + PAT in één DB::transaction aan. Failure op willekeurige stap →
 * volledige rollback (geen wees-Consumer of orphan-Account).
 *
 * Eén bron-van-waarheid voor onboarding-logica die zowel de CLI
 * (`hub:consumer:create`) als de Filament-wizard (PLAN 08-02) consumeren.
 *
 * Plain `plain_token` + `plain_webhook_callback_secret` worden alleen via de
 * return-array beschikbaar gesteld — bedoeld voor eenmalige Cache-flash. Nooit
 * loggen, nooit persistent dumpen. Encrypted-cast op Consumer + Connection
 * garandeert at-rest-encryption (regel `protected function casts()`).
 */
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

            // Test-only failure marker — bewijst rollback in feature-test zonder
            // model-event-listener of FK-violation te hoeven simuleren.
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

    /**
     * @param  array<int, string>  $abilities
     */
    private function assertAbilitiesWhitelisted(array $abilities): void
    {
        $invalid = array_values(array_diff($abilities, TokenAbilities::all()));

        if ($invalid !== []) {
            throw new InvalidArgumentException('Onbekende abilities: '.implode(', ', $invalid));
        }
    }
}
