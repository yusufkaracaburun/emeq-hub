<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie;

use Dedoc\Scramble\Attributes\Group;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Mandates', description: 'Mollie Mandates API (list per customer + get + revoke).', weight: 51)]
class MandatesController extends AbstractMolliePassThroughController
{
    public function index(Request $request, string $customer_id): Response
    {
        return $this->handle($request, '/v2/customers/{id}/mandates', function (Request $r) use ($customer_id) {
            $from = $r->query('from');
            $limit = $r->query('limit');

            try {
                $list = Mollie::client()->mandates->pageForId(
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

    public function show(Request $request, string $customer_id, string $mandate_id): Response
    {
        return $this->handle($request, '/v2/customers/{id}/mandates/{mandate_id}', function () use ($customer_id, $mandate_id) {
            try {
                $mandate = Mollie::client()->mandates->getForId($customer_id, $mandate_id);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->resourceToArray($mandate);
        });
    }

    public function destroy(Request $request, string $customer_id, string $mandate_id): Response
    {
        return $this->handle($request, '/v2/customers/{id}/mandates/{mandate_id}', function () use ($customer_id, $mandate_id) {
            try {
                Mollie::client()->mandates->revokeForId($customer_id, $mandate_id);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return ['status' => 204, 'body' => []];
        });
    }
}
