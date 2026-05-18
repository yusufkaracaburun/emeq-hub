<?php

namespace Tests\Unit\Mollie;

use App\Exceptions\Mollie\MissingConnectionContextException;
use App\Exceptions\Mollie\MissingPartnerTokenException;
use App\Models\Connection;
use App\Mollie\MollieAccessTokenResolver;
use App\Mollie\MollieConnectionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MollieAccessTokenResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_partner_returns_configured_token(): void
    {
        $resolver = new MollieAccessTokenResolver(
            new MollieConnectionContext,
            'access_partner_xyz',
        );

        $this->assertSame('access_partner_xyz', $resolver->resolveFor('partner'));
    }

    public function test_resolve_partner_throws_when_token_missing(): void
    {
        $resolver = new MollieAccessTokenResolver(
            new MollieConnectionContext,
            null,
        );

        $this->expectException(MissingPartnerTokenException::class);
        $this->expectExceptionMessage('Mollie partner-access-token niet geconfigureerd op Hub. Contact Emeq-staff.');

        $resolver->resolveFor('partner');
    }

    public function test_resolve_connection_returns_context_access_token(): void
    {
        $connection = Connection::factory()->forMollie()->active()->make([
            'access_token' => 'access_merchant_abc',
        ]);

        $context = new MollieConnectionContext;
        $context->set($connection);

        $resolver = new MollieAccessTokenResolver($context, 'access_partner_xyz');

        $this->assertSame('access_merchant_abc', $resolver->resolveFor('connection'));
    }

    public function test_resolve_connection_throws_when_context_empty(): void
    {
        $resolver = new MollieAccessTokenResolver(
            new MollieConnectionContext,
            'access_partner_xyz',
        );

        $this->expectException(MissingConnectionContextException::class);

        $resolver->resolveFor('connection');
    }

    public function test_resolve_unknown_token_type_throws_invalid_argument(): void
    {
        $resolver = new MollieAccessTokenResolver(
            new MollieConnectionContext,
            'access_partner_xyz',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('snelstart');

        $resolver->resolveFor('snelstart');
    }

    public function test_resolver_is_bound_as_singleton(): void
    {
        config()->set('services.mollie.partner_access_token', 'access_partner_xyz');

        $a = app(MollieAccessTokenResolver::class);
        $b = app(MollieAccessTokenResolver::class);

        $this->assertSame($a, $b);
    }

    /**
     * CR-02 regressie: partner-token wordt elke resolveFor()-call vers gelezen
     * uit de Closure, niet bij construct-time gefixeerd. Long-running workers
     * (Horizon, octane) zien daardoor env-rotatie zonder container-restart.
     */
    public function test_partner_token_reflects_config_changes_without_rebind(): void
    {
        config()->set('services.mollie.partner_access_token', 'access_partner_initial');

        $resolver = app(MollieAccessTokenResolver::class);
        $this->assertSame('access_partner_initial', $resolver->resolveFor('partner'));

        config()->set('services.mollie.partner_access_token', 'access_partner_rotated');
        $this->assertSame(
            'access_partner_rotated',
            $resolver->resolveFor('partner'),
            'Resolver moet de gerouleerde token teruggeven zonder container-rebind.',
        );
    }
}
