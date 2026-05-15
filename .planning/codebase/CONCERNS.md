# Codebase Concerns

**Analysis Date:** 2026-05-15

Concerns en gap-analyse op `feat/v02-account-subscriptions` (v0.2 milestone, ~50% door). v0.1 (Snelstart-SDK) is shipped; v0.2 is mid-build met 5 van 10 phases gelanded (Phase 2/3/4/5a/6) en 4 phases open (Phase 5b deels, Phase 5c blocked op partner, Phase 7 ready-to-execute, Phase 8/9 niet gestart). Dit document beschrijft wat er nu in de repo zit dat aandacht vereist — niet wat nog gepland staat.

## Tech Debt

**Webhook-routes erven `throttle:api` — geen `withoutMiddleware`-uitzondering:**
- Issue: `bootstrap/app.php:24-26` registreert `routes/webhooks.php` binnen `Route::middleware('api')`, en `bootstrap/app.php:30` prepended `throttle:api` aan de hele `api`-middlewaregroep. Géén van de webhook-routes (`/webhooks/mollie/{connection_id}`, `/cashier/webhook`, `/cashier/webhook/first-payment`, `/cashier/webhook/aftercare`) declareert `->withoutMiddleware(['throttle:api'])`.
- Files: `routes/webhooks.php:17-37`, `bootstrap/app.php:22-30`
- Impact: Bursting partners (Snelstart-batch-events, Mollie subscription-storms) raken de default `60/min/ip`-throttle — webhooks landen op 429 + Spatie webhook-server geeft op of repeats endeloos. Bij certificering met Snelstart (HUB-06 in `.planning/phases/05c-snelstart-webhook-handler/`) is dit een directe blocker zodra echte traffic begint.
- Fix-aanpak: Per webhook-route `->withoutMiddleware(['throttle:api'])`, of een dedicated `webhooks`-RateLimiter met hoger plafond in een `AppServiceProvider::boot()` `RateLimiter::for('webhooks', …)`-binding. Pak mee in Phase 5c-implementatie of als quick-task vóór productie.

**`v0.2-aanname` in MollieWebhookController over webhook-id-prefixes:**
- Issue: `MollieWebhookController:29-31` documenteert "alle webhook-id's zijn Payment-id's (`tr_*`)". Subscriptions (`sub_*`) en Refunds (`re_*`) triggeren in Mollie's Connect-flow ook een Payment-event, maar de huidige anti-spoof check `Mollie::client()->payments->get($payload['id'])` faalt voor andere id-prefixes.
- Files: `app/Http/Controllers/Webhooks/MollieWebhookController.php:75-91`
- Impact: Bij v0.3+ event-types die geen Payment-id meesturen (bv. `chargeback.created` met `chargeback_id`, of mandate-events) crasht de spoof-check op `400 resource_ownership_failed`. Phase 7 (Account-level subscriptions) gaat subscription-webhooks routeren — kan dat hier op stuk lopen?
- Fix-aanpak: Resource-type-detectie via id-prefix (`tr_` → payments, `sub_` → subscriptions, `re_` → refunds, `mdt_` → mandates) vóór de spoof-fetch; alternatief is Phase 7's `WebhookPayloadRouter` (07-05-PLAN) generaliseren naar alle resource-types.

**`EnsureEmeqAdminToken` is tijdelijke allowlist-middleware:**
- Issue: `app/Http/Middleware/EnsureEmeqAdminToken.php:12-18` zegt zelf dat dit een tijdelijke config-allowlist op `consumer_id`'s is, te vervangen door Phase 9's `is_emeq_staff`-boolean op `User`. Admin-billing-routes (`/v1/admin/billing/subscriptions`) zitten nu effectief achter een env-list — geen UI, geen audit, geen rotatie.
- Files: `app/Http/Middleware/EnsureEmeqAdminToken.php:20-36`, `routes/api.php:51-58`
- Impact: Admin-credentials zitten in `config/billing.php` (`admin_allowlist`-array). Niet schaalbaar voor >2 personen; geen makkelijke revoke. Werkt voor v0.2 maar moet bij Phase 9 (Filament) verdwijnen.
- Fix-aanpak: Phase 9 staat al gepland — `is_emeq_staff` op `User`-model + Filament `canAccessPanel()`-check vervangt allowlist. Niet zelf bouwen voor v0.2.

