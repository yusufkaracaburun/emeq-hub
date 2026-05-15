<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AccountSubscriptions;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resource controller stub — vol body in plan 07-04 Task 2.
 */
class AccountSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return response()->json(['error' => 'not_implemented'], 501);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['error' => 'not_implemented'], 501);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['error' => 'not_implemented'], 501);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return response()->json(['error' => 'not_implemented'], 501);
    }
}
