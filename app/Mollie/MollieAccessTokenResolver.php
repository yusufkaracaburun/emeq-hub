<?php

namespace App\Mollie;

use App\Exceptions\Mollie\MissingConnectionContextException;
use App\Exceptions\Mollie\MissingPartnerTokenException;
use Closure;
use InvalidArgumentException;

final class MollieAccessTokenResolver
{
    /**
     * @var Closure(): ?string
     */
    private readonly Closure $partnerTokenResolver;

    /**
     * @param  Closure(): ?string|string|null  $partnerToken  Closure die de partner-access-token
     *                                                        ophaalt op moment van resolveFor()-aanroep.
     *                                                        Een statische string of null wordt
     *                                                        backwards-compatible gewrapt in een
     *                                                        Closure. Closure-vorm is verplicht voor
     *                                                        long-running workers (Horizon, octane)
     *                                                        zodat env-rotatie zonder container-restart
     *                                                        doorwerkt.
     */
    public function __construct(
        private readonly MollieConnectionContext $context,
        Closure|string|null $partnerToken,
    ) {
        $this->partnerTokenResolver = $partnerToken instanceof Closure
            ? $partnerToken
            : static fn (): ?string => $partnerToken;
    }

    public function resolveFor(string $tokenType): string
    {
        return match ($tokenType) {
            'partner' => ($this->partnerTokenResolver)() ?? throw new MissingPartnerTokenException,
            'connection' => $this->context->has()
                ? $this->context->current()->access_token
                : throw new MissingConnectionContextException,
            default => throw new InvalidArgumentException("Unknown token type: {$tokenType}"),
        };
    }
}
