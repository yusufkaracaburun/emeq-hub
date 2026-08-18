<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\Provider;
use App\Integrations\Webhooks\CanonicalAction;
use App\Integrations\Webhooks\CanonicalEntityRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CanonicalEntityEnvelopeTest extends TestCase
{
    /** @return array<string, array{0: Provider, 1: array<string, mixed>, 2: string|null, 3: string|null}> */
    public static function payloads(): array
    {
        return [
            'exact genest' => [Provider::Exact, ['Content' => ['Key' => 'guid-1', 'Action' => 'Update']], 'guid-1', CanonicalAction::UPDATED],
            'exact plat' => [Provider::Exact, ['Key' => 'guid-2', 'Action' => 'Delete'], 'guid-2', CanonicalAction::DELETED],
            'exact onbekende actie' => [Provider::Exact, ['Content' => ['Key' => 'guid-3', 'Action' => 'Weird']], 'guid-3', CanonicalAction::UNMAPPED],
            'exact zonder key' => [Provider::Exact, ['Content' => ['Action' => 'Update']], null, CanonicalAction::UPDATED],
            'exact zonder actie' => [Provider::Exact, ['Content' => ['Key' => 'guid-4']], 'guid-4', null],
            'snelstart relatie' => [Provider::Snelstart, ['type' => 'Relatie.Created'], null, CanonicalAction::CREATED],
            'snelstart verkoopfactuur' => [Provider::Snelstart, ['type' => 'Verkoopfactuur.Updated'], null, CanonicalAction::UPDATED],
            'snelstart zonder punt' => [Provider::Snelstart, ['type' => 'Relatie'], null, null],
            'snelstart zonder type' => [Provider::Snelstart, ['administratieId' => 'aaa-111'], null, null],
            'mollie payment' => [Provider::Mollie, ['id' => 'tr_abc'], 'tr_abc', null],
            'mollie subscription' => [Provider::Mollie, ['id' => 'sub_abc'], 'sub_abc', null],
            'mollie zonder id' => [Provider::Mollie, [], null, null],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    #[DataProvider('payloads')]
    public function test_partner_payloads_map_to_entity_id_and_action(Provider $provider, array $payload, ?string $expectedEntityId, ?string $expectedAction): void
    {
        $registry = app(CanonicalEntityRegistry::class);

        $this->assertSame($expectedEntityId, $registry->entityIdFor($provider, $payload));
        $this->assertSame($expectedAction, $registry->actionFor($provider, $payload));
    }
}
