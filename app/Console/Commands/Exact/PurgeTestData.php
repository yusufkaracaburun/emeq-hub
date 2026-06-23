<?php

declare(strict_types=1);

namespace App\Console\Commands\Exact;

use App\Models\Connection;
use App\Services\Exact\ConnectionTokenStore;
use App\Services\Exact\HubExactCredentialResolver;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\Request\Delete\DeleteAccount;
use Emeq\ExactApi\Http\Request\Delete\DeletePurchaseEntry;
use Emeq\ExactApi\Http\Request\Delete\DeleteSalesEntry;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Emeq\ExactApi\OData\Envelope;
use Illuminate\Console\Command;
use Saloon\Enums\Method;
use Throwable;

/**
 * Ruimt test-boekingen en (expliciet opgegeven) test-relaties op in een Exact-division
 * zodat de boekhoud-koppeling opnieuw end-to-end getest kan worden.
 *
 * Boekingen (sales/purchase) worden áltijd verwijderd — die zet de Hub zelf neer. Relaties
 * worden NOOIT automatisch verwijderd (een division draagt Exact-default-relaties zoals
 * "Belastingdienst Omzetbelasting"); geef de te wissen relatie-GUID's expliciet via
 * --relations. Default is dry-run; --force voert daadwerkelijk uit.
 */
final class PurgeTestData extends Command
{
    protected $signature = 'exact:purge-test-data
                            {connection : Connection-ID van de Exact-koppeling}
                            {--force : Daadwerkelijk verwijderen (zonder = dry-run)}
                            {--relations= : Comma-separated relatie-GUID(s) om óók te verwijderen}';

    protected $description = 'Verwijder test-boekingen (en opgegeven relaties) in een Exact-division voor een schone her-test';

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

        $division = $connection->administratie_id;
        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        $connector = app(Exact::class)->connector($division);

        $get = function (string $endpoint, array $query) use ($connector): array {
            $response = $connector->send(new RawExactRequest(Method::GET, $endpoint, $query));

            return Envelope::results($response->json());
        };

        $sales = $get('/salesentry/SalesEntries', ['$select' => 'EntryID,EntryNumber,YourRef']);
        $purchase = $get('/purchaseentry/PurchaseEntries', ['$select' => 'EntryID,EntryNumber,YourRef']);
        $accounts = $get('/crm/Accounts', ['$select' => 'ID,Code,Name']);

        $relationIds = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('relations')))));

        $this->info("Division {$division} — Connection {$connection->id}");
        $this->newLine();

        $this->line('<comment>Boekingen (worden verwijderd):</comment>');
        foreach ([['Sales', $sales], ['Purchase', $purchase]] as [$label, $rows]) {
            foreach ($rows as $row) {
                $this->line("  [{$label}] {$row['EntryID']}  #".($row['EntryNumber'] ?? '-').'  '.($row['YourRef'] ?? '-'));
            }
        }
        $this->line('  totaal: '.(count($sales) + count($purchase)));
        $this->newLine();

        $this->line('<comment>Relaties in de division (verwijder alleen wat je opgeeft via --relations):</comment>');
        foreach ($accounts as $row) {
            $mark = in_array($row['ID'], $relationIds, true) ? '<fg=red>← wordt verwijderd</>' : '';
            $this->line("  {$row['ID']}  ".($row['Code'] ? trim($row['Code']) : '').'  '.$row['Name']."  {$mark}");
        }
        $this->newLine();

        if (! $this->option('force')) {
            $this->warn('DRY-RUN — niks verwijderd. Draai met --force om uit te voeren.');
            if ($relationIds !== []) {
                $this->line('  '.count($relationIds).' relatie(s) zouden worden verwijderd: '.implode(', ', $relationIds));
            }

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($sales as $row) {
            [$ok, $failed] = $this->delete($connector, new DeleteSalesEntry($row['EntryID']), "Sales {$row['EntryID']}", $ok, $failed);
        }
        foreach ($purchase as $row) {
            [$ok, $failed] = $this->delete($connector, new DeletePurchaseEntry($row['EntryID']), "Purchase {$row['EntryID']}", $ok, $failed);
        }
        foreach ($relationIds as $id) {
            [$ok, $failed] = $this->delete($connector, new DeleteAccount($id), "Relatie {$id}", $ok, $failed);
        }

        $this->newLine();
        $this->info("Klaar — {$ok} verwijderd, {$failed} mislukt.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{0: int, 1: int}  ...
     * @return array{0: int, 1: int}
     */
    private function delete(object $connector, object $request, string $label, int $ok, int $failed): array
    {
        try {
            $response = $connector->send($request);

            if ($response->failed()) {
                $this->error("  ✗ {$label} — HTTP {$response->status()}");

                return [$ok, $failed + 1];
            }

            $this->line("  <info>✓</info> {$label}");

            return [$ok + 1, $failed];
        } catch (Throwable $e) {
            $this->error("  ✗ {$label} — {$e->getMessage()}");

            return [$ok, $failed + 1];
        }
    }
}
