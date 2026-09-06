<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Http\Api;

use App\Http\Middleware\EnsureIdempotency;
use App\Integrations\Itheorie\Http\Api\Concerns\ForwardsToItheorie;
use App\Integrations\Itheorie\Http\Requests\StorePurchaseRequest;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Emeq\ItheorieApi\Data\PurchaseRequest;
use Emeq\ItheorieApi\Exceptions\ItheorieException;
use Emeq\ItheorieApi\Itheorie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class PurchasesController
{
    use ForwardsToItheorie;

    #[ResponseDoc(404, 'Onbekende aankoop, of een aankoop van een andere consumer.')]
    #[ResponseDoc(502, 'iTheorie gaf een foutmelding terug.')]
    public function show(Request $request, Itheorie $itheorie, string $purchase): JsonResponse
    {
        if (! $this->ledger->ownsPurchase($this->consumerId($request), $purchase)) {
            return $this->notFound();
        }

        return $this->fetchPurchase($request, $itheorie, $purchase);
    }

    private function fetchPurchase(Request $request, Itheorie $itheorie, string $purchase): JsonResponse
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
     * Koopt één toegangscode. Onomkeerbaar en gefactureerd.
     *
     * De Idempotency-Key wordt vóór de aankoop duurzaam vastgelegd. Een herhaling
     * met dezelfde sleutel levert de eerste aankoop terug in plaats van een tweede
     * code, ook nadat de idempotency-claim allang is opgeruimd.
     */
    #[ResponseDoc(400, 'Idempotency-Key ontbreekt of is ongeldig.')]
    #[ResponseDoc(409, 'Een eerdere poging met deze sleutel is halverwege afgebroken.')]
    #[ResponseDoc(422, 'iTheorie wees de aanvraag af.')]
    #[ResponseDoc(502, 'iTheorie gaf een foutmelding terug.')]
    public function store(StorePurchaseRequest $request, Itheorie $itheorie): JsonResponse
    {
        $validated = $request->validated();
        $consumerId = $this->consumerId($request);
        $reference = (string) $request->header(EnsureIdempotency::HEADER);

        $link = $this->ledger->claim($consumerId, $reference);

        if ($link->provider_entity_id !== null) {
            return $this->fetchPurchase($request, $itheorie, (string) $link->provider_entity_id);
        }

        if (! $link->wasRecentlyCreated) {
            Log::warning('itheorie.purchase.in_flight', [
                'consumer_id' => $consumerId,
                'reference' => $reference,
                'link_id' => $link->getKey(),
            ]);

            return response()->json([
                'error' => 'purchase_in_flight',
                'message' => 'Een eerdere poging met deze sleutel is afgebroken zonder bekende uitkomst. '
                    .'Mogelijk is er al een code gekocht; neem contact op voordat je het opnieuw probeert.',
            ], Response::HTTP_CONFLICT);
        }

        $purchase = new PurchaseRequest(
            course: (string) $validated['course'],
            name: (string) $validated['name'],
            email: (string) $validated['email'],
            mobilePhone: isset($validated['mobile_phone']) ? (string) $validated['mobile_phone'] : null,
            permissionToShareProgress: isset($validated['permission_to_share_progress'])
                ? (bool) $validated['permission_to_share_progress']
                : null,
        );

        Log::info('itheorie.purchase.attempt', [
            'consumer_id' => $consumerId,
            'reference' => $reference,
            'course' => $validated['course'],
        ]);

        return $this->forward(
            $request,
            'POST',
            '/itheorie/purchases',
            [],
            function () use ($itheorie, $purchase, $link, $consumerId, $reference): array {
                try {
                    $result = $itheorie->createPurchase($purchase);
                } catch (ItheorieException $e) {
                    if ($this->ledger->isDefinitelyNotCharged($e)) {
                        $link->delete();
                    } else {
                        Log::error('itheorie.purchase.outcome_unknown', [
                            'consumer_id' => $consumerId,
                            'reference' => $reference,
                            'kind' => $e->kind->value,
                            'partner_code' => $e->partnerCode,
                        ]);
                    }

                    throw $e;
                }

                $accessCode = $result['access_code'] ?? null;

                $this->ledger->record($link, (string) $result['id'], $accessCode);

                if ($accessCode === null || $accessCode === '') {
                    // Dit is bij iTheorie eerder gebeurd (aankoop 01KC6P19PXV5RXM70B6BX78KR4,
                    // 11-12-2025) en LENS vond de oorzaak niet. De aankoop is dan wél
                    // gefactureerd, dus dit is geld zonder tegenprestatie en het lost
                    // zichzelf niet op.
                    Log::error('itheorie.purchase.zonder_toegangscode', [
                        'consumer_id' => $consumerId,
                        'reference' => $reference,
                        'purchase_id' => $result['id'],
                    ]);
                } else {
                    Log::info('itheorie.purchase.completed', [
                        'consumer_id' => $consumerId,
                        'reference' => $reference,
                        'purchase_id' => $result['id'],
                    ]);
                }

                return $result;
            },
            $validated,
        );
    }
}