**Pint-baseline-drift (deferred uit v0.2-scope):**
- Issue: Vendor-published migrations in `database/migrations/2026_05_13_*`, `routes/web.php` (uit `0196e01`) en `packages/**` (gitignored, lees-clones) hebben formatting-drift ten opzichte van repo-wide Pint-config.
- Files: `database/migrations/2026_05_13_223626_create_personal_access_tokens_table.php`, `database/migrations/2026_05_13_223628_create_webhook_calls_table.php`, `database/migrations/2026_05_13_223629_add_attachments_to_webhook_calls_table.php`, `routes/web.php`
- Impact: `vendor/bin/pint --test` zou non-zero exit-en op deze files. Geen runtime-impact; CI/pre-commit kan struikelen wanneer ooit Pint-gating wordt aangezet.
- Fix-aanpak: Quick-task `pint-baseline-cleanup` of meepakken tijdens eerstvolgende migration-touchup. Genoemd in `.planning/STATE.md:111` als deferred.

**`packages/` is gitignored maar bestaat nog deels lokaal:**
- Issue: `composer.json:11-12` haalt `emeq/mollie-api` + `emeq/snelstart-api` via VCS-repos uit `composer.json:108-118`. Lokale `packages/`-directories (gitignored per `.gitignore:18` en `.ai/packages` rules) zijn lees-clones. Risico: een ontwikkelaar denkt nog dat een path-symlink werkt en commit composer.lock-mutaties.
- Files: `packages/` (gitignored — `mollie-api/`, `snelstart-api/` lees-clones), `.ai/packages` rule-block in `CLAUDE.md`
- Impact: Laravel Cloud-deploy breekt zodra `composer.lock` per ongeluk een `path`-dist krijgt (`packages/` bestaat niet op deploy-target).
- Fix-aanpak: Documentatie staat al in `CLAUDE.md` `.ai/packages` block. Bij elke `composer update emeq/*`-PR controleren dat `composer.lock`-dist een `git`-ref blijft en geen path. Eventueel `.git/hooks/pre-commit`-check.

**`emeq-mollie-api` GitHub-repo-description is stale:**
- Issue: De publieke repo `github.com/yusufkaracaburun/emeq-mollie-api` (sinds 2026-05-13) heeft een description die "Saloon v3" zegt, terwijl die keuze is gereverseerd naar wrap-`mollie/mollie-api-php`. STATE.md:126 markeert dit.
- Files: extern (GitHub repo metadata)
- Impact: Mensen die de repo browsen krijgen verkeerde verwachting van het pattern; geen runtime-impact.
- Fix-aanpak: Eerstvolgende push naar `emeq-mollie-api`-repo meepakken (gh repo edit). Genoemd in `.planning/STATE.md:126`.

**`refresh_token` rotation niet expliciet getest:**
- Issue: `MollieConnectOAuthFlow:70-74` bewaart `$response['refresh_token'] ?? $connection->refresh_token` — als Mollie een rotated refresh_token meestuurt, wordt die opgeslagen, anders behoud. Geen test in `tests/Feature/OAuth/` die dat scenario expliciet dekt.
- Files: `app/OAuth/Mollie/MollieConnectOAuthFlow.php:54-78`, `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php` (47 incomplete-marker)
- Impact: Als Mollie ooit refresh_token-rotation aanzet en de SDK een edge-case heeft (bv. zelfde token teruggeven), zien we het pas in productie via 401's.
- Fix-aanpak: Test toevoegen die response-payload zonder + met rotated refresh_token roteert, of meepakken in Phase 7-test-suite.

## Known Bugs

Geen actieve bugs gerapporteerd. `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php:47` heeft een `markTestIncomplete` voor concurrent-refresh-race-simulatie — dat is een ontbrekende test, geen bug.

