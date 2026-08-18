<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Api;

use App\Integrations\Mollie\Http\Requests\CreatePaymentLinkRequest;
use Dedoc\Scramble\Attributes\Group;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Payment Links', description: 'Mollie Payment Links API (list/get/create).', weight: 53)]
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
