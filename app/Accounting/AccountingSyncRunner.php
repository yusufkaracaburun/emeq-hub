<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Enums\SyncStatus;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Jobs\Accounting\SyncAccountingDocumentJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\PassThroughCall;
use App\Models\ProviderEntityLink;
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
    public function __construct(
        private AccountingTargetRegistry $registry,
        private ProviderEntityLinkRecorder $links,
    ) {}

    public function run(FinancialDocument $document, Connection $connection, Account $account, int $consumerId): AccountingSyncOutcome
    {
        $provider = $connection->provider->value;
        $start = microtime(true);
        $upstreamError = null;
        $responseBody = [];
        $status = 0;
        $fingerprint = DocumentFingerprint::for($document);

        // Tweede verdedigingslijn naast de idempotency-key: die vervalt, deze niet.
        // Bewust hier en niet in de controller, zodat het async-pad via
        // SyncAccountingDocumentJob dezelfde bescherming krijgt.
        $existing = $this->links->find($connection, $document->externalId);

        if ($existing !== null) {
            [$status, $upstreamError, $responseBody] = $this->replayExistingLink($existing, $document, $provider, $fingerprint);
            $this->audit($account, $connection, $consumerId, $provider, $document, $status, $start, $upstreamError, $responseBody);

            return new AccountingSyncOutcome($status, $responseBody);
        }

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

            $this->rememberLink($document, $connection, $result, $fingerprint);
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
     * Er staat al een boeking voor dit `external_id` op deze Connection.
     *
     * Gelijke fingerprint → dezelfde inhoud, dus dit is een retry: geef het eerdere
     * resultaat terug zonder opnieuw te boeken. Afwijkende fingerprint → dezelfde
     * identiteit met andere inhoud. De adapters kennen geen update-pad, dus dat kan
     * alleen een tweede boeking voor één brondocument worden; boekhoudkundig is een
     * correctie een creditnota met een eigen `external_id`. Daarom weigeren, met de
     * bestaande referentie in de body zodat de consumer kan reconciliëren.
     *
     * Een NULL-fingerprint (alleen mogelijk na handmatige DB-bewerking) telt als
     * afwijkend: niet kunnen verifiëren is geen reden om te herboeken.
     *
     * @return array{0: int, 1: string, 2: array<string, mixed>}
     */
    private function replayExistingLink(
        ProviderEntityLink $link,
        FinancialDocument $document,
        string $provider,
        string $fingerprint,
    ): array {
        $body = [
            'provider' => $provider,
            'external_id' => $document->externalId,
            'external_ref' => $link->provider_entity_id,
        ];

        if ($link->provider_entity_number !== null) {
            $body['external_number'] = $link->provider_entity_number;
        }

        if ($link->payload_fingerprint === $fingerprint) {
            return [200, 'deduplicated', [
                ...$body,
                'status' => SyncStatus::Posted->value,
                'deduplicated' => true,
            ]];
        }

        return [409, 'already_posted', [
            ...$body,
            'status' => SyncStatus::Rejected->value,
            'error' => 'document_already_posted',
            'message' => "Er is al een boeking met external_id '{$document->externalId}' op deze koppeling, met andere inhoud. Gebruik een nieuw external_id (een correctie is een creditnota).",
        ]];
    }

    /**
     * De boeking staat op dit punt bij de partner. Een fout bij het vastleggen van de
     * link mag een geslaagde boeking nooit alsnog laten falen — melden en doorgaan.
     */
    private function rememberLink(
        FinancialDocument $document,
        Connection $connection,
        AccountingResult $result,
        string $fingerprint,
    ): void {
        try {
            $this->links->record($document, $connection, $result, $fingerprint);
        } catch (Throwable $e) {
            report($e);
        }
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
