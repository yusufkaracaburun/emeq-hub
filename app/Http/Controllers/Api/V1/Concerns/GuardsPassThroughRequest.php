<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait GuardsPassThroughRequest
{
    /** @param  list<string>  $allowed */
    private function guardMethodAllowed(string $method, array $allowed): ?JsonResponse
    {
        if (in_array($method, $allowed, true)) {
            return null;
        }

        return response()->json([
            'error' => 'method_not_allowed',
            'message' => 'HTTP method niet toegestaan op pass-through-route.',
        ], Response::HTTP_METHOD_NOT_ALLOWED)->header('Allow', implode(', ', $allowed));
    }

    /** @param  list<string>  $required */
    private function guardTokenAbility(Request $request, array $required): ?JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        $hasAbility = $token !== null && collect($required)->contains(fn (string $ability) => $token->can($ability));

        if ($hasAbility) {
            return null;
        }

        return response()->json([
            'error' => 'insufficient_ability',
            'message' => 'Token mist vereiste ability voor deze methode.',
        ], Response::HTTP_FORBIDDEN);
    }

    /** @param  list<string>  $bodyMethods */
    private function guardJsonContentType(Request $request, string $method, array $bodyMethods): ?JsonResponse
    {
        if (! in_array($method, $bodyMethods, true)) {
            return null;
        }

        $contentType = strtolower((string) $request->header('Content-Type', ''));
        if (str_starts_with($contentType, 'application/json')) {
            return null;
        }

        return response()->json([
            'error' => 'unsupported_content_type',
            'message' => 'Pass-through accepteert alleen application/json voor '.implode('/', $bodyMethods).'.',
        ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }
}
