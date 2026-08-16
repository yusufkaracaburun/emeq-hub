<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * HTTP-niveau uitkomst van een accounting-push: de statuscode + de respons-body
 * (incl. semantische `status`, `external_id`, `external_ref`/`error`). Gedeeld door
 * het synchrone controller-pad en de async job — die laatste maakt er de webhook-payload van.
 */
final readonly class AccountingSyncOutcome
{
    /**
     * @param  array<string, mixed>  $responseBody
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $httpStatus,
        public array $responseBody,
        public array $headers = [],
    ) {}
}
