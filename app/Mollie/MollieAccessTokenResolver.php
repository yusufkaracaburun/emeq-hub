<?php

namespace App\Mollie;

use App\Exceptions\Mollie\MissingConnectionContextException;
use App\Exceptions\Mollie\MissingPartnerTokenException;
use InvalidArgumentException;

final class MollieAccessTokenResolver
{
    public function __construct(
        private readonly MollieConnectionContext $context,
        private readonly ?string $partnerToken,
    ) {}

    public function resolveFor(string $tokenType): string
    {
        return match ($tokenType) {
            'partner' => $this->partnerToken ?? throw new MissingPartnerTokenException,
            'connection' => $this->context->has()
                ? $this->context->current()->access_token
                : throw new MissingConnectionContextException,
            default => throw new InvalidArgumentException("Unknown token type: {$tokenType}"),
        };
    }
}
