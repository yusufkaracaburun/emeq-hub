<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('connections')->restrictOnDelete();
            $table->string('mollie_customer_id')->index();
            $table->string('mollie_subscription_id')->nullable();
            $table->string('mollie_mandate_id')->nullable();
            $table->string('status')->index();
            $table->char('amount_currency', 3)->default('EUR');
            $table->string('amount_value');
            $table->string('interval');
            $table->string('description');
            $table->unsignedInteger('times')->nullable();
            $table->date('start_date')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('last_payment_status')->nullable();
            $table->timestamp('last_webhook_event_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'status']);
            $table->index(['connection_id', 'status']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX account_subscriptions_connection_mollie_sub_unique '
            .'ON account_subscriptions (connection_id, mollie_subscription_id) '
            .'WHERE mollie_subscription_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('account_subscriptions');
    }
};
