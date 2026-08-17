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

/**
 * De relatie-resolutie-ladder: `party.relation_id` (pin) → mirror op `external_id` →
 * KvK → btw → genormaliseerde naam → aanmaken. Elke stap bindt alleen bij precies
 * één treffer; meer dan één treffer op KvK of btw is een {@see RelationAmbiguousException}
 * (de administratie is dan al dubbelzinnig, er nog een derde relatie bij zetten maakt
 * het erger). `person`-parties slaan KvK/btw/naam over — geen sterke sleutel, en
 * naam-matching op een natuurlijk persoon koppelt een factuur mogelijk aan de
 * verkeerde persoon (AVG-incident, geen boekhoudfout). Zie
 * `.docs/decisions/relation-resolution-ladder.md`.
 */
final class ExactRelationResolver
{
    public function __construct(private readonly BookingWarnings $warnings) {}

    public function resolve(Party $party, Connection $connection): string
    {
        if ($party->relationId !== null && $party->relationId !== '') {
            $this->ensureRole($party->relationId, $party->role, $connection, externalId: $party->externalId);
            // Eén boeking pint permanent: de mirror onthoudt de keuze, dus een volgend
            // document met hetzelfde external_id (zonder relation_id) hit'm op stap 1.
            $this->learn($connection, $party->externalId, $party->relationId, $party->name, matchedOn: 'pinned');

            return $party->relationId;
        }

        $externalId = $party->externalId;

        $hit = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_RELATION)
            ->where('code', $externalId)
            ->first();

        if ($hit !== null) {
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

    /**
     * KvK → btw → genormaliseerde naam. Geeft de GUID terug bij een enkelvoudige
     * treffer, null wanneer geen van de drie iets vindt (de caller maakt dan aan).
     */
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

        // Ambigu op naam stopt niet hard (zie ADR): 0 of 2+ treffers vallen door naar
        // aanmaken, net als de oude naam-match. Alleen KvK/btw zijn sterk genoeg om een
        // ambiguïteit hard te weigeren.
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

    /**
     * @param  list<array{id: string, name: string}>  $matches
     */
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

    /**
     * @param  array{id: string, code: string, name: string, is_sales: bool, is_supplier: bool, status: ?string}  $match
     */
    private function bindMatch(Party $party, Connection $connection, array $match, string $matchedOn): string
    {
        $this->ensureRole($match['id'], $party->role, $connection, $match, $party->externalId);
        $this->learn($connection, $party->externalId, $match['id'], $match['name'], matchedOn: $matchedOn);

        return $match['id'];
    }

    /**
     * Bewust géén rechtsvorm-suffixen strippen (in tegenstelling tot
     * `ExactReferenceData::normalizeName()`): de naam-match die hiervoor liep matchte
     * al genormaliseerd, dus met dezelfde suffix-strip zou dit altijd `false` teruggeven.
     * Deze zwakkere vergelijking (alleen hoofdletters/interpunctie weg) vangt nog wél
     * een echt afwijkende naam ("Acme BV" vs "Acme Holding BV") zonder te klagen over
     * "Acme BV" vs "Acme B.V.".
     */
    private static function normalizedNameDiffers(string $partyName, string $exactName): bool
    {
        $normalize = static function (string $name): string {
            $name = mb_strtolower(trim($name));
            $name = str_replace('.', '', $name);

            return trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name);
        };

        return $normalize($partyName) !== $normalize($exactName);
    }

    /**
     * Vult alleen een lege `ChamberOfCommerce`/`VATNumber` — nooit overschrijven, en
     * nooit naam/adres/contactgegevens: die zijn van de administratie. Maakt de ladder
     * zelfhelend: een administratie waar KvK leeg staat matcht de eerste keer op naam
     * en daarna op KvK.
     *
     * @param  array{id: string, chamber_of_commerce: ?string, vat_number: ?string}  $match
     */
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

    /**
     * Zorgt dat de relatie de rol-vlag draagt die de boeking nodig heeft: een crediteur-
     * boeking eist `IsSupplier`, een debiteur-boeking `IsSales` (+ `Status='C'`). Exact-
     * relaties mogen beide rollen tegelijk dragen → promoveren i.p.v. dupliceren (dezelfde
     * firma kan klant én leverancier zijn). Staat de vlag al goed, dan niets. `$known` zijn
     * de rol-vlaggen uit de matchCompany-stap; ontbreken ze (pin of mirror-hit), dan leest
     * ensureRole ze zelf op GUID. Niet leesbaar → overslaan; de boeking levert de fout dan
     * zelf op.
     *
     * @param  array{is_sales: bool, is_supplier: bool, status: ?string}|null  $known
     */
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

    /**
     * `UpdateAccount::$id` is private — geen getter op de SDK-request. De GUID staat
     * wél in het pad (`crm/Accounts(guid'{id}')`), dus die lezen we daaruit terug i.p.v.
     * de SDK een publieke accessor te laten toevoegen voor één interne caller.
     */
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

    /**
     * `$matchedOn` voedt de beheerdrawer en maakt achteraf verklaarbaar waaróm twee
     * dingen aan elkaar hangen: `kvk` | `vat` | `name` | `created` | `pinned`.
     */
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

    /**
     * Legt vast dat de Hub deze relatie heeft aangeraakt (aanmaken, sleutel-writeback,
     * rolpromotie) — het enige pad dat {@see \App\Integrations\Exact\Webhooks\ExactHubOriginDetector}
     * nog leest voor echo-detectie op relaties. `updateOrCreate` op `(connection, entity_type,
     * provider_entity_id)`: die tweede unieke sleutel op de tabel staat maar één rij per
     * relatie toe, dus twee verschillende external_id's die dezelfde relatie raken delen 'm.
     * Best-effort — een mislukte log-schrijving mag een geslaagde relatie-write nooit
     * alsnog laten falen.
     */
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
