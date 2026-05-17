<?php

declare(strict_types=1);

namespace Tests\Feature\Dev;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\OAuth\Contracts\OAuthFlow;
use App\OAuth\Mollie\MollieConnectOAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use App\Services\PartnerStatus;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan 08-05 — Dev `/dev/partners`-pagina-uitbreiding (D-06, UI-SPEC §S3).
 *
 * Task 1-scope: PartnerStatus-service + _domeinmodel.blade.php + _status-widget.blade.php.
 * Task 2-scope tests volgen later in dezelfde class (env-gating + blade-views + dev OAuth-init).
 */
class PartnerPagesTest extends TestCase
{
    use RefreshDatabase;

    // ---------- Task 1: PartnerStatus service ----------

    public function test_partner_status_service_returns_empty_collection_on_empty_db(): void
    {
        $result = app(PartnerStatus::class)->forProvider('mollie');

        $this->assertCount(0, $result);
    }

    public function test_partner_status_service_resolves_connected_for_active_mollie_connection(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()->for($account)->forMollie()->active()->create();

        $result = app(PartnerStatus::class)->forProvider('mollie');

        $this->assertCount(1, $result);
        $this->assertSame('connected', $result->first()['status']);
    }

    public function test_partner_status_service_resolves_pending_for_pending_connection(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()->for($account)->pending()->create();

        $result = app(PartnerStatus::class)->forProvider('mollie');

        $this->assertSame('pending', $result->first()['status']);
    }

    public function test_partner_status_service_resolves_revoked_for_revoked_connection(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()->for($account)->forMollie()->active()->create([
            'revoked_at' => now(),
        ]);

        $result = app(PartnerStatus::class)->forProvider('mollie');

        $this->assertSame('revoked', $result->first()['status']);
    }

    public function test_partner_status_service_resolves_none_for_account_without_connection(): void
    {
        $consumer = Consumer::factory()->create();
        Account::factory()->for($consumer)->create();

        $result = app(PartnerStatus::class)->forProvider('mollie');

        $this->assertCount(1, $result);
        $this->assertSame('none', $result->first()['status']);
    }

    public function test_partner_status_service_avoids_n_plus_one_queries(): void
    {
        $consumer = Consumer::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $account = Account::factory()->for($consumer)->create();
            Connection::factory()->for($account)->forMollie()->active()->create();
        }

        DB::enableQueryLog();
        $result = app(PartnerStatus::class)->forProvider('mollie');
        // Force collection-iteration zodat eager-loaded relations geconsumeerd worden.
        $result->each(fn (array $entry) => $entry['status']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // WR-03: exact-count tripwire i.p.v. lessThanOrEqual — een 3e query (bv. nieuw
        // eager-load voor `consumer`) moet zichtbaar zijn als regressie en
        // bewust naar boven worden bijgesteld.
        $this->assertSame(
            2,
            count($queries),
            'PartnerStatus::forProvider() moet exact 2 queries doen (Account + eager connections).'
        );
        $this->assertCount(5, $result);
    }

    public function test_partner_status_totals_for_provider_keeps_query_count_in_check(): void
    {
        $consumer = Consumer::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $account = Account::factory()->for($consumer)->create();
            Connection::factory()->for($account)->forMollie()->active()->create();
        }

        DB::enableQueryLog();
        $totals = app(PartnerStatus::class)->totalsForProvider('mollie');
        DB::disableQueryLog();
        $queries = DB::getQueryLog();

