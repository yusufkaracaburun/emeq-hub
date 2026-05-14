<?php

namespace Tests\Feature\Api\V1\Snelstart;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Concerns\PrimesSnelstartTokenCache;
use Tests\TestCase;

/**
 * Bewijst HUB-05 SC-7: raw credentials en request-body verschijnen nergens
 * in een pass_through_calls-rij. Fingerprint-only.
 */
class PassThroughAuditNoSecretsTest extends TestCase
{
    use PrimesSnelstartTokenCache;
    use RefreshDatabase;

    private const RAW_CLIENT_KEY = 'CK-test-rawkey-DO-NOT-LEAK';

    private const RAW_SUBSCRIPTION_KEY = 'SK-test-rawsubkey-DO-NOT-LEAK';

    private const SECRET_BODY_VALUE = 'SECRET-FROM-BODY';

    protected function setUp(): void
    {
        parent::setUp();
        MockClient::destroyGlobal();
        config(['snelstart.http.retry.times' => 1, 'snelstart.http.retry.sleep' => 0]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();
        parent::tearDown();
    }

    public function test_audit_row_after_successful_passthrough_contains_no_raw_client_key(): void
    {
        $this->doPassThroughCallWithRawSecrets();

        $row = (array) DB::table('pass_through_calls')->latest('id')->first();

        foreach ($row as $col => $val) {
            if (is_string($val)) {
                $this->assertStringNotContainsString(
                    self::RAW_CLIENT_KEY,
                    $val,
                    "Audit-kolom {$col} bevat raw clientKey.",
                );
            }
        }
    }

    public function test_audit_row_does_not_contain_subscription_key(): void
    {
        $this->doPassThroughCallWithRawSecrets();

        $row = (array) DB::table('pass_through_calls')->latest('id')->first();

        foreach ($row as $col => $val) {
            if (is_string($val)) {
                $this->assertStringNotContainsString(
                    self::RAW_SUBSCRIPTION_KEY,
                    $val,
                    "Audit-kolom {$col} bevat raw subscriptionKey.",
                );
            }
        }
    }

    public function test_audit_row_does_not_contain_request_body_for_post(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forSnelstart()->for($account)->create([
            'client_key' => self::RAW_CLIENT_KEY,
            'subscription_key' => self::RAW_SUBSCRIPTION_KEY,
        ]);
        $this->primeSnelstartToken($connection);

        $token = $consumer->createToken('test', [TokenAbilities::SNELSTART_WRITE])->plainTextToken;

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(['id' => 'r-1'], 201),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->postJson('/v1/snelstart/relaties', [
                'naam' => self::SECRET_BODY_VALUE,
            ])
            ->assertCreated();

        $row = (array) DB::table('pass_through_calls')->latest('id')->first();

        foreach ($row as $col => $val) {
            if (is_string($val)) {
                $this->assertStringNotContainsString(
                    self::SECRET_BODY_VALUE,
                    $val,
                    "Audit-kolom {$col} lekt raw request-body.",
                );
            }
        }

        // fingerprint is wel gezet (12-char sha256-prefix), niet de body.
        $this->assertNotNull($row['request_fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', (string) $row['request_fingerprint']);
    }

    public function test_empty_post_body_yields_null_fingerprint(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forSnelstart()->for($account)->create();
        $this->primeSnelstartToken($connection);

        $token = $consumer->createToken('test', [TokenAbilities::SNELSTART_WRITE])->plainTextToken;

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(['ok' => true], 201),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->postJson('/v1/snelstart/relaties', [])
            ->assertCreated();

        $row = (array) DB::table('pass_through_calls')->latest('id')->first();
        $this->assertNull($row['request_fingerprint'], 'Lege POST-body mag geen constante fingerprint produceren');
    }

    private function doPassThroughCallWithRawSecrets(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forSnelstart()->for($account)->create([
            'client_key' => self::RAW_CLIENT_KEY,
            'subscription_key' => self::RAW_SUBSCRIPTION_KEY,
        ]);
        $this->primeSnelstartToken($connection);

        $token = $consumer->createToken('test', [TokenAbilities::SNELSTART_READ])->plainTextToken;

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(['pong' => 'ok'], 200),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertOk();
    }
}
