<?php

declare(strict_types=1);

namespace App\Integrations\Exact;

use App\Models\Connection;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\ExactConnector;
use Emeq\ExactApi\Http\Request\Delete\DeleteWebhookSubscription;
use Emeq\ExactApi\Http\Request\Read\ListWebhookSubscriptions;
use Emeq\ExactApi\Http\Request\Write\CreateWebhookSubscription;
use Emeq\ExactApi\OData\Envelope;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Beheert de Exact-webhook-subscriptions van één Connection (register bij connect,
 * unsubscribe bij revoke). Bindt de SDK per-connection zoals ExactAccountingTarget,
 * zodat de reactieve token-refresh tegen déze Connection loopt.
 *
 * De Exact-wire (endpoints, OData-envelope) leeft in de SDK; deze service orkestreert
 * alleen: welke topics, welke CallbackURL, en waar de subscription-IDs landen
 * (`connection.metadata['exact_webhooks']`, topic → subscription-ID).
 */
final class ExactWebhookSubscriptionManager
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * @return array{callback_url: string, configured: list<string>, remote: array<string, string>, stored: array<string, string>, missing: list<string>, orphans: array<string, string>, stale: list<string>}
     */
    public function plan(Connection $connection): array
    {
        $division = (string) $connection->administratie_id;
        $topics = $this->topics();
        $stored = $this->storedSubscriptions($connection);

        $remote = ($division === '')
            ? []
            : $this->existingSubscriptions($this->connector($connection, $division));

        return [
            'callback_url' => $this->callbackUrl(),
            'configured' => $topics,
            'remote' => $remote,
            'stored' => $stored,
            'missing' => array_values(array_filter(
                $topics,
                static fn (string $topic): bool => ! isset($remote[$topic]),
            )),
            'orphans' => array_diff_key($remote, array_flip($topics)),
            'stale' => array_values(array_filter(
                array_keys($stored),
                static fn (string $topic): bool => ! isset($remote[$topic]),
            )),
        ];
    }

    /**
     * @param  array{callback_url: string, configured: list<string>, remote: array<string, string>, stored: array<string, string>, missing: list<string>, orphans: array<string, string>, stale: list<string>}|null  $plan
     */
    public function register(Connection $connection, ?array $plan = null): void
    {
        $division = (string) $connection->administratie_id;

        if ($division === '' || $this->topics() === []) {
            return;
        }

        $plan ??= $this->plan($connection);

        $connector = $this->connector($connection, $division);
        $stored = $plan['stored'];

        foreach ($plan['configured'] as $topic) {
            if (isset($plan['remote'][$topic])) {
                $stored[$topic] = $plan['remote'][$topic];

                continue;
            }

            $id = $this->createSubscription($connector, $topic, $plan['callback_url']);

            if ($id !== null) {
                $stored[$topic] = $id;
            }
        }

        $this->persist($connection, $stored);
    }

    /**
     * @param  list<string>  $topics
     * @return array{added: list<string>, removed: list<string>}
     */
    public function apply(Connection $connection, array $topics, ?array $plan = null): array
    {
        $division = (string) $connection->administratie_id;

        if ($division === '') {
            return ['added' => [], 'removed' => []];
        }

        $plan ??= $this->plan($connection);

        $connector = $this->connector($connection, $division);
        $stored = $plan['stored'];
        $added = [];
        $removed = [];

        foreach ($topics as $topic) {
            if (isset($plan['remote'][$topic])) {
                $stored[$topic] = $plan['remote'][$topic];

                continue;
            }

            $id = $this->createSubscription($connector, $topic, $plan['callback_url']);

            if ($id !== null) {
                $stored[$topic] = $id;
                $added[] = $topic;
            }
        }

        foreach ($plan['remote'] as $topic => $id) {
            if (in_array($topic, $topics, true)) {
                continue;
            }

            try {
                $connector->send(new DeleteWebhookSubscription((string) $id));
                $removed[] = $topic;
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            unset($stored[$topic]);
        }

        $this->persist($connection, array_intersect_key($stored, array_flip($topics)));

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Best-effort: na een OAuth-revoke kan de delete falen (token weg) — Exact ruimt
     * verweesde subscriptions 's nachts zelf op, dus we falen niet hard.
     */
    public function unsubscribe(Connection $connection): void
    {
        $division = (string) $connection->administratie_id;
        $stored = $this->storedSubscriptions($connection);

        if ($division === '' || $stored === []) {
            $this->persist($connection, []);

            return;
        }

        $connector = $this->connector($connection, $division);

        foreach ($stored as $id) {
            try {
                $connector->send(new DeleteWebhookSubscription((string) $id));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->persist($connection, []);
    }

    private function connector(Connection $connection, string $division): ExactConnector
    {
        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        app()->forgetInstance(Exact::class);

        /** @var Exact $exact */
        $exact = app(Exact::class);

        return $exact->connector($division);
    }

    private function createSubscription(ExactConnector $connector, string $topic, string $callbackUrl): ?string
    {
        // De connector heeft retries aan → een niet-retrybare 500 wordt door Saloon
        // gegooid i.p.v. teruggegeven. Beide paden afvangen: een duplicate ("Data
        // already exists" — een andere user van dezelfde klant abonneerde al) is
        // idempotent, geen fout. We krijgen dan geen ID; een volgende register()
        // pakt 'm via de list-stap.
        try {
            // IsInstant NIET meesturen: Exact accepteert die parameter alléén voor
            // het topic 'GoodsDeliveries' (anders HTTP 500). Default = batched.
            $response = $connector->send(new CreateWebhookSubscription(
                topic: $topic,
                callbackUrl: $callbackUrl,
            ));
        } catch (Throwable $e) {
            if ($this->isDuplicate($e->getMessage())) {
                return null;
            }

            throw $e;
        }

        if ($response->failed()) {
            if ($this->isDuplicate((string) $response->body())) {
                return null;
            }

            $response->throw();
        }

        return Envelope::firstId($response->json());
    }

    private function isDuplicate(string $message): bool
    {
        return str_contains($message, 'Data already exists');
    }

    /**
     * @return array<string, string> topic → subscription-ID
     */
    private function existingSubscriptions(ExactConnector $connector): array
    {
        $response = $connector->send(new ListWebhookSubscriptions);

        if ($response->failed()) {
            return [];
        }

        $map = [];

        foreach (Envelope::results($response->json()) as $subscription) {
            if (isset($subscription['Topic'], $subscription['ID'])) {
                $map[(string) $subscription['Topic']] = (string) $subscription['ID'];
            }
        }

        return $map;
    }

    /**
     * @return array<string, string> topic → subscription-ID
     */
    private function storedSubscriptions(Connection $connection): array
    {
        $subscriptions = ($connection->metadata ?? [])['exact_webhooks'] ?? [];

        return is_array($subscriptions) ? $subscriptions : [];
    }

    /**
     * @param  array<string, string>  $subscriptions
     */
    private function persist(Connection $connection, array $subscriptions): void
    {
        $metadata = $connection->metadata ?? [];

        if ($subscriptions === []) {
            unset($metadata['exact_webhooks']);
        } else {
            $metadata['exact_webhooks'] = $subscriptions;
        }

        $connection->update(['metadata' => $metadata]);
    }

    /**
     * @return list<string>
     */
    private function topics(): array
    {
        return array_values(array_filter(
            (array) $this->config->get('services.exact.webhook_topics', []),
            static fn (mixed $topic): bool => is_string($topic) && $topic !== '',
        ));
    }

    /**
     * Exact eist dat de CallbackURL hetzelfde scheme+domein heeft als de
     * geregistreerde RedirectURI (en HTTPS). Daarom leiden we de origin af van
     * `services.exact.redirect_uri` — niet van APP_URL, dat in dev de lokale
     * (niet-publieke, http) host is. Zo kan de CallbackURL nooit van de
     * Exact-constraint afdrijven. Geen redirect_uri (standalone/tests) → route().
     */
    public function callbackUrl(): string
    {
        $redirectUri = (string) $this->config->get('services.exact.redirect_uri');
        $parts = $redirectUri !== '' ? parse_url($redirectUri) : false;

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return route('webhooks.exact');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port.'/webhooks/exact';
    }
}
