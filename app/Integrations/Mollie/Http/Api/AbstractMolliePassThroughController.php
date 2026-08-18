<?php

namespace App\Integrations\Mollie\Http\Api;

use App\Enums\Provider;
use App\Http\Controllers\Api\V1\Concerns\GuardsPassThroughRequest;
use App\Integrations\Mollie\Http\Api\Concerns\RendersMollieResult;
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

abstract class AbstractMolliePassThroughController extends Controller
{
    use GuardsPassThroughRequest;
    use RendersMollieResult;

    private const BODY_METHODS = ['POST', 'PATCH'];

    public function __construct(protected readonly PassThroughPipeline $pipeline) {}

    /**
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

    /** @return array<string, mixed> */
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
            }
        }

        return json_decode((string) json_encode($resource), true) ?: [];
    }

    /** @return array<int|string, mixed> */
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
