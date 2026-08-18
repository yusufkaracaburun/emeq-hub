<?php

declare(strict_types=1);

namespace App\Console\Commands\Exact;

use App\Integrations\Exact\ConnectionTokenStore;
use App\Integrations\Exact\HubExactCredentialResolver;
use App\Models\Connection;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\Request\Delete\DeleteAccount;
use Emeq\ExactApi\Http\Request\Delete\DeleteDocument;
use Emeq\ExactApi\Http\Request\Delete\DeletePurchaseEntry;
use Emeq\ExactApi\Http\Request\Delete\DeleteSalesEntry;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Emeq\ExactApi\OData\Envelope;
use Illuminate\Console\Command;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Throwable;

/**
 * Ruimt test-boekingen, hun Documents en (expliciet opgegeven) test-relaties op in een
 * Exact-division zodat de boekhoud-koppeling opnieuw end-to-end getest kan worden.
 *
 * Boekingen (sales/purchase) en Documents worden áltijd verwijderd — beide zet de Hub
 * (of Exact zelf, bij elke PurchaseEntry) zelf neer. Documents gaan vóór de relaties weg:
 * Exact weigert een relatie te verwijderen zolang er nog een gekoppeld Document bestaat
 * ("Kan niet verwijderen: Relatie - Gebruikt in: Documenten"). Relaties worden NOOIT
 * automatisch verwijderd (een division draagt Exact-default-relaties zoals
 * "Belastingdienst Omzetbelasting"); geef de te wissen relatie-GUID's expliciet via
 * --relations. Default is dry-run; --force voert daadwerkelijk uit.
 *
 * LET OP — dit laat de Hub-eigen state staan (`provider_entity_links`, idempotency-claims,
 * relatie-mirror). Die legt vast wat de Hub in deze administratie heeft zien slagen, en
 * weet niet dat de boekingen buiten de Hub om verdwenen zijn. Biedt een consumer zo'n
 * `external_id` daarna opnieuw aan, dan antwoordt de Hub `200 deduplicated` of
 * `409 document_already_posted` en landt het document nooit in Exact. Draai daarom ná
 * een purge `hub:reset-connection` op dezelfde Connection; de command noemt dat zelf ook
 * aan het eind van een run. Bewust twee stappen: Hub-state weggooien is een aparte
 * beslissing, en de purge draait meestal in meerdere passes.
 *
 * Exact throttelt op ~60 calls/minuut per division en elke entry en elk Document is één
 * DELETE. Een volle administratie vergt daarom meerdere passes met een minuut ertussen;
 * de command is herhaalbaar en pakt bij elke run op wat er nog staat.
 */
final class PurgeTestData extends Command
{
    protected $signature = 'exact:purge-test-data
                            {connection : Connection-ID van de Exact-koppeling}
                            {--force : Daadwerkelijk verwijderen (zonder = dry-run)}
                            {--relations= : Comma-separated relatie-GUID(s) om óók te verwijderen}';

    protected $description = 'Verwijder test-boekingen, hun Documents (en opgegeven relaties) in een Exact-division voor een schone her-test';

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
        $documents = $get('/documents/Documents', ['$select' => 'ID,Subject']);
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

        $this->line('<comment>Documents (worden verwijderd — bijproduct van de boekingen, blokkeren anders de relaties):</comment>');
        foreach ($documents as $row) {
            $this->line("  {$row['ID']}  ".($row['Subject'] ?? '-'));
        }
        $this->line('  totaal: '.count($documents));
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
            $this->hubStateReminder($connection);

