<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Accounting\AccountingTargetRegistry;
use App\Enums\Provider;
use App\Integrations\Errors\UpstreamErrorMapperRegistry;
use App\Integrations\OAuth\OAuthFlowRegistry;
use App\Integrations\Webhooks\CanonicalEventRegistry;
use Tests\TestCase;

/**
 * De registries vallen bewust zacht terug wanneer een provider ontbreekt — een
 * vergeten registratie mag geen webhook of partner-fout laten sneuvelen. De prijs
 * daarvan is dat het gat stil blijft. Deze tests zijn waar het luid wordt.
 *
 * Een nieuwe Provider-enum-case breekt de bouw tot iemand per registry beslist:
 * registreren, of hier vastleggen waarom niet.
 */
final class ProviderRegistryCompletenessTest extends TestCase
{
    /**
     * Providers zonder OAuth-redirect-flow, met reden.
     *
     * @var array<string, string>
     */
    private const OAUTH_EXCEPTIONS = [
        'Snelstart' => 'authenticeert met grant_type=clientkey, zonder redirect-flow',
    ];

    /**
     * Providers die geen boekhoudkoppeling zijn, met reden.
     *
     * @var array<string, string>
     */
    private const ACCOUNTING_EXCEPTIONS = [
        'Mollie' => 'betaalprovider — boekt niets',
        'Snelstart' => 'boekhoudkoppeling nog niet gebouwd',
    ];

    /**
     * Elke provider praat HTTP en kan dus falen. Zonder mapper landt een
     * inhoudelijke afwijzing als generieke 502 bij de consumer.
     */
    public function test_elke_provider_heeft_een_foutmapper(): void
    {
        $registry = $this->app->make(UpstreamErrorMapperRegistry::class);

        foreach (Provider::cases() as $provider) {
            $this->assertTrue($registry->supports($provider->value), sprintf(
                'Provider %s heeft geen MapsUpstreamExceptions. Registreer er een in '
                .'AppServiceProvider, anders wordt elke partner-fout een 502.',
                $provider->name,
            ));
        }
    }

    /**
     * Zonder resolver krijgt de consumer `unmapped` in de envelope: de webhook
     * komt aan, maar zegt niets.
     */
    public function test_elke_provider_vertaalt_zijn_webhooks(): void
    {
        $registry = $this->app->make(CanonicalEventRegistry::class);

        foreach (Provider::cases() as $provider) {
            $this->assertTrue($registry->supports($provider), sprintf(
                'Provider %s heeft geen ResolvesCanonicalEvent. Zonder resolver levert '
                .'elke webhook van deze partner het canonieke event `unmapped`.',
                $provider->name,
            ));
        }
    }

    public function test_elke_provider_heeft_een_oauth_flow_of_een_reden(): void
    {
        $registered = $this->app->make(OAuthFlowRegistry::class)->providers();

        foreach (Provider::cases() as $provider) {
            $exempt = array_key_exists($provider->name, self::OAUTH_EXCEPTIONS);

            if ($exempt) {
                $this->assertNotContains($provider->value, $registered, sprintf(
                    'Provider %s staat hier als uitzondering ("%s") maar heeft wél een '
                    .'OAuthFlow. Haal de uitzondering weg.',
                    $provider->name,
                    self::OAUTH_EXCEPTIONS[$provider->name],
                ));

                continue;
            }

            $this->assertContains($provider->value, $registered, sprintf(
                'Provider %s heeft geen OAuthFlow. Registreer er een, of leg in '
                .'OAUTH_EXCEPTIONS vast waarom deze partner er geen nodig heeft.',
                $provider->name,
            ));
        }
    }

    public function test_elke_provider_heeft_een_boekhouddoel_of_een_reden(): void
    {
        $registry = $this->app->make(AccountingTargetRegistry::class);

        foreach (Provider::cases() as $provider) {
            $exempt = array_key_exists($provider->name, self::ACCOUNTING_EXCEPTIONS);

            if ($exempt) {
                $this->assertFalse($registry->supports($provider->value), sprintf(
                    'Provider %s staat hier als uitzondering ("%s") maar heeft wél een '
                    .'AccountingTarget. Haal de uitzondering weg.',
                    $provider->name,
                    self::ACCOUNTING_EXCEPTIONS[$provider->name],
                ));

                continue;
            }

            $this->assertTrue($registry->supports($provider->value), sprintf(
                'Provider %s heeft geen AccountingTarget. Registreer er een, of leg in '
                .'ACCOUNTING_EXCEPTIONS vast waarom deze partner niet boekt.',
                $provider->name,
            ));
        }
    }

    /**
     * De kill-switch komt uit config/hub-providers.php; een enum-case zonder
     * config-key levert een feature-flag die nooit aan kan staan.
     */
    public function test_elke_provider_staat_in_de_provider_config(): void
    {
        $configured = array_keys(config('hub-providers'));

        foreach (Provider::cases() as $provider) {
            $this->assertContains($provider->value, $configured, sprintf(
                'Provider %s ontbreekt in config/hub-providers.php. Zonder config-key is '
                .'de Pennant-kill-switch feature.provider:%s nooit actief.',
                $provider->name,
                $provider->value,
            ));
        }
    }
}
