<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Consumer;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group(name: 'System', description: 'Smoke- en auth-check endpoints.', weight: 5)]
class PingController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request): array
    {
        /** @var Consumer $consumer */
        $consumer = $request->user();

        return [
            'pong' => true,
            'consumer' => $consumer->slug,
            'abilities' => $consumer->currentAccessToken()?->abilities ?? [],
        ];
    }
}
