<?php

namespace App\Http\Controllers\Api\V1\Mollie;

use App\Enums\Provider;
use App\Http\Controllers\Api\V1\Concerns\GuardsPassThroughRequest;
use App\Http\Controllers\Api\V1\Mollie\Concerns\RendersMollieResult;
use App\Http\Controllers\Controller;
use App\Integrations\PassThrough\PassThroughContext;
use App\Integrations\PassThrough\PassThroughPipeline;
use App\Integrations\PassThrough\UpstreamResult;
use App\Models\Account;
use App\Models\Connection;
use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Facades\Mollie;
use Illuminate\Http\Request;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\BaseCollection;
use Mollie\Api\Resources\BaseResource;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Abstract base voor Mollie-pass-through-controllers. Concrete subclasses
 * leveren een SDK-call via de $sdkCall callable; deze base regelt
 * ability-guard (D-14), 415-guard (D-05), exception-mapping (D-13),
 * audit-write naar pass_through_calls (D-05) en response-render.
 *
 * Beslissingen: 05a-CONTEXT.md §<decisions> D-01, D-05, D-13, D-14.
 */
abstract class AbstractMolliePassThroughController extends Controller
{
    use GuardsPassThroughRequest;
    use RendersMollieResult;

    private const BODY_METHODS = ['POST', 'PATCH'];

    public function __construct(protected readonly PassThroughPipeline $pipeline) {}

    /**
     * Voer een Mollie-SDK-call uit binnen het pass-through-frame.
     *
     * @param  string  $endpoint  Endpoint-template ZONDER query-string, bv.
     *                            '/v2/payments' of '/v2/payments/{id}'.
     *                            Komt verbatim in de pass_through_calls.path-kolom.
     * @param  callable(Request): array<string,mixed>  $sdkCall  Levert de
     *                                                           Mollie-resource-array (uit ->toArray()) terug.
     *                                                           Mag een wrapper-array {status, body} returnen
     *                                                           om non-default status (bv. 201) te forceren.
     */
    protected function handle(Request $request, string $endpoint, callable $sdkCall): Response
    {
        $method = strtoupper($request->method());

        $required = $method === 'GET'
            ? [TokenAbilities::MOLLIE_READ, TokenAbilities::MOLLIE_WRITE, TokenAbilities::ADMIN]
            : [TokenAbilities::MOLLIE_WRITE, TokenAbilities::ADMIN];

        if ($response = $this->guardTokenAbility($request, $required)) {
            return $response;
        }

        if ($response = $this->guardJsonContentType($request, $method, self::BODY_METHODS)) {
            return $response;
        }

        $body = in_array($method, self::BODY_METHODS, true) ? $request->json()->all() : null;

        /** @var Account $account */
        $account = $request->attributes->get('mollie_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('mollie_connection');

        return $this->pipeline->run(
            new PassThroughContext(
                provider: Provider::Mollie,
                consumerId: $request->user()->getKey(),
                accountId: $account->getKey(),
                connectionId: $connection->getKey(),
                method: $method,
                path: $endpoint,
                query: $request->query(),
                body: $body,
            ),
            fn (): UpstreamResult => $this->toUpstreamResult($sdkCall($request), $method),
        );
    }

    /**
     * Serializeer een Mollie BaseResource (Customer/Payment/Refund/Mandate/...)
     * via response-body om de wire-shape verbatim te bewaren. Fallback
     * naar JsonSerializable wanneer test-stubs geen origin-Response hebben.
     *
     * @return array<string, mixed>
     */
    protected function resourceToArray(BaseResource $resource): array
    {
        $response = $resource->getResponse();

        if ($response !== null) {
            try {
                $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (Throwable) {
                // fallthrough naar object-cast
            }
        }

        return json_decode((string) json_encode($resource), true) ?: [];
    }

    /**
     * Serializeer een Mollie BaseCollection (CustomerCollection,
     * MethodCollection, RefundCollection, MandateCollection, ...) naar een
     * array. Bewaart Mollie's response-shape inclusief _links/_embedded
     * wanneer beschikbaar; valt anders terug op JsonSerializable.
     *
     * @return array<int|string, mixed>
     */
    protected function collectionToArray(BaseCollection $collection): array
    {
        $response = $collection->getResponse();

        if ($response !== null) {
            try {
                $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (Throwable) {
                // fallthrough
            }
        }

        $items = [];
        foreach ($collection as $item) {
            if ($item instanceof BaseResource) {
                $items[] = $this->resourceToArray($item);
            } else {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Bouwt een MollieApiClient voor de huidige request. Forward't de
     * Consumer's Idempotency-Key-header naar Mollie via de runtime-setter
     * (MollieApiClient::setIdempotencyKey()). De default UuidV7-generator
     * blijft de fallback zonder Consumer-header.
     *
     * Gedeeld pad voor alle 5 write-endpoints (D-06 / 05a-06-PLAN). PaymentsController
     * gebruikte 'm eerst als eigen method; gehoisd hierheen na verificatie-gap CR-01.
     */
    protected function buildClient(Request $request): MollieApiClient
    {
        $client = Mollie::client();

        $consumerKey = $request->header('Idempotency-Key');
        if (is_string($consumerKey) && $consumerKey !== '') {
            $client->setIdempotencyKey($consumerKey);
        }

        return $client;
    }
}
