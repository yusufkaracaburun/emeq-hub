<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Webhooks;

use App\Enums\Provider;
use App\Integrations\Webhooks\CanonicalAction;
use App\Integrations\Webhooks\CanonicalEvent;
use App\Integrations\Webhooks\ConsumerWebhookEnvelope;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConsumerWebhookEnvelopeTest extends TestCase
{
    public function test_entity_id_and_action_are_absent_when_not_given(): void
    {
        $envelope = ConsumerWebhookEnvelope::make(
            CanonicalEvent::BANK_STATEMENT_CHANGED,
            Provider::Exact,
            'school1',
            ['iets' => 'onbekends'],
        );

        $this->assertArrayNotHasKey('entity_id', $envelope);
        $this->assertArrayNotHasKey('action', $envelope);
    }

    public function test_entity_id_and_action_are_carried_when_given(): void
    {
        $envelope = ConsumerWebhookEnvelope::make(
            CanonicalEvent::SALES_INVOICE_CHANGED,
            Provider::Exact,
            'school1',
            [],
            entityId: 'guid-123',
            action: CanonicalAction::UPDATED,
        );

        $this->assertSame('guid-123', $envelope['entity_id']);
        $this->assertSame(CanonicalAction::UPDATED, $envelope['action']);
    }

    public function test_hub_authored_mirrors_caused_by_hub_when_true(): void
    {
        $envelope = ConsumerWebhookEnvelope::make(
            CanonicalEvent::SALES_INVOICE_CHANGED,
            Provider::Exact,
            'school1',
            [],
            causedByHub: true,
        );

        $this->assertTrue($envelope['caused_by_hub']);
        $this->assertTrue($envelope['hub_authored']);
    }

    public function test_hub_authored_and_caused_by_hub_are_both_absent_when_false(): void
    {
        $envelope = ConsumerWebhookEnvelope::make(
            CanonicalEvent::SALES_INVOICE_CHANGED,
            Provider::Exact,
            'school1',
            [],
            causedByHub: false,
        );

        $this->assertArrayNotHasKey('caused_by_hub', $envelope);
        $this->assertArrayNotHasKey('hub_authored', $envelope);
    }

    public function test_hub_last_wrote_at_is_absent_without_a_known_write(): void
    {
        $envelope = ConsumerWebhookEnvelope::make(
            CanonicalEvent::SALES_INVOICE_CHANGED,
            Provider::Exact,
            'school1',
            [],
        );

        $this->assertArrayNotHasKey('hub_last_wrote_at', $envelope);
    }

    public function test_hub_last_wrote_at_is_carried_as_iso8601(): void
    {
        $envelope = ConsumerWebhookEnvelope::make(
            CanonicalEvent::SALES_INVOICE_CHANGED,
            Provider::Exact,
            'school1',
            [],
            hubLastWroteAt: Carbon::parse('2026-08-01T10:00:00+00:00'),
        );

        $this->assertSame('2026-08-01T10:00:00+00:00', $envelope['hub_last_wrote_at']);
    }
}
