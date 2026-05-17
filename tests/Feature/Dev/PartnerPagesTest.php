<?php

declare(strict_types=1);

namespace Tests\Feature\Dev;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Services\PartnerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 08-05 — Dev `/dev/partners`-pagina-uitbreiding (D-06, UI-SPEC §S3).
 *
 * Task 1-scope: PartnerStatus-service + _domeinmodel.blade.php + _status-widget.blade.php.
 * Task 2-scope tests volgen later in dezelfde class (env-gating + blade-views + dev OAuth-init).
 */
class PartnerPagesTest extends TestCase
{
    use RefreshDatabase;

    // ---------- Task 1: PartnerStatus service ----------

    public function test_partner_status_service_returns_empty_collection_on_empty_db(): void
    {
        $result = app(PartnerStatus::class)->forProvider('mollie');

        $this->assertCount(0, $result);
    }

    public function test_partner_status_service_resolves_connected_for_active_mollie_connection(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()->for($account)->forMollie()->active()->create();

        $result = app(PartnerStatus::class)->forProvider('mollie');

        $this->assertCount(1, $result);
        $this->assertSame('connected', $result->first()['status']);
    }

    public function test_partner_status_service_resolves_pending_for_pending_connection(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()->for($account)->pending()->create();

        $result = app(PartnerStatus::class)->forProvider('mollie');

        $this->assertSame('pending', $result->first()['status']);
    }

    public function test_partner_status_service_resolves_revoked_for_revoked_connection(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()->for($account)->forMollie()->active()->create([
            'revoked_at' => now(),
        ]);

        $result = app(PartnerStatus::class)->forProvider('mollie');

        $this->assertSame('revoked', $result->first()['status']);
    }

    public function test_partner_status_service_resolves_none_for_account_without_connection(): void
    {
        $consumer = Consumer::factory()->create();
        Account::factory()->for($consumer)->create();

        $result = app(PartnerStatus::class)->forProvider('mollie');

        $this->assertCount(1, $result);
        $this->assertSame('none', $result->first()['status']);
    }

    public function test_partner_status_service_avoids_n_plus_one_queries(): void
    {
        $consumer = Consumer::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $account = Account::factory()->for($consumer)->create();
            Connection::factory()->for($account)->forMollie()->active()->create();
        }

        DB::enableQueryLog();
        $result = app(PartnerStatus::class)->forProvider('mollie');
        // Force collection-iteration zodat eager-loaded relations geconsumeerd worden.
        $result->each(fn (array $entry) => $entry['status']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            2,
            count($queries),
            'PartnerStatus::forProvider() moet één eager-load doen (max 2 queries: Account + connections).'
        );
        $this->assertCount(5, $result);
    }

    // ---------- Task 1: _domeinmodel.blade.php partial ----------

    public function test_domeinmodel_partial_contains_canonical_copy(): void
    {
        $html = view('partners.partials._domeinmodel')->render();

        $this->assertStringContainsString('Hoe de Hub-tenancy in elkaar zit', $html);
        $this->assertStringContainsString('Consumer', $html);
        $this->assertStringContainsString('Account', $html);
        $this->assertStringContainsString('Connection', $html);
        // Canonical copy excerpts (UI-SPEC §S3 regel 184-186)
        $this->assertStringContainsString('Naschool, Planny, externe app', $html);
        $this->assertStringContainsString('school A, vereniging C', $html);
    }

    // ---------- Task 1: _status-widget.blade.php partial ----------

    public function test_status_widget_renders_connected_state_with_emerald_color(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['display_name' => 'Demo School']);
        $connection = Connection::factory()->for($account)->forMollie()->active()->create();

        $accountStatus = collect([[
            'account' => $account,
            'connection' => $connection,
            'status' => 'connected',
        ]]);

        $html = view('partners.partials._status-widget', [
            'provider' => 'mollie',
            'accountStatus' => $accountStatus,
        ])->render();

        $this->assertStringContainsString('check-circle', $html);
        $this->assertStringContainsString('gekoppeld', $html);
        $this->assertStringContainsString('text-emerald-600', $html);
        $this->assertStringContainsString('Demo School', $html);
    }

    public function test_status_widget_renders_pending_state_with_amber_color(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->for($account)->pending()->create();

        $accountStatus = collect([[
            'account' => $account,
            'connection' => $connection,
            'status' => 'pending',
        ]]);

        $html = view('partners.partials._status-widget', [
            'provider' => 'mollie',
            'accountStatus' => $accountStatus,
        ])->render();

        $this->assertStringContainsString('clock', $html);
        $this->assertStringContainsString('pending', $html);
        $this->assertStringContainsString('text-amber-600', $html);
    }

    public function test_status_widget_renders_empty_state_when_no_accounts(): void
    {
        $html = view('partners.partials._status-widget', [
            'provider' => 'mollie',
            'accountStatus' => collect(),
        ])->render();

        $this->assertStringContainsString('Geen demo-Accounts', $html);
        $this->assertStringContainsString('php artisan db:seed', $html);
    }
}
