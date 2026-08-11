<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Contracts\ProbesPostedDocuments;
use App\Accounting\Enums\SyncStatus;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Jobs\Accounting\SyncAccountingDocumentJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ProviderEntityLink;
use App\OAuth\Exceptions\ProviderDisabledException;
use App\Support\Errors\UpstreamErrorMapperRegistry;
use App\Support\PassThrough\PassThroughRecorder;
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
        private UpstreamErrorMapperRegistry $errors,
        private PassThroughRecorder $recorder,
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
        //
        // Claim-first, met de unique index als mutex. Alleen lezen zou twee
        // gelijktijdige requests met verschillende idempotency-keys allebei laten
        // boeken: die zien geen link, en de tabel vangt dat pas achteraf.
        $claim = $this->claimOrExplain($document, $connection, $fingerprint);

        if (! $claim instanceof ProviderEntityLink) {
            [$status, $upstreamError, $responseBody] = $claim;
            $this->audit($account, $connection, $consumerId, $provider, $document, $status, $start, $upstreamError, $responseBody);

            return new AccountingSyncOutcome($status, $responseBody);
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
        } catch (AccountingMappingException $e) {
            $status = 422;
            $upstreamError = 'mapping_failed';
            $responseBody = ['status' => SyncStatus::Failed->value, 'external_id' => $document->externalId, 'error' => 'mapping_failed', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            $mapped = $this->errors->map($provider, $e);
            $status = $mapped['status'];
            $upstreamError = $mapped['short_code'];
            $responseBody = ['status' => SyncStatus::Failed->value, 'external_id' => $document->externalId, ...$mapped['body']];

            // 502/503/504 betekent dat we géén antwoord kregen — niet dat de partner
            // weigerde. Dan is het onbekend of de boeking toch geland is, en precies
            // daar ontstaat de dubbele boeking bij een retry. Even navragen.
            if ($status >= 502 && ($probed = $this->probe($document, $connection, $fingerprint)) !== null) {
                [$status, $upstreamError, $responseBody] = $probed;
                $booked = true;
            }
        } finally {
            // Niets geboekt → claim vrijgeven, anders blokkeert één storing dit
            // external_id voorgoed. Geldt voor élke faalgrond, ook een mapping-fout of
            // een uitgeschakelde provider.
            if (! $booked) {
                $this->links->releaseClaim($claim);
            }
        }

        $this->audit($account, $connection, $consumerId, $provider, $document, $status, $start, $upstreamError, $responseBody);

        return new AccountingSyncOutcome($status, $responseBody);
    }

    /**
     * Probeert dit `external_id` te claimen. Lukt dat, dan is de boeking van ons.
     *
     * Lukt het niet, dan is er al iets: een afgeronde boeking (replay of conflict), een
     * lopende poging (409, even wachten), of een claim van een request dat gestorven is
     * — die laatste nemen we over, want anders blokkeert een gecrashte worker dit
     * document tot iemand handmatig ingrijpt.
     *
     * @return ProviderEntityLink|array{0: int, 1: string, 2: array<string, mixed>}
     */
    private function claimOrExplain(FinancialDocument $document, Connection $connection, string $fingerprint): ProviderEntityLink|array
    {
        $claim = $this->links->claim($document, $connection);

        if ($claim !== null) {
            return $claim;
        }

        $existing = $this->links->find($connection, $document->externalId);

        // Tussen onze INSERT en deze SELECT is de rij weer weg: een gelijktijdige poging
        // faalde en gaf zijn claim vrij. De consumer mag het gewoon opnieuw proberen.
        if ($existing === null) {
            return $this->syncInProgress($document, $connection->provider->value);
        }

        if ($existing->provider_entity_id !== null) {
            return $this->replayExistingLink($existing, $document, $connection->provider->value, $fingerprint);
        }

        if (! $this->links->claimIsStale($existing)) {
            return $this->syncInProgress($document, $connection->provider->value);
        }

        $this->links->releaseClaim($existing);

        return $this->links->claim($document, $connection)
            ?? $this->syncInProgress($document, $connection->provider->value);
    }

    /**
     * Er loopt op dit moment een boeking voor dit document. Geen fout van de consumer —
     * wachten en opnieuw proberen is het juiste antwoord.
     *
     * @return array{0: int, 1: string, 2: array<string, mixed>}
     */
    private function syncInProgress(FinancialDocument $document, string $provider): array
    {
        return [409, 'sync_in_progress', [
            'provider' => $provider,
            'status' => SyncStatus::Pending->value,
            'external_id' => $document->externalId,
            'error' => 'document_sync_in_progress',
            'message' => "Er loopt al een boeking voor external_id '{$document->externalId}' op deze koppeling. Probeer het zo opnieuw.",
        ]];
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
     * Vraagt de partner na of de boeking tóch geland is.
     *
     * Dit dicht het laatste herboek-venster: `provider_entity_links` legt alleen vast
     * wat de Hub heeft zien slagen, dus als de partner commit en de respons ons niet
     * bereikt weet de Hub van niets en boekt een retry opnieuw.
     *
     * Vindt de probe het document, dan is de boeking geslaagd en wordt de link alsnog
     * vastgelegd. Vindt hij niets — of ondersteunt de provider geen probe, of faalt de
     * probe zelf — dan blijft de oorspronkelijke fout staan. Falen naar "rapporteer de
     * fout" is de veilige kant.
     *
     * @return array{0: int, 1: string, 2: array<string, mixed>}|null
     */
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
        $this->recorder->record(
            provider: $connection->provider,
            consumerId: $consumerId,
            accountId: $account->getKey(),
            connectionId: $connection->getKey(),
            method: 'POST',
            // Genormaliseerd endpoint-pad (leading /, conform de andere audit-paden).
            // De doc-type-suffix is verwijderd — hoort niet in `path`.
            path: '/v1/accounting/documents',
            status: $status,
            responseBody: (string) json_encode($responseBody),
            startedAt: $start,
            upstreamError: $upstreamError,
            direction: 'outbound',
            // De canonieke identiteit is de fingerprint, niet de body: die is hier al
            // gevalideerd en het externalId is wat een rij herleidbaar maakt.
            requestFingerprint: substr(hash('sha256', $document->externalId), 0, 12),
        );
    }
}
