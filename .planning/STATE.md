---
gsd_state_version: 1.0
milestone: v0.2
milestone_name: — Mollie + Connect + Subscriptions + Hub-skeleton
status: executing
stopped_at: Phase 8 context gathered — 7 D-decisions, Hub-side scope (Filament onboard-wizard + StartOAuthFlowAction + partner-pages + Resource-hints); Naschool-internals out of scope (eigen repo)
last_updated: "2026-05-16T20:03:07.795Z"
last_activity: 2026-05-16 -- Phase 10 execution started
progress:
  total_phases: 10
  completed_phases: 7
  total_plans: 62
  completed_plans: 57
  percent: 70
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-14 na v0.1 milestone-close)

**Core value:** Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, en beide live in één concrete consumer-feature. v0.1 heeft Snelstart-deel bewezen; v0.2 zet Mollie + Connect + Subscriptions + Hub-skeleton op.
**Current focus:** Phase 10 — phase-9-polish-deferred-review-findings

## Current Position

Phase: 10 (phase-9-polish-deferred-review-findings) — EXECUTING
Plan: 1 of 6
Status: Executing Phase 10
Last activity: 2026-05-16 -- Phase 10 execution started

## Performance Metrics

**v0.1 Velocity:**

- Total plans completed: 30 (Phase 1)
- Total execution time: ~12 uur (2026-05-14 00:42 → 12:02 CEST)
- Sub-repo werk: snelstart-sdk submodule wiring + Pest-coverage + push

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 01-snelstart-sdk-finalize | 3 | ~16 min | ~5 min |
| 03-hub-skeleton | 5/5 | ~27 min | ~5 min |
| 03 | 5 | - | - |
| 05a | 6 | - | - |
| 05b | 5 | - | - |
| 06 | 8 | - | - |
| 07 | 8 | ~execution-wave-based (5 waves) | n/a (parallel-execution per wave) |
| 09 | 11 | - | - |

**Recent Trend:**

- Last 3 plans: 03-04 (ConnectionEncryptionTest + ConsumerAccountScopingTest, ~6 min, 2 commits, 2 nieuwe files, 11 tests groen); 03-03 (PingController + /v1/ping + PingTest + SanctumAbilityTest, ~5 min, 3 commits, 3 nieuwe + 1 modified file, 6 nieuwe tests waarvan 1 incomplete); 03-05 (hub:consumer:create + DatabaseSeeder + acceptance-run, ~7 min, 4 commits, 2 nieuwe + 2 modified files, 5 nieuwe tests groen + 1 Rule-1-deviation voor User-seeder-idempotency)
- Trend v0.1: phase 1 sneller dan ingeschat (master-plan-aanname 30-60 min crash-fix; bleek NO CRASH REPRO)
- Trend v0.2 (Phase 3 closed): alle 5 plans binnen ~5-7 min/plan; PATTERNS.md analog-mapping + duidelijke `<read_first>`-sectie hielden context-switches minimaal; één Rule-1-deviation (DatabaseSeeder User-pad idempotency) die het plan-success-criterion afdwong bovenop de plan-action

| Phase 03 P03-05 | 7 | 4 tasks | 4 files |

*Updated 2026-05-14 — plan 03-05 voltooid; Phase 03 volledig afgerond.*
| Phase 04 P01 | 25 | 2 tasks | 6 files |
| Phase 04 P02 | ~15 min | 2 tasks | 6 files |
| Phase 04 P03 | 12min | 2 tasks | 6 files |
| Phase 04 P04 | ~12 min | 2 tasks | 6 files |
| Phase 04 P05 | ~10 min | 2 tasks | 2 files + acceptance |

## Accumulated Context

### Decisions

Decisions zijn gelogd in PROJECT.md Key Decisions table. Decisions die uit v0.1 zijn gekomen:

