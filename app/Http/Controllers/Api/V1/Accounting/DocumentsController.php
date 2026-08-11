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
use Illuminate\Http\JsonResponse;

/**
 * Provider-agnostische accounting-sync. Een consumer POST een canonical
 * FinancialDocument; de Hub resolvet de gekoppelde boekhoud-Connection van de
 * Account, dispatcht naar de juiste AccountingTarget-adapter en audit het als
 * uitgaande PassThroughCall. Welk pakket (Exact/Snelstart/…) is transparant.
 *
 * Default synchroon (201 + `status:posted`). Met `Prefer: respond-async` draait de
 * partner-push in een queue-job die het resultaat per webhook terugmeldt (202 + pending).
 */
#[Group(name: 'Accounting Sync', description: 'Push canonical financiële documenten naar het gekoppelde boekhoudpakket van de Account.', weight: 50)]
class DocumentsController extends Controller
{
    use ResolvesAccountingConnection;

    public function __construct(
        private readonly AccountingTargetRegistry $registry,
        private readonly AccountingSyncRunner $runner,
    ) {}

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

        return response()->json($outcome->responseBody, $outcome->httpStatus);
    }

    private function dispatchAsync(
        StoreDocumentRequest $request,
        FinancialDocument $document,
        Connection $connection,
        Account $account,
        int $consumerId,
    ): JsonResponse {
        // Async zonder terugmeld-kanaal is een zwart gat — weiger fail-fast i.p.v. de
        // uitkomst alleen in de audit te laten verdwijnen.
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

    /**
     * RFC 7240 `Prefer: respond-async` — de consumer vraagt de async-variant aan.
     */
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
