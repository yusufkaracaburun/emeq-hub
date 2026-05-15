<?php

namespace App\Http\Controllers\Api\V1\Mollie;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Connection;
use App\Models\PassThroughCall;
use App\Sanctum\TokenAbilities;
use App\Support\Mollie\MollieUpstreamErrorMapper;
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

        // 1. Ability-guard (D-14)
        $required = $method === 'GET'
            ? [TokenAbilities::MOLLIE_READ, TokenAbilities::MOLLIE_WRITE, TokenAbilities::ADMIN]
            : [TokenAbilities::MOLLIE_WRITE, TokenAbilities::ADMIN];

        $token = $request->user()?->currentAccessToken();
        $hasAbility = $token !== null && collect($required)->contains(fn (string $ability) => $token->can($ability));

        if (! $hasAbility) {
            return response()->json([
                'error' => 'insufficient_ability',
                'message' => 'Token mist vereiste ability voor deze methode.',
            ], Response::HTTP_FORBIDDEN);
        }

        // 2. 415-guard voor write-methods (D-05)
        $body = null;
        if (in_array($method, ['POST', 'PATCH'], true)) {
            $contentType = strtolower((string) $request->header('Content-Type', ''));
            if (! str_starts_with($contentType, 'application/json')) {
                return response()->json([
                    'error' => 'unsupported_content_type',
                    'message' => 'Pass-through accepteert alleen application/json voor POST/PATCH.',
                ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }
            $body = $request->json()->all();
        }

        // 3. SDK-call + exception-mapping
        $start = microtime(true);
        $upstreamError = null;
        $responseBody = '';
        $status = $method === 'POST' ? 201 : 200;
        $extraHeaders = [];

        try {
            $result = $sdkCall($request);
            // Concrete subclass kan {status, body} wrapper returnen voor non-default status
            if (is_array($result) && isset($result['status'], $result['body']) && is_int($result['status']) && is_array($result['body'])) {
                $status = $result['status'];
                $responseBody = json_encode($result['body'], JSON_THROW_ON_ERROR);
            } else {
                $responseBody = json_encode($result, JSON_THROW_ON_ERROR);
            }
        } catch (Throwable $e) {
            $mapped = MollieUpstreamErrorMapper::mapException($e);
            $status = $mapped['status'];
            $responseBody = json_encode($mapped['body'], JSON_THROW_ON_ERROR);
            $extraHeaders = $mapped['headers'];
            $upstreamError = $mapped['short_code'];
        }

        // 4. Audit-write (D-05 — alle drie 5b-CRITICAL-fixes ingebakken)
        /** @var Account $account */
        $account = $request->attributes->get('mollie_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('mollie_connection');
        $query = $request->query();

        PassThroughCall::create([
            'consumer_id' => $request->user()->getKey(),
            'account_id' => $account->getKey(),
            'connection_id' => $connection->getKey(),
            'provider' => 'mollie',
            'method' => $method,
            'path' => $endpoint,                       // CRITICAL: template, GEEN query-string
            'query_keys' => $query !== [] ? implode(',', array_keys($query)) : null,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'request_fingerprint' => (is_array($body) && $body !== [])
                ? substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12)
                : null,                                 // CRITICAL: NULL bij lege body
            'response_size_bytes' => strlen($responseBody),
            'upstream_error' => $upstreamError,
            'created_at' => now(),
        ]);

        return response($responseBody, $status)->withHeaders(array_merge(
            ['Content-Type' => 'application/json'],
            $extraHeaders,
        ));
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
