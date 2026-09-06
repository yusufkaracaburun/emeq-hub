<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie;

use App\Enums\Provider;
use App\Models\ProviderEntityLink;
use Emeq\ItheorieApi\Enums\ErrorKind;
use Emeq\ItheorieApi\Exceptions\ItheorieException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Duurzaam register van welke consumer welke iTheorie-aankoop deed.
 *
 * Bestaat om twee redenen die de idempotency-sleutel alleen niet dekt: die claim
 * wordt bij elke fout verwijderd en na 24 uur geprund, terwijl een aankoop
 * onomkeerbaar is en geld kost. En zonder eigenaar is elke aankoop van elke
 * consumer zichtbaar, want iTheorie kent maar één reseller voor de hele Hub.
 */
final class PurchaseLedger
{
    public function claim(int $consumerId, string $reference): ProviderEntityLink
    {
        $existing = $this->find($consumerId, ['external_id' => $reference]);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return ProviderEntityLink::create([
                'consumer_id' => $consumerId,
                'provider' => Provider::Itheorie->value,
                'entity_type' => ProviderEntityLink::ENTITY_PURCHASE,
                'external_id' => $reference,
                'origin' => ProviderEntityLink::ORIGIN_HUB,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return $this->find($consumerId, ['external_id' => $reference]) ?? throw $e;
        }
    }

    /**
     * Alleen wanneer de partner de aanvraag heeft afgewezen weten we zeker dat er
     * niets gekocht is. Bij een time-out, een 503 of een onbekende fout kan de
     * aankoop wél zijn doorgegaan; dan blijft de claim staan zodat een herhaling
     * niet stilletjes een tweede code koopt.
     */
    public function isDefinitelyNotCharged(ItheorieException $exception): bool
    {
        return in_array($exception->kind, [
            ErrorKind::Validation,
            ErrorKind::NotFound,
            ErrorKind::BadRequest,
            ErrorKind::Forbidden,
            ErrorKind::Reseller,
            ErrorKind::Authentication,
            ErrorKind::Token,
        ], true);
    }

    public function record(ProviderEntityLink $link, string $purchaseId, ?string $accessCode): void
    {
        $link->forceFill([
            'provider_entity_id' => $purchaseId,
            'payload_fingerprint' => $accessCode !== null ? hash('sha256', $accessCode) : null,
            'last_synced_at' => now(),
        ])->save();
    }

    public function ownsPurchase(int $consumerId, string $purchaseId): bool
    {
        return $this->find($consumerId, ['provider_entity_id' => $purchaseId]) !== null;
    }

    public function ownsAccessCode(int $consumerId, string $accessCode): bool
    {
        return $this->find($consumerId, ['payload_fingerprint' => hash('sha256', $accessCode)]) !== null;
    }

    /** @param array<string, string> $where */
    private function find(int $consumerId, array $where): ?ProviderEntityLink
    {
        return ProviderEntityLink::query()
            ->where('consumer_id', $consumerId)
            ->where('provider', Provider::Itheorie->value)
            ->where('entity_type', ProviderEntityLink::ENTITY_PURCHASE)
            ->where($where)
            ->first();
    }
}
