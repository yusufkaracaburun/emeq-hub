<?php

declare(strict_types=1);

namespace App\Integrations\Exact\PassThrough;

use App\Models\Connection;
use Illuminate\Contracts\Cache\Repository as Cache;

final class ExactErrorBudget
{
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
