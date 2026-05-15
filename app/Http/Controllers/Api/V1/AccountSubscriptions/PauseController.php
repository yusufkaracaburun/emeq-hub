<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AccountSubscriptions;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single-action stub — vol body in plan 07-04 Task 2.
 */
class PauseController extends Controller
{
    public function __invoke(Request $request, int $id): JsonResponse
    {
        return response()->json(['error' => 'not_implemented'], 501);
    }
}
