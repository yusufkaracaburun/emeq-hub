<?php

namespace Tests\Feature\Models;

use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\WebhookClient\Models\WebhookCall;
use Tests\TestCase;

class WebhookCallAuditColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_calls_table_has_audit_columns_after_migration(): void
    {
        $this->assertTrue(Schema::hasColumn('webhook_calls', 'direction'));
        $this->assertTrue(Schema::hasColumn('webhook_calls', 'provider'));
        $this->assertTrue(Schema::hasColumn('webhook_calls', 'consumer_id'));
        $this->assertTrue(Schema::hasColumn('webhook_calls', 'status'));
    }

    public function test_full_audit_row_persists_with_all_new_columns(): void
    {
        $consumer = Consumer::factory()->create();

        DB::table('webhook_calls')->insert([
            'name' => 'test',
            'url' => 'https://example.test/hook',
            'headers' => json_encode([]),
            'payload' => json_encode(['k' => 'v']),
            'direction' => 'incoming',
            'provider' => 'mollie',
            'consumer_id' => $consumer->id,
            'status' => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('webhook_calls')->where('provider', 'mollie')->first();

        $this->assertNotNull($row);
        $this->assertSame('incoming', $row->direction);
        $this->assertSame('mollie', $row->provider);
        $this->assertSame($consumer->id, $row->consumer_id);
        $this->assertSame('processed', $row->status);
    }

    public function test_legacy_spatie_shape_still_persists_without_new_columns(): void
    {
        WebhookCall::create([
            'name' => 'legacy',
            'url' => 'https://example.test',
            'headers' => [],
            'payload' => [],
        ]);

        $row = DB::table('webhook_calls')->where('name', 'legacy')->first();

        $this->assertNotNull($row);
        $this->assertSame('incoming', $row->direction);
        $this->assertNull($row->provider);
        $this->assertNull($row->consumer_id);
        $this->assertSame('processed', $row->status);
    }
}
