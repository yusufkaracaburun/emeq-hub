<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demo-aanvragen werden alleen als e-mail verstuurd. Met de log-mailer en
     * LOG_LEVEL=warning verdween zo'n aanvraag spoorloos: geen rij, geen mail,
     * geen logregel. De lead hoort in de database te staan; de melding is
     * daarnaast een gemak, geen schakel.
     */
    public function up(): void
    {
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();
            $table->string('company');
            $table->string('contact_name');
            $table->string('email');
            $table->string('preferred_slot');
            $table->text('message')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('status')->default('new'); // new | handled | declined
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
