<?php

namespace App\Http\Controllers\Api\V1\Snelstart;

use App\Enums\Provider;
use App\Http\Controllers\Api\V1\Concerns\GuardsPassThroughRequest;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Connection;
use App\Sanctum\TokenAbilities;
use App\Support\PassThrough\PassThroughRecorder;
use App\Support\Snelstart\HeaderForwarder;
use App\Support\Snelstart\UpstreamErrorMapper;
use Dedoc\Scramble\Attributes\Group;
use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;
use Emeq\SnelstartApi\Snelstart;
use Illuminate\Http\Request;
use Saloon\Enums\Method;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[Group(name: 'Snelstart', description: 'Snelstart OData-calls met de clientKey + subscriptionKey van de gekoppelde Account.', weight: 60)]
class PassThroughController extends Controller
{
    use GuardsPassThroughRequest;

    public function __construct(private readonly PassThroughRecorder $recorder) {}

    private const ALLOWED_METHODS = ['GET', 'POST', 'PATCH', 'DELETE'];

    private const BODY_METHODS = ['POST', 'PATCH'];

    public function __invoke(Request $request, string $path): Response
    {
        $method = strtoupper($request->method());

        if ($response = $this->guardMethodAllowed($method, self::ALLOWED_METHODS)) {
            return $response;
        }

        $required = $method === 'GET'
            ? [TokenAbilities::SNELSTART_READ, TokenAbilities::SNELSTART_WRITE, TokenAbilities::ADMIN]
            : [TokenAbilities::SNELSTART_WRITE, TokenAbilities::ADMIN];

        if ($response = $this->guardTokenAbility($request, $required)) {
            return $response;
        }

        if ($response = $this->guardJsonContentType($request, $method, self::BODY_METHODS)) {
            return $response;
        }

        $body = in_array($method, self::BODY_METHODS, true) ? $request->json()->all() : null;

        $endpoint = '/'.ltrim($path, '/');
        $query = $request->query();
        $headers = HeaderForwarder::forward($request);

        $start = microtime(true);
        $upstreamError = null;
        $responseBody = '';
        $status = 0;
        $contentType = 'application/json';
        $extraHeaders = [];

        try {
            /** @var Snelstart $snelstart */
            $snelstart = app(Snelstart::class);

            $sdkRequest = new RawSnelstartRequest(
                method: Method::from($method),
                endpoint: $endpoint,
                query: $query,
                body: $body,
                headers: $headers,
            );

            $sdkResponse = $snelstart->connector()->send($sdkRequest);

            // De SDK throwt niet automatisch op failed-status — geef de
            // Snelstart-mapped exception (Authentication/Validation/Server/
            // NotFound/RateLimit) een kans om door UpstreamErrorMapper te
            // worden gemapt naar de juiste Hub-response.
            if ($sdkResponse->failed()) {
                $sdkResponse->throw();
            }

            $status = $sdkResponse->status();
            $responseBody = $sdkResponse->body();
            $contentType = $sdkResponse->header('Content-Type') ?? 'application/json';
        } catch (Throwable $e) {
            $mapped = UpstreamErrorMapper::mapException($e);
            $status = $mapped['status'];
            $responseBody = json_encode($mapped['body'], JSON_THROW_ON_ERROR);
            $contentType = 'application/json';
            $extraHeaders = $mapped['headers'];
            $upstreamError = $mapped['short_code'];
        }

        /** @var Account $account */
        $account = $request->attributes->get('snelstart_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('snelstart_connection');

        $this->recorder->record(
            provider: Provider::Snelstart,
            consumerId: $request->user()->getKey(),
            accountId: $account->getKey(),
            connectionId: $connection->getKey(),
            method: $method,
            path: $endpoint,
            status: $status,
            responseBody: $responseBody,
            startedAt: $start,
            query: $query,
            body: $body,
            upstreamError: $upstreamError,
        );

        return response($responseBody, $status)->withHeaders(array_merge(
            ['Content-Type' => $contentType],
            $extraHeaders,
        ));
    }
}
