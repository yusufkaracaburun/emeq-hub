<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mollie;

use App\Models\PassThroughCall;
use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Exceptions\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

class MolliePassThroughAuditTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    public function test_audit_row_after_post_has_provider_mollie_and_correct_path_template(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);
        $this->bindMollieStub(fn () => $this->makePayment(['id' => 'tr_audit_1', 'status' => 'open']));

        $this->callMollie($token, 'POST', '/v1/mollie/payments', [
            'description' => 'audit-test',
            'amount' => ['currency' => 'EUR', 'value' => '3.00'],
        ])->assertCreated();

        $row = PassThroughCall::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('mollie', $row->provider);
        $this->assertSame('POST', $row->method);
        $this->assertSame('/v2/payments', $row->path);
        $this->assertNull($row->query_keys);
        $this->assertSame(201, $row->status);
    }

    public function test_audit_row_for_get_with_query_string_stores_query_keys_only_not_values(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_READ]);
        $this->bindMollieStub(fn (string $op, mixed $arg) => $this->makePayment([
            'id' => $arg,
            'status' => 'paid',
        ]));

        $this->callMollie($token, 'GET', '/v1/mollie/payments/tr_q?include=details,refunds&embed=foo')
            ->assertOk();

        $row = PassThroughCall::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('/v2/payments/{id}', $row->path);
        $this->assertNotNull($row->query_keys);
        $keys = explode(',', $row->query_keys);
        $this->assertEqualsCanonicalizing(['include', 'embed'], $keys);
        $this->assertStringNotContainsString('details', $row->query_keys);
        $this->assertStringNotContainsString('refunds', $row->query_keys);
    }

    public function test_audit_row_request_fingerprint_is_null_for_empty_post_body(): void
    {
        [, $token] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);

        $this->bindMollieStub(fn () => new ValidationException(message: 'description is required', field: 'description'));

        $this->bindMollieStub(fn (string $op, mixed $arg) => $this->makePayment([
            'id' => $arg,
            'status' => 'paid',
        ]));

        $this->callMollie($token, 'GET', '/v1/mollie/payments/tr_empty_body')->assertOk();

        $row = PassThroughCall::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->request_fingerprint, 'GET-call zonder body moet NULL fingerprint hebben (D-05 fix)');
    }

    public function test_audit_row_does_not_contain_access_token_or_credentials(): void
    {
        $rawToken = 'access_test_RAWTOKEN_DO_NOT_LEAK_'.bin2hex(random_bytes(8));

        [$consumer, $token, $account, $connection] = $this->setupMollieConsumer([TokenAbilities::MOLLIE_WRITE]);
        $connection->access_token = $rawToken;
        $connection->save();

        $this->bindMollieStub(fn () => $this->makePayment(['id' => 'tr_no_leak', 'status' => 'open']));

        $this->callMollie($token, 'POST', '/v1/mollie/payments', [
            'description' => 'no-leak-test',
            'amount' => ['currency' => 'EUR', 'value' => '1.00'],
        ])->assertCreated();

        $row = PassThroughCall::query()->latest('id')->first();
        $this->assertNotNull($row);

        foreach ($row->getAttributes() as $col => $value) {
            if (! is_string($value)) {
                continue;
            }
            $this->assertStringNotContainsString(
                'access_test_RAWTOKEN_DO_NOT_LEAK',
                $value,
                "Audit-kolom {$col} bevat raw access_token — security-leak.",
            );
        }
    }
}