**Tests in incomplete/skipped state:**
- `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php:47` — `markTestIncomplete('Concurrent-refresh-race wordt getest in een aparte testcase met parallel-process simulatie.')` — race-condition op `Cache::lock("oauth:refresh:{$connection->id}", 30)` in `MollieConnectOAuthFlow:56-77` is alleen single-process bewezen.
- `tests/Feature/Documentation/ScrambleRouteDiscoveryTest.php:70` — `markTestSkipped(...)` (skip-condition niet geverifieerd).
- `tests/Integration/IntegrationTestCase.php:34` — `markTestSkipped(...)` voor `CASHIER_MOLLIE_KEY`-missing in `.env`; gracefull skip, geen runtime-bug.

## Security Considerations

**`subscription_id` op Connection: NIET encrypted (bewust):**
- Risk: `database/migrations/2026_05_14_000003_create_connections_table.php:29` declareert `subscription_id` als plain `string`. STATE.md:80 bevestigt: "Snelstart's `subscriptionId` is een tenant-UUID, niet zelf een secret".
- Files: `app/Models/Connection.php:53-65`, `database/migrations/2026_05_14_000003_create_connections_table.php:29`
- Current mitigation: `subscription_id` is een UUID-tenant-identifier, geen authenticator. `client_key` + `subscription_key` zijn wel encrypted en pas die zijn voldoende om Snelstart-OData calls te doen.
- Recommendations: Beslissing is gedocumenteerd (STATE.md decisions). Geen actie. Maar: als deze flag ooit verandert (provider levert ipv. tenant-UUID een opaak secret), dan moet dit veld encrypted worden + migratie.

**`metadata` JSON op Connection: NIET encrypted:**
- Risk: `database/migrations/2026_05_14_000003_create_connections_table.php:32` declareert `metadata` JSON nullable. `Connection.php:61` cast naar `array`. Geen `encrypted:array` cast.
- Files: `app/Models/Connection.php:53-65`, `database/migrations/2026_05_14_000003_create_connections_table.php:32`
- Current mitigation: `metadata` is bedoeld voor provider-specifieke overflow (zie comment in migratie). Geen geheime velden mogen erin geschreven worden.
- Recommendations: Code-review-gate op elke nieuwe `metadata`-write-locatie: nooit OAuth-tokens, API-keys of refresh-tokens in `metadata`. Overweeg `encrypted:array` cast als preventie, met kostje dat WHERE-queries op metadata-keys nooit kunnen werken.

**Webhook-secret hard-fail-guard is async-only:**
- Risk: `MollieWebhookController:41-46` doet `config('services.mollie.webhook_secret')`-check stap 0 (500 + audit). Goed. Cashier-webhook heeft eigen middleware `RequireCashierWebhookSecret` (registered `bootstrap/app.php:35`). Beide patronen verschillen.
- Files: `app/Http/Controllers/Webhooks/MollieWebhookController.php:41-46`, `app/Http/Middleware/RequireCashierWebhookSecret.php`
- Current mitigation: Twee verschillende implementations van dezelfde guard ("webhook-secret moet bestaan"). Snelstart-webhook (Phase 5c) staat nog in plan-fase; moet zelfde pattern krijgen.
- Recommendations: Generaliseer naar één middleware (`require.webhook.secret:mollie` / `:cashier` / `:snelstart`) zodra Phase 5c landt — voorkomt drift over 3 implementations.

**HMAC anti-correlation reeds afgedwongen, niet runtime-getest:**
- Risk: `.ai/rules/global.md` zegt "Webhook-secrets per Connection — niet één globale secret per Consumer". `consumers.webhook_callback_secret` is encrypted (per-Consumer outbound). `services.mollie.webhook_secret` is platform-wide inbound (Connect-flow betekent platform-signed). Het pattern werkt — inbound ≠ outbound — maar er is geen test die expliciet bewijst dat een fan-out-job het Consumer-secret gebruikt en NIET het partner-secret.
- Files: `app/Jobs/ForwardMollieWebhookToConsumer.php:43-47`, `app/Models/Consumer.php:21-28`
- Current mitigation: `useSecret((string) $consumer->webhook_callback_secret)` in `ForwardMollieWebhookToConsumer.php:46`. Visueel correct.
- Recommendations: Test toevoegen in `tests/Feature/Webhooks/` die assertet dat de signed Spatie-WebhookServer-call het Consumer-secret meeneemt — niet het Mollie-platform-secret. Geen kritieke gap, wel hygiëne.

