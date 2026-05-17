<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SnelstartWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return response('', 200);
    }
}
