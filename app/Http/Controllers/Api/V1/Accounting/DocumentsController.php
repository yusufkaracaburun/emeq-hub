<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\FinancialDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreDocumentRequest;
use App\Models\Account;
use App\Models\Connection;
use App\Models\PassThroughCall;
use App\OAuth\Exceptions\ProviderDisabledException;
use App\Sanctum\TokenAbilities;
use App\Support\Exact\UpstreamErrorMapper;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Provider-agnostische accounting-sync. Een consumer POST een canonical
 * FinancialDocument; de Hub resolvet de gekoppelde boekhoud-Connection van de
 * Account, dispatcht naar de juiste AccountingTarget-adapter en audit het als
 * uitgaande PassThroughCall. Welk pakket (Exact/Snelstart/…) is transparant.
 */
#[Group(name: 'Accounting Sync', description: 'Push canonical financiële documenten naar het gekoppelde boekhoudpakket van de Account.', weight: 50)]
class DocumentsController extends Controller
{
    public function __construct(private readonly AccountingTargetRegistry $registry) {}

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $accountHeader = $request->header('X-Account-Id');

        if (! is_string($accountHeader) || $accountHeader === '') {
            return response()->json([
                'error' => 'missing_account_header',
                'message' => 'Vereiste header X-Account-Id ontbreekt.',
            ], 400);
        }

        $account = Account::query()
            ->where('consumer_id', $request->user()?->getKey())
            ->where('external_id', $accountHeader)
            ->first();

        if ($account === null) {
            return response()->json([
                'error' => 'account_not_found',
                'message' => 'Account niet gevonden voor deze Consumer.',
            ], 404);
        }

        $connection = $account->connections()
            ->whereNull('revoked_at')
            ->whereIn('provider', $this->registry->providers())
            ->first();

        if ($connection === null) {
            return response()->json([
                'error' => 'no_accounting_connection',
                'message' => 'Geen actieve boekhoud-Connection voor dit Account.',
            ], 404);
        }

        $provider = $connection->provider->value;

        if (! $this->tokenCanWrite($request, $provider)) {
            return response()->json([
                'error' => 'insufficient_ability',
                'message' => "Token mist vereiste ability '{$provider}:write'.",
            ], 403);
        }

        $document = FinancialDocument::fromArray($request->validated());

        $start = microtime(true);
        $upstreamError = null;
        $responseBody = [];
        $status = 0;

        try {
            $result = $this->registry->for($provider)->push($document, $connection);
            $status = $result->status;
            $responseBody = [
                'provider' => $provider,
                'status' => $result->status,
                'external_ref' => $result->externalRef,
            ];
        } catch (ProviderDisabledException $e) {
            $status = 503;
            $upstreamError = 'provider_disabled';
            $responseBody = ['error' => 'provider_disabled', 'message' => $e->getMessage()];
        } catch (AccountingMappingException $e) {
            $status = 422;
            $upstreamError = 'mapping_failed';
            $responseBody = ['error' => 'mapping_failed', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            $mapped = UpstreamErrorMapper::mapException($e);
            $status = $mapped['status'];
            $upstreamError = $mapped['short_code'];
            $responseBody = $mapped['body'];
        }

        $this->audit($request, $account, $connection, $provider, $document, $status, $start, $upstreamError, $responseBody);

        return response()->json($responseBody, $status);
    }

    private function tokenCanWrite(StoreDocumentRequest $request, string $provider): bool
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null) {
            return false;
        }

        return $token->can("{$provider}:write") || $token->can(TokenAbilities::ADMIN);
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function audit(
        StoreDocumentRequest $request,
        Account $account,
        Connection $connection,
        string $provider,
        FinancialDocument $document,
        int $status,
        float $start,
        ?string $upstreamError,
        array $responseBody,
    ): void {
        PassThroughCall::create([
            'direction' => 'outbound',
            'consumer_id' => $request->user()?->getKey(),
            'account_id' => $account->getKey(),
            'connection_id' => $connection->getKey(),
            'provider' => $provider,
            'method' => 'POST',
            'path' => 'accounting/documents:'.$document->type->value,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'request_fingerprint' => substr(hash('sha256', $document->externalId), 0, 12),
            'response_size_bytes' => strlen((string) json_encode($responseBody)),
            'upstream_error' => $upstreamError,
            'response_body' => PassThroughCall::errorBody($status, (string) json_encode($responseBody)),
            'created_at' => now(),
        ]);
    }
}