**Pending OAuth-state replay-window: 30 min default:**
- Risk: `app/Http/Controllers/Api/V1/OAuth/InitController` creëert een pending Connection met `oauth_state` (48 chars) + `oauth_state_expires_at = now()->addMinutes(30)`. Een gestolen state binnen die window kan een echte callback simuleren.
- Files: `database/migrations/2026_05_15_000001_add_oauth_state_to_connections_table.php`, `app/Console/Commands/PruneOAuthPendingConnections.php`
- Current mitigation: State is 48-char random; CSRF-replay-tests zitten in `tests/Feature/Api/V1/OAuth/CallbackTest.php` (STATE.md:148 noemt 4 paden inc. replay).
- Recommendations: TTL is OK; geen actie. Bij Filament-admin (Phase 9) een dashboard om actieve pending-Connections te zien zou nice-to-have zijn.

**`User`-model nog niet uitgebreid voor Filament-admin:**
- Risk: `app/Models/User.php:13-32` is base Laravel User. Phase 9 (Filament) heeft `is_emeq_staff`-boolean nodig + `canAccessPanel()`-check. Tot dan loopt admin-access via `EnsureEmeqAdminToken` op Consumers, niet op Users.
- Files: `app/Models/User.php:13-32`, `app/Http/Middleware/EnsureEmeqAdminToken.php`
- Current mitigation: Hub is API-only Bearer-PAT (STATE.md:83); User-model alleen voor Filament-admin in Phase 9.
- Recommendations: Geen actie tot Phase 9. Bij Phase 9 plan-phase staat dit al in scope per `ROADMAP.md:276-284`.

## Performance Bottlenecks

**`throttle:api` op webhook-ingress — al genoemd onder Tech Debt.**

**Geen rate-limit op `/v1/oauth/mollie/init`:**
- Problem: Init-endpoint zit achter Sanctum + `ability:mollie:write`. Default `throttle:api` is 60/min per Consumer-PAT. Voor een legit init-flow is dat ruim, maar er is geen aparte limit op het pre-creëren van pending Connections.
- Files: `routes/api.php:36-39`, `app/Console/Commands/PruneOAuthPendingConnections.php`
- Cause: PAT-houder die `init` herhaaldelijk aanroept zonder callback te doorlopen, vult de `connections`-tabel met pending rows tot `PruneOAuthPendingConnections` (handmatige command) draait.
- Improvement path: Limit aantal pending-Connections per Account (bv. max 5) of cron-schedule `oauth:prune-pending` (STATE.md:108 zegt expliciet "géén cron per D-04" — bewuste keuze, maar herzien als pending-rows scale-issue worden).

**`MollieWebhookController` anti-spoof doet een Mollie API-call per webhook:**
- Problem: Stap 4 (`MollieWebhookController:84-91`) doet `Mollie::client()->payments->get($payload['id'])` voor elke binnenkomende webhook om resource-ownership te bevestigen. Dat is een extra Mollie API-call per webhook + telt mee voor Mollie's rate-limits per Connect-token.
- Files: `app/Http/Controllers/Webhooks/MollieWebhookController.php:83-91`
- Cause: Bewuste anti-spoof-keuze (D-08 in 05a-CONTEXT). Webhook signed namens platform, niet namens Connection — fetch bewijst dat het resource bij déze Connection hoort.
- Improvement path: Geen — security trumps performance hier. Maar monitoring: als Mollie-Connect-rate-limits per token raken (Phase 7 met veel subscription-webhooks), overwegen om de fetch async te maken (post-202 in een job) met retries.

## Fragile Areas