            return self::SUCCESS;
        }

        $tally = [
            'Sales' => ['ok' => 0, 'failed' => 0],
            'Purchase' => ['ok' => 0, 'failed' => 0],
            'Documents' => ['ok' => 0, 'failed' => 0],
            'Relaties' => ['ok' => 0, 'failed' => 0],
        ];
        $failures = [];

        foreach ($sales as $row) {
            $this->recordResult($tally, $failures, 'Sales', "Sales {$row['EntryID']}", $connector, new DeleteSalesEntry($row['EntryID']));
        }
        foreach ($purchase as $row) {
            $this->recordResult($tally, $failures, 'Purchase', "Purchase {$row['EntryID']}", $connector, new DeletePurchaseEntry($row['EntryID']));
        }
        foreach ($documents as $row) {
            $this->recordResult($tally, $failures, 'Documents', "Document {$row['ID']}", $connector, new DeleteDocument($row['ID']));
        }
        foreach ($relationIds as $id) {
            $this->recordResult($tally, $failures, 'Relaties', "Relatie {$id}", $connector, new DeleteAccount($id));
        }

        $ok = array_sum(array_column($tally, 'ok'));
        $failed = array_sum(array_column($tally, 'failed'));

        $this->newLine();
        $this->info("Klaar — {$ok} verwijderd, {$failed} mislukt.");
        foreach ($tally as $category => $counts) {
            $this->line("  {$category}: {$counts['ok']} verwijderd, {$counts['failed']} mislukt");
        }

        if ($failures !== []) {
            $this->newLine();
            $this->line('<comment>Mislukte deletes:</comment>');
            foreach ($failures as $failure) {
                $this->line("  ✗ {$failure}");
            }
        }

        $this->hubStateReminder($connection);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * De Hub-eigen state overleeft deze purge en blokkeert anders elke her-test met
     * `deduplicated` of `document_already_posted` op documenten die in Exact allang weg
     * zijn. Weggooien blijft een aparte, expliciete stap.
     */
    private function hubStateReminder(Connection $connection): void
    {
        $this->newLine();
        $this->line('<comment>De Hub-kant staat er nog:</comment> entity-links, idempotency-claims en de relatie-mirror.');
        $this->line("  Ruim die op met:  <info>php artisan hub:reset-connection {$connection->id} --force</info>");
    }

    /**
     * Voert één delete uit, telt 'm mee in $tally onder $category en bewaart de
     * Exact-foutmelding in $failures bij een mislukking — zonder de rest van de purge
     * te stoppen.
     *
     * @param  array<string, array{ok: int, failed: int}>  $tally
     * @param  list<string>  $failures
     *
     * @param-out array<string, array{ok: int, failed: int}>  $tally
     */
    private function recordResult(array &$tally, array &$failures, string $category, string $label, object $connector, object $request): void
    {
        $result = $this->delete($connector, $request, $label);

        $tally[$category] ??= ['ok' => 0, 'failed' => 0];

        if ($result['ok']) {
            $tally[$category]['ok']++;

            return;
        }

        $tally[$category]['failed']++;
        $failures[] = "{$label} — {$result['message']}";
    }

    /**
     * @return array{ok: bool, message: ?string}
     */
    private function delete(object $connector, object $request, string $label): array
    {
        try {
            $response = $connector->send($request);

            if ($response->failed()) {
                $message = $this->exactErrorMessage($response);
                $this->error("  ✗ {$label} — {$message}");

                return ['ok' => false, 'message' => $message];
            }

            $this->line("  <info>✓</info> {$label}");

            return ['ok' => true, 'message' => null];
        } catch (Throwable $e) {
            $this->error("  ✗ {$label} — {$e->getMessage()}");

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Exact's functionele OData-foutmelding (`error.message.value`) — dezelfde tekst die
     * de boekhouder in de Exact-UI ziet. Valt terug op de HTTP-status wanneer de body geen
     * bruikbare melding bevat (bijv. een Akamai-blockpagina in plaats van JSON).
     */
    private function exactErrorMessage(Response $response): string
    {
        try {
            $message = $response->json('error.message.value');
        } catch (Throwable) {
            return "HTTP {$response->status()}";
        }

        return is_string($message) && $message !== '' ? $message : "HTTP {$response->status()}";
    }
}
