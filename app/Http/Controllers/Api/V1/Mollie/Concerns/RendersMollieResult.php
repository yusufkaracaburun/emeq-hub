<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mollie\Concerns;

use App\Integrations\PassThrough\UpstreamResult;

trait RendersMollieResult
{
    /** @throws \JsonException */
    private function toUpstreamResult(mixed $result, string $method): UpstreamResult
    {
        if (is_array($result) && isset($result['status'], $result['body']) && is_int($result['status']) && is_array($result['body'])) {
            return UpstreamResult::json($result['body'], $result['status']);
        }

        return new UpstreamResult(
            status: $method === 'POST' ? 201 : 200,
            body: json_encode($result, JSON_THROW_ON_ERROR),
        );
    }
}
