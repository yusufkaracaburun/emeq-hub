<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Accounting\AccountingResult;
use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\Contracts\SyncsReferenceData;
use App\Accounting\Enums\Capability;
use App\Accounting\FinancialDocument;
use App\Enums\Provider;
use App\Integrations\Exact\Accounting\ExactAccountingTarget;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Capabilities zijn afgeleid van `implements`, niet gedeclareerd in config. Deze
 * tests bewaken die eigenschap — zodra iemand een lijst gaat bijhouden, kunnen
 * declaratie en gedrag uit elkaar lopen.
 */
class AccountingTargetRegistryCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): AccountingTargetRegistry
    {
        return app(AccountingTargetRegistry::class);
    }

    private function connectionFor(Provider $provider): Connection
    {
        return Connection::factory()->create(['provider' => $provider->value]);
    }

    public function test_the_exact_target_declares_all_four_capabilities(): void
    {
        $this->assertEqualsCanonicalizing(
            Capability::values(),
            array_map(
                static fn (Capability $c): string => $c->value,
                $this->registry()->capabilitiesFor($this->connectionFor(Provider::Exact)),
            ),
        );
    }

    public function test_a_push_only_target_declares_only_write_documents(): void
    {
        $this->registry()->register(Provider::Snelstart->value, PushOnlyTarget::class);

        $this->assertSame(
            [Capability::WriteDocuments],
            $this->registry()->capabilitiesFor($this->connectionFor(Provider::Snelstart)),
        );
    }

    public function test_an_unregistered_provider_declares_nothing(): void
    {
        $this->assertSame([], $this->registry()->capabilitiesFor($this->connectionFor(Provider::Mollie)));
    }

    /**
     * De capability-vraag mag geen bijwerkingen hebben — hij wordt beantwoord met
     * reflectie, niet door de adapter te bouwen.
     */
    public function test_capabilities_do_not_instantiate_the_target(): void
    {
        $this->registry()->register(Provider::Snelstart->value, ExplodingTarget::class);

        $this->assertSame(
            [Capability::WriteDocuments],
            $this->registry()->capabilitiesFor($this->connectionFor(Provider::Snelstart)),
        );
    }

    /**
     * Declaratie en beschikbaarheid zijn twee assen. Een uitgeschakelde provider kan
     * nog steeds vertellen wat hij zou kunnen.
     */
    public function test_capabilities_are_declared_even_when_the_provider_is_disabled(): void
    {
        Feature::define('provider-exact-enabled', fn () => false);

        $connection = $this->connectionFor(Provider::Exact);

        $this->assertNotEmpty($this->registry()->capabilitiesFor($connection));
        $this->assertFalse($this->registry()->enabled(Provider::Exact->value));
    }

    public function test_the_typed_getter_returns_null_for_a_target_without_the_capability(): void
    {
        $this->registry()->register(Provider::Snelstart->value, PushOnlyTarget::class);

        $this->assertNull($this->registry()->syncsReferenceData($this->connectionFor(Provider::Snelstart)));
    }

    public function test_the_typed_getter_returns_the_contract_for_exact(): void
    {
        $this->assertInstanceOf(
            SyncsReferenceData::class,
            $this->registry()->syncsReferenceData($this->connectionFor(Provider::Exact)),
        );
    }

    /**
     * De getters lopen via `for()`, dus de kill-switch blijft gelden — anders zou de
     * capability-laag een gat om de vlag heen zijn.
     */
    public function test_the_typed_getter_honours_the_kill_switch(): void
    {
        Feature::define('provider-exact-enabled', fn () => false);

        $this->expectException(ProviderDisabledException::class);

        $this->registry()->syncsReferenceData($this->connectionFor(Provider::Exact));
    }

    public function test_every_capability_maps_to_an_existing_contract(): void
    {
        foreach (Capability::cases() as $capability) {
            $this->assertTrue(
                interface_exists($capability->contract()),
                "Capability {$capability->value} wijst naar een niet-bestaand contract.",
            );
        }
    }

    public function test_the_exact_target_implements_every_contract_it_declares(): void
    {
        foreach ($this->registry()->capabilitiesFor($this->connectionFor(Provider::Exact)) as $capability) {
            $this->assertTrue(
                is_a(ExactAccountingTarget::class, $capability->contract(), allow_string: true),
            );
        }
    }
}

/** Adapter die alleen kan boeken — de vorm die een nieuwe provider op dag 1 heeft. */
final class PushOnlyTarget implements AccountingTarget
{
    public function push(FinancialDocument $document, Connection $connection): AccountingResult
    {
        return new AccountingResult(201, 'ref', null, [], []);
    }
}

/** Bewijst dat capabilitiesFor() de klasse niet instantieert. */
final class ExplodingTarget implements AccountingTarget
{
    public function __construct()
    {
        throw new \RuntimeException('capabilitiesFor() hoort deze constructor nooit aan te roepen.');
    }

    public function push(FinancialDocument $document, Connection $connection): AccountingResult
    {
        return new AccountingResult(201, 'ref', null, [], []);
    }
}
