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

final class SearchVolumeController
{
    public function __construct(
        private readonly PassThroughPipeline $pipeline,
    ) {}

    #[ResponseDoc(404, 'Geen actieve DataForSEO-Connection voor dit Account.')]
    #[ResponseDoc(503, 'DataForSEO gaf een foutmelding terug.')]
    public function store(Request $request, DataForSeo $dataForSeo): JsonResponse
    {
        /** @var array{keywords: list<string>, location_code?: int, language_code?: string} $validated */
        $validated = $request->validate([
            'keywords' => ['required', 'array', 'min:1', 'max:1000'],
            'keywords.*' => ['required', 'string', 'max:80'],
            'location_code' => ['sometimes', 'integer'],
            'language_code' => ['sometimes', 'string', 'max:8'],
        ]);

        $keywords = $validated['keywords'];
        $options = array_diff_key($validated, ['keywords' => true]);

        if (isset($options['location_code'])) {
            $options['location_code'] = $request->integer('location_code');
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
                    method: 'POST',
                    path: '/dataforseo/search-volume',
                    body: $validated,
                ),
                function () use ($dataForSeo, $keywords, $options): UpstreamResult {
                    $result = $dataForSeo->searchVolume($keywords, $options);

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
