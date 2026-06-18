<?php

declare(strict_types=1);

namespace App\Support\Exact;

use App\Models\Connection;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Per-(connection, endpoint) error-budget-circuit-breaker voor de Exact-pass-through.
 *
 * Exact blokkeert een API-key tijdelijk bij >10 fouten (400/401/403/404) per
 * key/user/company/endpoint/uur. Die key is Hub-breed gedeeld, dus één stoeiende
 * consumer kan álle connections meeslepen. Deze breaker telt de tellende statussen
 * per (connection, endpoint) in een uur-venster en trip ruim vóór Exact's limiet,
 * zodat de Hub een eigen 429 teruggeeft i.p.v. door te tikken naar Exact.
 *
 * Teller leeft in de cache (Redis): `add(0, ttl)` zaait het venster met TTL, daarna
 * houdt `increment` (Redis INCR) de bestaande TTL aan — een rollend uur-venster vanaf
 * de eerste fout. Per-connection key isoleert de boosdoener van de rest.
 */
final class ExactErrorBudget
{
    /** Statussen die meetellen tegen Exact's error-limiet. 429/5xx tellen niet. */
    private const COUNTING_STATUSES = [400, 401, 403, 404];

    public function __construct(private readonly Cache $cache) {}

    public function isOpen(Connection $connection, string $endpoint): bool
    {
        if (! $this->config('enabled')) {
            return false;
        }

        return (int) $this->cache->get($this->key($connection, $endpoint), 0) >= (int) $this->config('threshold');
    }

    public function record(Connection $connection, string $endpoint, int $status): void
    {
        if (! $this->config('enabled') || ! in_array($status, self::COUNTING_STATUSES, true)) {
            return;
        }

        $key = $this->key($connection, $endpoint);

        $this->cache->add($key, 0, (int) $this->config('window'));
        $this->cache->increment($key);
    }

    public function retryAfter(): int
    {
        return (int) $this->config('window');
    }

    private function key(Connection $connection, string $endpoint): string
    {
        return 'exact-error-budget:'.$connection->getKey().':'.md5($endpoint);
    }

    private function config(string $key): mixed
    {
        return config("hub-providers.exact.error_budget.{$key}");
    }
}