**Cashier-Mollie compat — werkt, maar fragiel bij upstream-update:**
- Files: `composer.json:18` (`mollie/laravel-cashier-mollie: ^2.20`), `.docs/decisions/cashier-mollie-compat.md`, `app/Models/Consumer.php:11` (`Billable` trait)
- Why fragile: STATE.md:98 noemt "pad-a gekozen — werkt out-of-the-box op PHP 8.4 / Laravel 13". Maar Cashier-Mollie master-branch hangt nog op PHP 7.2 / Laravel 6-8 (.planning/STATE.md:125, memory). `^2.20` is een release-tak; bij elke Cashier-update is regressie mogelijk.
- Safe modification: Bij `composer update mollie/laravel-cashier-mollie` altijd integration-suite draaien (`composer test:integration`), niet alleen unit-suite. `phpunit.integration.xml` is bewust gescheiden — gebruik 'm.
- Test coverage: 3 happy-path integration-tests in `tests/Integration/Billing/CashierMollieSubscriptionFlowTest.php`; 237 unit-tests excluderen `@group integration`. Gap: failed-payment retry-flow is "vendor-coverage" gemarkeerd (SC-4 in Phase 6), niet door eigen tests bewezen.

**Webhook signature-verification per provider — drie patronen naast elkaar:**
- Files: `app/Http/Controllers/Webhooks/MollieWebhookController.php:48-60` (in-controller verify), `app/Http/Middleware/RequireCashierWebhookSecret.php` (middleware), Phase 5c plan (`.planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md:45-58` — config-driven HMAC nog onbekend algo/header)
- Why fragile: Drie verschillende plekken om "webhook is geldig" te beslissen. Drift-risico zodra signatures veranderen of een vierde provider (Moneybird, Exact) erbij komt.
- Safe modification: Vóór Phase 5c implementatie een gemeenschappelijke `VerifyWebhookSignature`-middleware-contract overwegen. Niet retroactief refactoren — pas bij volgende provider.
- Test coverage: Mollie-signatures via `tests/Feature/Webhooks/MollieWebhookSignatureTest.php` (266 lines, ruime coverage). Cashier-secret hard-fail via middleware-test. Snelstart-pad nog niet geschreven.

**`OAuthFlowRegistry` heeft één productie-implementatie + één fake:**
- Files: `app/OAuth/OAuthFlowRegistry.php`, `app/OAuth/Mollie/MollieConnectOAuthFlow.php`, `app/OAuth/Testing/FakeOAuthFlow.php`
- Why fragile: Pattern is in Phase 4 bewezen voor Mollie (SC-4 in `ROADMAP.md:105`), maar er is geen tweede productie-implementatie (Exact, Ibanity, Moneybird). Bij de tweede implementatie kunnen contract-aannames aan het licht komen (bv. `exchangeCode(Connection $connection, string $code)` vs. providers die meer params nodig hebben).
- Safe modification: Bij toevoegen tweede provider eerst `OAuthFlow`-contract opnieuw lezen + FakeOAuthFlow-test als reference; niet `MollieConnectOAuthFlow` kopiëren.
- Test coverage: Contract-test in `tests/Feature/OAuth/` dekt FakeOAuthFlow + MollieConnectOAuthFlow. Snelstart's `clientkey`-flow is bewust géén OAuthFlow-implementatie (key-based, geen authorize/callback).

**Migration `2026_05_15_074719_*` — 9 Cashier-tables in één wave:**
- Files: `database/migrations/2026_05_15_074719_create_{applied_coupons,credits,order_items,orders,payments,redeemed_coupons,refund_items,refunds,subscriptions}_table.php`, `2026_05_17_000001_align_subscriptions_owner_to_consumers.php`
- Why fragile: Cashier publiceert deze samen + één extra alignment-migration uit Phase 6. Bij Cashier-upgrade kunnen nieuwe published migrations afwijken; opnieuw publiceren met `php artisan vendor:publish --tag="cashier-migrations"` overschrijft als file-namen botsen.
- Safe modification: Bij Cashier-update eerst `vendor/mollie/laravel-cashier-mollie/database/migrations/`-diff bekijken vóór re-publish.
- Test coverage: Schema-asserties indirect via integration-suite; geen migratie-specifieke tests.

