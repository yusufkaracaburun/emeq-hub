<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Accounting\AccountingSyncRunner;
use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Enums\SyncStatus;
use App\Accounting\FinancialDocument;
use App\Http\Concerns\ResolvesAccountingConnection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreDocumentRequest;
use App\Jobs\Accounting\SyncAccountingDocumentJob;
use App\Models\Account;
use App\Models\Connection;
use App\Sanctum\TokenAbilities;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group(name: 'Accounting Sync', description: 'Push canonical financiële documenten naar het gekoppelde boekhoudpakket van de Account.', weight: 50)]
class DocumentsController extends Controller
{
    use ResolvesAccountingConnection;

    public function __construct(
        private readonly AccountingTargetRegistry $registry,
        private readonly AccountingSyncRunner $runner,
    ) {}

    #[Response(200, type: 'array{provider: string, status: string, external_id: string, external_ref: string|null, external_number?: int, attachments?: list<array{filename: string, status: string, document_ref: string|null, error: string|null}>, deduplicated?: bool, recovered?: bool}')]
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        if (! $this->tokenCanWrite($request)) {
            return response()->json([
                'error' => 'insufficient_ability',
                'message' => "Token mist vereiste ability '".TokenAbilities::ACCOUNTING_WRITE."'.",
            ], 403);
        }

        [$account, $connection] = $this->resolveAccountingConnection($request, $this->registry->providers());

        $provider = $connection->provider->value;

        $document = FinancialDocument::fromArray($request->validated());

        $consumerId = (int) $request->user()?->getKey();

        if ($this->wantsAsync($request)) {
            return $this->dispatchAsync($request, $document, $connection, $account, $consumerId);
        }

        $outcome = $this->runner->run($document, $connection, $account, $consumerId);

        return response()->json($outcome->responseBody, $outcome->httpStatus, $outcome->headers);
    }

    private function dispatchAsync(
        StoreDocumentRequest $request,
        FinancialDocument $document,
        Connection $connection,
        Account $account,
        int $consumerId,
    ): JsonResponse {
        if (! $request->user()?->webhook_callback_url) {
            return response()->json([
                'status' => SyncStatus::Rejected->value,
                'external_id' => $document->externalId,
                'error' => 'webhook_required',
                'message' => 'Async-modus vereist een geregistreerde webhook_callback_url op de Consumer.',
            ], 400);
        }

        SyncAccountingDocumentJob::dispatch($document, $connection, $account, $consumerId);

        return response()->json([
            'status' => SyncStatus::Pending->value,
            'external_id' => $document->externalId,
        ], 202);
    }

    private function wantsAsync(StoreDocumentRequest $request): bool
    {
        return str_contains(strtolower((string) $request->header('Prefer', '')), 'respond-async');
    }

    private function tokenCanWrite(StoreDocumentRequest $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null) {
            return false;
        }

        foreach (TokenAbilities::accounting(write: true) as $ability) {
            if ($token->can($ability)) {
                return true;
            }
        }

        return false;
    }
}
