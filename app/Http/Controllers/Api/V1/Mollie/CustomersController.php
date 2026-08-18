<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie;

use App\Http\Requests\Api\V1\Mollie\CreateCustomerRequest;
use Dedoc\Scramble\Attributes\Group;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Mollie\Api\Resources\BaseCollection;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Customers', description: 'Mollie Customers API (list/get/create).', weight: 50)]
class CustomersController extends AbstractMolliePassThroughController
{
    public function index(Request $request): Response
    {
        return $this->handle($request, '/v2/customers', function (Request $r) {
            $from = $r->query('from');
            $limit = $r->query('limit');

            try {
                $page = Mollie::client()->customers->page(
                    is_string($from) ? $from : null,
                    is_numeric($limit) ? (int) $limit : null,
                );
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $page instanceof BaseCollection
                ? $this->collectionToArray($page)
                : $this->resourceToArray($page);
        });
    }

    public function show(Request $request, string $id): Response
    {
        return $this->handle($request, '/v2/customers/{id}', function () use ($id) {
            try {
                $customer = Mollie::client()->customers->get($id);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->resourceToArray($customer);
        });
    }

    public function store(CreateCustomerRequest $request): Response
    {
        return $this->handle($request, '/v2/customers', function (Request $r) {
            /** @var CreateCustomerRequest $r */
            try {
                $customer = $this->buildClient($r)->customers->create($r->validated());
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return ['status' => 201, 'body' => $this->resourceToArray($customer)];
        });
    }
}