**`MollieWebhookController` audit-trail gebruikt 2 tabellen:**
- Files: `app/Http/Controllers/Webhooks/MollieWebhookController.php:94-99` (Spatie `WebhookCall::create()`), Phase 5b uses `pass_through_calls` (own table per ADR)
- Why fragile: Inkomende Mollie-webhooks landen in `webhook_calls` (Spatie), pass-through-calls landen in `pass_through_calls` (own). Phase 5c plant `pass_through_calls` met `direction=inbound` voor Snelstart-webhook-audit — geen `webhook_calls`. Drie tabel-conventies naast elkaar.
- Safe modification: Bij Phase 5c-implementatie expliciet documenteren waarom Mollie in `webhook_calls` blijft en Snelstart in `pass_through_calls`. Of: alles naar `pass_through_calls` migreren (breaking change).
- Test coverage: Beide auditpaden hebben dedicated tests; geen test asserteert beide tabellen tegelijk.

## Scaling Limits

**`pass_through_calls`-tabel grows unbounded:**
- Current capacity: Eén rij per consumer-API-call. Voor v0.2-volume (interne Emeq-apps) geen probleem.
- Limit: Bij v1.0+ commercieel scenario kan tabel snel naar miljoenen rijen groeien — geen partitioning, geen retention-policy.
- Files: `database/migrations/2026_05_15_000001_create_pass_through_calls_table.php`, `database/migrations/2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php`
- Scaling path: TTL-based archival job (bv. >90 dagen naar S3 + delete), of Postgres partition-by-month. Niet binnen v0.2-scope; backlog voor v1.0.

**`webhook_calls`-tabel (Spatie) grows unbounded:**
- Current capacity: Idem — één rij per binnenkomende webhook + één per failure-audit.
- Limit: Spatie's package biedt geen ingebouwde retention; alle attempts blijven staan.
- Files: `database/migrations/2026_05_13_223628_create_webhook_calls_table.php`, `database/migrations/2026_05_13_223629_add_attachments_to_webhook_calls_table.php`
- Scaling path: Spatie's eigen recommendation is een prune-command + scheduler. Niet uit-de-doos aan; backlog.

**Horizon-default queue-config:**
- Current capacity: Default Horizon-supervisor met Redis. Geen tuning voor partner-burst.
- Limit: Bij Snelstart-burst (Phase 5c fan-outs) of Mollie-subscription-storm (Phase 7) kunnen jobs queue-tijden van minuten oplopen.
- Files: `config/horizon.php` (default), Phase 5c-plan zegt `webhooks`-queue
- Scaling path: Aparte `webhooks`-queue met eigen supervisor + concurrency. Plan in `.planning/phases/05c-snelstart-webhook-handler/`.

## Dependencies at Risk

**`mollie/laravel-cashier-mollie ^2.20`:**
- Risk: Werkt nu op PHP 8.4 / Laravel 13 maar upstream-onderhoudstempo is onduidelijk (memory `reference_cashier_mollie_compat_risk.md`). Bij Laravel 14 ooit kan dit pad breken.
- Impact: Phase 6 (Cashier-flow voor Emeq→Consumers billing) breekt. Use-case B (Phase 7 Account-level subscriptions) gebruikt Cashier NIET — die heeft eigen `AccountSubscription`-model (STATE.md:100).
- Migration plan: Fork-and-patch in eigen vendor-dir of eigen subscription-laag voor use-case A. ADR-context in `.docs/decisions/cashier-mollie-compat.md`.

**`emeq/snelstart-api: dev-master`:**
- Risk: `composer.json:12` pinst de SDK op `dev-master`. Geen versie-tag; elke push naar master in `emeq-snelstart-api` propageert bij `composer update`.
- Impact: SDK-breaking change in master kan stilletjes in Hub landen.
- Migration plan: Tag stable versions in de SDK-repo (`v0.1.0`) + pin op `^0.1`. Memory `feedback_avoid_concurrent_gsd_sessions.md` raakt dit indirect (race-risico).

**`emeq/mollie-api: ^0.1.0-alpha.1`:**
- Risk: Alpha-version. SDK is in eigen sub-repo geshipped (Phase 2 voltooid 2026-05-14) maar nog niet stable-tagged.
- Impact: Bij API-breaking change in SDK kan Hub-side compat breken.
- Migration plan: Tag `v0.2.0` (stable) zodra v0.2-milestone close-out is. Pin op `^0.2`.

