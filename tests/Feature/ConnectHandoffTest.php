<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Exact\OAuth\ExactOAuthFlow;
use App\Integrations\Mollie\OAuth\MollieConnectOAuthFlow;
use App\Integrations\OAuth\Testing\FakeOAuthFlow;
use App\Jobs\Webhooks\ForwardConnectionRevokedToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Support\Connect\ConnectLinkFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ConnectHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(ExactOAuthFlow::class, FakeOAuthFlow::class);
        $this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class);
    }

    public function test_shows_the_manage_page_for_a_signed_link(): void
    {
        $account = $this->account('Kinderopvang Noord');

        $this->get($this->linkFor($account))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('connect/index')
                ->where('state', 'manage')
                ->where('accountName', 'Kinderopvang Noord')
                ->has('providers', 2));
    }

    public function test_only_lists_providers_that_are_actually_connectable(): void
    {
        $account = $this->account();

        $this->get($this->linkFor($account))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $keys = collect($page->toArray()['props']['providers'])->pluck('key');

                $this->assertEqualsCanonicalizing(['exact', 'mollie'], $keys->all());
            });
    }

    public function test_stays_on_one_page_when_every_provider_is_linked(): void
    {
        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();
        Connection::factory()->forMollie()->active()->for($account)->create();

        $this->get($this->linkFor($account))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'manage'));
    }

    public function test_marks_only_the_already_linked_provider_as_connected(): void
    {
        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();

        $this->get($this->linkFor($account))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $providers = collect($page->toArray()['props']['providers']);

                $this->assertSame('connected', $providers->firstWhere('key', 'exact')['status']);
                $this->assertSame('disconnected', $providers->firstWhere('key', 'mollie')['status']);
                $this->assertSame('manage', $page->toArray()['props']['state']);
            });
    }

    public function test_an_unsigned_link_renders_the_expired_page(): void
    {
        $account = $this->account();

        $this->get("/connect/{$account->getKey()}")
            ->assertStatus(410)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('connect/index')
                ->where('state', 'expired'));
    }

    public function test_a_tampered_account_id_is_rejected(): void
    {
        $mine = $this->account();
        $foreign = Account::factory()->for(Consumer::factory())->create();

        $tampered = str_replace(
            "/connect/{$mine->getKey()}",
            "/connect/{$foreign->getKey()}",
            $this->linkFor($mine),
        );

        $this->get($tampered)->assertStatus(410);
    }

    public function test_an_expired_link_renders_the_expired_page(): void
    {
        $account = $this->account();
        $link = $this->linkFor($account);

        $this->travel(ConnectLinkFactory::TTL_MINUTES + 1)->minutes();

        $this->get($link)->assertStatus(410);
    }

    public function test_starting_a_provider_creates_the_connection_and_redirects(): void
    {
        $account = $this->account();

        $startUrl = collect($this->getPageProps($this->linkFor($account))['providers'])
            ->firstWhere('key', 'exact')['start_url'];

        $this->post($startUrl)->assertRedirect();

        $this->assertDatabaseHas('connections', [
            'account_id' => $account->getKey(),
            'provider' => 'exact',
            'status' => 'pending',
        ]);
    }

    public function test_start_carries_the_validated_return_url_to_the_connection(): void
    {
        $consumer = Consumer::factory()->withAppUrl('https://consumer.test')->create();
        $account = Account::factory()->for($consumer)->create();

        $link = app(ConnectLinkFactory::class)->mint($account, 'https://consumer.test/klaar')['url'];

        $startUrl = collect($this->getPageProps($link)['providers'])
            ->firstWhere('key', 'exact')['start_url'];

        $this->post($startUrl)->assertRedirect();

        $this->assertDatabaseHas('connections', [
            'account_id' => $account->getKey(),
            'oauth_return_url' => 'https://consumer.test/klaar',
        ]);
    }

    public function test_start_rejects_an_unsigned_request(): void
    {
        $account = $this->account();

        $this->post("/connect/{$account->getKey()}/exact")->assertStatus(410);

        $this->assertDatabaseCount('connections', 0);
    }

    public function test_swapping_the_provider_in_a_signed_start_url_is_rejected(): void
    {
        $account = $this->account();

        $tampered = str_replace('/exact', '/snelstart', $this->startUrlFor($account, 'exact'));

        $this->post($tampered)->assertStatus(410);
        $this->assertDatabaseCount('connections', 0);
    }

    public function test_start_rejects_a_provider_without_an_oauth_flow(): void
    {
        $account = $this->account();

        $url = URL::temporarySignedRoute('connect.start', now()->addMinutes(15), [
            'account' => $account->getKey(),
            'provider' => 'snelstart',
        ]);

        $this->post($url)->assertNotFound();
        $this->assertDatabaseCount('connections', 0);
    }

    public function test_disconnecting_revokes_the_connection_and_notifies_the_consumer(): void
    {
        Queue::fake();

        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();

        $disconnectUrl = collect($this->getPageProps($this->linkFor($account))['providers'])
            ->firstWhere('key', 'exact')['disconnect_url'];

        $this->delete($disconnectUrl)->assertRedirect();

        $this->assertNotNull($account->connections()->where('provider', 'exact')->sole()->revoked_at);

        Queue::assertPushed(ForwardConnectionRevokedToConsumerJob::class);
    }

    public function test_disconnecting_does_not_extend_the_link_lifetime(): void
    {
        Queue::fake();

        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();

        $link = $this->linkFor($account);
        $originalExpiry = $this->expiryOf($link);

        $disconnectUrl = collect($this->getPageProps($link)['providers'])
            ->firstWhere('key', 'exact')['disconnect_url'];

        $this->travel(5)->minutes();

        $redirect = $this->delete($disconnectUrl)->assertRedirect()->headers->get('Location');

        $this->assertSame($originalExpiry, $this->expiryOf($redirect));
    }

    public function test_a_disconnected_provider_is_offered_for_connecting_again(): void
    {
        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();

        $disconnectUrl = collect($this->getPageProps($this->linkFor($account))['providers'])
            ->firstWhere('key', 'exact')['disconnect_url'];

        $this->delete($disconnectUrl);

        $exact = collect($this->getPageProps($this->linkFor($account))['providers'])
            ->firstWhere('key', 'exact');

        $this->assertSame('disconnected', $exact['status']);
        $this->assertNull($exact['disconnect_url']);
    }

    public function test_no_disconnect_url_is_offered_for_an_unconnected_provider(): void
    {
        $account = $this->account();

        $providers = collect($this->getPageProps($this->linkFor($account))['providers']);

        $this->assertNull($providers->firstWhere('key', 'exact')['disconnect_url']);
        $this->assertNull($providers->firstWhere('key', 'mollie')['disconnect_url']);
    }

    public function test_disconnect_rejects_an_unsigned_request(): void
    {
        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();

        $this->delete("/connect/{$account->getKey()}/exact")->assertStatus(410);

        $this->assertNull($account->connections()->where('provider', 'exact')->sole()->revoked_at);
    }

    public function test_disconnect_cannot_reach_another_tenants_connection(): void
    {
        $mine = $this->account();
        $foreign = Account::factory()->for(Consumer::factory())->create();
        Connection::factory()->forExact()->active()->for($foreign)->create();

        $tampered = str_replace(
            "/connect/{$mine->getKey()}/",
            "/connect/{$foreign->getKey()}/",
            $this->startUrlFor($mine, 'exact'),
        );

        $this->delete($tampered)->assertStatus(410);

        $this->assertNull($foreign->connections()->where('provider', 'exact')->sole()->revoked_at);
    }

    public function test_disconnecting_twice_is_harmless(): void
    {
        $account = $this->account();
        Connection::factory()->forExact()->active()->for($account)->create();

        $disconnectUrl = collect($this->getPageProps($this->linkFor($account))['providers'])
            ->firstWhere('key', 'exact')['disconnect_url'];

        $this->delete($disconnectUrl)->assertRedirect();
        $this->delete($disconnectUrl)->assertRedirect();
    }

    public function test_the_handoff_page_is_never_indexable(): void
    {
        $account = $this->account();

        $this->get($this->linkFor($account))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }

    private function account(?string $displayName = null): Account
    {
        return Account::factory()
            ->for(Consumer::factory())
            ->create($displayName !== null ? ['display_name' => $displayName] : []);
    }

    private function linkFor(Account $account): string
    {
        return app(ConnectLinkFactory::class)->mint($account)['url'];
    }

    private function startUrlFor(Account $account, string $provider): string
    {
        return collect($this->getPageProps($this->linkFor($account))['providers'])
            ->firstWhere('key', $provider)['start_url'];
    }

    private function expiryOf(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (int) ($query['expires'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function getPageProps(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }
}
