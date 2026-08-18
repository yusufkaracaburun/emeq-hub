<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Api;

use App\Integrations\Mollie\Http\Requests\CreateRefundRequest;
use Dedoc\Scramble\Attributes\Group;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Refunds', description: 'Mollie Refunds API (create per-payment + list + get standalone).', weight: 55)]
class RefundsController extends AbstractMolliePassThroughController
{
    public function store(CreateRefundRequest $request, string $payment_id): Response
    {
        return $this->handle($request, '/v2/payments/{id}/refunds', function (Request $r) use ($payment_id) {
            /** @var CreateRefundRequest $r */
            try {
                $refund = $this->buildClient($r)->paymentRefunds->createForId($payment_id, $r->validated());
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return ['status' => 201, 'body' => $this->resourceToArray($refund)];
        });
    }

    public function index(Request $request, string $payment_id): Response
    {
        return $this->handle($request, '/v2/payments/{id}/refunds', function (Request $r) use ($payment_id) {
            $from = $r->query('from');
            $limit = $r->query('limit');

            try {
                $list = Mollie::client()->paymentRefunds->pageForId(
                    $payment_id,
                    is_string($from) ? $from : null,
                    is_numeric($limit) ? (int) $limit : null,
                );
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->collectionToArray($list);
        });
    }

    public function show(Request $request, string $id): Response
    {
        $paymentId = $request->query('paymentId');

        if (! is_string($paymentId) || $paymentId === '') {
            return response()->json([
                'error' => 'missing_payment_id',
                'message' => 'Mollie vereist het bijbehorende paymentId om een refund te lezen. Geef ?paymentId=tr_xxx mee.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->handle($request, '/v2/refunds/{id}', function () use ($paymentId, $id) {
            try {
                $refund = Mollie::client()->paymentRefunds->getForId($paymentId, $id);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->resourceToArray($refund);
        });
    }
}
