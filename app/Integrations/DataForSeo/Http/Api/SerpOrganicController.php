<?php

declare(strict_types=1);

namespace App\Integrations\DataForSeo\Http\Api;

use App\Enums\Provider;
use App\Integrations\PassThrough\PassThroughContext;
use App\Integrations\PassThrough\PassThroughPipeline;
use App\Integrations\PassThrough\UpstreamResult;
use App\Models\Account;
use App\Models\Connection;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Emeq\DataForSeoApi\DataForSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

final class SerpOrganicController
{
    public function __construct(
        private readonly PassThroughPipeline $pipeline,
    ) {}

    #[ResponseDoc(404, 'Geen actieve DataForSEO-Connection voor dit Account.')]
    #[ResponseDoc(503, 'DataForSEO gaf een foutmelding terug.')]
    public function show(Request $request, DataForSeo $dataForSeo): JsonResponse
    {
        /** @var array{keyword: string} $validated */
        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:700'],
            'location_code' => ['sometimes', 'integer'],
            'language_code' => ['sometimes', 'string', 'max:8'],
            'depth' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'device' => ['sometimes', 'in:desktop,mobile'],
            'load_async_ai_overview' => ['sometimes', 'boolean'],
        ]);

        $keyword = $validated['keyword'];
        $options = [];

        foreach (['location_code' => 'integer', 'language_code' => 'string', 'depth' => 'integer', 'device' => 'string', 'load_async_ai_overview' => 'boolean'] as $field => $type) {
            if ($request->has($field)) {
                $options[$field] = match ($type) {
                    'integer' => $request->integer($field),
                    'boolean' => $request->boolean($field),
                    default => $request->string($field)->toString(),
                };
            }
        }

        /** @var Account $account */
        $account = $request->attributes->get('dataforseo_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('dataforseo_connection');

        try {
            $response = $this->pipeline->run(
                new PassThroughContext(
                    provider: Provider::DataForSeo,
                    consumerId: (int) $request->user()->getKey(),
                    accountId: (int) $account->getKey(),
                    connectionId: (int) $connection->getKey(),
                    method: 'GET',
                    path: '/dataforseo/serp-organic',
                    query: $validated,
                ),
                function () use ($dataForSeo, $keyword, $options): UpstreamResult {
                    $result = $dataForSeo->serpOrganic($keyword, $options);

                    return new UpstreamResult(
                        status: 200,
                        body: json_encode($result, JSON_THROW_ON_ERROR),
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

        /** @var string $content */
        $content = $response->getContent();

        return response()->json(json_decode($content, true), $response->getStatusCode());
    }
}
