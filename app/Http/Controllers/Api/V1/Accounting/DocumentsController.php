<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Accounting\AccountingSyncRunner;
use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\SyncStatus;
use App\Accounting\FinancialDocument;
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
    public function __construct(
        private readonly AccountingTargetRegistry $registry,
        private readonly AccountingSyncRunner $runner,
    ) {}

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

        // v1-grens: alleen verkoop/inkoop (sales_invoice/purchase_invoice/credit_note).
        // Ad-hoc income/expense → memoriaal hangt aan de GeneralJournalEntry-balancering
        // (#12) en komt in v2 — weiger nu expliciet i.p.v. een rauwe Exact-500 door te laten.
        if (in_array($document->type, [DocumentType::Income, DocumentType::Expense], true)) {
            return response()->json([
                'status' => SyncStatus::Rejected->value,
                'external_id' => $document->externalId,
                'error' => 'unsupported_document_type',
                'message' => "Doc-type '{$document->type->value}' wordt vanaf v2 ondersteund (ad-hoc income/expense → memoriaal, zie #12).",
            ], 422);
        }

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

    private function tokenCanWrite(StoreDocumentRequest $request, string $provider): bool
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null) {
            return false;
        }

        return $token->can("{$provider}:write") || $token->can(TokenAbilities::ADMIN);
    }
}
