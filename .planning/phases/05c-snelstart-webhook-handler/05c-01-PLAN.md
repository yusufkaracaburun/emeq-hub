---
phase: 05c-snelstart-webhook-handler
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_05_17_000001_add_inbound_columns_to_pass_through_calls_table.php
  - database/migrations/2026_05_17_000002_add_administratie_id_to_connections_table.php
  - app/Models/PassThroughCall.php
  - app/Models/Connection.php
  - database/factories/PassThroughCallFactory.php
  - database/factories/ConnectionFactory.php
  - tests/Feature/PassThroughCallInboundScopesTest.php
autonomous: true
requirements: [HUB-06]
tags:
  - laravel
  - migrations
  - postgres
  - audit-log
  - phpunit

must_haves:
  truths:
    - "`pass_through_calls` ondersteunt zowel outbound (Phase 5b) als inbound (Phase 5c) rijen via een non-null `direction`-kolom met default `outbound`"
    - "Idempotency op inbound webhooks is afdwingbaar via een Postgres unique constraint `(provider, event_id)` die NULLs toelaat voor outbound-rijen"
    - "Een inbound webhook met onbekende `administratieId` kan een audit-rij schrijven zonder Consumer/Account-FK (beide nullable)"
    - "Snelstart-Connections kunnen gevonden worden op `(provider='snelstart', administratie_id=<payload-uuid>)` zonder full-table scan"
  artifacts:
    - path: "database/migrations/2026_05_17_000001_add_inbound_columns_to_pass_through_calls_table.php"
      provides: "Migration die `direction` + `event_id` toevoegt en `consumer_id`/`account_id` nullable maakt"
      contains: "Schema::table('pass_through_calls'"
    - path: "database/migrations/2026_05_17_000002_add_administratie_id_to_connections_table.php"
      provides: "Migration die `connections.administratie_id` toevoegt met composite index op `(provider, administratie_id)`"
      contains: "administratie_id"
    - path: "app/Models/PassThroughCall.php"
      provides: "Eloquent-model met `direction` + `event_id` in Fillable, plus `scopeInbound` + `scopeOutbound`"
      exports: ["scopeInbound", "scopeOutbound"]
    - path: "app/Models/Connection.php"
      provides: "`administratie_id` in Fillable; geen `encrypted`-cast (tenant-UUID, geen secret)"
      contains: "'administratie_id'"
  key_links:
    - from: "pass_through_calls.event_id"
      to: "unique(provider, event_id)"
      via: "Postgres unique index voor idempotency"
      pattern: "compound unique"
    - from: "connections.administratie_id"
      to: "index(provider, administratie_id)"
      via: "composite B-tree voor webhook-routing lookup"
      pattern: "composite index"
---

<objective>
Schema-uitbreiding zodat `pass_through_calls` inbound webhook-rijen kan dragen en `connections` doorzoekbaar wordt op `administratie_id`.

Purpose: HUB-06 — alle structurele storage- en lookup-paths die plan 02-05 nodig hebben moeten op DB-laag aanwezig zijn voordat verifier/middleware/controller/job gebouwd worden. Eenmalige migration-set voorkomt dat latere plans nieuwe `ALTER TABLE`'s mengen met code-werk.

Output: 2 migrations, 2 model-updates, 2 factory-updates, 1 scope-smoketest. **Geen** code die het webhook-pad zelf raakt (dat zit in plan 02-05).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md
@CLAUDE.md
@database/migrations/2026_05_15_000001_create_pass_through_calls_table.php
@database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php
@database/migrations/2026_05_14_000003_create_connections_table.php
@app/Models/PassThroughCall.php
@app/Models/Connection.php
@database/factories/PassThroughCallFactory.php
@database/factories/ConnectionFactory.php
</context>

<tasks>

