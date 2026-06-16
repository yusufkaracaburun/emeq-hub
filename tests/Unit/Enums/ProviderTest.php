<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Provider;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase
{
    public function test_backing_values_match_hub_provider_config_keys(): void
    {
        $this->assertSame('mollie', Provider::Mollie->value);
        $this->assertSame('snelstart', Provider::Snelstart->value);
        $this->assertSame('exact', Provider::Exact->value);
    }

    public function test_values_returns_all_backing_strings(): void
    {
        $this->assertSame(['mollie', 'snelstart', 'exact'], Provider::values());
    }

    public function test_labels_are_human_readable(): void
    {
        $this->assertSame('Mollie', Provider::Mollie->getLabel());
        $this->assertSame('Snelstart', Provider::Snelstart->getLabel());
        $this->assertSame('Exact Online', Provider::Exact->getLabel());
    }

    public function test_each_case_has_a_filament_color(): void
    {
        foreach (Provider::cases() as $provider) {
            $this->assertNotSame('', $provider->getColor());
        }
    }
}
