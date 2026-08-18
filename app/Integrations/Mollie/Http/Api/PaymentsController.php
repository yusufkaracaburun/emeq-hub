<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Api;

use App\Integrations\Mollie\Http\Requests\CreatePaymentRequest;
use App\Models\Connection;
use Dedoc\Scramble\Attributes\Group;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Mollie\Api\Resources\Payment;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[Group(name: 'Mollie · Payments', description: 'Mollie Payments API (create/get/cancel).', weight: 52)]
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

    /** @return array<string, mixed> */
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
            }
        }

        return json_decode((string) json_encode($payment), true) ?: [];
    }
}
