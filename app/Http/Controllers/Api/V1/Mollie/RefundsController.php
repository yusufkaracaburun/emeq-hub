<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie;

use App\Http\Requests\Api\V1\Mollie\CreateRefundRequest;
use Dedoc\Scramble\Attributes\Group;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pass-through controller voor Mollie Refunds (create + list nested
 * onder Payment + get standalone).
 *
 * Beslissingen 05a-CONTEXT.md / 05a-04-PLAN.md: D-01 (per-resource),
 * D-04 (typed SDK-calls), D-13 (Mollie-error-mapping), D-14 (ability-gates).
 *
 * Plan-deviatie (Rule 1): Mollie's RefundEndpointCollection heeft geen
 * `get(string $id)` — alleen `page()` voor list-all-refunds. De
 * standalone-route /v1/mollie/refunds/{id} mapt daarom intern naar
 * `paymentRefunds->getForId($paymentId, $refundId)` en vereist een
 * `?paymentId=tr_xxx` query-parameter. Audit-path blijft het
 * Mollie-REST-endpoint-template `/v2/refunds/{id}`.
 */
#[Group(name: 'Mollie pass-through', description: 'Forward calls naar het Mollie-account van de gekoppelde Account.', weight: 50)]
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
