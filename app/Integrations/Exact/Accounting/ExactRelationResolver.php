<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Accounting;

use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\Party;
use App\Integrations\Exact\ConnectionTokenStore;
use App\Integrations\Exact\ExactReferenceData;
use App\Integrations\Exact\HubExactCredentialResolver;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\Request\Write\CreateAccount;
use Emeq\ExactApi\Http\Request\Write\UpdateAccount;
use Emeq\ExactApi\OData\Envelope;

/**
 * Lazy relatie-resolutie (de volatiele set — nul consumer-upkeep): map `party.external_id`
 * → Exact-relatie-GUID via de mirror; bij een miss match op de party-data (VATNumber, anders
 * Name) en leer de link in de mirror. Niet gevonden → null (de caller geeft een 422).
 *
 * Auto-create van een ontbrekende relatie vraagt de consumer expliciet aan per document
 * (`party.create_if_missing === true`) — een Connection-brede opt-in zou elke typefout in
 * een partijnaam onomkeerbaar in andermans administratie zetten. De Connection behoudt enkel
 * een veto: staat `metadata.accounting_mapping.auto_create_relations` expliciet op `false`,
 * dan blijft het 422 ongeacht de document-intentie. Onbezet (of `true`) vetoot niet.
 */
final class ExactRelationResolver
{
    public function resolve(Party $party, Connection $connection): ?string
    {
        $externalId = $party->externalId;

        if ($externalId !== null && $externalId !== '') {
            $hit = ConnectionAccountingRef::query()
                ->where('connection_id', $connection->getKey())
                ->where('kind', ConnectionAccountingRef::KIND_RELATION)
                ->where('code', $externalId)
                ->first();

            if ($hit !== null) {
                $this->ensureRole($hit->native_id, $party->role, $connection);

                return $hit->native_id;
            }
        }

        $match = (new ExactReferenceData($connection))->findRelation($party->vatNumber, $party->name);

        if ($match !== null) {
            $this->ensureRole($match['id'], $party->role, $connection, $match);
            $this->learn($connection, $externalId, $match['id'], $match['name']);

            return $match['id'];
        }

        if ($party->createIfMissing && ! $connection->autoCreateRelationsVetoed()) {
            $guid = $this->createRelation($party, $connection);
            $this->learn($connection, $externalId, $guid, $party->name, createdByHub: true);

            return $guid;
        }

        return null;
    }

    /**
     * Maakt de party als Exact-relatie aan en geeft de nieuwe GUID terug. Debiteur →
     * `Status='C'` + `IsSales`; crediteur → `IsSupplier`. Een Exact-fout surface't (de
     * boeking faalt dan met een upstream-error i.p.v. stilletjes te 422'en).
     */
    private function createRelation(Party $party, Connection $connection): string
    {
        $division = (string) $connection->administratie_id;

        if ($division === '') {
            throw new AccountingMappingException(
                'Exact-Connection heeft geen division (administratie_id) — herkoppel de Account.'
            );
        }

        $isCreditor = $party->role === 'creditor';
        $vatNumber = ($party->vatNumber !== null && $party->vatNumber !== '') ? $party->vatNumber : null;

        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        app()->forgetInstance(Exact::class);

        /** @var Exact $exact */
        $exact = app(Exact::class);

        $response = $exact->connector($division)->send(new CreateAccount(
            name: $party->name,
            status: $isCreditor ? null : 'C',
            isSales: $isCreditor ? null : true,
            isSupplier: $isCreditor ? true : null,
            vatNumber: $vatNumber,
        ));

        if ($response->failed()) {
            $response->throw();
        }

        $guid = Envelope::firstId($response->json());

        if ($guid === null || $guid === '') {
            throw new AccountingMappingException(
                "Exact gaf geen relatie-GUID terug bij het aanmaken van '{$party->name}'."
            );
        }

        return $guid;
    }

    /**
     * Zorgt dat de relatie de rol-vlag draagt die de boeking nodig heeft: een crediteur-
     * boeking eist `IsSupplier`, een debiteur-boeking `IsSales` (+ `Status='C'`). Exact-
     * relaties mogen beide rollen tegelijk dragen → promoveren i.p.v. dupliceren (dezelfde
     * firma kan klant én leverancier zijn). Staat de vlag al goed, dan niets. `$known` zijn
     * de rol-vlaggen uit findRelation; ontbreken ze (mirror-hit), dan leest ensureRole ze
     * zelf op GUID. Niet leesbaar → overslaan; de boeking levert de fout dan zelf op.
     *
     * @param  array{is_sales: bool, is_supplier: bool, status: ?string}|null  $known
     */
    private function ensureRole(string $guid, ?string $role, Connection $connection, ?array $known = null): void
    {
        $flags = $known ?? (new ExactReferenceData($connection))->relationRoles($guid);

        if ($flags === null) {
            return;
        }

        if ($role === 'creditor') {
            if (($flags['is_supplier'] ?? false) !== true) {
                $this->sendUpdate($connection, new UpdateAccount(id: $guid, isSupplier: true));
            }

            return;
        }

        if (($flags['is_sales'] ?? false) !== true) {
            $this->sendUpdate($connection, new UpdateAccount(id: $guid, status: 'C', isSales: true));
        }
    }

    private function sendUpdate(Connection $connection, UpdateAccount $request): void
    {
        $division = (string) $connection->administratie_id;

        if ($division === '') {
            return;
        }

        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        app()->forgetInstance(Exact::class);

        /** @var Exact $exact */
        $exact = app(Exact::class);

        $response = $exact->connector($division)->send($request);

        if ($response->failed()) {
            $response->throw();
        }
    }

    /**
     * `$createdByHub` markeert `attrs.created_by_hub` — het onderscheid tussen een relatie die
     * de Hub zelf in andermans administratie heeft gezet en een die enkel gematcht is.
     */
    private function learn(Connection $connection, ?string $externalId, string $guid, string $name, bool $createdByHub = false): void
    {
        if ($externalId === null || $externalId === '') {
            return;
        }

        $values = [
            'native_id' => $guid,
            'label' => $name !== '' ? $name : null,
            'synced_at' => now(),
        ];

        if ($createdByHub) {
            $values['attrs'] = ['created_by_hub' => true];
        }

        ConnectionAccountingRef::query()->updateOrCreate(
            [
                'connection_id' => $connection->getKey(),
                'kind' => ConnectionAccountingRef::KIND_RELATION,
                'code' => $externalId,
            ],
            $values,
        );
    }
}
