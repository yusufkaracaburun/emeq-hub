# Architecture audit — emeq-hub (first-party app layer) — 2026-06-15

**Scope:** whole-repo, first-party laag (`app/`, `routes/`, `config/`, `database/`, `bootstrap/`). `vendor/` + `packages/` (emeq-SDK's) buiten scope.
**Stack:** Laravel 13.9 · PHP 8.4 · Postgres 16 · Redis 7 · Horizon v5 · Sanctum v4 · Filament v4 · Pennant v1 · Cashier-Mollie
**Extensions loaded:** audit-architecture-laravel (strict, floor=🟡, api=🟠)
**Laravel mode:** full-stack (Filament/Livewire + 8 Blade-views) → api-only heuristics L14/L16/L18 grotendeels moot (routes al `/v1/`-prefixed, Sanctum geconfigureerd)
**Tools ingested:** php artisan about ✓ · Larastan ✗ (niet geïnstalleerd) · composer outdated ✗
**Files walked:** ~135 app PHP-files (~9.0k LOC app) · 22 migrations · **Auditor:** /ai:audit-architecture (ai-kit v1.40.0)

## Summary

- **🔴 0 · 🟠 4 · 🟡 7** findings, 0 🟢 (Laravel strict floor).
- Gezonde, goed-geteste codebase (391 tests groen, ADR-gedreven). **Geen blockers.** Geen mass-assignment-gat, geen layering-leak, geen swallowed-exception, geen ongeauthde mutating route, geen N+1.
- **Top 3 thema's:**
  1. **Provider-as-magic-string** — géén `Provider` enum; 40 losse `'mollie'`/`'snelstart'`-string-vergelijkingen. Dit is de gemeenschappelijke wortel onder de OCP-if-ladders, de parallel-duplicatie én de "nieuwe provider = shotgun surgery". Schaalt slecht: roadmap voegt Moneybird/Exact/Ibanity toe.
  2. **Provider-as schaalt-niet duplicatie** — Mollie- en Snelstart-paden zijn parallel gekopieerd (resolve-middleware, pass-through-serializer, content-type-guard, webhook-fan-out-jobs). 🟡 bij 2 providers, wordt 🟠 bij provider 3/4/5.
  3. **God-Filament-page** — `OnboardConsumer` (360 LOC, `submit()` 96 LOC, untyped `$data`-array) draagt te veel verantwoordelijkheden.

**Gecorrigeerde false-positives uit de eerste walk** (geverifieerd, géén finding):
`L22` mass-assignment — modellen gebruiken PHP-8 attribuut-syntax `#[Fillable([...])]` (Laravel 13), niet de property-vorm → fillable correct begrensd. · `FakeOAuthFlow` "dead" — gebruikt in 3 testbestanden. · `L19` queue=sync — default is `database`, `.env.example`=redis. · `MollieConnectionContext` "dead" — breed gebruikt. · `L11` migration-`down()` — alle 22 hebben een `down()`.

---

## 1. Design patterns

- **app/Http/Controllers/Api/V1/Mollie/Connect/AbstractMollieConnectPassThroughController.php:109** · 🟡 · `[laravel L9]` `handle()` ~100 LOC bundelt ability-guard + 415-content-type-guard + SDK-dispatch + exception-mapping + audit-write · evidence: methode loopt 109→210 · fix: extract `guardAbility()` / `validateContentType()` / `writeAuditLog()` of pipeline-middleware.
- De duplicatie-kant van dit patroon (twee bijna-identieke `handle()`-implementaties merchant vs Connect) → zie §3 (DRY).
- Overig: patronen verdienen hun plek (OAuthFlowRegistry, ProviderCredentialDescriptor zijn legitieme seams; bewuste keuze géén Repository — ADR-gedekt). Geen reflex-patronen.

## 2. SOLID

- **app/Filament/Pages/OnboardConsumer.php:1** · 🟠 · `[laravel L2-adjacent / SRP]` God-page 360 LOC: form-schema + submit-orchestratie + 3 provider-option-builders + connection-payload-builder + PAT-cache + notificaties. Verandert om ≥4 redenen · fix: extract `OnboardConsumerForm` (schema) + `OnboardConsumerOrchestrator` (action); page blijft dunne Livewire-coördinator.
- **app/Filament/Pages/OnboardConsumer.php:188** · 🟠 · `[laravel L9]` `submit()` 96 LOC (188→283): abilities + webhook-secret-gen + payload-build + completeness-validate + service-call + 3 exception-types + Cache-flash + notify + redirect · fix: split error-handling + secret-flash + post-hook.
- **app/Filament/Pages/OnboardConsumer.php:317** · 🟠 · `[OCP]` `buildConnectionPayload()` `if ($provider === 'snelstart') {...} else {Mollie default}` — groeit per provider · fix: provider-keyed factory/registry op basis van `ProviderCredentialDescriptor`. (Symptoom van A1, zie §9.)

## 3. DRY (knowledge-duplication)

Root-cause: **provider-axis copy-paste** — elke provider krijgt een eigen kopie i.p.v. één geparametriseerde abstractie. Affected paths:

- **app/Http/Middleware/ResolveMollieAccount.php:24** ↔ **app/Http/Middleware/ResolveSnelstartAccount.php:24** · 🟠 · header-validatie + Account-query + Connection-query identiek; verschilt alleen in provider-constant + resolver-binding-stijl (`MollieConnectionContext::set()` vs `app()->instance()`) · fix: abstracte `ResolveProviderAccount` met provider+resolver als ctor-params.
- **.../Mollie/AbstractMolliePassThroughController.php:134** ↔ **.../Connect/AbstractMollieConnectPassThroughController.php:220** · 🟡 · `resourceToArray()` verbatim gedupliceerd · fix: shared `MollieResourceSerializer`.
- **.../Snelstart/PassThroughController.php:50** ↔ **.../Mollie/AbstractMolliePassThroughController.php:59** · 🟡 · content-type-guard (`application/json`-prefix) + body-extract gedupliceerd · fix: `GuardJsonContentType`-middleware.
- **app/Jobs/ForwardMollieWebhookToConsumer.php** ↔ **app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php** · 🟡 · fan-out-kern identiek; verschilt in ctor-paramnaam + Snelstart-eventId-header + queue-binding · fix: één `ForwardWebhookToConsumerJob(Provider, Connection, payload, ?eventId)`.

## 4. YAGNI / dead-code

- **app/Support/Mollie/ConsumerIdempotencyKeyGenerator.php** · 🟡 · `[laravel L5]` nul callsites in `app/` én `tests/`; tests + runtime gebruiken de SDK-generator `Emeq\MollieApi\Idempotency\UuidV7IdempotencyKeyGenerator`. Vermoedelijk verdrongen door SDK-implementatie · evidence: grep callsites = alleen eigen class-definitie · fix: bevestig dat config geen `setIdempotencyKeyGenerator` op deze class wijst, dan verwijderen.
- Overig: geen verweesde scopes/policies/jobs, geen lege migration-`down()`.

## 5. Naming + comment-drift

- **app/Support/Snelstart/HeaderForwarder.php** + **UpstreamErrorMapper.php** vs **app/Support/Mollie/MollieHeaderForwarder.php** + **MollieUpstreamErrorMapper.php** · 🟡 · asymmetrische naamgeving: Mollie-prefix vs kale naam voor zelfde rol → leest als copy-paste-zonder-rename · fix: symmetrisch hernoemen (`SnelstartHeaderForwarder`, `SnelstartUpstreamErrorMapper`).
- **app/Mollie/MollieConnectionContext** (scoped-DI) vs **Snelstart credential-resolver** (`app()->instance()`-binding) · 🟡 · zelfde intentie, ander patroon + andere naam per provider · fix: documenteer in ADR waarom (of unificeer suffix).
- **routes/api.php:68** · 🟡 · comment refereert hard "Phase 13" i.p.v. ADR-link → verstaat na phase-pruning · fix: vervang door `.docs/decisions/`-verwijzing.

## 6. Coupling / cohesion

- **app/Http/Controllers/Api/V1/AccountSubscriptions/AccountSubscriptionController.php:142** · 🟠 · `[laravel L15]` `index()` doet `->accountSubscriptions()->latest()->get()` zonder paginatie → onbegrensde respons (al begrensd per account, vandaar 🟠 i.p.v. 🔴) · fix: `->paginate(15)` of `->cursorPaginate()`.
- "Nieuwe provider toevoegen" = shotgun surgery over ~6+ files (route + middleware + controller + resolver + config + Filament-views) — structurele kant van §3/§9. Gequantificeerd via de 40 magic-string-sites (A1).
- Geen N+1 (L1 schoon: fan-out-job laadt één account→consumer-chain, geen loop), geen god-module buiten OnboardConsumer, geen hidden temporal coupling.

## 7. Layer / dependency direction

**Covered, no findings.** Geen `use Illuminate\Http\Request` buiten `app/Http/` (L3 schoon). Geen cross-context reach-through (OAuth↛Billing, Mollie↛Snelstart ontkoppeld). Routes `/v1/`-prefixed (L16 ✓). OAuthFlowRegistry inverteert via container.

## 8. Error handling / failure modes

- **config/database.php:20** · 🟡 · `[laravel L20]` `env('DB_CONNECTION', 'sqlite')` — fallback naar sqlite als env ontbreekt in prod (Laravel-11+-skeleton-default). `.env.example` zet correct `pgsql` · fix: default → `pgsql`, of startup-assert dat non-local ≠ sqlite.
- Overig schoon: exception-mapping (`*UpstreamErrorMapper`), webhook-fan-out via Spatie `WebhookCall` (eigen retry/backoff → L8 bijt niet), geen swallowed catch, alle mutating routes auth-protected (L17 ✓), webhook-routes signature-verified.

## 9. Type safety / contract clarity

- **Provider als stringly-typed (geen enum)** · 🟠 · `[type-safety + OCP wortel]` géén `App\Enums\Provider`; **40** losse `'mollie'`/`'snelstart'`-string-vergelijkingen verspreid over Filament-resources, OAuth-actions, middleware, OnboardConsumer · evidence: `ConnectionResource.php:57/77`, `StartOAuthFlowAction.php:94`, `OnboardConsumer.php:152/157/317`, `ResolveMollieAccount.php:51` · fix: introduceer `enum Provider: string` (backed door `config/hub-providers.php`-keys), vervang vergelijkingen + `->where('provider', Provider::Mollie)`. **Dit is de hoogste-leverage fix**: ontmijnt §2-OCP, §3-DRY en §6-shotgun-surgery in één beweging vóór provider 3/4/5 landt.
- **app/Filament/Pages/OnboardConsumer.php:61** · 🟡 · `public array $data = []` (`array<string,mixed>`) — geneste form-state (`$data['connection']['provider']`) onttrekt zich aan static analysis · fix: typed DTO (`OnboardingFormData`).
- **app/Models/Connection.php:61** · 🟡 · `fingerprint()` leest `$this->{$primaryField}` uit descriptor zonder veld-bestaan-guard → silent null bij config-drift · fix: guard + expliciete exception bij misconfig.

---

## Tech-debt rolling table

| ID | Finding | Dim | Severity | Fix direction | Owner |
|----|---------|-----|----------|---------------|-------|
| A1 | Provider stringly-typed, geen `Provider` enum (40 magic-string sites) | 9/2/6 | 🟠 | `enum Provider: string` + vervang vergelijkingen | backend |
| A2 | Provider-axis parallel-duplicatie (middleware/serializer/guard/jobs) | 3 | 🟠 | geparametriseerde abstracties i.p.v. per-provider kopie | backend |
| A3 | `OnboardConsumer` god-page 360 LOC + `submit()` 96 LOC | 2 | 🟠 | extract Form + Orchestrator | backend |
| A4 | `AccountSubscriptionController::index` onbegrensde `->get()` | 6/8 | 🟠 | `->paginate()` | backend |
| A5 | Pass-through `handle()`-methodes ~100 LOC | 1 | 🟡 | extract guard/validate/audit | backend |
| A6 | `ConsumerIdempotencyKeyGenerator` ongebruikt (SDK-gen verdringt) | 4 | 🟡 | bevestig + verwijder | backend |
| A7 | `OnboardConsumer::$data` `array<string,mixed>` escape-hatch | 9 | 🟡 | typed DTO | backend |
| A8 | Support-class naam-asymmetrie Mollie-prefix vs kaal Snelstart | 5 | 🟡 | symmetrisch hernoemen | backend |
| A9 | `config/database.php` default `sqlite`-fallback | 8 | 🟡 | default → `pgsql` / assert | backend |
| A10 | `routes/api.php:68` hard "Phase 13"-comment | 5 | 🟡 | ADR-link i.p.v. phase-nr | backend |
| A11 | `Connection::fingerprint()` ongeguarde descriptor-veld-access | 9 | 🟡 | guard + exception | backend |

---

## Resolutie — fix-sessie 2026-06-15

Branch `fix/architecture-audit-a1-provider-enum`. Full suite 574/577 groen
(2 fails pre-existing en niet-gerelateerd: `UserResourceTest` roles-component,
`NoIndexHeaderTest` Redis-refused op `/up` in test-env).

| ID | Status | Commit / reden |
|----|--------|----------------|
| A1 | ✅ fixed | `edbd515` — `App\Enums\Provider` (HasLabel+HasColor), `Connection.provider`-cast, 40 sites opgeruimd, +`ProviderTest` |
| A3 | ✅ fixed | `4b14ac6` — `submit()` 95→38 LOC, 4 helpers (lichte variant) |
| A4 | ✅ fixed | `cb35ef4` — `index()` → `->paginate(25)` (additief `links`/`meta`) |
| A6 | ✅ fixed | `4e6658c` — `ConsumerIdempotencyKeyGenerator` verwijderd (supersedet D-06) |
| A9 | ✅ fixed | `436d766` — DB-fallback `sqlite`→`pgsql` |
| A10 | ✅ fixed | `436d766` — phase-nr-comment → ADR-link |
| A2 | ⏸️ deferred | naar Exact-koppeling (issue #3) — 2 intentioneel-divergente providers nu mergen is broos; de 3e provider forceert de juiste seam |
| A5 | ⏸️ deferred | fold in A2/Exact — zelfde pass-through-controllers |
| A8 | ⏸️ deferred | fold in A2/Exact — Snelstart-support-classes worden dan toch geraakt |
| A7 | ❎ wontfix | Filament bindt form-state aan een `public array` via `statePath` — een DTO vecht tegen het framework-model |
| A11 | ❎ wontfix | `fingerprint()` zit op het display-pad (Filament-tabel); een `throw` op config-drift crasht de admin-UI — silent-null badge is een acceptabel signaal |

Resterende open follow-up: A2/A5/A8 bij het oppakken van Exact Online (issue #3).

---

## Second-opinion re-walk — 2026-06-15

Onafhankelijke herhaling (3 parallelle agents, blind voor dit rapport) bevestigt de
conclusie: 0 blockers, alle fixes geland, A2/A5/A8 terecht deferred. Twee nieuwe minor
🟡-nits (observability + comment-drift). Zie
[`2026-06-15-emeq-hub-second-opinion-architecture-audit.md`](./2026-06-15-emeq-hub-second-opinion-architecture-audit.md).
