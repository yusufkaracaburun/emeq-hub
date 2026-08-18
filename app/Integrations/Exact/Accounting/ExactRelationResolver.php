<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Accounting;

use App\Accounting\BookingWarnings;
use App\Accounting\Exceptions\AccountingMappingException;
use App\Accounting\Exceptions\RelationAmbiguousException;
use App\Accounting\Party;
use App\Integrations\Exact\ConnectionTokenStore;
use App\Integrations\Exact\ExactReferenceData;
use App\Integrations\Exact\HubExactCredentialResolver;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\ProviderEntityLink;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\Request\Write\CreateAccount;
use Emeq\ExactApi\Http\Request\Write\UpdateAccount;
use Emeq\ExactApi\OData\Envelope;
use Throwable;

final class ExactRelationResolver
{
    public function __construct(private readonly BookingWarnings $warnings) {}

    public function resolve(Party $party, Connection $connection): string
    {
        if ($party->relationId !== null && $party->relationId !== '') {
            $this->ensureRole($party->relationId, $party->role, $connection, externalId: $party->externalId);
            $this->learn($connection, $party->externalId, $party->relationId, $party->name, matchedOn: 'pinned');

            return $party->relationId;
        }

        $externalId = $party->externalId;

        $hit = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_RELATION)
            ->where('code', $externalId)
            ->first();

        if ($hit !== null && ! $this->forgetIfGone($hit, $connection, $party)) {
            $this->ensureRole($hit->native_id, $party->role, $connection, externalId: $externalId);

            return $hit->native_id;
        }

        if ($party->isCompany()) {
            $matched = $this->matchCompany($party, $connection);

            if ($matched !== null) {
                return $matched;
            }
        }

        $guid = $this->createRelation($party, $connection);
        $this->learn($connection, $externalId, $guid, $party->name, matchedOn: 'created');
        $this->recordOrigin($connection, $guid, $externalId);
        $this->warnings->add(
            'relation.created',
            "Relatie '{$party->name}' stond nog niet in de administratie en is aangemaakt.",
            ['relation_id' => $guid],
        );

