<?php

declare(strict_types=1);

namespace App\Support\Mollie;

use Mollie\Api\Contracts\IdempotencyKeyGeneratorContract;

/**
 * One-shot generator die een vaste consumer-uitgegeven idempotency-key
 * returnt. Bedoeld voor het pad waarop een test of code-pad de generator
 * runtime moet swappen via MollieApiClient::setIdempotencyKeyGenerator().
 *
 * Voor het reguliere consumer-Idempotency-Key forward-pad gebruikt
 * PaymentsController de eenvoudiger MollieApiClient::setIdempotencyKey()
 * (zie 05a-03-PREFLIGHT.md V1). Deze class blijft beschikbaar voor:
 *   - tests die generator-injection willen verifiëren
 *   - toekomstige call-pad waarin alleen een generator-instance geaccepteerd wordt
 *
 * Beslissing: 05a-CONTEXT.md §<decisions> D-06.
 */
final class ConsumerIdempotencyKeyGenerator implements IdempotencyKeyGeneratorContract
{
    public function __construct(private readonly string $key) {}

    public function generate(): string
    {
        return $this->key;
    }
}
