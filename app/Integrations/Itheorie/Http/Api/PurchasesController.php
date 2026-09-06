<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Http\Api;

use App\Integrations\Itheorie\Http\Api\Concerns\ForwardsToItheorie;
use App\Integrations\Itheorie\Http\Requests\StorePurchaseRequest;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Emeq\ItheorieApi\Data\PurchaseRequest;
use Emeq\ItheorieApi\Itheorie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PurchasesController
{
    use ForwardsToItheorie;

    #[QueryParameter('page', description: 'Paginanummer.', type: 'integer', default: 1)]
    #[QueryParameter('limit', description: 'Aantal aankopen per pagina.', type: 'integer', default: 50)]
    #[ResponseDoc(502, 'iTheorie gaf een foutmelding terug.')]
    public function index(Request $request, Itheorie $itheorie): JsonResponse
    {
        ['page' => $page, 'limit' => $limit] = $this->pagination($request);

        return $this->forward(
            $request,
            'GET',
            '/itheorie/purchases',
            ['page' => $page, 'limit' => $limit],
            static fn (): array => $itheorie->purchases($page, $limit),
        );
    }

    #[ResponseDoc(404, 'Aankoop bestaat niet bij iTheorie.')]
    #[ResponseDoc(502, 'iTheorie gaf een foutmelding terug.')]
    public function show(Request $request, Itheorie $itheorie, string $purchase): JsonResponse
    {
        return $this->forward(
            $request,
            'GET',
            '/itheorie/purchases/{purchase}',
            [],
            static fn (): array => $itheorie->purchase($purchase),
        );
    }

    /**
     * Koopt één toegangscode. Onomkeerbaar en gefactureerd, dus Idempotency-Key is verplicht.
     */
    #[ResponseDoc(400, 'Idempotency-Key ontbreekt of is ongeldig.')]
    #[ResponseDoc(422, 'iTheorie wees de aanvraag af.')]
    #[ResponseDoc(502, 'iTheorie gaf een foutmelding terug.')]
    public function store(StorePurchaseRequest $request, Itheorie $itheorie): JsonResponse
    {
        $validated = $request->validated();

        $purchase = new PurchaseRequest(
            course: (string) $validated['course'],
            name: (string) $validated['name'],
            email: (string) $validated['email'],
            mobilePhone: isset($validated['mobile_phone']) ? (string) $validated['mobile_phone'] : null,
            permissionToShareProgress: isset($validated['permission_to_share_progress'])
                ? (bool) $validated['permission_to_share_progress']
                : null,
        );

        return $this->forward(
            $request,
            'POST',
            '/itheorie/purchases',
            [],
            static fn (): array => $itheorie->createPurchase($purchase),
            $validated,
        );
    }
}
