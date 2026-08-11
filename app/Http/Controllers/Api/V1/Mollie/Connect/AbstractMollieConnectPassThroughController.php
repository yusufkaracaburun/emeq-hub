<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie\Connect;

use App\Enums\Provider;
use App\Http\Controllers\Api\V1\Concerns\GuardsPassThroughRequest;
use App\Http\Controllers\Api\V1\Mollie\Concerns\RendersMollieResult;
use App\Http\Controllers\Controller;
use App\Integrations\Mollie\Exceptions\MissingPartnerTokenException;
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

/**
 * Abstract base voor Mollie-Connect-pass-through-controllers — gescheiden
 * hiërarchie van de Phase-5a merchant-base (D-03). Bouwt elke call een vers
 * MollieApiClient-instance via de container, bindt de partner-access-token
 * via MollieAccessTokenResolver, wikkelt élke SDK-call via
 * dispatchMollieCall() zodat raw Mollie\Api\Exceptions\ApiException eerst
 * door MollieExceptionMapper::map() naar de Hub-exception-tree wordt
 * genormaliseerd voordat UpstreamErrorMapper het pad kiest, en schrijft
 * een audit-rij met token_type='partner', connection_id=NULL, account_id=NULL.
 *
 * Beslissingen 13-CONTEXT.md §<decisions>: D-03 (gescheiden hiërarchie),
 * D-07 (geen resolve.mollie.account), D-11 (token_type-kolom), D-14 (mollie:*
 * abilities), MOLL-05 SC-1 (per-resource 401-error-mapping via dispatchMollieCall).
 */
abstract class AbstractMollieConnectPassThroughController extends Controller
{
    use GuardsPassThroughRequest;
    use RendersMollieResult;

    private const BODY_METHODS = ['POST', 'PATCH'];

    /**
     * Partner-access-token geresolved per request door handle(). Gecached zodat
     * client() én de audit-fingerprint dezelfde waarde delen — voorkomt dat
     * env-rotatie tussen SDK-call en audit-write de fingerprint laat afwijken
     * van de daadwerkelijk verzonden token (WR-02).
     */
    private ?string $resolvedPartnerToken = null;

    public function __construct(
        protected readonly MollieAccessTokenResolver $tokenResolver,
        protected readonly PassThroughPipeline $pipeline,
    ) {}

    /**
     * Bouwt een MollieApiClient voor de huidige request:
     *   1. Resolved via de container (app(MollieApiClient::class)) zodat tests
     *      een spy/stub kunnen injecteren via $this->app->instance(...).
     *   2. Zet de eerder door handle() geresolvede partner-access-token op de
     *      client. handle() resolved 'm vóór de SDK-call zodat fingerprint én
     *      upstream-call gegarandeerd dezelfde waarde gebruiken (WR-02).
     *   3. Forward't de Consumer's Idempotency-Key-header naar de SDK.
     *
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

    /**
     * Centrale exception-wrapper rond élke Mollie-SDK-call. Vangt raw
     * \Mollie\Api\Exceptions\ApiException, mapt 'm via MollieExceptionMapper::map()
     * naar de Hub-exception-tree (AuthenticationException, ValidationException,
     * etc.) en gooit door. Reden: UpstreamErrorMapper matcht uitsluitend
     * op de Hub-exception-typen — raw ApiException valt anders door naar de
     * catch-all mollie_unknown (502) i.p.v. de juiste 401→502 mollie_auth_failed-
     * branch (MOLL-05 SC-1).
     */
    protected function dispatchMollieCall(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (MollieApiException $e) {
            throw MollieExceptionMapper::map($e);
        }
    }

    /**
     * Voer een Connect-SDK-call uit binnen het pass-through-frame.
     *
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

        // Partner-token één keer resolven — voor zowel client() als de audit-
        // fingerprint, zodat ze gegarandeerd dezelfde waarde delen (WR-02).
        $partnerFingerprint = null;
        $tokenMissing = false;
        try {
            $this->resolvedPartnerToken = $this->tokenResolver->resolveFor('partner');
            $partnerFingerprint = substr(hash('sha256', $this->resolvedPartnerToken), 0, 12);
        } catch (MissingPartnerTokenException) {
            // Partner-token niet geconfigureerd — de call hieronder gooit 'm alsnog,
            // zodat de foutmapper er een 503 van maakt; auditrij krijgt NULL fingerprint.
            $tokenMissing = true;
        }

        return $this->pipeline->run(
            new PassThroughContext(
                provider: Provider::Mollie,
                consumerId: $request->user()->getKey(),
                // Connect-calls lopen op het partner-token, niet op een Connection van een
                // Account — vandaar geen tenant-kolommen maar wel een token-fingerprint.
                accountId: null,
                connectionId: null,
                method: $method,
                path: $endpoint,
                query: $request->query(),
                body: $body,
                // Expliciet — matched de factory-default + voorkomt dat pre-save
                // $model->direction-reads NULL teruggeven (WR-05).
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

    /**
     * Serializeer een Mollie BaseResource via response-body (wire-shape
     * verbatim, inclusief _links/_embedded). Fallback naar JsonSerializable
     * voor test-stubs zonder origin-Response. Chirurgische duplicatie van de
     * Phase-5a-base (D-03 staat dat toe boven generieke abstract-explosion).
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
                // fallthrough
            }
        }

        return json_decode((string) json_encode($resource), true) ?: [];
    }

    /**
     * Serializeer een Mollie BaseCollection (ProfileCollection,
     * PermissionCollection, ...) via response-body.
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
}
