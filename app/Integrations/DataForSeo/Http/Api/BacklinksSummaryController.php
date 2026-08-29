<?php

declare(strict_types=1);

namespace App\Integrations\DataForSeo\Http\Api;

use App\Enums\Provider;
use App\Integrations\PassThrough\PassThroughContext;
use App\Integrations\PassThrough\PassThroughPipeline;
use App\Integrations\PassThrough\UpstreamResult;
use App\Models\Account;
use App\Models\Connection;
use Emeq\DataForSeoApi\DataForSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

final class BacklinksSummaryController
{
    public function __construct(
        private readonly PassThroughPipeline $pipeline,
    ) {}

    public function show(Request $request, DataForSeo $dataForSeo): JsonResponse
    {
        $target = $request->string('target')->toString();

        if ($target === '') {
            return response()->json([
                'error' => 'missing_target',
                'message' => 'Query parameter "target" is vereist.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $extra = [];

        if ($request->has('include_subdomains')) {
            $extra['include_subdomains'] = $request->boolean('include_subdomains');
        }

        if ($request->has('backlinks_status_type')) {
            $extra['backlinks_status_type'] = $request->string('backlinks_status_type')->toString();
        }

        if ($request->has('internal_list_limit')) {
            $extra['internal_list_limit'] = $request->integer('internal_list_limit');
        }

        /** @var Account $account */
        $account = $request->attributes->get('dataforseo_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('dataforseo_connection');

        $query = [
            'target' => $target,
            ...$extra,
        ];

        try {
            $response = $this->pipeline->run(
                new PassThroughContext(
                    provider: Provider::DataForSeo,
                    consumerId: (int) $request->user()->getKey(),
                    accountId: (int) $account->getKey(),
                    connectionId: (int) $connection->getKey(),
                    method: 'GET',
                    path: '/dataforseo/backlinks-summary',
                    query: $query,
                ),
                function () use ($dataForSeo, $target, $extra): UpstreamResult {
                    $result = $dataForSeo->backlinksSummary($target, $extra);

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
