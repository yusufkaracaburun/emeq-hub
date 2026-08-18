<?php

namespace Tests\Feature\Services;

use App\Enums\Provider;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Services\ConsumerOnboarding;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Tests\TestCase;

class ConsumerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboard_creates_consumer_and_token_only(): void
    {
        $service = new ConsumerOnboarding;

        $result = $service->onboard([
            'name' => 'Naschool',
            'slug' => 'naschool',
            'token_name' => 'cli-default',
            'abilities' => ['*'],
        ]);

        $this->assertInstanceOf(Consumer::class, $result['consumer']);
        $this->assertSame('naschool', $result['consumer']->slug);
        $this->assertSame('Naschool', $result['consumer']->name);
        $this->assertNull($result['account']);
        $this->assertNull($result['connection']);
        $this->assertIsString($result['plain_token']);
        $this->assertNotEmpty($result['plain_token']);
        $this->assertNull($result['plain_webhook_callback_secret']);

        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_onboard_creates_account_when_external_id_provided(): void
    {
        $service = new ConsumerOnboarding;

        $result = $service->onboard([
            'name' => 'Naschool',
            'slug' => 'naschool',
            'token_name' => 'cli-default',
            'abilities' => ['*'],
            'external_id' => 'school1',
            'display_name' => 'School A',
        ]);

        $this->assertInstanceOf(Account::class, $result['account']);
        $this->assertSame('school1', $result['account']->external_id);
        $this->assertSame('School A', $result['account']->display_name);
        $this->assertSame($result['consumer']->id, $result['account']->consumer_id);
    }

    public function test_onboard_stores_webhook_callback_secret_encrypted_at_rest(): void
    {
        $service = new ConsumerOnboarding;

        $result = $service->onboard([
            'name' => 'Naschool',
            'slug' => 'naschool',
            'token_name' => 'cli-default',
            'abilities' => ['*'],
            'webhook_callback_url' => 'https://naschool.test/hooks',
            'webhook_callback_secret' => 'sek_plain',
        ]);

        $this->assertSame('sek_plain', $result['plain_webhook_callback_secret']);

        $raw = DB::table('consumers')->where('id', $result['consumer']->id)->value('webhook_callback_secret');
        $this->assertNotSame('sek_plain', $raw, 'webhook_callback_secret moet encrypted at rest staan');
        $this->assertNotEmpty($raw);

        $this->assertSame('sek_plain', $result['consumer']->fresh()->webhook_callback_secret);
        $this->assertSame('https://naschool.test/hooks', $result['consumer']->fresh()->webhook_callback_url);
    }

    public function test_onboard_persists_app_url(): void
    {
        $service = new ConsumerOnboarding;

        $result = $service->onboard([
            'name' => 'Naschool',
            'slug' => 'naschool',
            'token_name' => 'cli-default',
            'abilities' => ['*'],
            'app_url' => 'https://naschool.test',
        ]);

        $this->assertSame('https://naschool.test', $result['consumer']->fresh()->app_url);
    }

    public function test_onboard_creates_connection_with_encrypted_credentials(): void
    {
        $service = new ConsumerOnboarding;

        $result = $service->onboard([
            'name' => 'Naschool',
            'slug' => 'naschool',
            'token_name' => 'cli-default',
            'abilities' => ['*'],
            'external_id' => 'school1',
            'display_name' => 'School A',
            'connection' => [
                'provider' => 'snelstart',
                'client_key' => 'ck-raw',
                'subscription_key' => 'sk-raw',
                'subscription_id' => 'sub-uuid',
            ],
        ]);

        $this->assertInstanceOf(Connection::class, $result['connection']);
        $this->assertSame(Provider::Snelstart, $result['connection']->provider);
        $this->assertSame('pending', $result['connection']->status);
        $this->assertSame($result['account']->id, $result['connection']->account_id);

        $rawClientKey = DB::table('connections')->where('id', $result['connection']->id)->value('client_key');
        $this->assertNotSame('ck-raw', $rawClientKey, 'client_key moet encrypted at rest staan');

        $fresh = $result['connection']->fresh();
        $this->assertSame('ck-raw', $fresh->client_key);
        $this->assertSame('sk-raw', $fresh->subscription_key);
        $this->assertSame('sub-uuid', $fresh->subscription_id);
    }

    public function test_rollback_on_failure_leaves_db_empty(): void
    {
        $service = new ConsumerOnboarding;

        try {
            $service->onboard([
                'name' => 'Naschool',
                'slug' => 'naschool',
                'token_name' => 'cli-default',
                'abilities' => ['*'],
                'external_id' => 'school1',
                'display_name' => 'School A',
                'connection' => [
                    'provider' => 'snelstart',
                    'client_key' => 'ck',
                    'subscription_key' => 'sk',
                    'subscription_id' => 'si',
                ],
                '__force_failure' => true,
            ]);
            $this->fail('Onboarding had moeten falen door __force_failure-marker');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('forced failure', $e->getMessage());
        }

        $this->assertSame(0, Consumer::count(), 'Consumer-rij moet rollback-zijn');
        $this->assertSame(0, Account::count(), 'Account-rij moet rollback-zijn');
        $this->assertSame(0, Connection::count(), 'Connection-rij moet rollback-zijn');
        $this->assertSame(0, PersonalAccessToken::count(), 'PAT-rij moet rollback-zijn');
    }

    public function test_unknown_ability_is_rejected_with_dutch_message(): void
    {
        $service = new ConsumerOnboarding;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Onbekende abilities: invalid:ability');

        $service->onboard([
            'name' => 'Naschool',
            'slug' => 'naschool',
            'token_name' => 'cli-default',
            'abilities' => ['snelstart:read', 'invalid:ability'],
        ]);

        $this->assertSame(0, Consumer::count());
    }

    public function test_duplicate_slug_throws_query_exception_and_rolls_back(): void
    {
        $service = new ConsumerOnboarding;

        $service->onboard([
            'name' => 'Naschool',
            'slug' => 'naschool',
            'token_name' => 'cli-default',
            'abilities' => ['*'],
        ]);

        $this->assertSame(1, Consumer::count());

        $tokenCountBefore = PersonalAccessToken::count();

        try {
            $service->onboard([
                'name' => 'Naschool Tweede',
                'slug' => 'naschool',
                'token_name' => 'cli-default',
                'abilities' => ['*'],
                'external_id' => 'school1',
                'display_name' => 'School A',
            ]);
            $this->fail('Duplicate slug had QueryException moeten gooien');
        } catch (QueryException $e) {
        }

        $this->assertSame(1, Consumer::count());
        $this->assertSame(0, Account::count(), 'Tweede call: Account mag niet half-aangemaakt zijn');
        $this->assertSame($tokenCountBefore, PersonalAccessToken::count(), 'Tweede call: PAT mag niet half-aangemaakt zijn');
    }
}
