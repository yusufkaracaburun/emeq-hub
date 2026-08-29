<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAY_HEADER = 'Idempotent-Replayed';

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
            if ($mode === 'required') {
                return $this->error(
                    'idempotency_unavailable',
                    'Idempotentie vereist een geauthenticeerde consumer.',
                    500,
                );
            }

            return $next($request);
        }

        $fingerprint = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());
        $accountId = $this->accountId($request, (int) $consumerId);

        $claim = $this->claim($request, (int) $consumerId, $accountId, $key, $fingerprint);

        if ($claim === null) {
            $claim = $this->resolveConflict((int) $consumerId, $accountId, $key, $fingerprint);
        }

        if ($claim instanceof Response) {
            return $claim;
        }

        return $this->execute($request, $next, $claim);
    }

    private function accountId(Request $request, int $consumerId): ?int
    {
        $header = $request->header('X-Account-Id');

        if (! is_string($header) || $header === '') {
            return null;
        }

        return Account::query()
            ->where('consumer_id', $consumerId)
            ->where('external_id', $header)
            ->value('id');
    }

    private function claim(Request $request, int $consumerId, ?int $accountId, string $key, string $fingerprint): ?IdempotencyKey
    {
        try {
            return DB::transaction(fn () => IdempotencyKey::query()->create([
                'consumer_id' => $consumerId,
                'account_id' => $accountId,
                'key' => $key,
                'method' => $request->method(),
                'path' => $request->path(),
                'state' => IdempotencyKey::STATE_IN_FLIGHT,
                'request_fingerprint' => $fingerprint,
                'response_status' => null,
                'locked_at' => now(),
                'expires_at' => now()->addHours((int) config('hub.idempotency.retention_hours', 24)),
                'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function resolveConflict(int $consumerId, ?int $accountId, string $key, string $fingerprint): Response|IdempotencyKey
    {
        $existing = IdempotencyKey::query()
            ->where('consumer_id', $consumerId)
            ->where('key', $key)
            ->when(
                $accountId === null,
                fn ($query) => $query->whereNull('account_id'),
                fn ($query) => $query->where('account_id', $accountId),
            )
            ->first();

        if ($existing === null) {
            return $this->error(
                'idempotency_request_in_progress',
                'Er liep een request met deze Idempotency-Key dat zojuist eindigde. Probeer het opnieuw.',
                409,
                ['Retry-After' => '1'],
            );
        }

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

    private function takeOver(IdempotencyKey $existing, string $fingerprint): Response|IdempotencyKey
    {
        $claimed = IdempotencyKey::query()
            ->whereKey($existing->getKey())
            ->where('state', IdempotencyKey::STATE_IN_FLIGHT)
            ->where('locked_at', '<=', now()->subSeconds(IdempotencyKey::leaseSeconds()))
            ->update(['locked_at' => now(), 'request_fingerprint' => $fingerprint]);

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
            DB::transaction(fn () => $claim->delete());

            throw $e;
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            DB::transaction(fn () => $claim->delete());

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

    /** @param  array<string, string>  $headers */
    private function error(string $code, string $message, int $status, array $headers = []): Response
    {
        return response()->json(['error' => $code, 'message' => $message], $status, $headers);
    }
}
