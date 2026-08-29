<?php

declare(strict_types=1);

namespace App\Integrations\DataForSeo\Http\Api;

use App\Enums\Provider;
use App\Integrations\PassThrough\PassThroughContext;
use App\Integrations\PassThrough\PassThroughPipeline;
use App\Integrations\PassThrough\UpstreamResult;
use App\Models\Account;
use App\Models\Connection;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Emeq\DataForSeoApi\DataForSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

final class DomainOverviewController
{
    public function __construct(
        private readonly PassThroughPipeline $pipeline,
    ) {}

    #[QueryParameter('domain', description: 'Domein waarvoor het overzicht wordt opgevraagd.', required: true, type: 'string')]
    #[QueryParameter('location_name', description: 'DataForSEO-locatienaam.', type: 'string', default: 'Netherlands')]
    #[ResponseDoc(404, 'Geen actieve DataForSEO-Connection voor dit Account.')]
    #[ResponseDoc(502, 'DataForSEO gaf een foutmelding terug.')]
    public function show(Request $request, DataForSeo $dataForSeo): JsonResponse
    {
        $domain = $request->string('domain')->toString();

        if ($domain === '') {
            return response()->json([
                'error' => 'missing_domain',
                'message' => 'Query parameter "domain" is vereist.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $locationName = $request->string('location_name', 'Netherlands')->toString();

        /** @var Account $account */
        $account = $request->attributes->get('dataforseo_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('dataforseo_connection');

        $query = [
            'domain' => $domain,
            'location_name' => $locationName,
        ];

        try {
            $response = $this->pipeline->run(
                new PassThroughContext(
                    provider: Provider::DataForSeo,
                    consumerId: (int) $request->user()->getKey(),
                    accountId: (int) $account->getKey(),
                    connectionId: (int) $connection->getKey(),
                    method: 'GET',
                    path: '/dataforseo/domain-overview',
                    query: $query,
                ),
                function () use ($dataForSeo, $domain, $locationName): UpstreamResult {
                    $result = $dataForSeo->domainOverview($domain, $locationName);

                    $body = json_encode($result, JSON_THROW_ON_ERROR);

                    return new UpstreamResult(
                        status: 200,
                        body: $body,
                        contentType: 'application/json',
                    );
                },
            );
        } catch (JsonException) {
            return response()->json([
                'error' => 'serialization_error',
                'message' => 'Interne fout bij het serialiseren van het DataForSEO-antwoord.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        /** @var int $status */
        $status = $response->getStatusCode();
        /** @var string $content */
        $content = $response->getContent();

        $data = json_decode($content, true);

        return response()->json($data, $status);
    }
}
