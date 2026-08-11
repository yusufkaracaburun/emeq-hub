<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\AccountingResult;
use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\FinancialDocument;
use App\Enums\Provider;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Wie mag er bij `/v1/accounting/*`.
 *
 * De canonieke endpoints horen bij `accounting:*`. Daarnáást is er precies één
 * overgangspad: tokens met `exact:*`, uitgegeven toen dit deel van de API nog
 * Exact-only was. Dat pad hing eerst aan de gekoppelde provider in plaats van aan
 * Exact — waardoor elke nieuwe provider een legacy-recht zou erven dat bij hem nooit
 * heeft bestaan.
 */
class CanonicalAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_canonical_ability_opens_the_canonical_endpoint(): void
    {
        $this->capabilitiesWith(TokenAbilities::ACCOUNTING_READ)->assertOk();
    }

    /**
     * Het overgangspad. **Weghalen zodra de bestaande consumers een
     * `accounting:*`-token hebben** — dan hoort dit een 403 te worden.
     */
    public function test_a_legacy_exact_token_still_opens_the_canonical_endpoint(): void
    {
        $this->capabilitiesWith(TokenAbilities::EXACT_READ)->assertOk();
    }

    /**
     * Een provider-ability van een ándere provider is géén overgangspad: die tokens
     * hebben nooit toegang gehad tot de canonieke endpoints en krijgen die ook niet
     * cadeau zodra hun provider een boekhoudadapter krijgt.
     */
    public function test_another_providers_ability_does_not_open_the_canonical_endpoint(): void
    {
        app(AccountingTargetRegistry::class)->register(Provider::Snelstart->value, StubTarget::class);

        $this->capabilitiesWith(TokenAbilities::SNELSTART_READ, Provider::Snelstart)
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_ability');
    }

    /**
     * De ability wordt gecheckt vóór de koppeling wordt opgezocht: een token zonder
     * recht hoort niet te horen wélke koppelingen dit Account heeft.
     */
    public function test_a_token_without_the_ability_learns_nothing_about_the_connections(): void
    {
        $this->capabilitiesWith(TokenAbilities::MOLLIE_READ)
            ->assertStatus(403)
            ->assertJsonMissingPath('connections');
    }

    private function capabilitiesWith(string $ability, Provider $provider = Provider::Exact): TestResponse
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        Connection::factory()->for($account)->create([
            'provider' => $provider->value,
            'status' => 'active',
        ]);

        $token = $consumer->createToken('t', [$ability])->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/accounting/capabilities');
    }
}

/**
 * Minimale adapter — deze test gaat over abilities, niet over boeken.
 */
final class StubTarget implements AccountingTarget
{
    public function push(FinancialDocument $document, Connection $connection): AccountingResult
    {
        return new AccountingResult(status: 201, externalRef: 'stub-1', externalNumber: null, raw: [], attachments: []);
    }
}
