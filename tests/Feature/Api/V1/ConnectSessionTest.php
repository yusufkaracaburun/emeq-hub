<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ConnectSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_mints_a_signed_handoff_url_for_the_account(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connect-sessions', [
                'account_external_id' => 'school1',
                'display_name' => 'Kinderopvang Noord',
            ])
            ->assertOk()
            ->assertJsonStructure(['url', 'expires_at']);

        $account = $consumer->accounts()->where('external_id', 'school1')->sole();

        $this->assertSame('Kinderopvang Noord', $account->display_name);
        $this->assertTrue(URL::hasValidSignature($this->requestFor($response->json('url'))));
        $this->assertStringContainsString("/connect/{$account->getKey()}", $response->json('url'));
    }

    public function test_minted_link_actually_opens_the_handoff_page(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);

        $url = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connect-sessions', ['account_external_id' => 'school1'])
            ->assertOk()
            ->json('url');

        $this->get($url)->assertOk();
    }

    public function test_return_url_on_a_foreign_host_is_not_carried_into_the_link(): void
    {
        $consumer = Consumer::factory()->withAppUrl('https://consumer.test')->create();
        $token = $consumer->createToken('t', [TokenAbilities::INTEGRATIONS_MANAGE])->plainTextToken;

        $url = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connect-sessions', [
                'account_external_id' => 'school1',
                'return_url' => 'https://evil.test/steal',
            ])
            ->assertOk()
            ->json('url');

        $this->assertStringNotContainsString('evil.test', urldecode($url));
        $this->assertStringNotContainsString('return_url=', $url);
    }

    public function test_matching_return_url_is_carried_into_the_link(): void
    {
        $consumer = Consumer::factory()->withAppUrl('https://consumer.test')->create();
        $token = $consumer->createToken('t', [TokenAbilities::INTEGRATIONS_MANAGE])->plainTextToken;

        $url = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connect-sessions', [
                'account_external_id' => 'school1',
                'return_url' => 'https://tenant.consumer.test/integraties?emeq=return',
            ])
            ->assertOk()
            ->json('url');

        $this->assertStringContainsString(
            'tenant.consumer.test/integraties',
            urldecode($url),
        );
    }

    public function test_requires_a_token_ability(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::EXACT_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connect-sessions', ['account_external_id' => 'school1'])
            ->assertForbidden();
    }

    public function test_rejects_an_anonymous_caller(): void
    {
        $this->postJson('/v1/connect-sessions', ['account_external_id' => 'school1'])
            ->assertUnauthorized();
    }

    public function test_account_external_id_is_required(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connect-sessions', [])
            ->assertStatus(422);
    }

    public function test_categories_are_stored_as_a_snapshot_on_the_account(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);
        $first = [
            ['key' => 'expense:12', 'label' => 'Brandstof', 'type' => 'expense'],
            ['key' => 'income:3', 'label' => 'Lesgeld', 'type' => 'income'],
        ];
        $second = [['key' => 'expense:14', 'label' => 'Onderhoud', 'type' => 'expense']];

        $mint = fn (array $payload) => $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connect-sessions', ['account_external_id' => 'school1', ...$payload])
            ->assertOk();
        $snapshot = fn (): ?array => $consumer->accounts()->where('external_id', 'school1')->sole()->accounting_categories;

        $mint(['categories' => $first]);
        $this->assertSame($first, $snapshot());

        $mint(['categories' => $second]);
        $this->assertSame($second, $snapshot());

        $mint([]);
        $this->assertSame($second, $snapshot());
    }

    public function test_invalid_categories_are_rejected_per_field(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connect-sessions', [
                'account_external_id' => 'school1',
                'categories' => [
                    ['key' => 'expense:12', 'label' => 'Brandstof', 'type' => 'asset'],
                    ['key' => 'expense:12', 'type' => 'expense'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['categories.0.type', 'categories.0.key', 'categories.1.key', 'categories.1.label']);

        $this->assertSame(0, $consumer->accounts()->count());
    }

    public function test_invalid_mode_and_actor_are_rejected_per_field(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/connect-sessions', [
                'account_external_id' => 'school1',
                'mode' => 'edit',
                'actor' => ['name' => 'Jan'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode', 'actor.email']);
    }

    /** @return array{0: Consumer, 1: string} */
    private function consumerWithToken(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $token];
    }

    private function requestFor(string $url): Request
    {
        return Request::create($url);
    }
}
