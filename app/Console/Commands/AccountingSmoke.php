<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Accounting\AccountingSyncRunner;
use App\Accounting\AccountingTargetRegistry;
use App\Accounting\FinancialDocument;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Verifieert end-to-end of de accounting-sync van één Account-koppeling werkt:
 * post sample-documenten (verkoop/inkoop/income) via de échte AccountingSyncRunner
 * naar het gekoppelde boekhoudpakket en rapporteert per stuk posted/failed.
 *
 * Schrijft ECHTE boekingen in het boekhoudpakket — daarom default alleen in
 * local/testing; `--force` om elders te draaien (bv. tegen een test-administratie).
 */
class AccountingSmoke extends Command
{
    protected $signature = 'hub:accounting:smoke
                            {account : Account external_id}
                            {--consumer= : Consumer slug, naam of id (verplicht bij meerdere)}
                            {--types=sales_invoice,purchase_invoice,income : Door komma gescheiden doc-types}
                            {--debtor=HUB-TEST-KLANT : party.external_id voor verkoop/income}
                            {--creditor=HUB-TEST-LEVERANCIER : party.external_id voor inkoop/expense}
                            {--amount=1 : Regelbedrag ex-BTW}
                            {--rate=21 : BTW-tarief (0/9/21)}
                            {--force : Sta draaien buiten local/testing toe (schrijft echte boekingen)}';

    protected $description = 'Smoke-test de accounting-sync van een Account-koppeling met sample-documenten';

    public function handle(AccountingTargetRegistry $registry, AccountingSyncRunner $runner): int
    {
        if (! $this->guardEnvironment()) {
            return self::FAILURE;
        }

        $consumer = $this->resolveConsumer();

        if ($consumer === null) {
            return self::FAILURE;
        }

        $account = Account::query()
            ->where('consumer_id', $consumer->getKey())
            ->where('external_id', (string) $this->argument('account'))
            ->first();

        if ($account === null) {
            $this->error("Account '{$this->argument('account')}' niet gevonden voor Consumer '{$consumer->name}'.");

            return self::FAILURE;
        }

        $connection = $account->connections()
            ->whereNull('revoked_at')
            ->whereIn('provider', $registry->providers())
            ->first();

        if ($connection === null) {
            $this->error('Geen actieve boekhoud-Connection voor dit Account.');

            return self::FAILURE;
        }

        $this->line("Connection #{$connection->getKey()} · provider={$connection->provider->value} · division={$connection->administratie_id}");

        $rows = [];
        $allPosted = true;

        foreach ($this->resolveTypes() as $type) {
            [$status, $ref] = $this->push($runner, $type, $connection, $account, (int) $consumer->getKey());
            $allPosted = $allPosted && $status === 'posted';
            $rows[] = [$type, $status, $ref];
        }

        $this->table(['type', 'status', 'external_ref / error'], $rows);

        return $allPosted ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function push(AccountingSyncRunner $runner, string $type, Connection $connection, Account $account, int $consumerId): array
    {
        try {
            $document = FinancialDocument::fromArray($this->sampleDocument($type));
            $outcome = $runner->run($document, $connection, $account, $consumerId);
            $body = $outcome->responseBody;

            return [
                (string) ($body['status'] ?? 'unknown'),
                (string) ($body['external_ref'] ?? $body['error'] ?? ''),
            ];
        } catch (Throwable $e) {
            return ['failed', $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleDocument(string $type): array
    {
        $amount = (float) $this->option('amount');
        $rate = (float) $this->option('rate');
        $isPurchase = in_array($type, ['purchase_invoice', 'expense'], true);
        $category = $isPurchase ? 'kosten' : 'omzet';
        $signedAmount = $type === 'credit_note' ? -abs($amount) : $amount;
        $externalId = 'SMOKE-'.mb_strtoupper($type).'-'.now()->format('YmdHis');

        return [
            'type' => $type,
            'external_id' => $externalId,
            'number' => "Smoke {$type}",
            'issue_date' => now()->toDateString(),
            'party' => [
                'role' => $isPurchase ? 'creditor' : 'debtor',
                'name' => $isPurchase ? 'Smoke leverancier' : 'Smoke klant',
                'external_id' => (string) $this->option($isPurchase ? 'creditor' : 'debtor'),
            ],
            'lines' => [[
                'description' => "Smoke {$type}",
                'amount' => $signedAmount,
                'tax_rate' => $rate,
                'category' => $category,
            ]],
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveTypes(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $t): string => mb_trim($t),
            explode(',', (string) $this->option('types')),
        )));
    }

    private function resolveConsumer(): ?Consumer
    {
        $needle = $this->option('consumer');

        if ($needle === null || $needle === '') {
            $consumers = Consumer::query()->limit(2)->get();

            if ($consumers->count() === 1) {
                return $consumers->first();
            }

            $this->error('Meerdere Consumers — geef --consumer=<slug|naam|id>.');

            return null;
        }

        $consumer = Consumer::query()
            ->where('id', is_numeric($needle) ? (int) $needle : 0)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();

        if ($consumer === null) {
            $this->error("Consumer '{$needle}' niet gevonden.");
        }

        return $consumer;
    }

    private function guardEnvironment(): bool
    {
        if (! $this->app->environment('local', 'testing') && ! $this->option('force')) {
            $this->error('Schrijft echte boekingen — alleen local/testing, of draai met --force.');

            return false;
        }

        return true;
    }
}
