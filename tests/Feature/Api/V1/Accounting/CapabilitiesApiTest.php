<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\Enums\Capability;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Het discovery-endpoint: een consumer die één keer integreert wil kunnen vragen wat
 * de gekoppelde administratie ondersteunt, zonder te weten wélke provider dat is.
 */
class CapabilitiesApiTest extends TestCase
{
    use RefreshDatabase;

    private function consumerWithExactConnection(): Consumer
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);

        Connection::factory()->forExact()->create([
            'account_id' => $account->id,
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
        ]);

        return $consumer;
    }

    /**
     * @param  list<string>  $abilities
     */
    private function fetchCapabilities(Consumer $consumer, array $abilities = [TokenAbilities::EXACT_READ], ?string $accountId = 'school1'): TestResponse
    {
        $token = $consumer->createToken('t', $abilities)->plainTextToken;

        $request = $this->withHeader('Authorization', "Bearer {$token}");

        if ($accountId !== null) {
            $request = $request->withHeader('X-Account-Id', $accountId);
        }

        return $request->getJson('/v1/accounting/capabilities');
    }

    public function test_returns_the_provider_its_state_and_the_capability_list(): void
    {
        $this->fetchCapabilities($this->consumerWithExactConnection())
            ->assertOk()
            ->assertJsonPath('provider', 'exact')
            ->assertJsonPath('enabled', true)
            ->assertJsonCount(count(Capability::cases()), 'capabilities')
            ->assertJsonFragment(['capabilities' => Capability::values()]);
    }

    /**
     * Beschikbaarheid en kunnen zijn twee dingen: een uitgezette provider meldt nog
     * steeds wat hij ondersteunt, met `enabled: false` erbij.
     */
    public function test_reports_enabled_false_when_the_provider_is_switched_off(): void
    {
        Feature::define('provider-exact-enabled', fn () => false);

        $this->fetchCapabilities($this->consumerWithExactConnection())
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonCount(count(Capability::cases()), 'capabilities');
    }

    public function test_requires_the_account_header(): void
    {
        $this->fetchCapabilities($this->consumerWithExactConnection(), accountId: null)
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_account_header');
    }

    public function test_returns_404_for_an_unknown_account(): void
    {
        $this->fetchCapabilities($this->consumerWithExactConnection(), accountId: 'bestaat-niet')
            ->assertStatus(404)
            ->assertJsonPath('error', 'account_not_found');
    }

    public function test_requires_a_read_ability(): void
    {
        $this->fetchCapabilities($this->consumerWithExactConnection(), abilities: [TokenAbilities::MOLLIE_READ])
            ->assertStatus(403);
    }

    /**
     * Een Account zonder boekhoud-koppeling heeft niets te melden.
     */
    public function test_returns_404_without_an_accounting_connection(): void
    {
        $consumer = Consumer::factory()->create();
        $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);

        $this->fetchCapabilities($consumer)
            ->assertStatus(404)
            ->assertJsonPath('error', 'no_accounting_connection');
    }
}
