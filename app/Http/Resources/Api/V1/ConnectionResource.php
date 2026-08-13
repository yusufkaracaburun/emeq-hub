<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Connection
 */
class ConnectionResource extends JsonResource
{
    /**
     * Geen `data`-envelope: elk ander enkelvoudig /v1-antwoord dat een consumer
     * krijgt is plat (integrations, connect-sessions, oauth/init), dus wrappen
     * maakte dit een van de twee endpoints waar een SDK-gebruiker `['data']`
     * moest schrijven. Gepagineerde collecties (account-subscriptions,
     * accounting) houden hun envelope — daar draagt die `meta` / `next_cursor`.
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // De sleutel die overal élders naar de consumer gaat: de
            // integrations-lijst, de OAuth-init-respons, de
            // connection_revoked-webhook en X-Connection-Id. Zonder deze kon een
            // consumer die waarde nergens terugvinden op dit endpoint.
            'public_id' => $this->public_id,
            'account_id' => $this->account_id,
            'provider' => $this->provider,
            'status' => $this->status,
            'fingerprint' => $this->fingerprint(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
