<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\Provider;
use App\Integrations\Webhooks\CanonicalEvent;
use App\Integrations\Webhooks\CanonicalEventRegistry;
use App\Integrations\Webhooks\HubOriginRegistry;
use App\Jobs\Webhooks\ForwardWebhookToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\WebhookServer\CallWebhookJob;
use Tests\TestCase;

/**
 * De Hub stuurde partner-payloads ongewijzigd door: een consumer moest Exact's
 * `Topic`/`Action` leren én straks Moneybird's vorm ernaast. Terwijl het
 * async-boekpad (SyncAccountingDocumentJob) al wél canoniek publiceerde — twee
 * eventmodellen in één platform.
 */
class CanonicalEventEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: Provider, 1: array<string, mixed>, 2: string}>
     */
    public static function payloads(): array
    {
        return [
            'exact bank' => [Provider::Exact, ['Topic' => 'BankEntries', 'Action' => 'Update'], CanonicalEvent::BANK_STATEMENT_CHANGED],
            'exact cash' => [Provider::Exact, ['Topic' => 'CashEntries', 'Action' => 'Create'], CanonicalEvent::CASH_STATEMENT_CHANGED],
            'exact genest' => [Provider::Exact, ['Content' => ['Topic' => 'BankEntries']], CanonicalEvent::BANK_STATEMENT_CHANGED],
            'exact onbekend topic' => [Provider::Exact, ['Topic' => 'SalesInvoices'], CanonicalEvent::UNMAPPED],
            'snelstart relatie' => [Provider::Snelstart, ['type' => 'Relatie.Created'], CanonicalEvent::RELATION_CHANGED],
            'snelstart verkoopfactuur' => [Provider::Snelstart, ['type' => 'Verkoopfactuur.Updated'], CanonicalEvent::SALES_INVOICE_CHANGED],
            'snelstart onbekend' => [Provider::Snelstart, ['type' => 'Artikel.Created'], CanonicalEvent::UNMAPPED],
            'mollie subscription' => [Provider::Mollie, ['id' => 'sub_abc'], CanonicalEvent::SUBSCRIPTION_CHANGED],
            'mollie payment' => [Provider::Mollie, ['id' => 'tr_abc'], CanonicalEvent::PAYMENT_CHANGED],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('payloads')]
    public function test_partner_payloads_map_to_canonical_events(Provider $provider, array $payload, string $expected): void
    {
        $this->assertSame($expected, app(CanonicalEventRegistry::class)->eventFor($provider, $payload));
    }

    /**
     * Een provider zonder resolver mag de webhook niet laten sneuvelen — de
     * consumer krijgt de envelope, met een naam die zegt dat we het niet weten.
     */
    public function test_an_unresolvable_payload_still_produces_an_envelope(): void
    {
        $this->assertSame(
            CanonicalEvent::UNMAPPED,
            app(CanonicalEventRegistry::class)->eventFor(Provider::Exact, ['iets' => 'onbekends']),
        );
    }

    public function test_the_envelope_carries_the_consumers_own_account_id_and_the_raw_payload(): void
    {
        Bus::fake([CallWebhookJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school1']);
        $connection = Connection::factory()->forExact()->active()->for($account)->create();

        $payload = ['Topic' => 'BankEntries', 'Action' => 'Update', 'Content' => ['Division' => 4471372]];

        (new ForwardWebhookToConsumerJob(Provider::Exact, $connection, $payload, 'evt-1'))
            ->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class));

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job) use ($payload): bool {
            return $job->payload['event'] === CanonicalEvent::BANK_STATEMENT_CHANGED
                && $job->payload['provider'] === 'exact'
                // Het id dat de consumer zelf aanleverde, niet onze primary key.
                && $job->payload['account_id'] === 'school1'
                && is_string($job->payload['occurred_at'])
                && $job->payload['data'] === $payload;
        });
    }

    /**
     * De handleiding draagt consumers op om op `X-Emeq-Event-Id` te deduperen.
     * Mollie levert zelf geen event-id en vuurt meerdere webhooks voor dezelfde
     * resource — juist daar mag de header niet ontbreken.
     */
    public function test_de_dedupe_header_gaat_altijd_mee_ook_zonder_partner_event_id(): void
    {
        Bus::fake([CallWebhookJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school1']);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        (new ForwardWebhookToConsumerJob(Provider::Mollie, $connection, ['id' => 'tr_abc'], null))
            ->handle(app(CanonicalEventRegistry::class), app(HubOriginRegistry::class));

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job): bool {
            return isset($job->headers['X-Emeq-Event-Id'])
                && $job->headers['X-Emeq-Event-Id'] !== '';
        });
    }
}
