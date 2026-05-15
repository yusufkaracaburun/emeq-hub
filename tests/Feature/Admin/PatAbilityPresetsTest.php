<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Consumers\ConsumerResource;
use App\Sanctum\TokenAbilities;
use Tests\TestCase;

/**
 * D-03 discovery-contract: zorgt dat elke ability uit TokenAbilities::all() óf
 * in een preset zit óf expliciet in PAT_CUSTOM_ONLY staat. Faalt zodra een
 * nieuwe TokenAbilities-constant wordt toegevoegd zonder preset-update — CI
 * dwingt de developer om óf een preset uit te breiden óf PAT_CUSTOM_ONLY te
 * patchen.
 *
 * Geen RefreshDatabase: pure constants-test, geen DB-state nodig.
 */
class PatAbilityPresetsTest extends TestCase
{
    public function test_every_token_ability_is_covered_by_a_preset_or_custom_only_list(): void
    {
        $covered = collect(ConsumerResource::PAT_PRESETS)
            ->pluck('abilities')
            ->flatten()
            ->merge(ConsumerResource::PAT_CUSTOM_ONLY)
            ->unique()
            ->all();

        foreach (TokenAbilities::all() as $ability) {
            $this->assertContains(
                $ability,
                $covered,
                "Ability '{$ability}' moet in een preset OF in PAT_CUSTOM_ONLY staan.",
            );
        }
    }

    public function test_pat_presets_constants_have_expected_shape(): void
    {
        $this->assertNotEmpty(ConsumerResource::PAT_PRESETS);

        foreach (ConsumerResource::PAT_PRESETS as $slug => $entry) {
            $this->assertIsString($slug, 'Preset-slug moet string zijn.');
            $this->assertArrayHasKey('label', $entry, "Preset '{$slug}' mist 'label'.");
            $this->assertArrayHasKey('abilities', $entry, "Preset '{$slug}' mist 'abilities'.");
            $this->assertIsString($entry['label'], "Preset '{$slug}'.label moet string zijn.");
            $this->assertIsArray($entry['abilities'], "Preset '{$slug}'.abilities moet array zijn.");
            $this->assertNotEmpty($entry['abilities'], "Preset '{$slug}'.abilities mag niet leeg zijn.");

            foreach ($entry['abilities'] as $ability) {
                $this->assertIsString($ability, "Preset '{$slug}'.abilities-entry moet string zijn.");
            }
        }
    }

    public function test_billing_abilities_are_custom_only(): void
    {
        $this->assertContains(
            TokenAbilities::BILLING_READ,
            ConsumerResource::PAT_CUSTOM_ONLY,
            'BILLING_READ moet in PAT_CUSTOM_ONLY staan (regressie-vangnet).',
        );

        $this->assertContains(
            TokenAbilities::BILLING_WRITE,
            ConsumerResource::PAT_CUSTOM_ONLY,
            'BILLING_WRITE moet in PAT_CUSTOM_ONLY staan (regressie-vangnet).',
        );
    }
}
