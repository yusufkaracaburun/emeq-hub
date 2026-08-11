<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Hub-brede idempotentie voor write-requests, consumer-scoped.
 *
 * De unique index op `(consumer_id, key)` is de mutex: de rij wordt met één INSERT
 * geclaimd vóórdat de handler draait. Verliest die INSERT, dan loopt er al een request
 * met dezelfde key, of is er al een afgeronde respons om te herhalen. Zonder die
 * claim-eerst-volgorde konden twee gelijktijdige requests allebei bij de partner boeken.
 *
 * Bewust géén transactie om de handler heen: die zou een database-connectie vasthouden
 * voor de duur van een HTTP-round-trip naar de partner.
 *
 * Modus `required` → ontbrekende key geeft 400. Alleen 2xx wordt bewaard; een mislukte
 * poging geeft de claim vrij zodat hij opnieuw mag.
 */
class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    /** Zichtbaar op een herhaalde respons, zodat een consumer replay van uitvoering kan onderscheiden. */
    public const REPLAY_HEADER = 'Idempotent-Replayed';

    /** Printbare ASCII, 1–255 tekens. Weert CR/LF en onbegrensde sleutels. */
    private const KEY_SHAPE = '/^[\x21-\x7E]{1,255}$/';

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $key = $request->header(self::HEADER);
        $consumerId = $request->user()?->getKey();

        if (! is_string($key) || $key === '') {
            if ($mode === 'required') {
                return $this->error('idempotency_key_required', 'Vereiste header Idempotency-Key ontbreekt.', 400);
            }

            return $next($request);
        }

        if (preg_match(self::KEY_SHAPE, $key) !== 1) {
            return $this->error('idempotency_key_invalid', 'Idempotency-Key moet 1–255 printbare ASCII-tekens zijn.', 400);
        }

        if ($consumerId === null) {
            return $next($request);
        }

        $fingerprint = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());

        $claim = $this->claim($request, (int) $consumerId, $key, $fingerprint);

        if ($claim === null) {
            $claim = $this->resolveConflict((int) $consumerId, $key, $fingerprint);
        }

        // Een terminale respons: replay, hergebruik-conflict of "er loopt er al een".
        if ($claim instanceof Response) {
            return $claim;
        }

        return $this->execute($request, $next, $claim);
    }

    /**
     * Eén INSERT, geen SELECT ervoor. Slaagt hij, dan is de claim van ons.
     */
    private function claim(Request $request, int $consumerId, string $key, string $fingerprint): ?IdempotencyKey
    {
        try {
            return IdempotencyKey::query()->create([
                'consumer_id' => $consumerId,
                'key' => $key,
                'method' => $request->method(),
                'path' => $request->path(),
                'state' => IdempotencyKey::STATE_IN_FLIGHT,
                'request_fingerprint' => $fingerprint,
                'response_status' => null,
                'locked_at' => now(),
                'expires_at' => now()->addHours((int) config('hub.idempotency.retention_hours', 24)),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * De INSERT verloor. Bestaande rij ophalen en bepalen wat dat betekent: een
     * `Response` is terminaal, een `IdempotencyKey` betekent "claim is nu van ons,
     * draai de handler".
     */
    private function resolveConflict(int $consumerId, string $key, string $fingerprint): Response|IdempotencyKey
    {
        $existing = IdempotencyKey::query()
            ->where('consumer_id', $consumerId)
            ->where('key', $key)
            ->first();

        // De rij is tussen onze INSERT en deze SELECT verdwenen: de winnaar faalde en
        // gaf zijn claim vrij. De consumer mag het gewoon opnieuw proberen.
        if ($existing === null) {
            return $this->error(
                'idempotency_request_in_progress',
                'Er liep een request met deze Idempotency-Key dat zojuist eindigde. Probeer het opnieuw.',
                409,
                ['Retry-After' => '1'],
            );
        }

        // Een bekende fingerprint die niet matcht betekent: dezelfde sleutel, ander
        // document. Stil de oude respons herhalen zou de tweede boeking laten
        // verdwijnen zonder dat iemand het merkt.
        if ($existing->request_fingerprint !== null && $existing->request_fingerprint !== $fingerprint) {
            return $this->error(
                'idempotency_key_reuse',
                'Deze Idempotency-Key is al gebruikt voor een ander request. Gebruik een nieuwe sleutel.',
                422,
            );
        }

        if ($existing->state === IdempotencyKey::STATE_COMPLETED) {
            return $this->replay($existing);
        }

        if (! $existing->leaseHasExpired()) {
            return $this->error(
                'idempotency_request_in_progress',
                'Er loopt al een request met deze Idempotency-Key. Probeer het later opnieuw.',
                409,
                ['Retry-After' => (string) $existing->secondsUntilLeaseExpires()],
            );
        }

        return $this->takeOver($existing, $fingerprint);
    }

    /**
     * De lease is verlopen; het vorige request is kennelijk gestorven. Conditioneel
     * overnemen, zodat twee retries die tegelijk aankomen niet allebei denken te winnen.
     */
    private function takeOver(IdempotencyKey $existing, string $fingerprint): Response|IdempotencyKey
    {
        $claimed = IdempotencyKey::query()
            ->whereKey($existing->getKey())
            ->where('state', IdempotencyKey::STATE_IN_FLIGHT)
            ->where('locked_at', '<=', now()->subSeconds(IdempotencyKey::leaseSeconds()))
            ->update(['locked_at' => now(), 'request_fingerprint' => $fingerprint]);

        // Nul rijen geraakt: een andere retry was net eerder met dezelfde overname.
        if ($claimed === 0) {
            return $this->error(
                'idempotency_request_in_progress',
                'Er loopt al een request met deze Idempotency-Key. Probeer het later opnieuw.',
                409,
                ['Retry-After' => '1'],
            );
        }

        return $existing->refresh();
    }

    private function execute(Request $request, Closure $next, IdempotencyKey $claim): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $claim->delete();

            throw $e;
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            // Mislukt mag opnieuw — dat was het contract vóór de claim-laag en het
            // blijft het contract erna.
            $claim->delete();

            return $response;
        }

        $claim->forceFill([
            'state' => IdempotencyKey::STATE_COMPLETED,
            'response_status' => $status,
            'content_type' => $response->headers->get('Content-Type'),
            'response_body' => $response->getContent(),
            'completed_at' => now(),
            'expires_at' => now()->addHours((int) config('hub.idempotency.retention_hours', 24)),
        ])->save();

        return $response;
    }

    private function replay(IdempotencyKey $existing): Response
    {
        return response($existing->response_body, (int) $existing->response_status)
            ->header('Content-Type', $existing->content_type ?? 'application/json')
            ->header(self::REPLAY_HEADER, 'true');
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function error(string $code, string $message, int $status, array $headers = []): Response
    {
        return response()->json(['error' => $code, 'message' => $message], $status, $headers);
    }
}
