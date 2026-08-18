<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Exact\Webhooks;

use App\Integrations\Exact\Webhooks\ExactEventResolver;
use Tests\TestCase;

class ExactTopicCoverageTest extends TestCase
{
    public function test_every_configured_topic_can_be_read_back_by_the_consumer(): void
    {
        $resources = [
            'Accounts' => 'crm/Accounts',
            'BankEntries' => 'financialtransaction/BankEntries',
            'CashEntries' => 'financialtransaction/CashEntries',
            'Documents' => 'documents/Documents',
            'GeneralJournalEntries' => 'generaljournalentry/GeneralJournalEntries',
            'GLAccounts' => 'financial/GLAccounts',
            'PurchaseEntries' => 'purchaseentry/PurchaseEntries',
            'SalesEntries' => 'salesentry/SalesEntries',
        ];

        $allowed = (array) config('hub-providers.exact.allowed_paths', []);

        foreach ((array) config('services.exact.webhook_topics', []) as $topic) {
            $resource = $resources[$topic] ?? null;

            $this->assertNotNull($resource, "Topic '{$topic}' heeft geen bekende Exact-resource in deze test. Vul 'm aan, of haal het topic weg.");
            $this->assertContains($resource, $allowed, "Topic '{$topic}' notificeert over '{$resource}', maar dat pad staat niet in de pass-through-whitelist. De consumer krijgt dan een seintje over iets dat hij niet kan ophalen.");
        }
    }

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