<task type="auto">
  <name>Task 1: Migration `add_inbound_columns_to_pass_through_calls_table`</name>
  <files>database/migrations/2026_05_17_000001_add_inbound_columns_to_pass_through_calls_table.php</files>
  <read_first>
    - .planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md (sectie `<decisions> ### 🔒 Locked — Audit-tabel reuse`)
    - database/migrations/2026_05_15_000001_create_pass_through_calls_table.php (huidige schema — kolom-volgorde + index-pattern)
    - database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php (pattern voor `Schema::table`-additions)
  </read_first>
  <action>
    Maak migration met `php artisan make:migration add_inbound_columns_to_pass_through_calls_table --no-interaction`. Hernoem naar `2026_05_17_000001_*` (lexicaal NA bestaande `2026_05_15_*`-migraties).

    `up()` doet **exact** drie dingen in deze volgorde:

    ```php
    public function up(): void
    {
        // 1. Nullable maken van tenant-FKs zodat een inbound webhook met onbekende
        //    administratieId een audit-rij kan schrijven (CONTEXT.md decision
        //    "Onbekende administratieId" + "Audit-tabel reuse").
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->foreignId('consumer_id')->nullable()->change();
            $table->foreignId('account_id')->nullable()->change();
        });

        // 2. `direction` + `event_id` toevoegen. Default `outbound` retro-vult
        //    bestaande 5b-rijen; nieuwe inbound-rijen zetten 'inbound' expliciet.
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->string('direction', 10)->default('outbound')->after('id');
            $table->string('event_id')->nullable()->after('request_fingerprint');
            $table->index(['direction', 'created_at']);
        });

        // 3. Postgres unique constraint voor idempotency. Postgres staat
        //    meerdere NULLs toe in een unique index (default), dus outbound-rijen
        //    (event_id=NULL) blokkeren elkaar niet.
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->unique(['provider', 'event_id'], 'pass_through_calls_provider_event_unique');
        });
    }
    ```

    `down()`:

    ```php
    public function down(): void
    {
        Schema::table('pass_through_calls', function (Blueprint $table): void {
            $table->dropUnique('pass_through_calls_provider_event_unique');
            $table->dropIndex(['direction', 'created_at']);
            $table->dropColumn(['direction', 'event_id']);
        });
        // consumer_id/account_id revert naar non-null laten we expliciet weg:
        // forward-only-prod-policy (CLAUDE.md — Migrations zijn forward-only in prod).
    }
    ```

    Run `vendor/bin/pint --dirty --format agent`.
  </action>
  <verify>
    <automated>php artisan migrate:fresh --no-interaction 2>&1 | tail -20</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "direction.*10.*outbound" database/migrations/2026_05_17_000001_add_inbound_columns_to_pass_through_calls_table.php` >= 1
    - `grep -c "pass_through_calls_provider_event_unique" database/migrations/2026_05_17_000001_add_inbound_columns_to_pass_through_calls_table.php` >= 1
    - `grep -c "consumer_id.*nullable.*change\|account_id.*nullable.*change" database/migrations/2026_05_17_000001_add_inbound_columns_to_pass_through_calls_table.php` >= 2
    - `php artisan migrate:fresh --no-interaction` exit 0
    - `php artisan db:show --counts | grep pass_through_calls` toont de tabel
    - Smoke: `php artisan tinker --execute 'DB::table("pass_through_calls")->insert(["direction" => "inbound", "provider" => "snelstart", "event_id" => "evt-1", "method" => "POST", "path" => "/webhooks/snelstart", "status" => 200, "duration_ms" => 10]); echo "ok";'` exit 0 (inbound-rij met NULL consumer/account is toegestaan)
  </acceptance_criteria>
  <done>Migration runt clean op fresh DB; idempotency-unique-index bestaat; outbound-rijen blijven werken met `event_id=NULL`.</done>
</task>

<task type="auto">
  <name>Task 2: Migration `add_administratie_id_to_connections_table`</name>
  <files>database/migrations/2026_05_17_000002_add_administratie_id_to_connections_table.php</files>
  <read_first>
    - database/migrations/2026_05_14_000003_create_connections_table.php (pattern voor `connections`-kolom-volgorde; `subscription_id` is de anchor voor `after()`)
    - database/migrations/2026_05_15_000001_add_oauth_state_to_connections_table.php (pattern voor `Schema::table`-add op connections)
    - .planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md (sectie `<decisions> ### ❓ Aanname (vraag #3)`)
  </read_first>
  <action>
    Maak migration met `php artisan make:migration add_administratie_id_to_connections_table --no-interaction`. Hernoem naar `2026_05_17_000002_*`.

    ```php
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table): void {
            // nullable: bestaande Mollie-Connections hebben geen administratie_id.
            // niet encrypted: tenant-UUID per Snelstart-OData-conventie is geen
            // secret (analoog aan subscription_id, zie 03-01 decision).
            $table->string('administratie_id')->nullable()->after('subscription_id');
            $table->index(['provider', 'administratie_id']);
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'administratie_id']);
            $table->dropColumn('administratie_id');
        });
    }
    ```

    Run `vendor/bin/pint --dirty --format agent`.
  </action>
  <verify>
    <automated>php artisan migrate:fresh --no-interaction && php artisan db:table connections 2>&1 | grep administratie_id</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "administratie_id" database/migrations/2026_05_17_000002_add_administratie_id_to_connections_table.php` >= 2
    - `grep -c "encrypted" database/migrations/2026_05_17_000002_add_administratie_id_to_connections_table.php` == 0 (geen encrypted-cast — tenant-UUID is geen secret)
    - `grep -c "index.*provider.*administratie_id" database/migrations/2026_05_17_000002_add_administratie_id_to_connections_table.php` >= 1
    - `php artisan migrate:fresh --no-interaction` exit 0
    - `php artisan db:table connections` toont kolom `administratie_id` (nullable, string)
  </acceptance_criteria>
  <done>Kolom + composite index bestaan op een fresh DB; geen rauwe `encrypted`-cast (zou de OData-lookup-query stuk maken).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: `PassThroughCall`-model — `direction` + `event_id` + scopes</name>
  <files>app/Models/PassThroughCall.php, database/factories/PassThroughCallFactory.php, tests/Feature/PassThroughCallInboundScopesTest.php</files>
  <behavior>
    - `PassThroughCall::create(['direction' => 'inbound', ...])` persisteert `direction` en `event_id`
    - `PassThroughCall::inbound()->get()` retourneert alleen rijen met `direction = 'inbound'`
    - `PassThroughCall::outbound()->get()` retourneert alleen rijen met `direction = 'outbound'` (de default voor 5b-rijen)
    - `PassThroughCallFactory::new()->inbound()` produceert een geldige inbound-rij met `event_id` + Snelstart-defaults
    - Een tweede `create()` met dezelfde `(provider, event_id)` gooit een DB-constraint-violation (idempotency-guarantee)
  </behavior>
  <read_first>
    - app/Models/PassThroughCall.php (huidig model — voeg toe, niet vervang)
    - app/Models/Connection.php (pattern voor `#[Fillable]` array-uitbreiding)
    - database/factories/PassThroughCallFactory.php (huidige outbound-default)
    - database/factories/ConnectionFactory.php (voor `forSnelstart`-state — niet wijzigen, hergebruiken)
  </read_first>
  <action>
    **1. `app/Models/PassThroughCall.php`** — breid `#[Fillable]` uit met `direction` + `event_id` (na `request_fingerprint` resp. vooraan na `id`-volgorde van de migratie):

    ```php
    #[Fillable([
        'direction',
        'consumer_id',
        'account_id',
        'connection_id',
        'provider',
        'method',
        'path',
        'query_keys',
        'status',
        'duration_ms',
        'request_fingerprint',
        'event_id',
        'response_size_bytes',
        'upstream_error',
        'created_at',
    ])]
    ```

    Voeg twee scope-methods toe:

    ```php
    public function scopeInbound(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeOutbound(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('direction', 'outbound');
    }
    ```

    **2. `database/factories/PassThroughCallFactory.php`** — voeg een `inbound()`-state toe:

    ```php
    public function inbound(): static
    {
        return $this->state(fn (): array => [
            'direction' => 'inbound',
            'method' => 'POST',
            'path' => '/webhooks/snelstart',
            'event_id' => 'evt-'.fake()->uuid(),
            'request_fingerprint' => substr(hash('sha256', fake()->uuid()), 0, 12),
        ]);
    }
    ```

    De bestaande `definition()` blijft outbound-default — voeg `'direction' => 'outbound'` en `'event_id' => null` expliciet toe zodat de defaults na de migratie zichtbaar zijn (DB-default werkt ook, maar de factory moet self-documenting blijven).

    **3. Test `tests/Feature/PassThroughCallInboundScopesTest.php`** met `RefreshDatabase`:
    1. `test_inbound_scope_filters_correctly` — maak 2 outbound + 1 inbound; assert `PassThroughCall::inbound()->count() === 1` en `outbound()->count() === 2`
    2. `test_inbound_audit_row_allows_null_tenant` — `PassThroughCall::factory()->inbound()->create(['consumer_id' => null, 'account_id' => null, 'connection_id' => null])` slaagt; refresh + asserts alle drie FKs null
    3. `test_duplicate_provider_event_id_is_rejected` — gebruik `try/catch` op `QueryException`; tweede `create` met zelfde `(provider, event_id)` gooit unique-violation; eerste rij is intact

    Run `vendor/bin/pint --dirty --format agent` en `php artisan test --compact --filter=PassThroughCallInboundScopesTest`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=PassThroughCallInboundScopesTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "'direction'" app/Models/PassThroughCall.php` >= 1
    - `grep -c "'event_id'" app/Models/PassThroughCall.php` >= 1
    - `grep -cE "function scope(Inbound|Outbound)" app/Models/PassThroughCall.php` == 2
    - `grep -c "public function inbound" database/factories/PassThroughCallFactory.php` == 1
    - `php artisan test --compact --filter=PassThroughCallInboundScopesTest` exit 0, 3 tests passed
    - `php artisan test --compact --filter=PassThroughCallModelTest` exit 0 (regressie-check op bestaande 05b-01-tests)
  </acceptance_criteria>
  <done>Scopes werken; factory exposes `inbound()`-state; DB-constraint dwingt idempotency af.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 4: `Connection`-model + factory — `administratie_id`</name>
  <files>app/Models/Connection.php, database/factories/ConnectionFactory.php, tests/Feature/ConnectionAdministratieIdTest.php</files>
  <behavior>
    - `$connection->administratie_id = '00000000-0000-0000-0000-...';` + `save()` persisteert raw — geen encryption
    - `ConnectionFactory::new()->forSnelstart()` zet automatisch een geldige `administratie_id` UUID
    - `Connection::where('provider', 'snelstart')->where('administratie_id', $uuid)->first()` gebruikt de composite index (smoke via `EXPLAIN`)
  </behavior>
  <read_first>
    - app/Models/Connection.php (huidig Fillable + casts)
    - database/factories/ConnectionFactory.php (bestaande `forSnelstart()`-state)
    - .planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md (decision: niet encrypted)
  </read_first>
  <action>
    **1. `app/Models/Connection.php`** — voeg `'administratie_id'` toe aan `#[Fillable]` na `'subscription_id'`. **Niet** toevoegen aan `casts()` (geen `encrypted`) en **niet** aan `#[Hidden]` (UUID mag in API-response).

    **2. `database/factories/ConnectionFactory.php`** — breid `forSnelstart()` uit zodat `administratie_id` een UUID krijgt:

    ```php
    public function forSnelstart(): static
    {
        return $this->state(fn (): array => [
            'provider' => 'snelstart',
            'client_key' => 'ck_'.fake()->uuid(),
            'subscription_key' => 'sk_'.fake()->uuid(),
            'subscription_id' => fake()->uuid(),
            'administratie_id' => fake()->uuid(),
            // ... rest van bestaande state
        ]);
    }
    ```

    (Vervang alleen de bestaande array-keys met deze; behoud andere keys die er nu staan.)

    **3. Test `tests/Feature/ConnectionAdministratieIdTest.php`** met `RefreshDatabase`:
    1. `test_administratie_id_persists_unencrypted` — maak een Connection met `administratie_id = 'abc-123'`; `DB::table('connections')->where(...)` selecteert raw waarde `abc-123` zonder decrypt-call
    2. `test_factory_forSnelstart_sets_administratie_id` — `Connection::factory()->forSnelstart()->create()`; `administratie_id` is een non-empty UUID
    3. `test_lookup_by_provider_and_administratie_id_returns_connection` — maak 1 mollie + 2 snelstart-Connections met verschillende UUIDs; `Connection::where('provider', 'snelstart')->where('administratie_id', $expected)->first()` returnt exact één match

    Run pint + `php artisan test --compact --filter=ConnectionAdministratieIdTest`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=ConnectionAdministratieIdTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "'administratie_id'" app/Models/Connection.php` >= 1
    - `grep -B2 -A2 "administratie_id" app/Models/Connection.php | grep -c "encrypted"` == 0 (administratie_id staat NIET tussen encrypted-casts)
    - `grep -c "administratie_id" database/factories/ConnectionFactory.php` >= 1
    - `php artisan test --compact --filter=ConnectionAdministratieIdTest` exit 0, 3 tests passed
    - Volledige Connection-test-suite blijft groen: `php artisan test --compact --filter=ConnectionEncryptionTest` + `ConsumerAccountScopingTest` exit 0
  </acceptance_criteria>
  <done>UUID-string is queryable, factory geeft 'm automatisch, geen regressie op encryption-tests.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| DB-layer ↔ application-code | `administratie_id` is tenant-UUID maar geen secret; queryable via WHERE-clause zonder decrypt-overhead |
| Migration runtime ↔ existing prod-data | `direction` default `outbound` retro-vult bestaande Phase 5b-rijen; geen data-loss-risk |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05c-01 | Tampering | `event_id`-collision aanvaller spoofed | mitigate | Idempotency-unique pakt dezelfde event_id; tweede dispatch geblokkeerd op DB-laag. Aanvaller moet eerst valid HMAC kunnen forgen (zie plan 02). |
| T-05c-02 | Information disclosure | `administratie_id` lekt cross-tenant via index-scan | accept | UUID is geen secret; cross-tenant resolution gebeurt in plan 03 controller via `connection.account.consumer`-chain, niet op deze kolom direct. |
| T-05c-03 | Repudiation | Forward-only migrations + nullable change | accept | `consumer_id`/`account_id` worden nullable; geen NOT NULL-revert in `down()` om prod-data niet te corrumperen (CLAUDE.md "forward-only in prod"). |
</threat_model>

<verification>
- `php artisan migrate:fresh --no-interaction` runt clean
- 3 nieuwe feature-tests (`PassThroughCallInboundScopesTest`, `ConnectionAdministratieIdTest`) groen
- Bestaande 05b-01 + 03-04 tests blijven groen (regressie-check)
- Pint clean
</verification>

<success_criteria>
- `pass_through_calls` heeft `direction`, `event_id`, nullable `consumer_id`/`account_id`, en een unique index `(provider, event_id)`
- `connections` heeft `administratie_id` + composite index `(provider, administratie_id)`
- `PassThroughCall::inbound()`/`outbound()`-scopes + `PassThroughCallFactory::inbound()`-state bestaan
- Volledige Hub-testsuite (`php artisan test --compact`) groen — geen regressies
</success_criteria>

<output>
Na completion: schrijf `.planning/phases/05c-snelstart-webhook-handler/05c-01-SUMMARY.md` per template; vermeld de migration-filenames + scope-helpers zodat plan 02-05 ze direct kunnen referencen.
</output>