- ✅ Drop Saloon `MockClient`-pipeline voor exception-mapping tests; PHPUnit-mocks op `Response` zijn cleaner (01-02)
- ✅ VCS-distributie zonder auth voor publieke SDKs is voldoende (01-03)
- ✅ `Dto/` + `Resources/` leeg in Snelstart — `RawSnelstartRequest` + OData QueryBuilder dekken 96 endpoints
- ❌ **Reversed 2026-05-14:** Eigen Saloon-wrapper voor Mollie → vervangen door wrap `mollie/mollie-api-php` direct
- ❌ **Reversed 2026-05-14:** API-key auth voor Mollie in v0.1 → Mollie Connect vanaf v0.2 dag 1
- 🆕 **New 2026-05-14:** Subscriptions in v0.2 voor beide use-cases (Emeq→Consumers + Accounts→eindgebruikers)
- 🆕 **New 2026-05-14:** Mollie facade-alias = `EmeqMollie` (niet `Mollie`) i.v.m. collision met laravel-mollie
- 🆕 **New 2026-05-14 (03-01):** `connections.subscription_id` niet versleuteld — Snelstart's `subscriptionId` is een tenant-UUID, niet zelf een secret (alleen `client_key`/`subscription_key`/`access_token`/`refresh_token` krijgen `encrypted` cast)
- 🆕 **New 2026-05-14 (03-01):** Connection-factory default = Snelstart-shape; `forSnelstart()`/`forMollie()` als state-methodes (niet aparte factories) — bewijst SC-5 uit HUB-01
- 🆕 **New 2026-05-14 (03-02):** `App\Sanctum\TokenAbilities` als `final class` met `public const` i.p.v. `enum TokenAbility: string` — Sanctum vergelijkt ruwe strings via `tokenCan(...)`; enum-`->value`-roundtrip overbodig + matched de minimalistische repo-conventie (geen enums yet)
- 🆕 **New 2026-05-14 (03-02):** Geen `EnsureFrontendRequestsAreStateful`-middleware — Hub is API-only Bearer-PAT, geen SPA-cookies (PATTERNS.md regel 433)
- 🆕 **New 2026-05-14 (03-02):** `web`-guard + `users`-provider blijven naast `sanctum`/`consumers` — User-model is voor Filament admin in Phase 9, niet verwijderen
- 🆕 **New 2026-05-14 (03-04):** Encryption-at-rest is via `DB::table()->value()` bewezen op productiestack (echte `APP_KEY`, geen MockEncrypter) — Phase 5b mag erop bouwen dat een DB-dump geen plain credentials lekt
- 🆕 **New 2026-05-14 (03-04):** Cross-Consumer query-isolation is op Eloquent-laag bewezen voor zowel directe `Account::where('consumer_id', ...)` als de relatie-syntax `$consumer->accounts()` — Phase 5b's pass-through-API kan deze patterns zonder extra row-level filter veilig gebruiken
- 🆕 **New 2026-05-14 (03-03):** `PingController` is single-action `__invoke` retournerend plain array (Laravel cast't naar JSON); single-action gekozen i.p.v. resourceful controller voor één smoke-route — copy-target voor Phase 5b's `Snelstart\PassthroughController`
- 🆕 **New 2026-05-14 (03-03):** `SanctumAbilityTest::test_token_without_required_ability_is_rejected` blijft `markTestIncomplete` tot Phase 5b een route met `->middleware('ability:snelstart:read')` heeft — suite blijft groen (incomplete ≠ failed), placeholder wordt scherp ingevuld bij Phase 5b
- 🆕 **New 2026-05-14 (03-03):** `Tests\Feature\Api` sub-namespace voor HTTP-feature-tests (eigen directory `tests/Feature/Api/`); `Tests\Feature` root blijft voor model-laag-bewijs (encryption + scoping)
- 🆕 **New 2026-05-14 (03-05):** `DatabaseSeeder` `User::factory()`-pad krijgt eigen `exists()`-guard — plan-action stond `User::factory()->create()` als-is maar dat crasht op `users.email_unique` bij 2× `db:seed` zonder `migrate:fresh`. Minimale Rule-1-fix die plan-acceptance-grep (`User::factory == 1`) én plan-success-criterion (idempotency) tegelijk respecteert
- 🆕 **New 2026-05-14 (03-05):** `hub:consumer:create`-command gebruikt property-stijl `protected $signature`/`$description` i.p.v. de nieuwere `#[Signature]`/`#[Description]`-attributes uit Laravel 12+ `make:command`-output — matched `routes/console.php`-conventie en blijft compatibel met acceptance-grep
- [Phase ?]: Phase 04-01: OAuthFlow-contract in Hub-laag (app/OAuth/Contracts/, D-13); FakeOAuthFlow test-fixture in app/OAuth/Testing/ runtime-namespace (D-12) — container-bindable in feature-tests
- [Phase ?]: MollieConnectOAuthFlow + Registry zonder declare(strict_types=1) — Hub-tree-conventie wint
- [Phase ?]: MOLLIE_CONNECT_*-env-keys in eigen .env.example-blok, gescheiden van MOLLIE_PARTNER_* (verschillende rollen)
- [Phase ?]: OAuthFlowRegistry::for() gooit InvalidArgumentException met NL-message
- [Phase ?]: Plan 04-03: VCS-install van emeq/mollie-api met ^0.1.0-alpha.1 tag (repo default branch is feat/foundation, dev-master zou hebben gefaald)
- [Phase ?]: Plan 04-03: scoped(MollieConnectionContext) + bind(SDK-contract -> HubMollieCredentialResolver) in AppServiceProvider — D-16 ingelost
- 🆕 **New 2026-05-15 (06-01)**: Cashier-Mollie pad-a gekozen — `mollie/laravel-cashier-mollie ^2.20.1` werkt out-of-the-box op PHP 8.4 / Laravel 13; geen fork of eigen subscription-laag nodig voor use-case A
- 🆕 **New 2026-05-15 (06-02)**: Cashier-Mollie v2.x publiceerd 9 migrations (NIET 10 — geen `subscription_items`; gebruikt `orders` + `order_items` pattern). Schema-velden `status` / `mollie_subscription_id` / `mollie_mandate_id` op `subscriptions`-table bestaan ook niet — Cashier derived deze via `active()` / `cancelled()` / `onTrial()` / `onGracePeriod()` / `ended()` accessors
- 🆕 **New 2026-05-15 (06-03)**: `Billable` trait op `Consumer` (NIET op `Account`). Account-level subscriptions (use-case B / Phase 7) krijgen een eigen `AccountSubscription`-model — Cashier is single-tenant en past niet bij multi-tenant Connect-flow
- 🆕 **New 2026-05-15 (06-04)**: Plan-storage config-driven via `config/billing-plans.php` (NIET via Cashier's Plan-model). `App\Billing\PlanResolver::find(string): array` retourneert plan-config + gooit `UnknownPlanException` op missing keys
- 🆕 **New 2026-05-15 (06-05)**: 3 billing-routes met dual-ability-pattern (`ability:billing:read,billing:write,*`) — consumer-read voor eigen subscription, admin-create/cancel achter `EnsureEmeqAdminToken`-middleware met env-driven allowlist
- 🆕 **New 2026-05-15 (06-06)**: Cashier-webhook landt op `/cashier/webhook*` (3 routes; default + aftercare + first-payment) met stap-0 hard-fail-guard `RequireCashierWebhookSecret` (zelfde pattern als Phase 5a D-08 stap 1 voor Mollie-Connect-webhook). `Cashier::ignoreRoutes()` in `AppServiceProvider` voorkomt auto-registratie van Cashier-routes; eigen bindings via routes/webhooks.php
- 🆕 **New 2026-05-15 (06-07)**: Integration-tests gescheiden via `phpunit.integration.xml` + `@group integration` + `IntegrationTestCase`. Default suite (`php artisan test`) sluit integration-group uit; `composer test:integration` runt apart. Tests skippen graceful zonder `CASHIER_MOLLIE_KEY` in `.env`
- 🆕 **New 2026-05-15 (07-02)**: Self-transitions in `StateTransitions::assertTransition()` zijn idempotent — geen exception. Reden: webhook-replay-safety (Mollie-resync mag dezelfde status opnieuw zetten zonder te crashen). Niet in CONTEXT D-04 expliciet; toegevoegd tijdens exhaustive-matrix-test-design
- 🆕 **New 2026-05-15 (07-03)**: `AccountSubscriptionManager` doet `Mollie::client()` per call (geen cached client-instance) — `HubMollieCredentialResolver` leest `MollieConnectionContext` bij elke `client()`-call → per-tenant fresh credentials zonder leak-risk. `amount_value` cast naar `'string'` defensief (Mollie-decimal-shape behouden)
- 🆕 **New 2026-05-15 (07-04)**: Lege list (HTTP 200 `data: []`) op `GET /v1/account-subscriptions?account_external_id=<vreemde Consumer>` i.p.v. 404 — info-disclosure-pattern uit Phase 5a. `HandlesAccountSubscriptionRequests`-trait dedupliceert `findOwnedSubscription`/`notFound`/`stateConflict`/`mollieError`/`auditCall` over de 3 controllers
- 🆕 **New 2026-05-15 (07-04, MEDIUM #3)**: Scope-niveau van mutate-endpoints (`pause`/`resume`/`destroy`) = **per-Consumer**, niet per-Account. Cross-Consumer = 404, same-Consumer-other-Account = 200. Vastgelegd in ADR `.docs/decisions/account-subscriptions.md` §Scope-niveau. Rationale: Sanctum PAT-scope is per-Consumer; per-Account-scope zou Sanctum-misbruik of UX-regressie vereisen
- 🆕 **New 2026-05-15 (07-05)**: `final`-keyword verwijderd op `AccountSubscriptionManager` + `PaymentWebhookHandler` + `SubscriptionWebhookHandler` voor Mockery-spies in unit-tests (Rule 3 deviation). `WebhookHandlerResult` als value-object met `shouldAudit()` + `shouldFanOut()` introspectie — `MollieWebhookController` blijft single-source-of-truth voor 5a-flow-volgorde (D-18)
- 🆕 **New 2026-05-15 (07-07)**: Aparte `AccountSubscriptionIntegrationTestCase` i.p.v. Phase 6's `IntegrationTestCase` hergebruiken (vermijdt `config('mollie.key')` cross-contamination tussen Cashier en Connect-OAuth). `Connection.expires_at = now()->addYear()` op test-Connection — voorkomt accidentele refresh-trigger tijdens slow-network test-runs
- 🆕 **New 2026-05-15 (07-08, MEDIUM #4)**: Integration-test-execution-keuze ⏭️ Pad B (default): "Geen Connect-token beschikbaar in CI/UAT — integration-test gedrukt naar manueel zodra token beschikbaar is. SC-1 vendor-coverage uitgesteld naar v0.2.1." Re-run-triggers: (1) Connect-test-token verkregen, (2) v0.2.1-release-window, (3) Naschool-go-live UAT
- 🆕 **New 2026-05-16 (09-01)**: `webhook_calls` krijgt 4 audit-kolommen (`direction` enum / `provider` string / `consumer_id` nullable-FK / `status` enum) — additive forward-only migratie; defaults (`incoming`/`processed`) maken bestaande Spatie-rijen valide. Phase 5c (Snelstart webhook-handler in progress) vult automatisch in. FK-constraint driver-conditional (Postgres-only — SQLite-`ALTER-TABLE-ADD-FK` `__temp__`-rebuild wist nieuwe kolommen).
- 🆕 **New 2026-05-16 (09-02)**: Filament v4.11.3 + Spatie laravel-permission 6.25.0 geïnstalleerd; `AdminPanelProvider` op `/admin` met `web`-guard + `discoverResources`. Filament-published assets (~4MB CSS/JS/fonts) gitignored — regenerate via `composer post-update-cmd`.
- 🆕 **New 2026-05-16 (09-03)**: RBAC via Spatie 2-rol-model (`super-admin` + `staff`) met 6 permissions; **`is_emeq_staff` boolean reversed (D-05) — komt nooit in een migratie**. User-model krijgt `HasRoles` + `implements FilamentUser` + `canAccessPanel(Panel)`. `EmeqStaffSeeder` env-driven idempotent (geen `app()->isProduction()`-guard — env-vars zijn de production-safe-knop).
- 🆕 **New 2026-05-16 (09-04)**: `ProviderCredentialDescriptor`-laag — `config/hub-providers.php` + `App\Support\ProviderCredentialDescriptor` (final readonly value-object). `Connection::fingerprint()` descriptor-aware refactor met **gedragsbehoud** (`ConnectionEncryptionTest` blijft groen zonder testfile-wijziging). Invariant: nieuwe provider = config-row + factory-update + Section, GEEN nieuwe Filament Resource-class.
- 🆕 **New 2026-05-16 (09-05..09-10)**: 7 Filament Resources gelanced (Consumer CRUD + Issue-PAT met 5 presets + Custom-mode / Connection read+revoke met no-secret-leak / Account read / WebhookCall viewer / AccountSubscription read+state-flip via manager-delegation / Cashier Subscription read+derived-status / User super-admin-gated via `manage-staff`-gate). 52 nieuwe feature-tests onder `tests/Feature/Admin/` (15 test-classes). Filament v4 nested namespace `App\Filament\Resources\<Plural>\<Resource>` is de gegenereerde shape; plan-paths uit v3-flat-stijl zijn opgeheven.
- 🆕 **New 2026-05-16 (09-11)**: Phase 9 ACCEPTANCE-document met evidence voor alle 10 SC's geschreven; ADR `.docs/decisions/filament-admin-panel.md` (gitignored — lokaal aanwezig, niet in git) met 5 D-decisions + 9 out-of-scope items + verification-path. ROADMAP/REQUIREMENTS/STATE gesynced; commit volgt na human-verify "approved".

### Pending Todos

- ✅ Phase 03 hub-skeleton voltooid (alle 5 plans + HUB-01 SC-1 t/m SC-5 bewezen)
- ✅ Phase 06 Cashier-Mollie integratie voltooid (8/8 plans, SUB-01 = Complete, 237 tests + integration-suite gescheiden, ACCEPTED 2026-05-15)
- ✅ **Phase 07 ACCEPTED 2026-05-15** — SUB-02 Complete; 8/8 plans + 11/11 D-32 + 337 tests bewezen
- ✅ **Phase 09 ACCEPTED 2026-05-16** — HUB-04 Complete; 11/11 plans + 391 tests / 1353 assertions groen; visuele review 6/6 checks groen + CR-01 fix met regressie-test
- 🛡 **Phase 09 — deferred code-review findings (REVIEW.md → tracking-todos voor v0.2.1)**:
  - **CR-02**: Spatie permissies (`view-consumers`, `manage-consumers`, etc.) geseed maar door géén enkele resource ge-enforced via `canAccess()`. D-05 is alleen actief op `manage-staff`-gate (UserResource). Action: voeg `canAccess()` toe aan 6 resources + tenant-scope-test voor SC-7
  - **WR-01**: UserResource laat laatste super-admin zichzelf naar staff downgraden → admin-paneel onbereikbaar. Action: guard in UserResource of pre-save-hook
  - **WR-02**: WebhookCallInfolist doet `json_encode()` op een text-kolom → dubbel-encoded exception-display
  - **WR-03**: assignRole Select-options niet server-side gevalideerd met `->in()` → mogelijk 500 bij geknoeide form-state
  - **WR-04**: EmeqStaffSeeder updatet password niet bij bestaande user (silent operatie-fout)
  - **WR-05**: UserForm password-veld combineert `dehydrateStateUsing` + `dehydrated(filled)` zonder edit-zonder-password-regressie-test
  - **WR-06**: Plain PAT-token zit in `wire:snapshot` + Alpine `x-data` (twee keer in HTTP-response tot dismiss)
  - **IN-01..04**: N+1 op Consumer-lookup per WebhookCall-rij; exception-message-leak in cancelAction; AdminPanelProvider::default() footgun; ProviderCredentialDescriptor::tryFor() utility
- 🆕 **Suggested next**: `/docs-sync` skill activeren vóór de Phase-9-commit-merge — Phase 9 raakte `app/Models/User.php` (HasRoles + FilamentUser), `app/Models/Connection.php` (descriptor-aware fingerprint), `app/Providers/AppServiceProvider.php` (manage-staff gate), nieuwe `config/hub-providers.php`, nieuwe `database/migrations/2026_05_19_*`, en alle `app/Filament/Resources/*` — documentatie-drift mogelijk in `.docs/` (CLAUDE.md / README / memory-files)
- `/gsd-discuss-phase 8` runnen na Phase-9-checkpoint-approval — Naschool wiring (Snelstart Stancl-resolver + EnrollmentConfirmed-job + Mollie-via-Hub checkout); depends on Phase 5a (al klaar) + Phase 4 (al klaar). Verse sessie + `/clear` aanbevolen — Phase 8 raakt `school-activities-hub/backend/`
- **Baseline Pint-drift cleanup (deferred)**: pre-Phase-6 scaffold-drift in `database/migrations/2026_05_13_*` + `routes/web.php` (uit `0196e01`) + gitignored `packages/**` — pakken bij toekomstige scaffold-touchup of dedicated quick-task `pint-baseline-cleanup`
- **Worktree-bootstrap-pattern (recurring)**: Claude Code's `isolation="worktree"` mist `.env` + `vendor/` → executor moet `cp ../../.env .env` + `ln -sf ../../vendor vendor` doen vóór tests. Voorgesteld backlog-item: `.claude/hooks/worktree-bootstrap.sh` voor automatische bootstrap
- **Composer autoload cache na worktree-merge**: handmatige `composer dump-autoload` was elke wave nodig om vendor/composer/autoload_classmap.php te refreshen. Voorgesteld: `.claude/hooks/post-merge.sh` automatisering
- **`/docs-sync`** runnen vóór Phase 7 start — Phase 6 hooks signaleerden documentation-drift na elke plan (config/services.php, routes/webhooks.php, namespace-additions) die niet in plan-scope opgepakt zijn
- `/gsd-plan-phase 5b` runnen — Snelstart-pass-through API (depends on Phase 3 only, parallelliseerbaar met Phase 4)
- Scramble (`dedoc/scramble`) `/docs/api` + Sanctum-bearer-extension is al gepubliceerd op deze branch — verifieer + commit als quick-task wanneer Phase 5a/5b begint
- Mollie-tak (Phase 2 + 4 + 5a) parallel werk; aparte sessie/working-copy
- **Out-of-scope cleanup (deferred-items.md):** Pint-drift op vendor-published `webhook_calls`-migrations + `routes/web.php` + `packages/**` — pakken bij Phase 5a/5b wanneer audit-logging / webhooks worden aangeraakt
- **`akaunting/laravel-firewall` implementeren** — Hub-edge bescherming (IP-blocking, abuse-throttle, country-rules) bovenop Sanctum/throttle. Past bij publieke `/webhooks/{provider}`-routes (Phase 5a/5b) + consumer-`/v1/*`. Wegen tegen Caddy-native middleware. Zie https://github.com/akaunting/laravel-firewall.
- **`spatie/laravel-activitylog` implementeren** — audit-trail bovenop bestaande `pass_through_calls`. Geschikt voor Consumer/Account/Connection-mutaties (CRUD-events, OAuth-status-overgangen, token-refresh). Aanvulling op pass-through-audit; geen vervanging. Zie https://spatie.be/docs/laravel-activitylog/v5/introduction.
- **`spatie/laravel-health` implementeren** — health-checks framework bovenop bestaande `/up`-smoke. Geschikt voor DB/Redis/Horizon/queue-depth/partner-API-reachability monitoring. Past bij ops-laag (Phase 7?) zodra meer dan basic up-check nodig is. Zie https://spatie.be/docs/laravel-health/v1/introduction.

### Blockers/Concerns

- **Cashier-Mollie compat-risico (v0.2)**: `mollie/laravel-cashier-mollie` master-branch hangt op PHP 7.2 / Laravel 6-8. Compatibiliteit met PHP 8.4 / Laravel 13 moet worden gecheckt in Phase 6 (use-case A integratie). Mogelijk fork-and-update of zelf subscription-laag bouwen. Phase 6 success criterion 1 vereist expliciete ADR met conclusie.
- **`yusufkaracaburun/emeq-mollie-api` repo description is stale**: zegt nog "Saloon v3" terwijl die keuze is gereverseerd. Wordt bijgewerkt bij eerste push in Phase 2.
- ~~**`.docs/partners/mollie/` bestaat nog niet**~~: ✅ **Resolved 2026-05-14 via quick `260514-tny`** — 11 references + indexed README geland. Phase 5a planning unblocked.

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 260514-iai | App-wide noindex/nofollow voor bots | 2026-05-14 | 0354074 | [260514-iai-app-wide-noindex-nofollow-voor-bots](./quick/260514-iai-app-wide-noindex-nofollow-voor-bots/) |
| 260514-ndk | Sluit Phase 3 SC-3-gap: refresh_token encryption-at-rest test | 2026-05-14 | d4c31d3 | [260514-ndk-sluit-sc-3-gap-voor-phase-3-voeg-refresh](./quick/260514-ndk-sluit-sc-3-gap-voor-phase-3-voeg-refresh/) |
| 260514-nup | Cleanup Phase 3 review-findings (BL-02 + WR-01 + WR-03) | 2026-05-14 | 1fcde28 | [260514-nup-cleanup-phase-3-review-findings-bl-02-ab](./quick/260514-nup-cleanup-phase-3-review-findings-bl-02-ab/) |
| 260514-qxk | Fix 05b CRITICAL findings (CR-01 + CR-02 + CR-03) | 2026-05-14 | 286dd99 | [260514-qxk-fix-05b-critical-findings-body-forwardin](./quick/260514-qxk-fix-05b-critical-findings-body-forwardin/) |
| 260514-tny | Import Mollie API docs (11 references + indexed README) als Phase 5a precondition — `.docs/` gitignored, plan-deviated van 4 commits naar SUMMARY-only | 2026-05-14 | (n/a — `.docs/` gitignored) | [260514-tny-import-mollie-api-docs-into-docs-partner](./quick/260514-tny-import-mollie-api-docs-into-docs-partner/) |
| 260515-c52 | Snelstart certificeringspad productie-route (Blade-voorbeeldpagina + ADR + 8 partner-vragen via Gmail) + Phase 5c CONTEXT.md prep met 5 ❓-aannames | 2026-05-15 | fcf64b7 (Blade; ADR + rate-limits in `.docs/` gitignored) | [260515-c52-snelstart-certificeringspad-productie-ro](./quick/260515-c52-snelstart-certificeringspad-productie-ro/) |
| 260515-vsy | Sync 05a-VERIFICATION.md frontmatter met HUMAN-UAT-resultaat (status → passed_with_deferred; #1 pass + #2/#3 blocked op Phase 8); audit-uat 5 → 0 open items | 2026-05-15 | 23c865b | (inline /gsd-fast — geen quick-dir) |
| 260516-dev | bin/dev bootstrap-script (up/down/reset/smoke/test/status) + .dev/ gitignore — dispatcht docker compose (db/redis/caddy) + php artisan serve + horizon op host, --reset doet migrate:fresh --seed (alleen local/testing) | 2026-05-16 | 8b58bb4 | (inline /gsd-fast — geen quick-dir) |
| 260516-qau | docs-sync drift fix — ADR `feature-flags-pennant-kill-switch.md` (Pennant + scope-design + 3 integratie-punten) + `.docs/README.md` indeling-tabel rij voor `strategy/`. Beide artifacts `.docs/` (gitignored), alleen PLAN.md + SUMMARY.md committed | 2026-05-16 | 71b7ac6 (PLAN.md; ADR + README in `.docs/` gitignored) | [260516-qau-docs-sync-drift-fix-adr-pennant-kill-swi](./quick/260516-qau-docs-sync-drift-fix-adr-pennant-kill-swi/) |

### Roadmap Evolution

- 2026-05-16 — **Phase 10 toegevoegd**: "Phase 9 polish — deferred review-findings" toegevoegd aan v0.2 milestone (depends on Phase 9). Volledige scope: 11 deferred bevindingen uit `09-REVIEW.md` (CR-02 BLOCKER-class + WR-01..06 + IN-01..04). `total_phases` 10→11. Branch: `chore/v021-phase9-polish`. Plan-bron: `09-REVIEW.md` + STATE Pending-Todos (post-merge-master-cleanup).
- 2026-05-16 — **Phase 09 plan 11 executed (PENDING checkpoint-approval)**: 11/11 plans afgerond met 10/10 SC-evidence in `09-11-ACCEPTANCE.md` (alle 10 success-criteria uit `09-CONTEXT.md` regels 234-246 bewezen via 52 nieuwe `tests/Feature/Admin/*`-tests + 1 audit-migratie + CLI-route-list). ADR `.docs/decisions/filament-admin-panel.md` (gitignored) met 5 D-decisions (D-01 7 resources + PassThroughCall out-of-scope / D-02 webhook_calls audit-kolommen / D-03 PAT presets+custom / D-04 ProviderCredentialDescriptor / D-05 Spatie RBAC + drop `is_emeq_staff`) + 9 out-of-scope items + verification-path. ROADMAP Phase 9 `[x]` + Progress 11/11 + 2026-05-16 + Goal "7 resources" (was "4 resources"); REQUIREMENTS HUB-04 `[x]` + Complete + uitgebreide validation-note; STATE counters bijgewerkt (completed_phases 6→7, total_plans 51→62, completed_plans 48→52, percent 60→84). 389 tests / 1343 assertions / 0 failed / 1 pre-existing incomplete (Phase 3-03 SanctumAbilityTest placeholder voor Phase 5b). Wacht op "approved" → planning-sync-commit + Phase 8 ontblokt naast Phase 5b/5c.
- 2026-05-14 — Phase 9 (Filament admin-UI voor Emeq-medewerkers) toegevoegd aan v0.2 milestone; HUB-04 toegevoegd aan REQUIREMENTS.md. Plan-bron: `.claude/plans/ow-dit-wil-ik-immutable-snowglobe.md`. Depends on Phase 3 + Phase 4; parallel met Phase 6/7.
- 2026-05-14 — Phase 5 gesplitst in 5a (Mollie) + 5b (Snelstart-pass-through) en HUB-05 toegevoegd aan REQUIREMENTS.md. Plan-bron: `.claude/plans/volgens-mij-is-snelstart-api-piped-parasol.md`. Reden: user wil consumer-app via `hub.emeq.nl` Snelstart-calls passen-doorzetten — was eerder niet expliciet in scope (Phase 8 deed Snelstart-direct-via-SDK in Naschool). Phase 5b depends on Phase 3 only; parallel met Phase 4 mogelijk.
- 2026-05-14 — Plan 03-01 voltooid: `consumers`/`accounts`/`connections` migrations + `Consumer`/`Account`/`Connection` Eloquent-models + factories. Fundatie voor HUB-01 staat; HUB-01 blijft Pending tot Phase 3 in z'n geheel geland is (Sanctum + ping + tests).
- 2026-05-14 — Plan 03-02 voltooid: Sanctum-guard + consumers-provider in `config/auth.php`, `apiPrefix: 'v1'` in `bootstrap/app.php`, `App\Sanctum\TokenAbilities` constants-class, `routes/api.php` skeleton. Auth-laag staat; plan 03-03 kan `/v1/ping` op deze stack landen.
- 2026-05-14 — Plan 03-04 voltooid (Wave 2b parallel afgehandeld vóór 03-03): `ConnectionEncryptionTest` (7 tests) + `ConsumerAccountScopingTest` (4 tests). HUB-01 SC-3 (geen raw credentials in `toArray()`) volledig bewezen; SC-4 query-laag bewezen — route-laag wacht op Phase 5b's pass-through-API.
- 2026-05-14 — Plan 03-03 voltooid: `routes/api.php` `/v1/ping` + `App\Http\Controllers\Api\V1\PingController` + `PingTest` (3 tests) + `SanctumAbilityTest` (2 passed + 1 incomplete-placeholder voor Phase 5b ability-middleware). HUB-01 SC-2 end-to-end bewezen (Bearer-PAT → Consumer-slug → 200-respond). Volledige suite 22/22 + 1 incomplete.
- 2026-05-14 — Plan 03-05 voltooid: `hub:consumer:create`-artisan-command (4 options, SUCCESS/INVALID/FAILURE-exit-codes, plain-token via `warn()`) + `DatabaseSeeder` met production-guard + idempotente demo-Consumer (naschool) + demo-Account (school1) + `HubConsumerCreateTest` (5 tests groen). HUB-01 SC-1 bewezen via tinker-verify; end-to-end smoke (CLI-token → `/v1/ping` in-process → `{"pong":true,"consumer":"smoke-test","abilities":["snelstart:read"]}`). Volledige suite 27 passed / 1 incomplete / 0 failed. **Phase 3 volledig afgerond.**
- 2026-05-14 — Plan 04-04 voltooid: `InitController` (POST `/v1/oauth/mollie/init`, Sanctum + `ability:mollie:write`, JSON-respons met `connection_id` + `redirect_url`, pre-created pending Connection met 48-char `oauth_state` + 30min TTL) + `CallbackController` (GET `/v1/oauth/mollie/callback`, publiek, state-verify, ruilt code in via `OAuthFlowRegistry`) + 7 feature-testpaden (3 InitTest happy/no-ability/cross-Consumer + 4 CallbackTest happy/tampered/expired/replay). Auto-deviation: Sanctum-middleware-aliassen `ability`/`abilities` toegevoegd aan `bootstrap/app.php` — canonical Sanctum-v4 setup die ontbrak. ROADMAP SC-1 + SC-2 + SC-5 bewezen. Volledige suite 127 passed / 1 incomplete / 0 failed.
- 2026-05-14 — Quick task 260514-qxk: Phase 5b CRITICAL-fixes (CR-01 415-guard non-JSON POST/PATCH + CR-02 `query_keys`-kolom replacement voor query-string PII-lekkage + CR-03 NULL fingerprint voor lege body). 4 commits in 2 RED/GREEN-cycli; migration `2026_05_15_000002_add_query_keys_to_pass_through_calls_table.php` + controller-hardening + 3 nieuwe tests + 1 update. 28/28 Snelstart-suite groen; 120/120 full suite. Phase 5b nu merge-ready voor zover CRITICAL-findings betreft (7 WR + 4 INFO blijven open).
- 2026-05-14 — Plan 04-05 voltooid + **Phase 04 volledig afgerond**: `PruneOAuthPendingConnections` artisan-command (`oauth:prune-pending` met `--dry-run`, D-09 handmatige cleanup, géén cron per D-04) + 2 tests (prunes-expired + dry-run-no-delete). BLOCKING phase-acceptance 8/8 groen: migrate, schema-check, route:list, container-bindings (`HubMollieCredentialResolver` + `MollieConnectOAuthFlow`), command-registratie, full suite 129/129, pint clean. ROADMAP-vs-CONTEXT delta SC-1: ROADMAP zegt `GET /v1/oauth/mollie/authorize?account=…`; implementatie volgt CONTEXT D-01/D-08 `POST /v1/oauth/mollie/init` met JSON-return — opgelost in commit `5208492` (ROADMAP SC-1 herschreven naar D-01/D-08-wording + Phase 4 hoofdcheckbox `[x]`). Alle 5 SC's (SC-1..SC-5) gedekt door 26 dedicated tests.
- 2026-05-15 — **Phase 06 voltooid**: 8/8 plans, SC-1 (compat-ADR pad-a) + SC-2 (integration-test in `tests/Integration/Billing/CashierMollieSubscriptionFlowTest`) + SC-3 (cred-isolation runtime-bewezen: `EmeqMollie` + `Mollie` + `Cashier` classes coexist; `CASHIER_MOLLIE_KEY` los van Connection.access_token uit Phase 4) bewezen. SC-4 (failed-payment retry) als vendor-coverage gemarkeerd. Tracking: `06-08-ACCEPTANCE.md` met 8/8 D-18 items + 18 confirmed decisions; ROADMAP Phase 6 `[x]` 8/8 plans + 2026-05-15; REQUIREMENTS SUB-01 = Complete. Standard suite 237 passed; integration-suite 4 skipped graceful zonder CASHIER_MOLLIE_KEY; Phase 5a regressie clean (49 Mollie-routes + 19 webhooks). Pint-fix in `routes/api.php` als Phase-6-attributable acceptance-finalisatie. Phase 7 (use-case B / Account-level subscriptions) ontblokt; Phase 9 (Filament admin-UI) ook ontblokt en parallel mogelijk.
- 2026-05-15 — **Phase 5c toegevoegd**: HUB-06 (Snelstart webhook-handler) toegevoegd aan REQUIREMENTS.md + ROADMAP.md (Phase 5c entry + Details + Progress + Coverage). Productie-certificeringsblocker — Snelstart vereist publieke webhook-URL bij certificeringsaanvraag. Plan-bron: quick-task 260515-c52 (Snelstart certificeringspad). CONTEXT.md `78b5cf7` met 5 ❓-aannames (HMAC-header + algo, secret-lifecycle, routing-veld, retry-policy, event-typen); plan-phase wacht op antwoord van `partner@snelstart.nl` op Gmail-draft `r-8836998535038336548` (verwacht ≤2026-05-26). `total_phases` 9→10.
- 2026-05-15 — **Phase 07 plan 08 executed (PENDING checkpoint-approval)**: 11/11 D-32 acceptance-criteria (10× ✅ + 1× ⏭️ Pad B integration-test-keuze wegens ontbrekende `MOLLIE_CONNECT_TEST_ACCESS_TOKEN`), ADR `.docs/decisions/account-subscriptions.md` met §Scope-niveau (MEDIUM #3 — per-Consumer mutate-scope) + §Integration-test-keuze (MEDIUM #4 — Pad B-rationale + re-run-triggers), ROADMAP Phase 7 `[x]` + Progress 8/8 + 2026-05-15, REQUIREMENTS SUB-02 `[x]` Complete. 337 tests / 1100 assertions groen; integration-test skipt graceful; Phase 5a regressie clean (`MollieWebhookIngressTest` + `MollieWebhookAntiSpoofingTest` 2/2). Wacht op "approved" om Phase 8 te ontblokken.
- 2026-05-14 — **Phase 02 audit-correctie**: ROADMAP-status was `[ ]` maar de 8 Phase-2 plans in `.planning/phases/02-emeq-mollie-api-foundation/` waren al volledig geshipped in de SDK-sub-repo (`emeq/mollie-api v0.1.0-alpha.1`). `git log --grep="02-"` toont alleen het plan-creatie-commit; geen execution-commits omdat `packages/` gitignored is in Hub. Gap was administratief, niet technisch. Bonus boven plan-scope: exception-mapper + idempotency-generator + webhook-signature-helper. Daarmee is Phase 5a NIET geblokkeerd op Phase 2 — MOLL-03 (Resources) valt in Phase 5a's eigen scope, niet Phase 2.

## Deferred Items

Items acknowledged en deferred bij milestone-close 2026-05-14:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| requirement | MOLL-01 (Mollie-SDK skeleton) | Herzien voor v0.2 (wrap mollie-api-php) | 2026-05-14 |
| requirement | MOLL-02 (Mollie auth + connector) | Herzien voor v0.2 (Connect OAuth-broker) | 2026-05-14 |
| requirement | MOLL-03 (Mollie resources) | Uitgebreid voor v0.2 (+ Mandates + Subscriptions) | 2026-05-14 |
| requirement | MOLL-04 (Mollie webhook verifier) | Herzien voor v0.2 (Connect-webhook signing) | 2026-05-14 |
| requirement | NSCH-01 (Composer-wiring + resolvers) | Mollie-deel verandert (via Hub); Snelstart-deel ongewijzigd | 2026-05-14 |
| requirement | NSCH-02 (SyncEnrollmentToSnelstartJob) | Ongewijzigd voor v0.2 | 2026-05-14 |
| requirement | NSCH-03 (Mollie checkout-flow) | Herzien voor v0.2 (via Hub-Connect) | 2026-05-14 |

## Session Continuity

Last session: 2026-05-16T14:57:43.975Z
Stopped at: Phase 8 context gathered — 7 D-decisions, Hub-side scope (Filament onboard-wizard + StartOAuthFlowAction + partner-pages + Resource-hints); Naschool-internals out of scope (eigen repo)
Resume file: .planning/phases/08-naschool-wiring-snelstart-mollie-via-hub/08-CONTEXT.md
Next action options:

  1. `/docs-sync` — Phase 9 raakte User/Connection/AppServiceProvider models en routes; doc-drift check vóór Phase 8 start (recommended eerst)
  2. `/gsd-plan-phase 5b` — Snelstart-pass-through API, parallel-pad zonder partner-dep
  3. `/gsd-discuss-phase 8` — Naschool wiring (Snelstart Stancl + EnrollmentConfirmed + Mollie-checkout); verse sessie + /clear aanbevolen
  4. v0.2.1 polish — CR-02 + WR-01..6 deferred Phase-9 findings (tracking-todos in STATE)
