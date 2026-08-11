<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pass_through_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('direction', 10)->default('outbound');
            // FKs nullable: inbound webhooks met onbekende administratieId mogen tóch een audit-rij krijgen.
            $table->foreignId('consumer_id')->nullable()->constrained('consumers')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('connections')->nullOnDelete();
            $table->string('provider');
            $table->string('token_type', 16)->nullable()->index();
            $table->string('method', 10);
            $table->text('path');
            $table->string('query_keys')->nullable();
            $table->smallInteger('status');
            $table->integer('duration_ms');
            $table->string('request_fingerprint', 12)->nullable();
            $table->string('partner_token_fingerprint', 16)->nullable();
            $table->string('event_id')->nullable();
            // Correlatie-id: één consumer-request van pass-through tot inkomende
            // webhook met één query terug te vinden. Breedte 64 dekt een ULID (26),
            // een UUID (36) en de langst geaccepteerde inbound waarde
            // (zie App\Http\Middleware\AssignRequestId).
            $table->string('request_id', 64)->nullable()->index();
            $table->integer('response_size_bytes')->nullable();
            $table->string('upstream_error')->nullable();
            // Errors-only response-body (status >= 400), capped + redacted. Geen
            // succesvolle reads → minimaliseert klant-PII at rest in de audit-tabel.
            $table->text('response_body')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['consumer_id', 'created_at']);
            $table->index(['account_id', 'created_at']);
            $table->index(['direction', 'created_at']);
            // Postgres + SQLite staan meerdere NULLs toe in unique index → outbound (event_id=NULL) blokkeert niet.
            $table->unique(['provider', 'event_id'], 'pass_through_calls_provider_event_unique');
        });

        DB::statement(
            'CREATE INDEX pass_through_calls_status_failures '
            .'ON pass_through_calls (status) WHERE status >= 500'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_through_calls');
    }
};
