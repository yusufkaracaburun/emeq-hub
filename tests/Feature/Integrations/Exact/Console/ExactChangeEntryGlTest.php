<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Exact\Console;

use App\Models\Account;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use App\Models\ProviderEntityLink;
use Emeq\ExactApi\Http\Request\Read\GetPurchaseEntries;
use Emeq\ExactApi\Http\Request\Write\UpdatePurchaseEntryLine;
use Emeq\ExactApi\Http\Request\Write\UpdateSalesEntryLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class ExactChangeEntryGlTest extends TestCase
{
    use RefreshDatabase;

    private const ENTRY_ID = '11111111-1111-1111-1111-111111111111';

    private const LINE_1 = 'aaaaaaaa-0000-0000-0000-000000000001';

    private const LINE_2 = 'aaaaaaaa-0000-0000-0000-000000000002';

    private const GL_WRONG = 'bbbbbbbb-0000-0000-0000-000000004000';

    private const GL_RIGHT = 'bbbbbbbb-0000-0000-0000-000000004600';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.client_secret' => 'app_test_secret',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
            'services.exact.api_base_url' => 'https://start.exactonline.nl',
        ]);
    }

    public function test_dry_run_shows_planned_changes_and_sends_no_put(): void
    {
        $mock = MockClient::global([$this->entryResponse()]);
        $connection = $this->exactConnection();
        $this->link($connection, 'EXP-1');

        $this->artisan('exact:change-entry-gl', [
            'connection' => $connection->id,
            'csv' => $this->csv("external_id,gl_code\nEXP-1,4600\n"),
        ])
            ->expectsOutputToContain('26700012')
            ->expectsOutputToContain('wijzigen')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $mock->assertNotSent(UpdatePurchaseEntryLine::class);
        $mock->assertNotSent(UpdateSalesEntryLine::class);
        $mock->assertSent(fn ($request): bool => $request instanceof GetPurchaseEntries
            && in_array('PurchaseEntryLines', explode(',', (string) $request->query()->get('$select')), true));
    }

    private function exactConnection(): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->for($account)->create();

        foreach (['4000' => self::GL_WRONG, '4600' => self::GL_RIGHT] as $code => $guid) {
            ConnectionAccountingRef::query()->create([
                'connection_id' => $connection->id,
                'kind' => ConnectionAccountingRef::KIND_GL,
                'code' => $code,
                'native_id' => $guid,
                'label' => "Grootboek {$code}",
            ]);
        }

        return $connection;
    }

    private function link(Connection $connection, string $externalId, string $subtype = 'expense', string $entryId = self::ENTRY_ID): ProviderEntityLink
    {
        return ProviderEntityLink::factory()->create([
            'connection_id' => $connection->id,
            'external_id' => $externalId,
            'provider_entity_id' => $entryId,
            'entity_subtype' => $subtype,
        ]);
    }

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gl').'.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    /** @param  list<array{0: string, 1: int, 2: string, 3: string}>  $lines  [id, lineNumber, glGuid, glCode] */
    private function entryResponse(string $collection = 'PurchaseEntryLines', ?array $lines = null, string $entryId = self::ENTRY_ID): MockResponse
    {
        $lines ??= [
            [self::LINE_1, 1, self::GL_WRONG, '4000'],
            [self::LINE_2, 2, self::GL_WRONG, '4000'],
        ];

        return MockResponse::make(['d' => ['results' => [[
            'EntryID' => $entryId,
            'EntryNumber' => 26700012,
            $collection => ['results' => array_map(static fn (array $line): array => [
                'ID' => $line[0],
                'LineNumber' => $line[1],
                'GLAccount' => $line[2],
                'GLAccountCode' => $line[3],
                'AmountFC' => 121.0,
                'Description' => 'Brandstof',
            ], $lines)],
        ]]]], 200);
    }

    public function test_apply_puts_each_line_with_line_id_and_target_gl_guid_and_logs_metadata(): void
    {
        Log::spy();
        $mock = MockClient::global([
            $this->entryResponse(),
            MockResponse::make([], 204),
            MockResponse::make([], 204),
        ]);
        $connection = $this->exactConnection();
        $this->link($connection, 'EXP-1');

        $this->artisan('exact:change-entry-gl', [
            'connection' => $connection->id,
            'csv' => $this->csv("external_id,gl_code\nEXP-1,4600\n"),
            '--apply' => true,
        ])
            ->expectsConfirmation('2 regel(s) in Exact omboeken?', 'yes')
            ->expectsOutputToContain('2 gewijzigd, 0 overgeslagen, 0 fout')
            ->assertSuccessful();

        foreach ([self::LINE_1, self::LINE_2] as $lineId) {
            $mock->assertSent(fn ($request): bool => $request instanceof UpdatePurchaseEntryLine
                && $request->resolveEndpoint() === "/purchaseentry/PurchaseEntryLines(guid'{$lineId}')"
                && $request->body()->all() === ['GLAccount' => self::GL_RIGHT]);
        }

        Log::shouldHaveReceived('info')->with('exact.entry_line.gl_changed', [
            'connection_id' => $connection->id,
            'external_id' => 'EXP-1',
            'entry_id' => self::ENTRY_ID,
            'line_number' => '1',
            'old_gl' => '4000',
            'new_gl' => '4600',
        ])->once();
    }

    public function test_apply_uses_sales_line_request_for_sales_documents(): void
    {
        $mock = MockClient::global([
            $this->entryResponse('SalesEntryLines', [[self::LINE_1, 1, self::GL_WRONG, '4000']]),
            MockResponse::make([], 204),
        ]);
        $connection = $this->exactConnection();
        $this->link($connection, 'INV-1', 'sales_invoice');

        $this->artisan('exact:change-entry-gl', [
            'connection' => $connection->id,
            'csv' => $this->csv("external_id,gl_code\nINV-1,4600\n"),
            '--apply' => true,
        ])
            ->expectsConfirmation('1 regel(s) in Exact omboeken?', 'yes')
            ->assertSuccessful();

        $mock->assertSent(UpdateSalesEntryLine::class);
        $mock->assertNotSent(UpdatePurchaseEntryLine::class);
    }

    public function test_line_column_limits_the_change_to_that_line(): void
    {
        $mock = MockClient::global([
            $this->entryResponse(),
            MockResponse::make([], 204),
        ]);
        $connection = $this->exactConnection();
        $this->link($connection, 'EXP-1');

        $this->artisan('exact:change-entry-gl', [
            'connection' => $connection->id,
            'csv' => $this->csv("external_id,gl_code,line\nEXP-1,4600,2\n"),
            '--apply' => true,
        ])
            ->expectsConfirmation('1 regel(s) in Exact omboeken?', 'yes')
            ->assertSuccessful();

        $mock->assertSent(fn ($request): bool => $request instanceof UpdatePurchaseEntryLine
            && str_contains($request->resolveEndpoint(), self::LINE_2));
        $mock->assertNotSent(fn ($request): bool => $request instanceof UpdatePurchaseEntryLine
            && str_contains($request->resolveEndpoint(), self::LINE_1));
    }

    public function test_line_already_on_target_gl_is_skipped(): void
    {
        $mock = MockClient::global([
            $this->entryResponse(lines: [
                [self::LINE_1, 1, self::GL_RIGHT, '4600'],
                [self::LINE_2, 2, self::GL_WRONG, '4000'],
            ]),
            MockResponse::make([], 204),
        ]);
        $connection = $this->exactConnection();
        $this->link($connection, 'EXP-1');

        $this->artisan('exact:change-entry-gl', [
            'connection' => $connection->id,
            'csv' => $this->csv("external_id,gl_code\nEXP-1,4600\n"),
            '--apply' => true,
        ])
            ->expectsOutputToContain('al goed')
            ->expectsConfirmation('1 regel(s) in Exact omboeken?', 'yes')
            ->expectsOutputToContain('1 gewijzigd, 1 overgeslagen, 0 fout')
            ->assertSuccessful();

        $mock->assertNotSent(fn ($request): bool => $request instanceof UpdatePurchaseEntryLine
            && str_contains($request->resolveEndpoint(), self::LINE_1));
    }

    public function test_unknown_external_id_and_unknown_gl_code_fail_per_row_without_stopping_the_rest(): void
    {
        $mock = MockClient::global([
            $this->entryResponse(lines: [[self::LINE_1, 1, self::GL_WRONG, '4000']]),
            MockResponse::make([], 204),
        ]);
        $connection = $this->exactConnection();
        $this->link($connection, 'EXP-1');
        $this->link($connection, 'EXP-2', entryId: '22222222-2222-2222-2222-222222222222');

        $this->artisan('exact:change-entry-gl', [
            'connection' => $connection->id,
            'csv' => $this->csv("external_id,gl_code\nNOPE-9,4600\nEXP-2,9999\nEXP-1,4600\n"),
            '--apply' => true,
        ])
            ->expectsOutputToContain('geen boeking met dit external_id')
            ->expectsOutputToContain('grootboek-code 9999 niet in de mirror')
            ->expectsConfirmation('1 regel(s) in Exact omboeken?', 'yes')
            ->expectsOutputToContain('1 gewijzigd, 0 overgeslagen, 2 fout')
            ->assertFailed();

        $mock->assertSentCount(2);
        $mock->assertSent(UpdatePurchaseEntryLine::class);
    }

    public function test_failed_put_is_isolated_and_exits_non_zero(): void
    {
        $mock = MockClient::global([
            $this->entryResponse(),
            MockResponse::make(['error' => ['code' => ['value' => ''], 'message' => ['lang' => 'nl-NL', 'value' => 'Periode is afgesloten']]], 400),
            MockResponse::make([], 204),
        ]);
        $connection = $this->exactConnection();
        $this->link($connection, 'EXP-1');

        $this->artisan('exact:change-entry-gl', [
            'connection' => $connection->id,
            'csv' => $this->csv("external_id,gl_code\nEXP-1,4600\n"),
            '--apply' => true,
        ])
            ->expectsConfirmation('2 regel(s) in Exact omboeken?', 'yes')
            ->expectsOutputToContain('Periode is afgesloten')
            ->expectsOutputToContain('1 gewijzigd, 0 overgeslagen, 1 fout')
            ->assertFailed();

        $mock->assertSentCount(3);
    }

    public function test_declined_confirmation_changes_nothing(): void
    {
        $mock = MockClient::global([$this->entryResponse()]);
        $connection = $this->exactConnection();
        $this->link($connection, 'EXP-1');

        $this->artisan('exact:change-entry-gl', [
            'connection' => $connection->id,
            'csv' => $this->csv("external_id,gl_code\nEXP-1,4600\n"),
            '--apply' => true,
        ])
            ->expectsConfirmation('2 regel(s) in Exact omboeken?', 'no')
            ->assertFailed();

        $mock->assertNotSent(UpdatePurchaseEntryLine::class);
    }

    public function test_non_interactive_apply_does_not_proceed_silently(): void
    {
        $mock = MockClient::global([$this->entryResponse(), MockResponse::make([], 204), MockResponse::make([], 204)]);
        $connection = $this->exactConnection();
        $this->link($connection, 'EXP-1');

        $exitCode = $this->withoutMockingConsoleOutput()->artisan('exact:change-entry-gl', [
            'connection' => $connection->id,
            'csv' => $this->csv("external_id,gl_code\nEXP-1,4600\n"),
            '--apply' => true,
            '--no-interaction' => true,
        ]);

        $this->assertNotSame(0, $exitCode);
        $mock->assertNotSent(UpdatePurchaseEntryLine::class);
    }

    public function test_links_of_another_connection_are_never_used(): void
    {
        $mock = MockClient::global([]);
        $connection = $this->exactConnection();
        $other = $this->exactConnection();
        $this->link($other, 'EXP-1');

        $this->artisan('exact:change-entry-gl', [
            'connection' => $connection->id,
            'csv' => $this->csv("external_id,gl_code\nEXP-1,4600\n"),
            '--apply' => true,
        ])
            ->expectsOutputToContain('geen boeking met dit external_id')
            ->assertFailed();

        $mock->assertNothingSent();
    }
}
