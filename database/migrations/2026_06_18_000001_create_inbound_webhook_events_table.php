<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->index();
            $table->string('event_id')->nullable();
            $table->string('topic')->nullable();
            $table->string('action')->nullable();
            $table->foreignId('connection_id')->nullable()->constrained('connections')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('consumer_id')->nullable()->constrained('consumers')->cascadeOnDelete();
            $table->smallInteger('status');
            $table->string('outcome')->index();
            $table->string('fanout_status')->nullable();
            $table->string('request_fingerprint', 12)->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->timestamp('received_at')->useCurrent();

            $table->index(['provider', 'received_at']);
            $table->index(['consumer_id', 'received_at']);
            $table->unique(['provider', 'event_id'], 'inbound_webhook_events_provider_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_webhook_events');
    }
};
