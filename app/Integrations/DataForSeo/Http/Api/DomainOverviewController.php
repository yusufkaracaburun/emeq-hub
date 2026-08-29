<?php

declare(strict_types=1);

namespace App\Integrations\DataForSeo\Http\Api;

use Emeq\DataForSeoApi\DataForSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DomainOverviewController
{
    public function show(Request $request, DataForSeo $dataForSeo): JsonResponse
    {
        $domain = $request->string('domain')->toString();

        if ($domain === '') {
            return response()->json([
                'error' => 'missing_domain',
                'message' => 'Query parameter "domain" is vereist.',
            ], 422);
        }

        $result = $dataForSeo->domainOverview($domain);

        return response()->json($result);
    }
}
