<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie;

use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pass-through controller voor Mollie PaymentMethods (list-only).
 *
 * Single-action `__invoke` — er is alleen GET /v2/methods. Mollie's
 * `methods->all($query)` accepteert optioneel filters zoals amount,
 * locale, sequenceType. We geven de hele query-string door zodat de
 * Hub geen Mollie-filter-shape hoeft te dupliceren.
 */
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
