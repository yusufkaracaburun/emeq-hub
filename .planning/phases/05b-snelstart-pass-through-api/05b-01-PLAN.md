---
phase: 05b-snelstart-pass-through-api
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_05_15_000001_create_pass_through_calls_table.php
  - app/Models/PassThroughCall.php
  - database/factories/PassThroughCallFactory.php
  - .docs/decisions/pass-through-calls-table.md
autonomous: true
requirements: [HUB-05]
tags:
  - laravel
  - migrations
  - postgres
  - audit-log
  - phpunit

must_haves:
  truths:
    - "Een audit-rij voor een Snelstart-pass-through-call kan persistent worden opgeslagen zonder raw credentials"
    - "Schema voorziet in failure-monitoring (partial index op status >= 500) en per-Consumer chronologie"
    - "`PassThroughCall`-model is een Eloquent-model dat factories en BelongsTo-relaties exposeert"
  artifacts:
    - path: "database/migrations/2026_05_15_000001_create_pass_through_calls_table.php"
      provides: "Postgres-tabel `pass_through_calls` met alle audit-kolommen + 3 indexen"
      contains: "Schema::create('pass_through_calls'"
    - path: "app/Models/PassThroughCall.php"
      provides: "Eloquent-model `App\\Models\\PassThroughCall` met immutability (timestamps=false, alleen created_at)"
      exports: ["consumer", "account", "connection"]
    - path: "database/factories/PassThroughCallFactory.php"
      provides: "Factory voor `PassThroughCall` om audit-rijen in tests aan te maken"
      contains: "extends Factory"
  key_links:
    - from: "App\\Models\\PassThroughCall"
      to: "App\\Models\\Consumer / Account / Connection"
      via: "BelongsTo-relaties"
      pattern: "BelongsTo"
    - from: "pass_through_calls.connection_id"
      to: "connections.id"
      via: "foreign key nullOnDelete (audit-rij overleeft revoke/delete van Connection)"
      pattern: "nullOnDelete"
---

<objective>
Een immutable `pass_through_calls`-tabel + `App\Models\PassThroughCall` + factory voor het audit-log van de Snelstart-pass-through-API.

