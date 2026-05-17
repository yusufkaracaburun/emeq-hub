# Phase 5c: Snelstart webhook-handler — Context

**Gathered:** 2026-05-15
**Status:** Partial — partner-respons 2026-05-17 sluit 4/5 aannames (🔒 #1 / #2 / #3 / #5); vraag #4 (retry-policy) blijft ❓ blocked op follow-up
**Requirement:** HUB-06 (toe te voegen aan REQUIREMENTS.md bij plan-phase)
**Depends on:** Phase 5b (Snelstart-pass-through API — `connections` + `pass_through_calls`-tabel), Phase 5a-01 (`consumers.webhook_callback_url` + `consumers.webhook_callback_secret` reeds aanwezig per migratie `2026_05_16_000001`)

<domain>
## Phase Boundary

Snelstart stuurt webhooks naar één publieke partner-URL `https://hub.emeq.nl/webhooks/snelstart` (bij certificering doorgegeven; geen Sanctum-auth). De Hub:

1. Verifieert HMAC met globale `SNELSTART_WEBHOOK_SECRET` env var (🔒 #1 confirmed 2026-05-17 — header + algoritme bevestigd)
2. Parsed payload en leest tenant-routing-veld `administratieId` (🔒 #3 confirmed 2026-05-17 — UUID-string)
3. Resolved `Connection` waar `connections.administratie_id == payload.administratieId`
4. Schrijft audit-row in `pass_through_calls` met `direction = inbound`
5. Enqueuet outbound fan-out-job richting `consumers.webhook_callback_url` met HMAC-signing via `consumers.webhook_callback_secret` (per-Consumer, hergebruik Phase 5a-01-pattern)
6. Respondt 200 aan Snelstart zodra event is opgenomen (niet wachten op consumer-callback-respons)

**Out of scope** (later phases of na partner-respons):
- Replay-knop voor gefaalde inbound events → Phase 9 Filament admin
- OData-polling als safety-net wanneer Snelstart-retries verloren gaan → afhankelijk van retry-policy-antwoord (vraag #4)
- Snelstart event-typen filteren / dispatchen per type → MVP doet "alles forwarden", refinement later
- Aparte event-tabel met `event_id`-uniqueness → MVP gebruikt `pass_through_calls`; aparte tabel bij scale
- Outbound webhook-server-config zelf — bestaat al uit Phase 5a-01

</domain>

<decisions>
## Implementation Decisions

### 🔒 Locked uit ADR `snelstart-certificering-pad.md`

- **Eén partner-URL** `https://hub.emeq.nl/webhooks/snelstart` — niet één URL per Connection. Bij certificering registreren we deze ene URL voor onze AppShortName.
- **HMAC-secret = globaal partner-secret** via `SNELSTART_WEBHOOK_SECRET` env var in Laravel Cloud. Roteerbaar via Snelstart developer-portal (🔒 #2 locked 2026-05-17 — Claude-pick, partner liet keuze open; rotation-pattern matched subscription-keys uit `subscription.md`).
- **Per-Connection routing via payload-veld**, niet via URL-padparameter — Snelstart stuurt alle administraties naar dezelfde URL.
- **Anti-correlation**: inbound HMAC-secret (Snelstart→Hub) ≠ outbound HMAC-secret (Hub→Consumer). Per-Consumer `webhook_callback_secret` blijft afgeschermd van de partner-secret.

### 🔒 Locked — invariants uit `.ai/rules/global.md` + project-context

- **Geen raw secrets in logs/audit**: payload-fingerprint (sha256 eerste 12 chars) in `pass_through_calls.request_fingerprint`. Raw body alleen kort in memory.
- **Webhook-secret encrypted at rest**: bestaande `consumers.webhook_callback_secret` is `text` met `encrypted` cast (Phase 5a-01-pattern). Geen nieuwe kolom nodig voor outbound.
- **Cross-Consumer-isolation**: een webhook voor administratie A van Consumer X mag nooit fan-outten naar Consumer Y's callback. Resolutie via `connections.administratie_id` → `connections.account_id` → `accounts.consumer_id`-chain.

### 🔒 Locked (vraag #1, confirmed 2026-05-17) — HMAC-header-naam + algorithme

**Confirmed:** Header = `X-SnelStart-Signature`, algorithme = `HMAC-SHA256` over raw request body, hex-encoded. Bron: partner-respons 2026-05-17 (Gmail-thread van `r-8836998535038336548`).

**Implementatie-defensief:** config-driven via `config/services.php`:
```php
'snelstart' => [
    'webhook_secret' => env('SNELSTART_WEBHOOK_SECRET'),
    'webhook_signature_header' => env('SNELSTART_WEBHOOK_SIGNATURE_HEADER', 'X-SnelStart-Signature'),
    'webhook_signature_algo' => env('SNELSTART_WEBHOOK_SIGNATURE_ALGO', 'sha256'),
],
```

**Fix-bij-respons:** wijzig env vars + `services.php`-defaults zonder code-deploy.

### 🔒 Locked (vraag #2, Claude-pick 2026-05-17 — partner liet keuze open) — Webhook-secret-lifecycle

**Locked:** Eén partner-secret per AppShortName. Roteerbaar via Snelstart developer-portal-UI met overlap-window (primary/secondary, analoog aan subscription-keys uit `subscription.md`).

**Defensief:** support voor twee secrets via `SNELSTART_WEBHOOK_SECRET` + `SNELSTART_WEBHOOK_SECRET_NEXT`. Verifier accepteert match op één van beide tijdens rotatie. Default: alleen primary.

**Rationale Claude-pick:** partner heeft geen expliciete opinion gegeven; secret-lifecycle pattern uit Snelstart's eigen subscription-key-model (gedocumenteerd in `subscription.md`) is de minst-verrassende default voor Emeq-dev's die de bestaande partner-conventies kennen. Geen rework-risico: code blijft hetzelfde als Snelstart later een per-URL-secret-tabel introduceert (config-driven secret-resolver, niet per-Connection-secret).

### 🔒 Locked (vraag #3, confirmed 2026-05-17) — Tenant-routing veld

**Confirmed:** Payload bevat `administratieId` als **UUID-string** (camelCase per Snelstart OData-conventie zoals in `apis-7c385276.md`). Partner-respons 2026-05-17 bevestigde het UUID-type expliciet; veldnaam `administratieId` blijft afgeleid uit OData-conventie en is niet apart bevestigd in de respons — match met partner-docs maakt rework-risico nihil.

**Nieuwe kolom op `connections`:**
```php
Schema::table('connections', function (Blueprint $table): void {
    $table->string('administratie_id')->nullable()->after('subscription_id');
    $table->index(['provider', 'administratie_id']);
});
```

- `nullable` omdat bestaande Mollie-Connections geen `administratie_id` hebben
- **niet `encrypted`**: Snelstart's administratie-UUIDs zijn geen secret (analoog aan `subscription_id`, zie Phase 3 decision 03-01)
- Composite index voor de webhook-lookup-query

**Fix-bij-respons:** als veldnaam anders is (bv. `relatieId` of nested `payload.administratie.id`) verander de migration-naam + de parser. Eenmalige refactor.

### ❓ Aanname (vraag #4) — Retry-policy — **BLOCKED 2026-05-17**

**Status:** partner heeft niet geantwoord op deze vraag in de respons van 2026-05-17. Aanname blijft defensief; follow-up nodig vóór `/gsd-plan-phase 5c` mits dit als acceptable risk geclassificeerd wordt (zie "Volgende stap" onderaan).

**Aanname (defensief):** Snelstart doet 5× exponential backoff (Azure APIM-default), eindstaat = verloren. Geen DLQ aan Snelstart-zijde.

**Implicaties:**
- Idempotency-tracking: voeg `event_id` toe aan `pass_through_calls` met unique index per `(provider, event_id)`. Bij re-delivery binnen retry-window → 200 + log + geen action.
- Monitoring-SLA: alert op `status >= 500 AND direction = inbound` binnen 5 min. Bestaande partial index `pass_through_calls_status_failures` werkt al.
- Safety-net: scheduled job die periodically OData `?modifiedSince=…` queries doet om gemiste mutaties op te halen — **out of scope voor 5c MVP**, in plan-phase als optionele 5c-N taak.

**Fix-bij-respons:**
- Als Snelstart een DLQ + portaal-replay heeft: skip OData-safety-net, voeg admin-UI replay-trigger toe in Phase 9.
- Als retries veel agressiever zijn (bv. 1× single-shot): bouw OData-polling als hard requirement, niet optioneel.

### 🔒 Locked (vraag #5, confirmed 2026-05-17) — Event-typen voor v1

**Confirmed:** Snelstart biedt minimaal `Relatie.*` en `Verkoopfactuur.*` event-typen. MVP filtert niet — we forwarden alles wat binnenkomt naar de consumer en laten de consumer per type beslissen.

**Fix-bij-respons (alleen als opt-in-registratie later opduikt):** configureer welke types we willen ontvangen via een nieuw `consumers.snelstart_webhook_events` JSON-array (later).

### 🔒 Locked — Onbekende `administratieId`

Wanneer een geldige HMAC binnenkomt maar de `administratieId` matcht geen `Connection`:

- **Respons:** 200 (niet 404). Snelstart wist er niets van; 404 zou retry-cyclus triggeren zonder kans op success.
- **Audit-row:** `direction=inbound`, `provider=snelstart`, `connection_id=NULL`, `account_id=NULL`, `consumer_id=NULL`, `status=200`, `upstream_error='unknown_administratie_id'`. Forensics behouden voor "hé waarom kreeg ik een webhook voor een administratie die ik niet ken".
- **Geen fan-out**: outbound job wordt niet enqueued.

### 🔒 Locked — Audit-tabel reuse (nieuwe migration)

Hergebruiken `pass_through_calls` met:

```php
Schema::table('pass_through_calls', function (Blueprint $table): void {
    $table->string('direction', 10)->default('outbound')->after('id');
    $table->string('event_id')->nullable()->after('request_fingerprint');
    $table->index(['direction', 'created_at']);
    $table->unique(['provider', 'event_id'], 'pass_through_calls_provider_event_unique');
});
```

Plus `account_id` + `consumer_id` nullable maken (inbound webhook met onbekende administratie heeft geen geresolveerde tenant):

```php
Schema::table('pass_through_calls', function (Blueprint $table): void {
    $table->foreignId('consumer_id')->nullable()->change();
    $table->foreignId('account_id')->nullable()->change();
});
```

**Rationale:** één query-bron voor alle calls (`SELECT * WHERE consumer_id = X` werkt voor zowel outbound als inbound). Filtering via `direction`.

### 🔒 Locked — Fan-out timing (async)

Bij geldige inbound:
1. Schrijf audit-row + ack 200 binnen <500ms — Snelstart's retry-window niet uitbuiten.
2. Dispatch `ForwardSnelstartWebhookToConsumerJob` op Horizon-queue `webhooks`.
3. Job gebruikt Spatie `laravel-webhook-server` met `consumers.webhook_callback_url` + `consumers.webhook_callback_secret`.
4. Consumer-callback-falen → Spatie's eigen retries (3× exponential, dan DLQ in Horizon failed_jobs).

**Sync-overweging afgewezen:** synchrone consumer-call binnen webhook-cyclus = onze 200-respons hangt aan consumer's beschikbaarheid → Snelstart retries opstapelen bij consumer-downtime. Async ontkoppelt de twee SLA's.

### 🔒 Locked — Route + middleware

```php
// routes/webhooks.php (nieuw bestand)
Route::post('/webhooks/snelstart', SnelstartWebhookController::class)
    ->middleware(['verify.snelstart.signature'])
    ->name('webhooks.snelstart');
```

`bootstrap/app.php` registratie:
```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    then: function () {
        Route::middleware(['api'])->group(base_path('routes/webhooks.php'));
    },
)
```

Geen Sanctum. Geen rate-limiting via `throttle:api` (Snelstart kan bursten — als wij dat throttle'n missen we events). Signature-middleware is de gatekeeper.

### 🔒 Locked — Invalid signature handling

**Respons:** 401, lege body. **Géén audit-row** (anti-amplification: aanvaller moet niet via 5xx + audit kunnen leren of zijn forgery in de buurt komt).

Optioneel: counter in Redis voor `snelstart_invalid_signature_count` om alerting op anomalies te doen. Stale na 24h.

</decisions>

<specifics>
## Specific Ideas

- **Verifier-class:** `App\Webhooks\SnelstartSignatureVerifier` (analoog aan `MollieWebhookSignature` uit `emeq/mollie-api` SDK — zie Phase 5a). Constructor injecteert config; method `verify(string $rawBody, string $headerValue): bool`.
- **Middleware:** `VerifySnelstartSignature` in `app/Http/Middleware/` — leest `$request->getContent()` (raw), pakt header naam uit config, vergelijkt met `hash_equals()` (timing-safe).
- **Controller:** `SnelstartWebhookController` single-action `__invoke` — match pattern uit `PingController` (Phase 3) + `PassThroughController` (Phase 5b). Dispatcht parser + audit-write + job-enqueue, retourneert 200 + lege body.
- **Job:** `ForwardSnelstartWebhookToConsumerJob` in `app/Jobs/Webhooks/`. Implementeert `ShouldQueue`, `tries = 3`, `backoff = [60, 300, 900]` (1m/5m/15m). Bij definitieve failure: Horizon `failed_jobs` + audit-row update via `upstream_error`-veld.
- **Models:** geen nieuwe; `Connection`-model krijgt een `administratie_id` field + de bestaande `webhook_callback_*` op `Consumer` wordt hergebruikt. Mogelijk een `PassThroughCall::scopeInbound()` + `::scopeOutbound()` voor query-leesbaarheid.
- **Tests** (Pest-style via PHPUnit, match `tests/Feature/`-conventie):
  - `SnelstartWebhookValidSignatureTest` — geldige HMAC + bekende administratie → 200 + audit + job dispatched (`Bus::fake()` + `Queue::fake()`)
  - `SnelstartWebhookInvalidSignatureTest` — verkeerde HMAC → 401, geen audit, geen job
  - `SnelstartWebhookUnknownAdministratieTest` — geldige HMAC + onbekende administratie_id → 200 + audit met NULL-tenant, geen job
  - `SnelstartWebhookIdempotencyTest` — zelfde `event_id` 2x → tweede call = 200 + 1 audit-row (dup), 1 job (originele) — geen dubbele forward
  - `ForwardSnelstartWebhookJobTest` — verzendt naar consumer-callback met juiste HMAC-header (`X-Emeq-Signature` of vergelijkbaar, decide bij plan)

</specifics>

<canonical_refs>
## Canonical References

- `.docs/decisions/snelstart-certificering-pad.md` — ADR met productie-keuze + 5 deliverables (gitignored, on disk)
- `.docs/partners/snelstart/certificering-d4b0407a.md` — partner-spec webhook-vereiste + rate-limits
- `.docs/partners/snelstart/oauth-deef6709.md` — webhookURL-verplichting + context-meegeven-mechanisme (regels 37, 62-63, 83-90)
- `.docs/partners/snelstart/apis-7c385276.md` + `apidocumentatie-3285ca90.md` — payload-veld-conventies (camelCase OData)
- `.planning/quick/260515-c52-snelstart-certificeringspad-productie-ro/partner-support-email-draft.md` — 8 vragen die de ❓-aannames omzetten in 🔒
- `.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md` — pass-through pattern (route shape, resolver-binding, audit-pattern) — copy-targets voor 5c
- Gmail-draft `r-8836998535038336548` (info@emeq.nl drafts) — verzonden 2026-05-15

</canonical_refs>

<code_context>
## Code to Read at Plan-Time

- `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php` — bestaande schema die we uitbreiden met `direction` + `event_id` + nullable tenant-FKs
- `database/migrations/2026_05_16_000001_add_webhook_callback_to_consumers_table.php` — `consumers.webhook_callback_url` + `consumers.webhook_callback_secret` (bestaand, Phase 5a-01)
- `app/Models/Connection.php` — voor `administratie_id`-attribute + scope-method
- `app/Models/PassThroughCall.php` — voor `direction` + `event_id`-attributes + scopes
- `bootstrap/app.php` — voor `routes/webhooks.php`-registratie
- `config/services.php` — voor `services.snelstart.webhook_*`-keys
- `packages/snelstart-api/src/` — SDK voor eventuele helper-classes (verifier-pattern uit Mollie-SDK is een copy-target maar Snelstart-SDK heeft 'm nog niet)

</code_context>

<deferred>
## Deferred to Plan-Time of Later Phases

- **Phase 5c plan-phase:** concretiseer aantal plans (verwacht 4-5: migration + verifier + controller + job + integration-test). Bij `/gsd-plan-phase 5c`.
- **Phase 9 (Filament admin):** replay-knop voor gefaalde inbound events + view voor `pass_through_calls?direction=inbound`.
- **Na partner-respons:**
  - HMAC-header + algo: env-vars updaten in Laravel Cloud, default in `config/services.php` aanpassen.
  - Veldnaam `administratie_id`: hernoemen via volg-migratie als payload-key anders is.
  - Retry-policy: beslis of OData-safety-net-job moet, en met welke `?modifiedSince`-cadence.
- **OData-safety-net (optioneel, post-MVP):** scheduled job per Connection die `Relaties + Verkoopfacturen ?modifiedSince=<last_inbound_event_at>` haalt om gaten te dichten.

</deferred>

---

## Volgende stap

1. **Partner-respons binnen 2026-05-17:** 4/5 aannames bevestigd (🔒 #1 / #2 / #3 / #5). Vraag #4 (retry-policy) is niet beantwoord — blijft ❓ blocked.
2. **CONTEXT.md gesynced** op 2026-05-17 — ❓ → 🔒 voor #1 / #2 / #3 / #5, history van originele aannames bewaard.
3. **Twee paden voor `/gsd-plan-phase 5c`:**
   - **(a) Plannen mét defensieve #4-aanname:** acceptabel als (i) de OData-safety-net-job als optionele extra plan-taak in scope blijft en (ii) idempotency via `event_id`-unique-index hard wordt afgedwongen. Code blijft correct ongeacht werkelijke retry-policy; alleen de safety-net-job is potentieel overbodig of juist verplicht achteraf.
   - **(b) Follow-up verzenden** naar `partner@snelstart.nl` met scherpere vraag (retry-aantal + backoff-curve + DLQ ja/nee) en wachten. Geen rework-risico, maar partner-respons-cyclus stagneert plan-phase.

Migratie-veldnamen (administratieId UUID) en HMAC-protocol (X-SnelStart-Signature + HMAC-SHA256) zijn geconfirmeerd; rework-risico op die fronten is weg.
