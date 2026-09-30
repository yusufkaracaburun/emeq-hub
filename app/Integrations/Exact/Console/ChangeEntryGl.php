<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Console;

use App\Accounting\Enums\DocumentType;
use App\Integrations\Exact\ConnectionTokenStore;
use App\Integrations\Exact\HubExactCredentialResolver;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\ProviderEntityLink;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\ExactConnector;
use Emeq\ExactApi\Http\Request\Read\GetPurchaseEntries;
use Emeq\ExactApi\Http\Request\Read\GetSalesEntries;
use Emeq\ExactApi\Http\Request\Write\UpdatePurchaseEntryLine;
use Emeq\ExactApi\Http\Request\Write\UpdateSalesEntryLine;
use Emeq\ExactApi\OData\Envelope;
use Emeq\ExactApi\OData\Filter;
use Emeq\ExactApi\OData\Guid;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ChangeEntryGl extends Command
{
    private const ACTION_CHANGE = 'wijzigen';

    private const ACTION_SKIP = 'al goed';

    private const ACTION_ERROR = 'fout';

    protected $signature = 'exact:change-entry-gl
                            {connection : Connection-id}
                            {csv : Pad naar CSV met kolommen external_id,gl_code en optioneel line}
                            {--apply : Echt wijzigen (zonder = dry-run)}';

    protected $description = 'Corrigeer het grootboek van al geboekte Exact-boekingsregels vanuit een CSV';

    public function handle(): int
    {
        $connection = Connection::find($this->argument('connection'));

        if ($connection === null) {
            $this->error("Connection {$this->argument('connection')} niet gevonden.");

            return self::FAILURE;
        }

        if ($connection->provider->value !== 'exact') {
            $this->error("Connection {$connection->id} is geen Exact-koppeling ({$connection->provider->value}).");

            return self::FAILURE;
        }

        try {
            $rows = $this->readCsv((string) $this->argument('csv'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        $connector = app(Exact::class)->connector($connection->administratie_id);

        $plan = [];

        foreach ($rows as $row) {
            array_push($plan, ...$this->planRow($connection, $connector, $row));
        }

        $this->table(
            ['external_id', 'EntryNumber', 'LineNumber', 'Bedrag', 'Huidig GL', 'Nieuw GL', 'Actie'],
            array_map(static fn (array $item): array => [
                $item['external_id'],
                $item['entry_number'],
                $item['line_number'],
                $item['amount'],
                $item['old_gl'],
                $item['new_gl'],
                $item['action'] === self::ACTION_ERROR ? "fout: {$item['message']}" : $item['action'],
            ], $plan),
        );

        $changes = array_values(array_filter($plan, static fn (array $item): bool => $item['action'] === self::ACTION_CHANGE));
        $skipped = count(array_filter($plan, static fn (array $item): bool => $item['action'] === self::ACTION_SKIP));
        $errors = count($plan) - count($changes) - $skipped;

        if (! $this->option('apply')) {
            $this->warn('DRY-RUN, niks gewijzigd. Draai met --apply om uit te voeren.');
            $this->line(count($changes)." te wijzigen, {$skipped} al goed, {$errors} fout.");

            return $errors > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($changes !== [] && ! $this->confirm(count($changes).' regel(s) in Exact omboeken?', false)) {
            $this->warn('Afgebroken, niks gewijzigd.');

            return self::FAILURE;
        }

        $changed = 0;

        foreach ($changes as $item) {
            $message = $this->put($connector, $item);

            if ($message === null) {
                $changed++;
                Log::info('exact.entry_line.gl_changed', $this->logContext($connection, $item));
                $this->line("  <info>✓</info> {$item['external_id']} regel {$item['line_number']}");

                continue;
            }

            $errors++;
            Log::warning('exact.entry_line.gl_change_failed', $this->logContext($connection, $item) + ['error' => $message]);
            $this->error("  ✗ {$item['external_id']} regel {$item['line_number']}: {$message}");
        }

        $this->newLine();
        $this->info("Klaar: {$changed} gewijzigd, {$skipped} overgeslagen, {$errors} fout.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<array{external_id: string, gl_code: string, line: ?int}> */
    private function readCsv(string $path): array
    {
        $handle = is_readable($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            throw new RuntimeException("CSV {$path} niet leesbaar.");
        }

        $header = array_map(static fn (?string $column): string => mb_trim((string) $column), fgetcsv($handle, escape: '') ?: []);

        if (! in_array('external_id', $header, true) || ! in_array('gl_code', $header, true)) {
            fclose($handle);

            throw new RuntimeException('CSV mist de kolommen external_id en/of gl_code.');
        }

        $rows = [];

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }

            $row = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
            $line = mb_trim((string) ($row['line'] ?? ''));

            $rows[] = [
                'external_id' => mb_trim((string) $row['external_id']),
                'gl_code' => mb_trim((string) $row['gl_code']),
                'line' => $line === '' ? null : (int) $line,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array{external_id: string, gl_code: string, line: ?int}  $row
     * @return list<array<string, mixed>>
     */
    private function planRow(Connection $connection, ExactConnector $connector, array $row): array
    {
        $error = fn (string $message): array => [[
            'external_id' => $row['external_id'],
            'entry_number' => '',
            'line_number' => $row['line'] ?? '',
            'amount' => '',
            'old_gl' => '',
            'new_gl' => $row['gl_code'],
            'action' => self::ACTION_ERROR,
            'message' => $message,
        ]];

        $link = ProviderEntityLink::query()
            ->where('connection_id', $connection->getKey())
            ->where('entity_type', ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT)
            ->where('external_id', $row['external_id'])
            ->first();

        if ($link === null) {
            return $error('geen boeking met dit external_id op deze connection');
        }

        $type = DocumentType::tryFrom((string) $link->entity_subtype);
        $entryId = Guid::tryFrom((string) $link->provider_entity_id);

        if ($type === null || $entryId === null) {
            return $error("link heeft geen bruikbaar documenttype of EntryID ({$link->entity_subtype})");
        }

        $glGuid = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_GL)
            ->where('code', $row['gl_code'])
            ->value('native_id');

        if ($glGuid === null) {
            return $error("grootboek-code {$row['gl_code']} niet in de mirror");
        }

        $purchase = in_array($type, [DocumentType::PurchaseInvoice, DocumentType::Expense], true);
        $collection = $purchase ? 'PurchaseEntryLines' : 'SalesEntryLines';
        $params = [
            '$select' => "EntryID,EntryNumber,{$collection}",
            '$filter' => Filter::eq('EntryID', $entryId)->expression,
            '$expand' => $collection,
            '$top' => 1,
        ];

        try {
            $response = $connector->send($purchase ? new GetPurchaseEntries($params) : new GetSalesEntries($params));
        } catch (Throwable $e) {
            return $error("boeking ophalen mislukt: {$e->getMessage()}");
        }

        if ($response->failed()) {
            return $error('boeking ophalen mislukt: '.(Envelope::errorMessage($response->body()) ?? "HTTP {$response->status()}"));
        }

        $entry = Envelope::results((array) $response->json())[0] ?? null;

        if ($entry === null) {
            return $error('boeking niet gevonden in Exact');
        }

        $lines = array_values(array_filter(
            Envelope::results(['d' => $entry[$collection] ?? null]),
            static fn (array $line): bool => $row['line'] === null || (int) ($line['LineNumber'] ?? 0) === $row['line'],
        ));

        if ($lines === []) {
            return $error($row['line'] === null ? 'boeking heeft geen regels' : "regel {$row['line']} niet gevonden");
        }

        return array_map(static fn (array $line): array => [
            'external_id' => $row['external_id'],
            'entry_number' => (string) ($entry['EntryNumber'] ?? ''),
            'line_number' => (string) ($line['LineNumber'] ?? ''),
            'amount' => (string) ($line['AmountFC'] ?? ''),
            'old_gl' => mb_trim((string) ($line['GLAccountCode'] ?? '')),
            'new_gl' => $row['gl_code'],
            'action' => strcasecmp((string) ($line['GLAccount'] ?? ''), (string) $glGuid) === 0 ? self::ACTION_SKIP : self::ACTION_CHANGE,
            'message' => null,
            'purchase' => $purchase,
            'entry_id' => $entryId->value,
            'line_id' => (string) ($line['ID'] ?? ''),
            'gl_guid' => (string) $glGuid,
        ], $lines);
    }

    /** @param  array<string, mixed>  $item */
    private function put(ExactConnector $connector, array $item): ?string
    {
        $request = $item['purchase']
            ? new UpdatePurchaseEntryLine($item['line_id'], $item['gl_guid'])
            : new UpdateSalesEntryLine($item['line_id'], $item['gl_guid']);

        try {
            $response = $connector->send($request);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return $response->failed()
            ? (Envelope::errorMessage($response->body()) ?? "HTTP {$response->status()}")
            : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function logContext(Connection $connection, array $item): array
    {
        return [
            'connection_id' => $connection->id,
            'external_id' => $item['external_id'],
            'entry_id' => $item['entry_id'],
            'line_number' => $item['line_number'],
            'old_gl' => $item['old_gl'],
            'new_gl' => $item['new_gl'],
        ];
    }
}
