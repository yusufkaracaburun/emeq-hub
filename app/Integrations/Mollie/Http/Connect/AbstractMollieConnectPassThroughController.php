<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Connect;

use App\Enums\Provider;
use App\Http\Controllers\Api\V1\Concerns\GuardsPassThroughRequest;
use App\Http\Controllers\Controller;
use App\Integrations\Mollie\Exceptions\MissingPartnerTokenException;
use App\Integrations\Mollie\Http\Api\Concerns\RendersMollieResult;
use App\Integrations\Mollie\MollieAccessTokenResolver;
use App\Integrations\PassThrough\PassThroughContext;
use App\Integrations\PassThrough\PassThroughPipeline;
use App\Integrations\PassThrough\UpstreamResult;
use App\Sanctum\TokenAbilities;
use Emeq\MollieApi\Exceptions\MollieExceptionMapper;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException as MollieApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\BaseCollection;
use Mollie\Api\Resources\BaseResource;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

abstract class AbstractMollieConnectPassThroughController extends Controller
{
    use GuardsPassThroughRequest;
    use RendersMollieResult;

    private const BODY_METHODS = ['POST', 'PATCH'];

    private ?string $resolvedPartnerToken = null;

    public function __construct(
        protected readonly MollieAccessTokenResolver $tokenResolver,
        protected readonly PassThroughPipeline $pipeline,
    ) {}

    /**
     * @throws MissingPartnerTokenException wanneer handle() de token nog niet
     *                                      heeft geresolved (bv. directe
     *                                      client()-call buiten het handle()-
     *                                      frame); enforce't WR-03's contract
     *                                      type-systeem-niveau.
     */
    protected function client(Request $request): MollieApiClient
    {
        if ($this->resolvedPartnerToken === null) {
            throw new MissingPartnerTokenException;
        }

        $client = app(MollieApiClient::class);
        $client->setAccessToken($this->resolvedPartnerToken);

        $consumerKey = $request->header('Idempotency-Key');
        if (is_string($consumerKey) && $consumerKey !== '') {
            $client->setIdempotencyKey($consumerKey);
        }

        return $client;
    }

    protected function dispatchMollieCall(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (MollieApiException $e) {
            throw MollieExceptionMapper::map($e);
        }
    }

    /**
     * @param  string  $endpoint  Endpoint-template zonder query-string, bv.
     *                            '/v2/onboarding/me' of '/v2/profiles/{id}'.
     * @param  callable(Request): array<string,mixed>  $sdkCall  Levert de
     *                                                           Mollie-resource-array terug. Mag
     *                                                           {status, body} returnen voor non-default status.
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

        $partnerFingerprint = null;
        $tokenMissing = false;
        try {
            $this->resolvedPartnerToken = $this->tokenResolver->resolveFor('partner');
            $partnerFingerprint = substr(hash('sha256', $this->resolvedPartnerToken), 0, 12);
        } catch (MissingPartnerTokenException) {
            $tokenMissing = true;
        }

        return $this->pipeline->run(
            new PassThroughContext(
                provider: Provider::Mollie,
                consumerId: $request->user()->getKey(),
                accountId: null,
                connectionId: null,
                method: $method,
                path: $endpoint,
                query: $request->query(),
                body: $body,
                direction: 'outbound',
                extra: [
                    'token_type' => 'partner',
                    'partner_token_fingerprint' => $partnerFingerprint,
                ],
            ),
            function () use ($sdkCall, $request, $method, $tokenMissing): UpstreamResult {
                if ($tokenMissing) {
                    throw new MissingPartnerTokenException;
                }

                return $this->toUpstreamResult($sdkCall($request), $method);
            },
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
}