**`predis/predis ^3.4`:**
- Risk: Predis v3.x verving phpredis-conventie; nieuwe major-line. Compose-audit zou kunnen meppen op `php-http/discovery`-allow-plugin.
- Impact: Redis-cache / session / queue breken bij regressie.
- Migration plan: Pin niet onder `^3`, en bij `composer audit` errors expliciet ignoren via lijst `composer.json:101-104`.

**Audit-ignores in `composer.json`:**
- Risk: `composer.json:101-104` ignoreert 3 advisories (`PKSA-xnj5-w74d-6wmz`, `PKSA-5szq-gvrg-ttfq`, `PKSA-rnpm-45mg-w6ht`). Geen rationale in-code.
- Impact: Stille acceptatie van bekende CVE's.
- Migration plan: Documenteer in `.docs/decisions/` of `composer.json:audit.ignore-rationale` waarom elke ignore acceptabel is. Quarterly review.

## Missing Critical Features

**Phase 5b is gemerged + getest, maar geen webhook-handler voor Snelstart:**
- Problem: Phase 5b (Snelstart-pass-through API) is voltooid; consumers kunnen `/v1/snelstart/{path}` calls doen. Phase 5c (Snelstart webhook-handler) staat als plan klaar (5 plans) maar wacht op partner-respons (Gmail-draft `r-8836998535038336548` verzonden 2026-05-15).
- Blocks: Snelstart-productie-certificering. Snelstart vereist publieke webhook-URL bij certificeringsaanvraag (ROADMAP.md:175-177).
- Files: `.planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md` (5 ❓-aannames), `.docs/decisions/snelstart-certificering-pad.md`

**Phase 7 (Account-level subscriptions / use-case B) niet gestart:**
- Problem: 8 plans gepland (`07-01` t/m `07-08`), CONTEXT.md geland, maar geen executie. Multi-tenant `AccountSubscription`-model bestaat niet — Cashier dekt alleen use-case A.
- Blocks: SUB-02 requirement; Phase 8 Naschool-wiring NSCH-03 hangt niet direct af van Phase 7 (eindgebruikers betalen single-shot, geen subscription) maar v1.0 commercial readiness wel.
- Files: `.planning/phases/07-account-level-subscriptions-use-case-b/`

**Phase 9 (Filament admin-UI) niet gestart:**
- Problem: HUB-04 requirement. Geen admin-UI bestaat. Emeq-medewerkers moeten `tinker` of CLI-commands gebruiken voor Consumer-/Connection-management.
- Blocks: Operational tooling. `EnsureEmeqAdminToken`-allowlist-workaround draait tot Phase 9 landt.
- Files: `.planning/phases/09-filament-admin-ui-voor-emeq-medewerkers/` (2 plans pre-gepland)

**Phase 8 (Naschool wiring) niet gestart:**
- Problem: NSCH-01/02/03 requirements. Hub is technically klaar; Naschool-repo moet `emeq/snelstart-api` + `emeq/mollie-api` consumeren via Hub. Core-value-criterium ("twee providers live in één concrete consumer-feature").
- Blocks: v0.2 milestone-close zonder concrete consumer-bewijs.
- Files: `/Users/yusufkaracaburun/Sites/localhost/school-activities-hub/backend/` (extern, buiten deze workspace per memory `feedback_scope_emeq_hub.md`).

**Webhook-retention / cleanup tooling ontbreekt:**
- Problem: Geen artisan-command voor `webhook_calls` / `pass_through_calls`-tabel cleanup. Alleen `oauth:prune-pending` voor Connection-pending-state.
- Blocks: Lange-termijn DB-groei (zie Scaling Limits hierboven).
- Files: `app/Console/Commands/PruneOAuthPendingConnections.php` (enige bestaande prune-command)

**`/up`-healthcheck dekt niet alles:**
- Problem: `bootstrap/app.php:23` registreert `health: '/up'`. Default Laravel-implementatie checkt PHP + bootstrap. Geen partner-API-reachability, geen Horizon-queue-depth, geen Redis-roundtrip.
- Blocks: Productie-monitoring is blind voor partner-outages.
- Files: `bootstrap/app.php:23`, STATE.md:121 noemt "`spatie/laravel-health` implementeren" als backlog-item.

## Test Coverage Gaps