        return $guid;
    }

    private function matchCompany(Party $party, Connection $connection): ?string
    {
        $data = new ExactReferenceData($connection);

        $kvkMatches = $data->relationsByChamberOfCommerce($party->chamberOfCommerce);
        $this->guardAgainstAmbiguity($party, 'KvK-nummer', $party->chamberOfCommerce, $kvkMatches);

        if (count($kvkMatches) === 1) {
            return $this->bindMatch($party, $connection, $kvkMatches[0], matchedOn: 'kvk');
        }

        $vatMatches = $data->relationsByVatNumber($party->vatNumber);
        $this->guardAgainstAmbiguity($party, 'btw-nummer', $party->vatNumber, $vatMatches);

        if (count($vatMatches) === 1) {
            return $this->bindMatch($party, $connection, $vatMatches[0], matchedOn: 'vat');
        }

        $nameMatches = $data->relationsByName($party->name);

        if (count($nameMatches) === 1) {
            $match = $nameMatches[0];
            $guid = $this->bindMatch($party, $connection, $match, matchedOn: 'name');
            $this->writebackIdentifiers($connection, $match, $party);
            $this->warnings->add(
                'relation.matched_by_name',
                "Relatie '{$party->name}' is herkend op naam in plaats van KvK- of btw-nummer.",
                ['relation_id' => $match['id'], 'matched_name' => $match['name']],
            );

            if (self::normalizedNameDiffers($party->name, $match['name'])) {
                $this->warnings->add(
                    'relation.name_differs',
                    "De naam in de administratie ('{$match['name']}') wijkt af van de aangeleverde naam ('{$party->name}').",
                    ['relation_id' => $match['id'], 'party_name' => $party->name, 'matched_name' => $match['name']],
                );
            }

            return $guid;
        }

        return null;
    }

    /** @param  list<array{id: string, name: string}>  $matches */
    private function guardAgainstAmbiguity(Party $party, string $label, ?string $value, array $matches): void
    {
        if (count($matches) <= 1) {
            return;
        }

        throw new RelationAmbiguousException(
            "Meerdere relaties in de administratie matchen op {$label} '{$value}' voor '{$party->name}'. ".
            'Kies er één en stuur die mee als party.relation_id.',
            array_map(fn (array $match): array => ['id' => $match['id'], 'name' => $match['name']], $matches),
        );
    }

    /** @param  array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string}  $match */
    private function bindMatch(Party $party, Connection $connection, array $match, string $matchedOn): string
    {
        $this->ensureRole($match['id'], $party->role, $connection, $match, $party->externalId);
        $this->learn($connection, $party->externalId, $match['id'], $match['name'], matchedOn: $matchedOn);

        return $match['id'];
    }

    private static function normalizedNameDiffers(string $partyName, string $exactName): bool
    {
        $normalize = static function (string $name): string {
            $name = mb_strtolower(trim($name));
            $name = str_replace('.', '', $name);

            return trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name);
        };

        return $normalize($partyName) !== $normalize($exactName);
    }

    /** @param  array{id: string, chamber_of_commerce: ?string, vat_number: ?string}  $match */
    private function writebackIdentifiers(Connection $connection, array $match, Party $party): void
    {
        $chamberOfCommerce = $match['chamber_of_commerce'] === null ? $party->chamberOfCommerce : null;
        $vatNumber = $match['vat_number'] === null ? $party->vatNumber : null;

        if ($chamberOfCommerce === null && $vatNumber === null) {
            return;
        }

        $this->sendUpdate($connection, new UpdateAccount(
            id: $match['id'],
            chamberOfCommerce: $chamberOfCommerce,
            vatNumber: $vatNumber,
        ), $party->externalId);
    }

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

        $exact = $this->bindSdk($connection);

        $response = $exact->connector($division)->send(new CreateAccount(
            name: $party->name,
            status: $isCreditor ? null : 'C',
            isSales: $isCreditor ? null : true,
            isSupplier: $isCreditor ? true : null,
            vatNumber: $vatNumber,
            chamberOfCommerce: $party->chamberOfCommerce,
            addressLine1: $party->addressLine1,
            addressLine2: $party->addressLine2,
            postcode: $party->postcode,
            city: $party->city,
            state: $party->state,
            country: $party->country,
            email: $party->email,
            phone: $party->phone,
            website: $party->website,
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

    /** @param  array{is_sales: bool, is_supplier: bool, status: ?string}|null  $known */
    private function ensureRole(string $guid, ?string $role, Connection $connection, ?array $known = null, ?string $externalId = null): void
    {
        $flags = $known ?? (new ExactReferenceData($connection))->relationRoles($guid);

        if ($flags === null) {
            return;
        }

        if ($role === 'creditor') {
            if (($flags['is_supplier'] ?? false) !== true) {
                $this->sendUpdate($connection, new UpdateAccount(id: $guid, isSupplier: true), $externalId);
            }

            return;
        }

        if (($flags['is_sales'] ?? false) !== true) {
            $this->sendUpdate($connection, new UpdateAccount(id: $guid, status: 'C', isSales: true), $externalId);
        }
    }

    private function sendUpdate(Connection $connection, UpdateAccount $request, ?string $externalId = null): void
    {
        $division = (string) $connection->administratie_id;

        if ($division === '') {
            return;
        }

        $exact = $this->bindSdk($connection);

        $response = $exact->connector($division)->send($request);

        if ($response->failed()) {
            $response->throw();
        }

        $this->recordOrigin($connection, $this->updatedGuid($request), $externalId);
    }

    private function updatedGuid(UpdateAccount $request): string
    {
        preg_match("/guid'([^']+)'/", $request->resolveEndpoint(), $matches);

        return $matches[1] ?? '';
    }

    private function bindSdk(Connection $connection): Exact
    {
        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        app()->forgetInstance(Exact::class);

        return app(Exact::class);
    }

    private function forgetIfGone(ConnectionAccountingRef $hit, Connection $connection, Party $party): bool
    {
        if (! (new ExactReferenceData($connection))->relationIsGone($hit->native_id)) {
            return false;
        }

        $guid = $hit->native_id;
        $hit->delete();

        ProviderEntityLink::query()
            ->where('connection_id', $connection->getKey())
            ->where('entity_type', ProviderEntityLink::ENTITY_RELATION)
            ->where('provider_entity_id', $guid)
            ->delete();

        $this->warnings->add(
            'relation.relinked',
            "De gekoppelde relatie voor '{$party->name}' bestaat niet meer in de administratie; er is opnieuw gezocht.",
            ['previous_relation_id' => $guid],
        );

        return true;
    }

    private function learn(Connection $connection, string $externalId, string $guid, string $name, string $matchedOn): void
    {
        if ($externalId === '') {
            return;
        }

        ConnectionAccountingRef::query()->updateOrCreate(
            [
                'connection_id' => $connection->getKey(),
                'kind' => ConnectionAccountingRef::KIND_RELATION,
                'code' => $externalId,
            ],
            [
                'native_id' => $guid,
                'label' => $name !== '' ? $name : null,
                'synced_at' => now(),
                'attrs' => ['matched_on' => $matchedOn],
            ],
        );
    }

    private function recordOrigin(Connection $connection, string $guid, ?string $externalId): void
    {
        if ($guid === '') {
            return;
        }

        try {
            ProviderEntityLink::query()->updateOrCreate(
                [
                    'connection_id' => $connection->getKey(),
                    'entity_type' => ProviderEntityLink::ENTITY_RELATION,
                    'provider_entity_id' => $guid,
                ],
                [
                    'provider' => $connection->provider->value,
                    'administratie_id' => (string) ($connection->administratie_id ?? ''),
                    'entity_subtype' => '',
                    'external_id' => $externalId ?? $guid,
                    'payload_fingerprint' => null,
                    'origin' => ProviderEntityLink::ORIGIN_HUB,
                    'last_synced_at' => now(),
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
