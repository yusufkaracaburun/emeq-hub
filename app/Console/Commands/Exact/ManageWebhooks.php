<?php

declare(strict_types=1);

namespace App\Console\Commands\Exact;

use App\Enums\Provider;
use App\Integrations\Exact\ConnectionTokenStore;
use App\Integrations\Exact\ExactWebhookSubscriptionManager;
use App\Integrations\Exact\HubExactCredentialResolver;
use App\Models\Connection;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\ExactConnector;
use Emeq\ExactApi\Http\Request\Delete\DeleteWebhookSubscription;
use Emeq\ExactApi\Http\Request\Write\CreateWebhookSubscription;
use Emeq\ExactApi\OData\Envelope;
use Illuminate\Console\Command;
use Throwable;

final class ManageWebhooks extends Command
{
    protected $signature = 'exact:webhooks
                            {connection : Connection-ID (numeriek) of public_id (con_…)}
                            {action=status : status | register | unregister | probe}
                            {--force : Daadwerkelijk uitvoeren (zonder = dry-run)}
                            {--topics= : Alleen voor probe — comma-separated topic-strings om te testen}';

    protected $description = 'Toon en herstel de Exact-webhook-abonnementen van één Connection zonder opnieuw te koppelen';

    public function handle(ExactWebhookSubscriptionManager $manager): int
    {
        $connection = $this->resolveConnection((string) $this->argument('connection'));

        if ($connection === null) {
            $this->error("Connection '{$this->argument('connection')}' niet gevonden.");

            return self::FAILURE;
        }

        if ($connection->provider !== Provider::Exact) {
            $this->error("Connection {$connection->id} is geen Exact-koppeling ({$connection->provider->value}).");

            return self::FAILURE;
        }

        if ($connection->revoked_at !== null) {
            $this->warn('Deze koppeling is ingetrokken — opruimen mag, registreren heeft geen zin.');
        }

        $this->info("{$connection->public_id} (#{$connection->id}) — division {$connection->administratie_id}");
        $this->newLine();

        try {
            return match ((string) $this->argument('action')) {
                'status' => $this->status($manager, $connection),
                'register' => $this->register($manager, $connection),
                'unregister' => $this->unregister($manager, $connection),
                'probe' => $this->probe($connection),
                default => $this->unknownAction(),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function status(ExactWebhookSubscriptionManager $manager, Connection $connection): int
    {
        $plan = $manager->plan($connection);

        $this->line("<comment>CallbackURL</comment> — {$plan['callback_url']}");
        $this->newLine();

        if ($plan['configured'] === []) {
            $this->warn('Geen topics geconfigureerd (services.exact.webhook_topics).');

            return self::SUCCESS;
        }

        $this->line('<comment>Geconfigureerde topics</comment>');

        foreach ($plan['configured'] as $topic) {
            $id = $plan['remote'][$topic] ?? null;
            $this->line($id !== null ? "  ✓ {$topic} — {$id}" : "  ✗ {$topic} — geen abonnement bij Exact");
        }

        $this->reportDrift($plan);

        return $plan['missing'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{remote: array<string, string>, stored: array<string, string>, orphans: array<string, string>, stale: list<string>, ...}  $plan
     */
    private function reportDrift(array $plan): void
    {
        if ($plan['orphans'] !== []) {
            $this->newLine();
            $this->line('<comment>Bij Exact, maar niet door ons geconfigureerd</comment>');

            foreach ($plan['orphans'] as $topic => $id) {
                $this->warn("  ⚠ {$topic} — {$id}");
            }
        }

        if ($plan['stale'] !== []) {
            $this->newLine();
            $this->line('<comment>Verouderde metadata — wij kennen een ID dat Exact niet meer heeft</comment>');

            foreach ($plan['stale'] as $topic) {
                $this->warn("  ⚠ {$topic} — {$plan['stored'][$topic]}");
            }
        }
    }

    private function register(ExactWebhookSubscriptionManager $manager, Connection $connection): int
    {
        $plan = $manager->plan($connection);

        if ($plan['missing'] === []) {
            $this->info('Niets te doen — elk geconfigureerd topic heeft al een abonnement.');
            $this->reportDrift($plan);

            if ($this->option('force')) {
                $manager->register($connection, $plan);
            }

            return self::SUCCESS;
        }

        $this->line('<comment>Zou aanmaken</comment>');

        foreach ($plan['missing'] as $topic) {
            $this->line("  + {$topic} → {$plan['callback_url']}");
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('DRY-RUN — niks geregistreerd. Draai met --force om uit te voeren.');

            return self::SUCCESS;
        }

        $manager->register($connection, $plan);

        $this->newLine();
        $this->info('Klaar — geregistreerd:');

        foreach ($connection->refresh()->metadata['exact_webhooks'] ?? [] as $topic => $id) {
            $this->line("  {$topic} — {$id}");
        }

        return self::SUCCESS;
    }

    private function unregister(ExactWebhookSubscriptionManager $manager, Connection $connection): int
    {
        $plan = $manager->plan($connection);
        $targets = $plan['stored'] !== [] ? $plan['stored'] : $plan['remote'];

        if ($targets === []) {
            $this->info('Niets te doen — geen abonnementen gevonden.');

            return self::SUCCESS;
        }

        $this->line('<comment>Zou opzeggen</comment>');

        foreach ($targets as $topic => $id) {
            $this->line("  - {$topic} — {$id}");
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('DRY-RUN — niks opgezegd. Draai met --force om uit te voeren.');

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! $this->confirm("Abonnementen van {$connection->public_id} echt opzeggen?", false)) {
            $this->warn('Geannuleerd — niks opgezegd.');

            return self::FAILURE;
        }

        $manager->unsubscribe($connection);

        $this->newLine();
        $this->info('Klaar — opgezegd.');

        return self::SUCCESS;
    }

    private function probe(Connection $connection): int
    {
        $topics = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $this->option('topics')),
        )));

        if ($topics === []) {
            $this->error('Geef de te testen topics op met --topics=Accounts,SalesEntries.');

            return self::FAILURE;
        }

        $this->line('<comment>Zou proberen</comment>');

        foreach ($topics as $topic) {
            $this->line("  ? {$topic}");
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('DRY-RUN — niks geprobeerd. Draai met --force om uit te voeren.');

            return self::SUCCESS;
        }

        $connector = $this->connector($connection);
        $callbackUrl = app(ExactWebhookSubscriptionManager::class)->callbackUrl();

        $this->newLine();
        $this->line('<comment>Uitkomst</comment>');

        foreach ($topics as $topic) {
            $this->line($this->probeTopic($connector, $topic, $callbackUrl));
        }

        return self::SUCCESS;
    }

    private function probeTopic(ExactConnector $connector, string $topic, string $callbackUrl): string
    {
        try {
            $response = $connector->send(new CreateWebhookSubscription(topic: $topic, callbackUrl: $callbackUrl));
        } catch (Throwable $e) {
            return "  ✗ {$topic} — {$e->getMessage()}";
        }

        $id = Envelope::firstId($response->json());

        if ($id === null) {
            return "  ✓ {$topic} — geaccepteerd, geen ID teruggekregen";
        }

        try {
            $connector->send(new DeleteWebhookSubscription($id));
        } catch (Throwable $e) {
            return "  ✓ {$topic} — geaccepteerd ({$id}), maar OPRUIMEN MISLUKT: {$e->getMessage()}";
        }

        return "  ✓ {$topic} — geaccepteerd en weer opgeruimd";
    }

    private function unknownAction(): int
    {
        $this->error("Onbekende actie '{$this->argument('action')}'. Kies status, register, unregister of probe.");

        return self::FAILURE;
    }

    private function connector(Connection $connection): ExactConnector
    {
        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        app()->forgetInstance(Exact::class);

        return app(Exact::class)->connector((string) $connection->administratie_id);
    }

    private function resolveConnection(string $identifier): ?Connection
    {
        if (str_starts_with($identifier, Connection::PUBLIC_ID_PREFIX)) {
            return Connection::query()->where('public_id', $identifier)->first();
        }

        return Connection::find($identifier);
    }
}