Purpose: HUB-05 success criterion 7 — "Elke pass-through-call landt 1 rij in `pass_through_calls` (Consumer/Account/Connection-fingerprint/method/path/status); raw credentials nergens". Tabel is **niet** een hergebruik van `webhook_calls` (Spatie's tabel is voor inkomend-van-partner → uitgaand-naar-consumer fan-out; pass-through is een fundamenteel ander stream-pattern — zie ADR).

Output: nieuwe migration + Eloquent-model + factory + ADR die de deviatie van ROADMAP-tekst documenteert (per `.ai/rules/engineering.md` — *"Conflicten oppervlakken, niet uitmiddelen"*).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md
@CLAUDE.md
@database/migrations/2026_05_14_000003_create_connections_table.php
@database/migrations/2026_05_14_151327_add_active_unique_to_connections.php
@database/factories/ConnectionFactory.php
@app/Models/Connection.php

<interfaces>
<!-- Bestaande relaties die `PassThroughCall` raakt -->

From app/Models/Consumer.php:
```php
class Consumer extends Authenticatable {
    public function accounts(): HasMany; // hasMany(Account::class)
}
```

From app/Models/Account.php:
```php
#[Fillable(['consumer_id', 'external_id', 'display_name'])]
class Account extends Model {
    public function consumer(): BelongsTo;
    public function connections(): HasMany;
}
```

From app/Models/Connection.php:
```php
class Connection extends Model {
    public function account(): BelongsTo;
    public function fingerprint(): ?string; // sha256(client_key|access_token)[0..12]
}
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Migration `create_pass_through_calls_table`</name>
  <files>database/migrations/2026_05_15_000001_create_pass_through_calls_table.php</files>
  <read_first>
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md (sectie `<decisions> ### Audit-log — Nieuwe pass_through_calls-tabel`)
    - database/migrations/2026_05_14_000003_create_connections_table.php (pattern voor foreignId + cascadeOnDelete)
    - database/migrations/2026_05_14_151327_add_active_unique_to_connections.php (pattern voor raw `DB::statement` op partial indexes — Postgres-specifiek)
  </read_first>
  <action>
    Maak een nieuwe migration met `php artisan make:migration create_pass_through_calls_table --no-interaction`. Hernoem het bestand naar `2026_05_15_000001_create_pass_through_calls_table.php` (matches lexicale volgorde NA bestaande `2026_05_14_*`-migraties).

    Het `up()`-blok bevat **exact** deze kolomvolgorde en types (waarde-tabel uit CONTEXT.md `<decisions>`-sectie):

    ```php
    Schema::create('pass_through_calls', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('consumer_id')->constrained('consumers')->cascadeOnDelete();
        $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
        $table->foreignId('connection_id')->nullable()->constrained('connections')->nullOnDelete();
        $table->string('provider');                  // 'snelstart' (provider-agnostisch voor toekomstige 5c+)
        $table->string('method', 10);                // HTTP-method (GET/POST/PATCH/DELETE)
        $table->text('path');                        // volledig request-path inclusief query-string
        $table->smallInteger('status');              // upstream HTTP-status (of 0 bij network-error)
        $table->integer('duration_ms');              // end-to-end latency
        $table->string('request_fingerprint', 12)->nullable(); // sha256 van request-body[0..12]; NULL voor GET
        $table->integer('response_size_bytes')->nullable();    // capacity-planning, geen body-content
        $table->string('upstream_error')->nullable();          // short-code bij 502-rewrap (bv. 'snelstart_auth')
        $table->timestamp('created_at')->useCurrent(); // géén updated_at — immutable audit
        $table->index(['consumer_id', 'created_at']);
        $table->index(['account_id', 'created_at']);
    });

    // Partial index voor failure-monitoring; Postgres-specifiek (zelfde pattern als
    // connections_account_id_provider_active_unique uit Phase 3-cleanup).
    DB::statement(
        'CREATE INDEX pass_through_calls_status_failures '
        .'ON pass_through_calls (status) WHERE status >= 500'
    );
    ```

    Het `down()`-blok mag bestaan (Postgres rolt indexes automatisch terug bij DROP TABLE):

    ```php
    public function down(): void {
        Schema::dropIfExists('pass_through_calls');
    }
    ```

    **Niet** `$table->timestamps()` gebruiken — `updated_at` mag niet bestaan op een immutable audit-rij (CONTEXT.md decision: *"géén updated_at"*).

    **Niet** een `subscription_id`-kolom of body-snapshot opnemen — alleen fingerprint + metadata.

    Run pint na de wijziging: `vendor/bin/pint --dirty --format agent`.
  </action>
  <verify>
    <automated>php artisan migrate --pretend 2>&1 | grep -E "(create table .?pass_through_calls.?|CREATE INDEX pass_through_calls_status_failures)" | wc -l</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "Schema::create('pass_through_calls'" database/migrations/2026_05_15_000001_create_pass_through_calls_table.php` >= 1
    - `grep -c "CREATE INDEX pass_through_calls_status_failures" database/migrations/2026_05_15_000001_create_pass_through_calls_table.php` >= 1
    - `grep -c "timestamps()" database/migrations/2026_05_15_000001_create_pass_through_calls_table.php` == 0 (immutable — alleen `created_at` via `useCurrent()`)
    - `php artisan migrate --pretend` exit 0 en bevat "create table" voor `pass_through_calls`
    - `php artisan migrate` exit 0 op een fresh database (smoke: `php artisan migrate:fresh --no-interaction`)
  </acceptance_criteria>
  <done>De migration maakt de tabel met alle kolommen + 3 indexen aan op een fresh Postgres-DB zonder errors; rollback werkt via `migrate:fresh`.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: `App\Models\PassThroughCall` + factory</name>
  <files>app/Models/PassThroughCall.php, database/factories/PassThroughCallFactory.php, tests/Feature/PassThroughCallModelTest.php</files>
  <behavior>
    - `PassThroughCall::create([...])` schrijft een rij; `updated_at` bestaat niet (model heeft `$timestamps = false` en handelt `created_at` zelf af via DB-default of via een `creating`-event)
    - `PassThroughCall::factory()->create()` produceert een geldige rij met linked Consumer/Account/Connection (via factories)
    - `$call->consumer`, `$call->account`, `$call->connection` zijn `BelongsTo`-relaties die de juiste model-instanties teruggeven
    - `$call->connection` mag null zijn (nullOnDelete is toegestaan voor audit-immutability)
  </behavior>
  <read_first>
    - app/Models/Connection.php (pattern voor `#[Fillable]` attribute, `casts()`-method, BelongsTo)
    - app/Models/Account.php (pattern voor minimale model + relaties)
    - database/factories/ConnectionFactory.php (pattern voor factory met linked-factory-defaults)
    - database/factories/AccountFactory.php (pattern voor `consumer_id => Consumer::factory()` linking)
  </read_first>
  <action>
    **Model** `app/Models/PassThroughCall.php`:

    ```php
    <?php

    namespace App\Models;

    use Database\Factories\PassThroughCallFactory;
    use Illuminate\Database\Eloquent\Attributes\Fillable;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    #[Fillable([
        'consumer_id',
        'account_id',
        'connection_id',
        'provider',
        'method',
        'path',
        'status',
        'duration_ms',
        'request_fingerprint',
        'response_size_bytes',
        'upstream_error',
        'created_at',
    ])]
    class PassThroughCall extends Model
    {
        /** @use HasFactory<PassThroughCallFactory> */
        use HasFactory;

        public $timestamps = false;

        public function consumer(): BelongsTo
        {
            return $this->belongsTo(Consumer::class);
        }

        public function account(): BelongsTo
        {
            return $this->belongsTo(Account::class);
        }

        public function connection(): BelongsTo
        {
            return $this->belongsTo(Connection::class);
        }

        protected function casts(): array
        {
            return [
                'status'              => 'integer',
                'duration_ms'         => 'integer',
                'response_size_bytes' => 'integer',
                'created_at'          => 'datetime',
            ];
        }
    }
    ```

    Geen `static::booted()`-hook nodig voor `created_at` — `useCurrent()` op de migratie vult 'm op DB-laag wanneer de Eloquent-insert het veld weglaat. Wanneer een test handmatig `created_at` zet, respecteert Eloquent dat omdat de kolom in `$fillable` staat.

    **Factory** `database/factories/PassThroughCallFactory.php`:

    ```php
    <?php

    namespace Database\Factories;

    use App\Models\Account;
    use App\Models\Connection;
    use App\Models\Consumer;
    use App\Models\PassThroughCall;
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * @extends Factory<PassThroughCall>
     */
    class PassThroughCallFactory extends Factory
    {
        /**
         * @return array<string, mixed>
         */
        public function definition(): array
        {
            $consumer = Consumer::factory();
            $account  = Account::factory()->for($consumer);

            return [
                'consumer_id'         => $consumer,
                'account_id'          => $account,
                'connection_id'       => Connection::factory()->forSnelstart()->for($account),
                'provider'            => 'snelstart',
                'method'              => 'GET',
                'path'                => 'echo/ping',
                'status'              => 200,
                'duration_ms'         => fake()->numberBetween(20, 400),
                'request_fingerprint' => null,
                'response_size_bytes' => fake()->numberBetween(20, 5000),
                'upstream_error'      => null,
                'created_at'          => now(),
            ];
        }
    }
    ```

    **Smoke-test** `tests/Feature/PassThroughCallModelTest.php` met `RefreshDatabase`:
    1. `test_factory_creates_row_with_relations` — `PassThroughCall::factory()->create()`; assert `$row->consumer`, `$row->account`, `$row->connection` zijn niet null en instances van de juiste class
    2. `test_does_not_track_updated_at` — assert kolom `updated_at` bestaat niet via `Schema::hasColumn('pass_through_calls', 'updated_at') === false`
    3. `test_connection_id_survives_connection_delete` — maak een Connection, dan een PassThroughCall die ernaar verwijst, dan `$connection->delete()`; assert `$row->refresh()->connection_id === null` (nullOnDelete-gedrag bevestigd)

    Run pint: `vendor/bin/pint --dirty --format agent`.
    Run de test: `php artisan test --compact --filter=PassThroughCallModelTest`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=PassThroughCallModelTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "class PassThroughCall extends Model" app/Models/PassThroughCall.php` == 1
    - `grep -c "public \$timestamps = false" app/Models/PassThroughCall.php` == 1
    - `grep -cE "function (consumer|account|connection)\\(\\): BelongsTo" app/Models/PassThroughCall.php` == 3
    - `grep -c "class PassThroughCallFactory extends Factory" database/factories/PassThroughCallFactory.php` == 1
    - `php artisan test --compact --filter=PassThroughCallModelTest` exit 0, 3 tests passed
  </acceptance_criteria>
  <done>Model + factory bestaan, drie smoke-tests groen, BelongsTo-relaties beide kanten op werkbaar.</done>
</task>

<task type="auto">
  <name>Task 3: ADR — `pass-through-calls-table` (deviatie van ROADMAP `webhook_calls`)</name>
  <files>.docs/decisions/pass-through-calls-table.md</files>
  <read_first>
    - .docs/decisions/intern-admin-ui-filament.md (template-format voor ADR-secties: Status / Keuze / Context / Consequenties)
    - .docs/decisions/mollie-passthrough-api.md (referentie voor pass-through ADR-stijl in dit project)
    - .planning/REQUIREMENTS.md (HUB-05 zegt nog "webhook_calls" — deze ADR documenteert dat 5b ervan afwijkt)
    - .planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md (sectie `<decisions> ### Audit-log` is de bron-van-waarheid)
  </read_first>
  <action>
    Schrijf een Nederlands-Engels ADR (Nederlands proza, Engelse identifiers) volgens de bestaande `.docs/decisions/*`-stijl. Sections (in deze volgorde):

    1. `# Pass-through-calls audit-tabel — eigen tabel, niet `webhook_calls``
    2. `## Status` — *"Geaccepteerd 2026-05-14 — Phase 5b kiest voor een nieuwe `pass_through_calls`-tabel in plaats van de bestaande Spatie `webhook_calls`-tabel."*
    3. `## Keuze` — bullet points: nieuwe tabel; kolommen exact uit Task 1 migratie; immutable (geen `updated_at`); 3 indexes (chronologisch per Consumer, per Account, partial op failures).
    4. `## Context` — waarom geen `webhook_calls`:
       - `webhook_calls` (Spatie) modelt *"inkomend van partner → uitgaande consumer-callback"* fan-out — een ander stream-pattern dan pass-through (Consumer→Hub→Partner→terug)
       - Mengen forceert een `direction`-discriminator + veel NULL-kolommen
       - HUB-05 ROADMAP-tekst noemt `webhook_calls` maar PROJECT.md architectuurdiagram laat `webhook_calls` als fan-out-tabel zien; deze ADR oppervlakt die spanning
       - Phase 5a kan dezelfde keuze maken; provider-agnostisch via de `provider`-kolom
    5. `## Consequenties` —
       - Bij Phase 5a: hergebruik dezelfde tabel (al provider-agnostisch) of beslis dan opnieuw
       - Filament Phase 9 admin-UI krijgt een 5e resource erbij (`PassThroughCallResource`) — out-of-scope voor 5b
       - Retention-policy: deferred (zie CONTEXT.md `<deferred>`)
       - HUB-05 in REQUIREMENTS.md verwijst nog naar `webhook_calls` — moet bij Phase 5b-close gecorrigeerd worden naar `pass_through_calls`

    Eind-regel: *"Bron: `.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md` §`<decisions> ### Audit-log`."*

    Géén heredoc / cat <<EOF gebruiken — alleen Write-tool. **Géén** AI-cliché's (anti-cliché-lijst uit `.ai/rules/global.md`).
  </action>
  <verify>
    <automated>test -f .docs/decisions/pass-through-calls-table.md && grep -cE "^## (Status|Keuze|Context|Consequenties)" .docs/decisions/pass-through-calls-table.md</automated>
  </verify>
  <acceptance_criteria>
    - `test -f .docs/decisions/pass-through-calls-table.md` exit 0
    - `grep -c "^## Status$" .docs/decisions/pass-through-calls-table.md` == 1
    - `grep -c "^## Keuze$" .docs/decisions/pass-through-calls-table.md` == 1
    - `grep -c "^## Context$" .docs/decisions/pass-through-calls-table.md` == 1
    - `grep -c "^## Consequenties$" .docs/decisions/pass-through-calls-table.md` == 1
    - `grep -c "pass_through_calls" .docs/decisions/pass-through-calls-table.md` >= 3
    - **Trigger `docs-sync` skill** als follow-up in de execute-sessie (nieuwe ADR-file → `.docs/README.md`-index moet 'm opnemen)
  </acceptance_criteria>
  <done>ADR bestaat, alle 4 secties aanwezig, deviatie van ROADMAP/REQUIREMENTS-tekst expliciet gedocumenteerd, docs-sync getriggerd.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| DB row → audit-consumer (logs/Filament/SIEM later) | Wat geschreven wordt verlaat de Hub via downstream consumers van audit-data; raw secrets mogen hier nooit landen |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05b-01 | Information disclosure | `pass_through_calls.path` kolom | mitigate | Path bevat query-string en wordt opgeslagen, maar `client_key`/`subscription_key` zitten niet in path; route is `/v1/snelstart/{path}` — Consumer kan geen credential in path zetten zonder dat de Hub die eerst zou loggen. Whitelist verifieerd in Plan 03 + 05. Geen body-snapshot. |
| T-05b-02 | Information disclosure | `pass_through_calls.upstream_error` kolom | mitigate | Alleen short-code (`snelstart_auth`, `snelstart_5xx`, `snelstart_timeout`) — geen response-body, geen credentials. Bron-tabel in Plan 03. |
| T-05b-03 | Tampering | Audit-immutability | mitigate | Géén `updated_at`-kolom; geen Eloquent-`fill()`-pad voor updates in code (model wordt alleen via `create()` gebruikt in PassThroughController — zie Plan 05). DB-level constraint deferred (PG-`UPDATE`-trigger overweegbaar; nu niet — `<deferred>` retention-policy). |
| T-05b-04 | Repudiation | Cross-tenant audit-leakage | accept | Consumer-id is FK met cascadeOnDelete; bij Consumer-delete worden audit-rijen meegegooid. Voor Phase 5b acceptabel: GDPR-erasure-pad past hierop. Async-archief out-of-scope. |
</threat_model>

<verification>
- Migration runt clean (`php artisan migrate:fresh --no-interaction`)
- `PassThroughCallModelTest` 3 tests groen
- ADR-file bestaat met 4 secties
- Pint clean (`vendor/bin/pint --dirty --format agent` zero changes after task-runs)
- `docs-sync` skill triggert na ADR-toevoeging (handmatig signaal in execute-sessie; niet binnen plan-context spawnen)
</verification>

<success_criteria>
- `pass_through_calls` tabel staat in een fresh DB met 11 datakolommen + `id` + `created_at` (totaal 13 kolommen)
- `App\Models\PassThroughCall` is een werkend Eloquent-model met `$timestamps = false` + 3 BelongsTo's
- ADR documenteert de deviatie van HUB-05 ROADMAP-tekst (`webhook_calls` → `pass_through_calls`)
- Volledige Hub-testsuite blijft groen (`php artisan test --compact`) — geen regressies in bestaande Phase 3-tests
</success_criteria>

<output>
Na completion: maak `.planning/phases/05b-snelstart-pass-through-api/05b-01-SUMMARY.md` per template; vermeld dat de ADR een follow-up `docs-sync` skill-run vereist en dat HUB-05 REQUIREMENTS-tekst aan einde van Phase 5b bijgewerkt moet worden.
</output>