**Concurrent OAuth-refresh-race niet getest:**
- What's not tested: `MollieConnectOAuthFlow:56-77` gebruikt `Cache::lock("oauth:refresh:{$connection->id}", 30)->block(15, …)` om concurrent refreshes te serialiseren. De lock-semantiek zelf is niet end-to-end getest.
- Files: `tests/Feature/OAuth/MollieConnectOAuthFlowTest.php:47` (markTestIncomplete)
- Risk: Twee parallele requests die beide tegelijk refresh triggeren — als de lock niet werkt zoals verwacht (bv. Redis-lock-driver vs. database-driver), kan een dubbele refresh-call ontstaan + Mollie-refresh-token rotation laat één request met een ongeldige token zitten.
- Priority: Medium. Hit-rate is laag (alleen bij sub-5-min expiry-window onder load). Plannen als integration-test in Phase 7.

**Failed-payment-retry-flow (SC-4 Phase 6) is "vendor-coverage":**
- What's not tested: Cashier-Mollie's eigen retry-mechanisme bij failed first-payment. STATE.md:103 zegt "vendor-coverage" — Cashier's eigen tests dekken het.
- Files: `tests/Integration/Billing/CashierMollieSubscriptionFlowTest.php` (136 lines, happy paths)
- Risk: Bij Cashier-update kan retry-edge-case breken zonder dat onze suite het ziet.
- Priority: Low — Cashier-suite zit op upstream. Bij eerstvolgende cashier-update integration-test toevoegen die forced-fail simuleert.

**`/v1/snelstart/{path}`-edge-cases beperkt getest:**
- What's not tested: OData-fout-formaten met onverwachte error-shape, niet-UTF8-payload, 5xx-cascade. Phase 5b heeft 7 dedicated tests (`PassThroughEchoPingTest`, `PassThroughOdataRelatiesTest`, `PassThroughErrorMappingTest`, `PassThroughAuditNoSecretsTest`, `HeaderForwardingTest`) maar geen fuzzing.
- Files: `tests/Feature/Api/V1/Snelstart/*.php`
- Risk: Snelstart-foutcodes zijn deels gedocumenteerd, deels niet (memory: "geen verzonnen partner-features"). Onverwachte error-payload kan `UpstreamErrorMapper` doen crashen → 500 i.p.v. mapped 4xx/5xx.
- Priority: Medium. Bij Phase 5c (webhooks) is de Snelstart-API-shape weer onder de loep — extend Phase 5c-tests.

**Mollie-webhook resource-type beyond Payment niet getest:**
- What's not tested: `MollieWebhookController` met `sub_*`-id, `re_*`-id, `mdt_*`-id payloads.
- Files: `app/Http/Controllers/Webhooks/MollieWebhookController.php:75-91`, `tests/Feature/Webhooks/MollieWebhookSignatureTest.php`
- Risk: Phase 7 (Account-subscriptions) gaat subscription-webhooks routeren — eerste hit met `sub_*` id kan falen.
- Priority: High vóór Phase 7-executie. Phase 7-plan 07-05 (`WebhookPayloadRouter`) is bedoeld om dit op te lossen.

**Cross-Consumer-isolation: route-level bewezen, query-level bewezen, MAAR niet voor admin-billing:**
- What's not tested: Een Consumer-A's PAT met `billing:write,*`-ability + zonder `emeq.admin`-allowlist krijgt 403 (al getest). Maar: een wel-allowlisted Consumer die per ongeluk een `id`-parameter manipuleert om een andere Consumer's subscription te cancelen — alleen route-level binding op `id` voorkomt dat.
- Files: `app/Http/Controllers/Api/V1/Admin/Billing/SubscriptionController.php` (niet gelezen, maar in routes/api.php:54-57 met `{id}` parameter), `routes/api.php:51-58`
- Risk: Admin-route met `{id}` zonder ownership-check kan cross-Consumer-cancel toelaten.
- Priority: Medium. Test toevoegen die assertet dat admin-cancel respecteert dat de subscription-id moet matchen op een bekende Consumer's eigen Cashier-subscription (of expliciet niet — admin moet alle Consumers kunnen cancelen).

---

*Concerns audit: 2026-05-15*
