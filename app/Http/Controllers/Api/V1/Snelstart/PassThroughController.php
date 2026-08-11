<?php

namespace App\Http\Controllers\Api\V1\Snelstart;

use App\Enums\Provider;
use App\Http\Controllers\Api\V1\Concerns\GuardsPassThroughRequest;
use App\Http\Controllers\Controller;
use App\Integrations\PassThrough\PassThroughContext;
use App\Integrations\PassThrough\PassThroughPipeline;
use App\Integrations\PassThrough\UpstreamResult;
use App\Integrations\Snelstart\PassThrough\HeaderForwarder;
use App\Models\Account;
use App\Models\Connection;
use App\Sanctum\TokenAbilities;
use Dedoc\Scramble\Attributes\Group;
use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;
use Emeq\SnelstartApi\Snelstart;
use Illuminate\Http\Request;
use Saloon\Enums\Method;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Snelstart', description: 'Snelstart OData-calls met de clientKey + subscriptionKey van de gekoppelde Account.', weight: 60)]
class PassThroughController extends Controller
{
    use GuardsPassThroughRequest;

    public function __construct(private readonly PassThroughPipeline $pipeline) {}

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

        /** @var Account $account */
        $account = $request->attributes->get('snelstart_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('snelstart_connection');

        return $this->pipeline->run(
            new PassThroughContext(
                provider: Provider::Snelstart,
                consumerId: $request->user()->getKey(),
                accountId: $account->getKey(),
                connectionId: $connection->getKey(),
                method: $method,
                path: $endpoint,
                query: $query,
                body: $body,
            ),
            function () use ($method, $endpoint, $query, $body, $headers): UpstreamResult {
                /** @var Snelstart $snelstart */
                $snelstart = app(Snelstart::class);

                $sdkResponse = $snelstart->connector()->send(new RawSnelstartRequest(
                    method: Method::from($method),
                    endpoint: $endpoint,
                    query: $query,
                    body: $body,
                    headers: $headers,
                ));

                // De SDK throwt niet automatisch op failed-status — geef de
                // Snelstart-mapped exception (Authentication/Validation/Server/
                // NotFound/RateLimit) een kans om door de foutmapper te worden
                // gemapt naar de juiste Hub-response.
                if ($sdkResponse->failed()) {
                    $sdkResponse->throw();
                }

                return new UpstreamResult(
                    status: $sdkResponse->status(),
                    body: $sdkResponse->body(),
                    contentType: $sdkResponse->header('Content-Type') ?? 'application/json',
                );
            },
        );
    }
}
