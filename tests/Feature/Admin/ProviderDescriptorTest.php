<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Support\ProviderCredentialDescriptor;
use InvalidArgumentException;
use Tests\TestCase;

class ProviderDescriptorTest extends TestCase
{
    public function test_mollie_descriptor_resolves_from_config(): void
    {
        $descriptor = ProviderCredentialDescriptor::for('mollie');

        $this->assertSame('mollie', $descriptor->key);
        $this->assertSame(['access_token', 'refresh_token'], $descriptor->encryptedFields);
        $this->assertSame('OAuth token', $descriptor->primaryFingerprintLabel);
        $this->assertSame('mollie', $descriptor->oauthFlowKey);
    }

    public function test_snelstart_descriptor_has_null_oauth_flow_key(): void
    {
        $descriptor = ProviderCredentialDescriptor::for('snelstart');

        $this->assertSame('snelstart', $descriptor->key);
        $this->assertSame(['client_key', 'subscription_key'], $descriptor->encryptedFields);
        $this->assertSame('Client key', $descriptor->primaryFingerprintLabel);
        $this->assertNull($descriptor->oauthFlowKey);
    }

    public function test_unknown_provider_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Onbekende provider: unknown-provider');

        ProviderCredentialDescriptor::for('unknown-provider');
    }

    public function test_try_for_returns_descriptor_for_known_provider(): void
    {
        $descriptor = ProviderCredentialDescriptor::tryFor('mollie');

        $this->assertInstanceOf(ProviderCredentialDescriptor::class, $descriptor);
        $this->assertSame('mollie', $descriptor->key);
        $this->assertSame('mollie', $descriptor->oauthFlowKey);
    }

    public function test_try_for_returns_null_for_unknown_provider(): void
    {
        $this->assertNull(ProviderCredentialDescriptor::tryFor('non-existent-provider-xyz'));
    }

    public function test_adding_theoretical_provider_appears_in_all(): void
    {
        // D-04 success-criterium 10: een nieuwe provider toevoegen vereist
        // alleen een rij in config/hub-providers.php — geen Filament-code-wijziging.
        config(['hub-providers.moneybird' => [
            'encrypted_fields' => ['access_token', 'refresh_token'],
            'primary_label' => 'OAuth token',
            'oauth_flow_key' => 'moneybird',
        ]]);

        $keys = array_map(
            fn (ProviderCredentialDescriptor $d): string => $d->key,
            ProviderCredentialDescriptor::all(),
        );

        $this->assertContains('moneybird', $keys);
        $this->assertContains('mollie', $keys);
        $this->assertContains('snelstart', $keys);
    }
}
