<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie;

use Dedoc\Scramble\Attributes\Group;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Payment Methods', description: 'Mollie PaymentMethods API (list).', weight: 54)]
class PaymentMethodsController extends AbstractMolliePassThroughController
{
    public function __invoke(Request $request): Response
    {
        return $this->handle($request, '/v2/methods', function (Request $r) {
            $query = $r->query();
            $params = is_array($query) ? $query : [];

            try {
                $methods = Mollie::client()->methods->all($params);
            } catch (MollieApiException $e) {
                throw MollieExceptionMapper::map($e);
            }

            return $this->collectionToArray($methods);
        });
    }
}
