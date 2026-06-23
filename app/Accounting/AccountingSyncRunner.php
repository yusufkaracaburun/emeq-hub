<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Enums\SyncStatus;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Jobs\Accounting\SyncAccountingDocumentJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\PassThroughCall;
use App\OAuth\Exceptions\ProviderDisabledException;
use App\Support\Exact\UpstreamErrorMapper;
use Throwable;

/**
 * Voert één accounting-push uit tegen de provider-adapter: mapt het resultaat (of de
 * fout) naar een HTTP-statuscode + respons-body en schrijft één outbound PassThroughCall.
 * Gedeeld door de synchrone controller-edge en de async {@see SyncAccountingDocumentJob}.
 */
final readonly class AccountingSyncRunner
{
    public function __construct(private AccountingTargetRegistry $registry) {}

    public function run(FinancialDocument $document, Connection $connection, Account $account, int $consumerId): AccountingSyncOutcome
    {
        $provider = $connection->provider->value;
        $start = microtime(true);
        $upstreamError = null;
        $responseBody = [];
        $status = 0;

        try {
            $result = $this->registry->for($provider)->push($document, $connection);
            $status = $result->status;
            $responseBody = [
                'provider' => $provider,
                'status' => SyncStatus::Posted->value,
                'external_id' => $document->externalId,
                'external_ref' => $result->externalRef,
            ];

            if ($result->externalNumber !== null) {
                $responseBody['external_number'] = $result->externalNumber;
            }

            if ($result->attachments !== []) {
                $responseBody['attachments'] = $result->attachments;
            }
        } catch (ProviderDisabledException $e) {
            $status = 503;
            $upstreamError = 'provider_disabled';
            $responseBody = ['status' => SyncStatus::Failed->value, 'external_id' => $document->externalId, 'error' => 'provider_disabled', 'message' => $e->getMessage()];
        } catch (AccountingMappingException $e) {
            $status = 422;
            $upstreamError = 'mapping_failed';
            $responseBody = ['status' => SyncStatus::Failed->value, 'external_id' => $document->externalId, 'error' => 'mapping_failed', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            $mapped = UpstreamErrorMapper::mapException($e);
            $status = $mapped['status'];
            $upstreamError = $mapped['short_code'];
            $responseBody = ['status' => SyncStatus::Failed->value, 'external_id' => $document->externalId, ...$mapped['body']];
        }

        $this->audit($account, $connection, $consumerId, $provider, $document, $status, $start, $upstreamError, $responseBody);

        return new AccountingSyncOutcome($status, $responseBody);
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function audit(
        Account $account,
        Connection $connection,
        int $consumerId,
        string $provider,
        FinancialDocument $document,
        int $status,
        float $start,
        ?string $upstreamError,
        array $responseBody,
    ): void {
        PassThroughCall::create([
            'direction' => 'outbound',
            'consumer_id' => $consumerId,
            'account_id' => $account->getKey(),
            'connection_id' => $connection->getKey(),
            'provider' => $provider,
            'method' => 'POST',
            // Genormaliseerd endpoint-pad (leading /, conform de andere audit-paden).
            // De doc-type-suffix is verwijderd — hoort niet in `path`.
            'path' => '/v1/accounting/documents',
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
