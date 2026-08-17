<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Integrations\Exact\Webhooks\ExactEventResolver;
use Tests\TestCase;

class ExactTopicCoverageTest extends TestCase
{
    public function test_every_configured_topic_has_a_canonical_event(): void
    {
        $resolver = new ExactEventResolver;
        $topics = (array) config('services.exact.webhook_topics', []);

        $this->assertNotEmpty($topics, 'Zonder geconfigureerde topics bewaakt deze test niets.');

        foreach ($topics as $topic) {
            $this->assertNotNull(
                $resolver->resolve(['Content' => ['Topic' => $topic]]),
                "Topic '{$topic}' staat in services.exact.webhook_topics maar ExactEventResolver kent er geen canonieke naam voor. Voeg een match-arm én een rij in docs/consumer-integration-guide.md toe, of haal het topic weg.",
            );
        }
    }
}
