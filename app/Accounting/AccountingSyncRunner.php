<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Contracts\ProbesPostedDocuments;
use App\Accounting\Enums\SyncStatus;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\Exceptions\RelationAmbiguousException;
use App\Integrations\Errors\UpstreamErrorMapperRegistry;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Integrations\PassThrough\PassThroughRecorder;
use App\Models\Account;
use App\Models\Connection;
use App\Models\IdempotencyKey;
use App\Models\ProviderEntityLink;
use Throwable;

final readonly class AccountingSyncRunner
{
    public function __construct(
        private AccountingTargetRegistry $registry,
        private ProviderEntityLinkRecorder $links,
        private UpstreamErrorMapperRegistry $errors,
        private PassThroughRecorder $recorder,
        private BookingWarnings $warnings,
    ) {}

    public function run(FinancialDocument $document, Connection $connection, Account $account, int $consumerId): AccountingSyncOutcome
    {
        $provider = $connection->provider->value;
        $start = microtime(true);
        $fingerprint = DocumentFingerprint::for($document);

        $claim = $this->claimOrExplain($document, $connection, $fingerprint);

        if (! $claim instanceof ProviderEntityLink) {
            [$status, $upstreamError, $responseBody, $headers] = $claim;
            $this->audit($account, $connection, $consumerId, $provider, $document, $status, $start, $upstreamError, $responseBody);

            return new AccountingSyncOutcome($status, $responseBody, $headers);
        }

        $lock = $this->links->administrationLock($connection, $document, $fingerprint);

        if ($lock !== null && ! $lock->get()) {
            $this->links->releaseClaim($claim);

            [$status, $upstreamError, $responseBody, $headers] = $this->syncInProgressElsewhere($document, $provider);
            $this->audit($account, $connection, $consumerId, $provider, $document, $status, $start, $upstreamError, $responseBody);

            return new AccountingSyncOutcome($status, $responseBody, $headers);
        }

        try {
            return $this->push($document, $connection, $account, $consumerId, $fingerprint, $claim, $start);
        } finally {
            $lock?->release();
        }
    }

    private function push(
        FinancialDocument $document,
        Connection $connection,
        Account $account,
        int $consumerId,
        string $fingerprint,
        ProviderEntityLink $claim,
        float $start,
    ): AccountingSyncOutcome {
        $this->warnings->flush();

        $provider = $connection->provider->value;
        $upstreamError = null;
        $responseBody = [];
        $status = 0;

        $elsewhere = $this->links->findPostedOnSameAdministration($connection, $document, $fingerprint);

        if ($elsewhere !== null) {
            $this->links->releaseClaim($claim);

            [$status, $upstreamError, $responseBody, $headers] = $this->alreadyPostedElsewhere($document, $provider);
            $this->audit($account, $connection, $consumerId, $provider, $document, $status, $start, $upstreamError, $responseBody);

            return new AccountingSyncOutcome($status, $responseBody, $headers);
        }

        $booked = false;

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
            $booked = true;
        } catch (ProviderDisabledException $e) {
            $status = 503;
            $upstreamError = 'provider_disabled';
            $responseBody = ['status' => SyncStatus::Failed->value, 'external_id' => $document->externalId, 'error' => 'provider_disabled', 'message' => $e->getMessage()];
        } catch (RelationAmbiguousException $e) {
            $status = 409;
            $upstreamError = 'relation_ambiguous';
            $responseBody = [
                'status' => SyncStatus::Failed->value,
                'external_id' => $document->externalId,
                'error' => 'relation_ambiguous',
                'message' => $e->getMessage(),
                'candidates' => $e->candidates,
            ];
        } catch (AccountingMappingException $e) {
            $status = 422;
            $upstreamError = 'mapping_failed';
            $responseBody = ['status' => SyncStatus::Failed->value, 'external_id' => $document->externalId, 'error' => 'mapping_failed', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            $mapped = $this->errors->map($provider, $e);
            $status = $mapped['status'];
            $upstreamError = $mapped['short_code'];
            $responseBody = ['status' => SyncStatus::Failed->value, 'external_id' => $document->externalId, ...$mapped['body']];

            if ($status >= 502 && ($probed = $this->probe($document, $connection, $fingerprint)) !== null) {
                [$status, $upstreamError, $responseBody] = $probed;
                $booked = true;
            }
        } finally {
            if (! $booked) {
                $this->links->releaseClaim($claim);
            }
        }

        $warnings = $this->warnings->all();

        if ($warnings !== []) {
            $responseBody['warnings'] = $warnings;
        }

        $this->audit($account, $connection, $consumerId, $provider, $document, $status, $start, $upstreamError, $responseBody);

        return new AccountingSyncOutcome($status, $responseBody);
    }

    /** @return ProviderEntityLink|array{0: int, 1: string, 2: array<string, mixed>, 3: array<string, string>} */
    private function claimOrExplain(FinancialDocument $document, Connection $connection, string $fingerprint): ProviderEntityLink|array
    {
        $claim = $this->links->claim($document, $connection);

        if ($claim !== null) {
            return $claim;
        }

        $existing = $this->links->find($connection, $document);

        if ($existing === null) {
            return $this->syncInProgress($document, $connection->provider->value, 1);
        }

        if ($existing->provider_entity_id !== null) {
            return $this->replayExistingLink($existing, $document, $connection->provider->value, $fingerprint);
        }

        if (! $this->links->claimIsStale($existing)) {
            return $this->syncInProgress($document, $connection->provider->value, $this->links->secondsUntilClaimStale($existing));
        }

        $this->links->releaseClaim($existing);

        return $this->links->claim($document, $connection)
            ?? $this->syncInProgress($document, $connection->provider->value, 1);
    }

    /** @return array{0: int, 1: string, 2: array<string, mixed>, 3: array<string, string>} */
    private function alreadyPostedElsewhere(FinancialDocument $document, string $provider): array
    {
        return [409, 'already_posted_other_connection', [
            'provider' => $provider,
            'status' => SyncStatus::Rejected->value,
            'external_id' => $document->externalId,
            'error' => 'document_already_posted',
            'message' => 'Dit document staat al in deze administratie, geboekt via een andere koppeling. Boek het niet nogmaals; een correctie is een creditnota met een eigen external_id.',
        ], []];
    }

    /** @return array{0: int, 1: string, 2: array<string, mixed>, 3: array<string, string>} */
    private function syncInProgressElsewhere(FinancialDocument $document, string $provider): array
    {
        return [409, 'sync_in_progress_other_connection', [
            'provider' => $provider,
            'status' => SyncStatus::Pending->value,
            'external_id' => $document->externalId,
            'error' => 'document_sync_in_progress',
            'message' => 'Een andere koppeling op deze administratie boekt dit document op dit moment. Probeer het zo opnieuw.',
        ], ['Retry-After' => (string) IdempotencyKey::retryAfterCeilingSeconds()]];
    }

    /** @return array{0: int, 1: string, 2: array<string, mixed>, 3: array<string, string>} */
    private function syncInProgress(FinancialDocument $document, string $provider, int $retryAfterSeconds): array
    {
        return [409, 'sync_in_progress', [
            'provider' => $provider,
            'status' => SyncStatus::Pending->value,
            'external_id' => $document->externalId,
            'error' => 'document_sync_in_progress',
            'message' => "Er loopt al een boeking voor external_id '{$document->externalId}' op deze koppeling. Probeer het zo opnieuw.",
        ], ['Retry-After' => (string) $retryAfterSeconds]];
    }

    /** @return array{0: int, 1: string, 2: array<string, mixed>, 3: array<string, string>} */
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
            ], []];
        }

        return [409, 'already_posted', [
            ...$body,
            'status' => SyncStatus::Rejected->value,
            'error' => 'document_already_posted',
            'message' => "Er is al een boeking met external_id '{$document->externalId}' op deze koppeling, met andere inhoud. Gebruik een nieuw external_id (een correctie is een creditnota).",
        ], []];
    }

    /** @return array{0: int, 1: string, 2: array<string, mixed>}|null */
    private function probe(FinancialDocument $document, Connection $connection, string $fingerprint): ?array
    {
        try {
            $target = $this->registry->for($connection->provider->value);

            if (! $target instanceof ProbesPostedDocuments) {
                return null;
            }

            $found = $target->findPostedDocument($document, $connection);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($found === null) {
            return null;
        }

        $this->rememberLink($document, $connection, new AccountingResult(
            status: 201,
            externalRef: $found->id,
            externalNumber: $found->number === null ? null : (int) $found->number,
            raw: [],
            attachments: [],
        ), $fingerprint);

        $body = [
            'provider' => $connection->provider->value,
            'status' => SyncStatus::Posted->value,
            'external_id' => $document->externalId,
            'external_ref' => $found->id,
            'recovered' => true,
        ];

        if ($found->number !== null) {
            $body['external_number'] = $found->number;
        }

        return [200, 'recovered_after_timeout', $body];
    }

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

    /** @param  array<string, mixed>  $responseBody */
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
        $this->recorder->record(
            provider: $connection->provider,
            consumerId: $consumerId,
            accountId: $account->getKey(),
            connectionId: $connection->getKey(),
            method: 'POST',
            path: '/v1/accounting/documents',
            status: $status,
            responseBody: (string) json_encode($responseBody),
            startedAt: $start,
            upstreamError: $upstreamError,
            direction: 'outbound',
            requestFingerprint: substr(hash('sha256', $document->externalId), 0, 12),
            extra: ($responseBody['warnings'] ?? []) === [] ? [] : ['warnings' => $responseBody['warnings']],
        );
    }
}
