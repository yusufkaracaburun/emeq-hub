<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie;

use App\Http\Requests\Api\V1\Mollie\CreatePaymentRequest;
use App\Models\Connection;
use Dedoc\Scramble\Attributes\Group;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Mollie\Api\Resources\Payment;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Pass-through controller voor Mollie Payments (create + get + cancel).
 *
 * Beslissingen 05a-CONTEXT.md: D-01 (per-resource), D-02 (Mollie/-folder),
 * D-04 (typed SDK-calls), D-06 (Idempotency-Key forward),
 * D-13 (Mollie-error-mapping), D-14 (mollie:read/write ability-gates).
 *
 * WebhookUrl-injectie (D-08): als Consumer geen webhookUrl in payload zet,
 * vult de Hub automatisch url('/webhooks/mollie/{connection_id}') in zodat
 * Mollie naar onze ingress-controller post (Plan 05a-02).
 *
 * Idempotency-Key forward via AbstractMolliePassThroughController::buildClient (D-06).
 */
#[Group(name: 'Mollie', description: 'Mollie API-calls naar het Mollie-account van de gekoppelde Account.', weight: 50)]
class PaymentsController extends AbstractMolliePassThroughController
{
    public function store(CreatePaymentRequest $request): Response
    {
        return $this->handle($request, '/v2/payments', function (Request $request) {
            $payload = $request->validated();

            if (empty($payload['webhookUrl'])) {
                /** @var Connection $connection */
                $connection = $request->attributes->get('mollie_connection');
                $payload['webhookUrl'] = url("/webhooks/mollie/{$connection->getKey()}");
            }

            $client = $this->buildClient($request);

            try {
                $payment = $client->payments->create($payload);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return ['status' => 201, 'body' => $this->paymentToArray($payment)];
        });
    }

    public function show(Request $request, string $id): Response
    {
        return $this->handle($request, '/v2/payments/{id}', function (Request $request) use ($id) {
            try {
                $payment = Mollie::client()->payments->get($id);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->paymentToArray($payment);
        });
    }

    public function destroy(Request $request, string $id): Response
    {
        return $this->handle($request, '/v2/payments/{id}', function (Request $request) use ($id) {
            try {
                $payment = Mollie::client()->payments->cancel($id);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->paymentToArray($payment);
        });
    }

    /**
     * Mollie's typed resource heeft géén toArray()-method op de base; we
     * serializeren via response-body om de wire-shape (inclusief _links,
     * _embedded) verbatim te bewaren.
     *
     * @return array<string, mixed>
     */
    private function paymentToArray(Payment $payment): array
    {
        $response = $payment->getResponse();

        if ($response !== null) {
            try {
                $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (Throwable) {
                // fallthrough naar object-cast
            }
        }

        // Fallback wanneer test-stub geen origin-Response heeft.
        return json_decode((string) json_encode($payment), true) ?: [];
    }
}
