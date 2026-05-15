<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie;

use App\Http\Requests\Api\V1\Mollie\CreatePaymentLinkRequest;
use Dedoc\Scramble\Attributes\Group;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pass-through controller voor Mollie Payment Links (create + get + list).
 *
 * Beslissingen 05a-CONTEXT.md: D-01 (per-resource), D-04 (typed SDK-calls),
 * D-13 (Mollie-error-mapping), D-14 (ability-gates).
 *
 * Vendor: `MollieApiClient::$paymentLinks` exposes
 * `PaymentLinkEndpointCollection` met create/get/page-methods.
 */
#[Group(name: 'Mollie pass-through', description: 'Forward calls naar het Mollie-account van de gekoppelde Account.', weight: 50)]
class PaymentLinksController extends AbstractMolliePassThroughController
{
    public function index(Request $request): Response
    {
        return $this->handle($request, '/v2/payment-links', function (Request $r) {
            $from = $r->query('from');
            $limit = $r->query('limit');

            try {
                $list = Mollie::client()->paymentLinks->page(
                    is_string($from) ? $from : null,
                    is_numeric($limit) ? (int) $limit : null,
                );
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->collectionToArray($list);
        });
    }

    public function store(CreatePaymentLinkRequest $request): Response
    {
        return $this->handle($request, '/v2/payment-links', function (Request $r) {
            /** @var CreatePaymentLinkRequest $r */
            try {
                $link = $this->buildClient($r)->paymentLinks->create($r->validated());
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return ['status' => 201, 'body' => $this->resourceToArray($link)];
        });
    }

    public function show(Request $request, string $id): Response
    {
        return $this->handle($request, '/v2/payment-links/{id}', function () use ($id) {
            try {
                $link = Mollie::client()->paymentLinks->get($id);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->resourceToArray($link);
        });
    }
}
