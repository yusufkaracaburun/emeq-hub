<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound partner→Hub webhook-audit (provider-agnostisch). Eigen concern, los van
 * `pass_through_calls` (= Consumer→Hub→Partner pass-through + accounting).
 *
 * **Metadata-only — géén payload/headers** (AVG: de Hub is processor, niet owner
 * van de partner-/eindgebruiker-data). Genoeg getypte velden voor incident-triage
 * (provider/topic/action/outcome/status/fanout), niet de inhoud zelf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->index();
            // Idempotency-sleutel (hash/ref, géén PII). Nullable → duplicate-rijen
            // dragen NULL zodat de unique-index ze niet blokkeert (PG/SQLite).
            $table->string('event_id')->nullable();
            $table->string('topic')->nullable();
            $table->string('action')->nullable();
            $table->foreignId('connection_id')->nullable()->constrained('connections')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('consumer_id')->nullable()->constrained('consumers')->cascadeOnDelete();
            $table->smallInteger('status');
            // processed | duplicate | unknown_tenant | malformed | invalid_signature | misconfigured
            $table->string('outcome')->index();
            // dispatched | skipped_no_callback | not_applicable
            $table->string('fanout_status')->nullable();
            $table->string('request_fingerprint', 12)->nullable();
            // Correlatie-id, zelfde vorm als op pass_through_calls.
            $table->string('request_id', 64)->nullable()->index();
            $table->timestamp('received_at')->useCurrent();

            $table->index(['provider', 'received_at']);
            $table->index(['consumer_id', 'received_at']);
            // Idempotency per provider; meerdere NULLs toegestaan in PG/SQLite.
            $table->unique(['provider', 'event_id'], 'inbound_webhook_events_provider_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_webhook_events');
    }
};
