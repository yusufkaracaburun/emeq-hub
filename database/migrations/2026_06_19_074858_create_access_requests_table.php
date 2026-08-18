<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company');
            $table->string('contact_name');
            $table->string('email');
            $table->string('app_url')->nullable();
            $table->json('providers');
            $table->text('message')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('status')->default('new');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
