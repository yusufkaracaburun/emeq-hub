<?php

declare(strict_types=1);

namespace App\Services\Exact;

use App\Models\Connection;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\Request\Read\GetGlAccounts;
use Emeq\ExactApi\Http\Request\Read\GetJournals;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Emeq\ExactApi\Http\Request\Read\GetVatCodes;
use Emeq\ExactApi\OData\Envelope;
use Saloon\Http\Request as SdkRequest;
use Throwable;

/**
 * Haalt Exact-referentiedata (BTW-codes, grootboekrekeningen, relaties, dagboeken)
 * op voor de boekhoud-mapping-UI in de admin. Draait server-side op één Connection
 * — geen consumer-request, dus géén ExactForwarder/pass-through-audit — en bindt de
 * SDK per-call zoals ResolveExactAccount + ExactAccountingTarget.
 *
 * De endpoints + response-envelope leven in de emeq/exact-api SDK (named read-requests
 * + Envelope); deze service kiest alleen de `$select` en mapt de records naar UI-labels.
 * Faalt zacht: bij een ontbrekende division, een pending Connection of een Exact-fout
 * levert elke methode een lege lijst, zodat de UI terugvalt op handinvoer.
 */
final class ExactReferenceData
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * BTW-codes: [Code => label]. De Code is de waarde die de mapping als VATCode opslaat.
     *
     * @return array<string, string>
     */
    public function vatCodes(): array
    {
        $out = [];

        foreach ($this->fetch(new GetVatCodes(['$select' => 'Code,Description,Percentage'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[$code] = $this->label($code, $row['Description'] ?? null).$this->percentageSuffix($row['Percentage'] ?? null);
        }

        return $out;
    }

    /**
     * Grootboekrekeningen: [GUID => label]. De ID (GUID) is de mapping-waarde.
     *
     * @return array<string, string>
     */
    public function glAccounts(): array
    {
        $out = [];

        foreach ($this->fetch(new GetGlAccounts(['$select' => 'ID,Code,Description'])) as $row) {
            $id = (string) ($row['ID'] ?? '');

            if ($id === '') {
                continue;
            }

            $out[$id] = $this->label(trim((string) ($row['Code'] ?? '')), $row['Description'] ?? null);
        }

        return $out;
    }

    /**
     * Relaties: [GUID => Naam]. De ID (GUID) is de mapping-waarde (crm/Account-GUID).
     *
     * @return array<string, string>
     */
    public function relations(): array
    {
        $out = [];

        foreach ($this->fetch(new GetRelations(['$select' => 'ID,Name,Code'])) as $row) {
            $id = (string) ($row['ID'] ?? '');

            if ($id === '') {
                continue;
            }

            $out[$id] = (string) ($row['Name'] ?? $id);
        }

        return $out;
    }

    /**
     * Dagboeken: [Code => label]. De Code is de mapping-waarde (Journal).
     *
     * @return array<string, string>
     */
    public function journals(): array
    {
        $out = [];

        foreach ($this->fetch(new GetJournals(['$select' => 'Code,Description,Type'])) as $row) {
            $code = trim((string) ($row['Code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $out[$code] = $this->label($code, $row['Description'] ?? null);
        }

        return $out;
    }

    /**
     * Zoekt één Exact-relatie op BTW-nummer (anders naam). Ambiguïteit-veilig: bij 0 óf
     * >1 treffer null (niet automatisch kiezen). Read-only; gebruikt door het validate-
     * rapport om "bestaande relatie" vs "nieuw" te tonen zonder te muteren.
     *
     * @return array{id: string, code: string, name: string}|null
     */
    public function findRelation(?string $vatNumber, ?string $name): ?array
    {
        $filter = $this->relationFilter($vatNumber, $name);

        if ($filter === null) {
            return null;
        }

        $rows = $this->fetch(new GetRelations(['$select' => 'ID,Code,Name', '$filter' => $filter, '$top' => '2']));

        if (count($rows) !== 1) {
            return null;
        }

        $id = (string) ($rows[0]['ID'] ?? '');

        return $id === '' ? null : [
            'id' => $id,
            'code' => trim((string) ($rows[0]['Code'] ?? '')),
            'name' => (string) ($rows[0]['Name'] ?? ''),
        ];
    }

    private function relationFilter(?string $vatNumber, ?string $name): ?string
    {
        $vatNumber = trim((string) $vatNumber);

        if ($vatNumber !== '') {
            return "VATNumber eq '".$this->escapeOData($vatNumber)."'";
        }

        $name = trim((string) $name);

        return $name !== '' ? "Name eq '".$this->escapeOData($name)."'" : null;
    }

    private function escapeOData(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(SdkRequest $request): array
    {
        $division = (string) $this->connection->administratie_id;

        if ($division === '') {
            return [];
        }

        try {
            app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($this->connection));
            app()->instance(TokenStore::class, new ConnectionTokenStore($this->connection));
            app()->forgetInstance(Exact::class);

            /** @var Exact $exact */
            $exact = app(Exact::class);

            $response = $exact->connector($division)->send($request);

            if ($response->failed()) {
                return [];
            }

            return Envelope::results($response->json());
        } catch (Throwable) {
            return [];
        }
    }

    private function label(string $code, ?string $description): string
    {
        $description = trim((string) $description);

        if ($code !== '' && $description !== '') {
            return "{$code} — {$description}";
        }

        return $code !== '' ? $code : $description;
    }

    private function percentageSuffix(mixed $percentage): string
    {
        if ($percentage === null || $percentage === '') {
            return '';
        }

        $value = (float) $percentage;
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return " ({$formatted}%)";
    }
}
