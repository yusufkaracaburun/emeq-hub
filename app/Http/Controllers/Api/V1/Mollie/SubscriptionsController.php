<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie;

use App\Http\Requests\Api\V1\Mollie\CreateSubscriptionRequest;
use Dedoc\Scramble\Attributes\Group;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Subscriptions', description: 'Mollie Subscriptions API (nested onder customer; list/get/create/cancel).', weight: 56)]
class SubscriptionsController extends AbstractMolliePassThroughController
{
    public function index(Request $request, string $customer_id): Response
    {
        return $this->handle($request, '/v2/customers/{id}/subscriptions', function (Request $r) use ($customer_id) {
            $from = $r->query('from');
            $limit = $r->query('limit');

            try {
                $list = Mollie::client()->subscriptions->pageForId(
                    $customer_id,
                    is_string($from) ? $from : null,
                    is_numeric($limit) ? (int) $limit : null,
                );
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->collectionToArray($list);
        });
    }

    public function store(CreateSubscriptionRequest $request, string $customer_id): Response
    {
        return $this->handle($request, '/v2/customers/{id}/subscriptions', function (Request $r) use ($customer_id) {
            /** @var CreateSubscriptionRequest $r */
            try {
                $subscription = $this->buildClient($r)->subscriptions->createForId($customer_id, $r->validated());
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return ['status' => 201, 'body' => $this->resourceToArray($subscription)];
        });
    }

    public function show(Request $request, string $customer_id, string $sub_id): Response
    {
        return $this->handle($request, '/v2/customers/{id}/subscriptions/{sub_id}', function () use ($customer_id, $sub_id) {
            try {
                $subscription = Mollie::client()->subscriptions->getForId($customer_id, $sub_id);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->resourceToArray($subscription);
        });
    }

    public function destroy(Request $request, string $customer_id, string $sub_id): Response
    {
        return $this->handle($request, '/v2/customers/{id}/subscriptions/{sub_id}', function () use ($customer_id, $sub_id) {
            try {
                $cancelled = Mollie::client()->subscriptions->cancelForId($customer_id, $sub_id);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->resourceToArray($cancelled);
        });
    }
}