        // totalsForProvider() roept forProvider() één keer aan → max 2 queries.
        // Een refactor die forProvider() tweemaal zou aanroepen verdubbelt dit.
        $this->assertSame(2, count($queries), 'totalsForProvider() moet ook exact 2 queries doen');
        $this->assertSame(['connected' => 3, 'total' => 3], $totals);
    }

    // ---------- Task 1: _domeinmodel.blade.php partial ----------

    public function test_domeinmodel_partial_contains_canonical_copy(): void
    {
        $html = view('partners.partials._domeinmodel')->render();

        $this->assertStringContainsString('Hoe de Hub-tenancy in elkaar zit', $html);
        $this->assertStringContainsString('Consumer', $html);
        $this->assertStringContainsString('Account', $html);
        $this->assertStringContainsString('Connection', $html);
        // Canonical copy excerpts (UI-SPEC §S3 regel 184-186)
        $this->assertStringContainsString('Naschool, Planny, externe app', $html);
        $this->assertStringContainsString('school A, vereniging C', $html);
    }

    // ---------- Task 1: _status-widget.blade.php partial ----------

    public function test_status_widget_renders_connected_state_with_emerald_color(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['display_name' => 'Demo School']);
        $connection = Connection::factory()->for($account)->forMollie()->active()->create();

        $accountStatus = collect([[
            'account' => $account,
            'connection' => $connection,
            'status' => 'connected',
        ]]);

        $html = view('partners.partials._status-widget', [
            'provider' => 'mollie',
            'accountStatus' => $accountStatus,
        ])->render();

        $this->assertStringContainsString('check-circle', $html);
        $this->assertStringContainsString('gekoppeld', $html);
        $this->assertStringContainsString('text-emerald-600', $html);
        $this->assertStringContainsString('Demo School', $html);
    }

    public function test_status_widget_renders_pending_state_with_amber_color(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->for($account)->pending()->create();

        $accountStatus = collect([[
            'account' => $account,
            'connection' => $connection,
            'status' => 'pending',
        ]]);

        $html = view('partners.partials._status-widget', [
            'provider' => 'mollie',
            'accountStatus' => $accountStatus,
        ])->render();

        $this->assertStringContainsString('clock', $html);
        $this->assertStringContainsString('pending', $html);
        $this->assertStringContainsString('text-amber-600', $html);
    }

    public function test_status_widget_renders_empty_state_when_no_accounts(): void
    {
        $html = view('partners.partials._status-widget', [
            'provider' => 'mollie',
            'accountStatus' => collect(),
        ])->render();

        $this->assertStringContainsString('Geen demo-Accounts', $html);
        $this->assertStringContainsString('php artisan db:seed', $html);
    }

    // ---------- Task 2: env-gating + blade-views + dev OAuth-init route ----------

    public function test_index_page_renders_with_domeinmodel_blok(): void
    {
        $this->get('/dev/partners')
            ->assertOk()
            ->assertSee('Partner previews')
            ->assertSee('Hoe de Hub-tenancy in elkaar zit');
    }

    public function test_index_page_shows_per_provider_status_totaal(): void
    {
        $consumer = Consumer::factory()->create();
        $a1 = Account::factory()->for($consumer)->create();
        Connection::factory()->for($a1)->forMollie()->active()->create();
        Account::factory()->for($consumer)->create(); // 1 zonder Mollie

        $this->get('/dev/partners')
            ->assertOk()
            ->assertSee('Mollie: 1/2 Accounts gekoppeld');
    }

    public function test_mollie_page_renders_koppel_stappen_and_cta(): void
    {
        // assertSee escape: false omdat Blade-template literal apostroph bevat ('Start OAuth-flow'),
        // Laravel's assertSee default-escape verwacht &#039; in haystack die er niet is.
        $this->get('/dev/partners/mollie')
            ->assertOk()
            ->assertSee('Koppelen via OAuth Connect')
            ->assertSee('Zorg dat school A een Mollie test-account heeft')
            ->assertSee("Klik op 'Start OAuth-flow' hieronder", escape: false)
            ->assertSee('Na goedkeuring landt de access_token encrypted')
            ->assertSee('Start OAuth-flow')
            ->assertSee('bg-amber-500', escape: false)
            ->assertSee('/dev/partners/mollie/start-oauth', escape: false);
    }

    public function test_mollie_dev_oauth_route_redirects_to_mollie_authorize_url(): void
    {
        // Bind FakeOAuthFlow zodat we Mollie-call kunnen onderscheppen voor deterministische URL.
        $this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class);

        $consumer = Consumer::factory()->create(['slug' => 'naschool']);
        Account::factory()->for($consumer)->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);

        $response = $this->get('/dev/partners/mollie/start-oauth');

        $response->assertStatus(302);
        $this->assertStringStartsWith('https://fake.oauth.local/authorize', $response->headers->get('Location'));
        $this->assertStringContainsString('state=', $response->headers->get('Location'));
    }

    public function test_mollie_dev_oauth_route_creates_pending_connection(): void
    {
        $this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class);

        $consumer = Consumer::factory()->create(['slug' => 'naschool']);
        $account = Account::factory()->for($consumer)->create([
            'external_id' => 'school1',
        ]);

        $this->assertSame(0, Connection::query()->count());

        $this->get('/dev/partners/mollie/start-oauth')->assertStatus(302);

        $connection = Connection::query()->where('account_id', $account->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame('mollie', $connection->provider);
        $this->assertSame('pending', $connection->status);
        $this->assertNotNull($connection->oauth_state);
        $this->assertSame(48, strlen($connection->oauth_state));
        $this->assertNotNull($connection->oauth_state_expires_at);
        $this->assertTrue($connection->oauth_state_expires_at->isFuture());
        $this->assertTrue($connection->oauth_state_expires_at->diffInMinutes(now()) <= 30);
    }

    public function test_mollie_dev_oauth_route_returns_404_without_demo_account(): void
    {
        // Geen Naschool-seed → abort(404, 'Geen demo-Account ...'). Production-404-page
        // toont de abort-message niet (alleen bij APP_DEBUG=true), maar 404-status
        // bewijst de guard. De message-inhoud is gevalideerd via tinker en hardcoded
        // in routes/web.php — runtime-bewijs van de string heeft weinig toegevoegde
        // waarde bovenop het 404-bewijs.
        $response = $this->get('/dev/partners/mollie/start-oauth');

        $response->assertNotFound();
    }

    /**
     * CR-04 regression — een fout in getAuthorizationUrl() mag geen orphan
     * pending-Connection achterlaten. Voorheen schreef de route de
     * Connection vóór de partner-call, dus elke retry stapelde rijen op
     * (30-min oauth_state TTL).
     */
    public function test_mollie_dev_oauth_route_does_not_create_orphan_connection_when_authorize_url_throws(): void
    {
        $this->app->bind(MollieConnectOAuthFlow::class, function () {
            return new class implements OAuthFlow
            {
                public function getAuthorizationUrl(Account $account, array $scopes, string $state): string
                {
                    throw new \RuntimeException('Mollie down.');
                }

                public function exchangeCode(Connection $connection, string $code): Connection
                {
                    return $connection;
                }

                public function refreshToken(Connection $connection): Connection
                {
                    return $connection;
                }

                public function revoke(Connection $connection): void {}
            };
        });

        $consumer = Consumer::factory()->create(['slug' => 'naschool']);
        Account::factory()->for($consumer)->create();

        $this->assertSame(0, Connection::query()->count());

        $response = $this->get('/dev/partners/mollie/start-oauth');

        // 503-fail (not 500/302) — abort message toont alleen bij APP_DEBUG=true,
        // maar status-code is altijd geset.
        $response->assertStatus(503);

        // Cruciaal: geen orphan pending-row, ook niet na retry.
        $this->assertSame(0, Connection::query()->count(), 'Geen orphan Connection na failed authorize-URL');

        $this->get('/dev/partners/mollie/start-oauth')->assertStatus(503);
        $this->assertSame(0, Connection::query()->count(), 'Retry mag ook geen rij achterlaten');
    }

    public function test_mollie_page_includes_status_widget_with_connected_account(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['display_name' => 'Test School A']);
        Connection::factory()->for($account)->forMollie()->active()->create();

        $this->get('/dev/partners/mollie')
            ->assertOk()
            ->assertSee('Live koppel-status (dev-omgeving)')
            ->assertSee('Test School A')
            ->assertSee('text-emerald-600', escape: false);
    }

    public function test_snelstart_page_renders_koppel_stappen_and_curl(): void
    {
        $this->get('/dev/partners/snelstart')
            ->assertOk()
            ->assertSee('Koppelen via credential-form')
            ->assertSee('Vraag bij SnelStart de drie credentials op')
            ->assertSee('POST naar', escape: false)
            ->assertSee('De Hub encrypt de credentials at rest')
            ->assertSee('curl -X POST', escape: false)
            ->assertSee('/v1/connections', escape: false)
            ->assertSee('"provider":"snelstart"', escape: false);
    }

    public function test_snelstart_page_does_not_leak_plain_client_key(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()->for($account)->forSnelstart()->create([
            'client_key' => 'SECRETKEY_PLAIN_DO_NOT_LEAK',
        ]);

        $this->get('/dev/partners/snelstart')
            ->assertOk()
            ->assertDontSee('SECRETKEY_PLAIN_DO_NOT_LEAK');
    }

    public function test_domeinmodel_blok_appears_on_both_provider_pages(): void
    {
        $this->get('/dev/partners/mollie')
            ->assertOk()
            ->assertSee('Hoe de Hub-tenancy in elkaar zit');

        $this->get('/dev/partners/snelstart')
            ->assertOk()
            ->assertSee('Hoe de Hub-tenancy in elkaar zit');
    }

    /**
     * LAST in the file on purpose — createFreshApp() mutates the global container
     * which terminates RefreshDatabase's transaction. Keep all DB-touching tests
     * above this one.
     */
    public function test_dev_partner_routes_404_in_non_local_envs(): void
    {
        foreach (['staging', 'preview', 'uat', 'production'] as $env) {
            $app = $this->createFreshApp($env);

            $this->assertNull(
                $app['router']->getRoutes()->getByName('dev.partners.index'),
                "/dev/partners moet 404 zijn in env={$env}."
            );
            $this->assertNull(
                $app['router']->getRoutes()->getByName('dev.partners.preview'),
                "/dev/partners/{provider} moet 404 zijn in env={$env}."
            );
            $this->assertNull(
                $app['router']->getRoutes()->getByName('dev.partners.mollie.start-oauth'),
                "/dev/partners/mollie/start-oauth moet 404 zijn in env={$env}."
            );
        }
    }

    /**
     * Bouwt een geïsoleerde Application-instance voor env-gating-inspectie.
     *
     * `routes/web.php` registreert nieuwe Routes op de globale facade-resolved router.
     * Daarom snapshotten we de Container::getInstance() vóór en restoren we 'm na,
     * zodat RefreshDatabase's teardown niet stuk gaat (config-facade onreachable).
     */
    private function createFreshApp(string $env): Application
    {
        $originalContainer = Container::getInstance();

        try {
            $app = require __DIR__.'/../../../bootstrap/app.php';
            $app['env'] = $env;
            $app->detectEnvironment(fn () => $env);

            Route::clearResolvedInstances();
            $router = $app->make('router');
            $router->setRoutes(new RouteCollection);
            require __DIR__.'/../../../routes/web.php';

            return $app;
        } finally {
            Container::setInstance($originalContainer);
        }
    }
}
