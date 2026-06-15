# Architectuur-audit — emeq-hub — second-opinion re-walk — 2026-06-15

**Scope:** first-party laag (`app/`, `routes/`, `config/`, `database/`, `bootstrap/`). `vendor/` + `packages/` (emeq-SDK's) + `tests/` buiten scope.
**Stack:** Laravel 13.9 · PHP 8.4 · Postgres 16 · Redis 7 · Horizon v5 · Sanctum v4 · Filament v4 · Pennant v1 · Cashier-Mollie
**Extensions loaded:** audit-architecture-laravel (strict, floor=🟡, api=🟠)
**Mode:** **second-opinion** — onafhankelijke herhaling van [`2026-06-15-emeq-hub-architecture-audit.md`](./2026-06-15-emeq-hub-architecture-audit.md), bewust blind voor dat rapport. 3 parallelle `Explore`-agents per dimensie-cluster, hun claims daarna adversarieel geverifieerd tegen de broncode.
**Files walked:** ~140 app PHP-files (~9.4k LOC) · 22 migrations · **Auditor:** /ai:audit-architecture (ai-kit v1.40.0)

## Samenvatting

- **🔴 0 · 🟠 0 (nieuw) · 🟡 2 (nieuw)** — plus 3 al-bekende, bewust-deferde items (A2/A5/A8) opnieuw bevestigd als open.
- **Verdict: het originele rapport houdt stand.** Een onafhankelijke walk reproduceert dezelfde conclusie — gezonde, goed-geteste codebase, **geen blockers**. Geen swallowed-exception, geen layering-leak, geen mass-assignment-gat, geen ongeauthde mutating route, geen echte N+1, lijst-endpoints gepagineerd, API ge-throttled, jobs delegeren retry/backoff aan Spatie.
- **Net-new code sinds de eerste audit** (`PassThroughCallResource`-viewer, `UserResource` roles-fix) — apart geïnspecteerd, **schoon**: eager-loaded tabel-query (`->with(['consumer','account','connection'])`), geen rauwe token/secret-render, read-only immutable audit-viewer.

### Adversarieel verworpen (false-positives uit de agent-walk)

Deze drie zijn door een agent opgevoerd maar bij verificatie tegen de bron **geen finding**:

- **`ForwardMollieWebhookToConsumer.php:37` "N+1 🔴"** → **verworpen.** `handle()` draait één keer per webhook-event op één enkel `Connection`-model; `->account?->consumer` is een 2-query lazy-load, geen loop, geen collection-iteratie. Dit is geen N+1. (Origineel rapport + tweede agent bevestigen schoon.)
- **`AbstractMollieConnectPassThroughController.php:92` `dispatchMollieCall(): mixed` "L14"** → **verworpen.** Geeft SDK-objecten terug via `resourceToArray()`, geen Eloquent-model. `mixed` is eerlijk voor een generieke, provider-resource-agnostische pass-through-wrapper; L14 (Eloquent zonder JsonResource) bijt hier niet.
- **`OnboardConsumer::$data` `array<string,mixed>`** → al afgehandeld als **A7 wontfix** in het origineel (Filament bindt form-state aan een `public array` via `statePath`).

### Top-thema's

1. **Het origineel + de fix-sessie houden stand onder onafhankelijke toetsing.** Alle fixes (Provider-enum, paginatie, pgsql-fallback, dead-code-verwijdering) zijn geverifieerd geland.
2. **De deferde provider-axis-duplicatie (A2/A5/A8) is reëel en nog open** — opnieuw gevonden, terecht uitgesteld tot de 3e provider (Exact, issue #3).
3. **Twee nieuwe, minor 🟡-kandidaten** (observability + comment-drift), beide verdedigbaar-as-is.

---

## 1. Design patterns

**Bevestigt origineel — geen nieuwe findings.** Patronen verdienen hun plek (`OAuthFlowRegistry`, `ProviderCredentialDescriptor`, `AccountSubscriptionManager`-state-machine, `ConsumerOnboarding`-service). Geen reflex-patronen, geen pattern-naam-zonder-implementatie. Bewuste keuze géén Repository is ADR-gedekt.

## 2. SOLID

**Bevestigt origineel — geen nieuwe findings.** A3 (`OnboardConsumer::submit()`) is gesplitst (95→38 LOC, commit `4b14ac6`); `OnboardConsumer` blijft de grootste page (408 LOC) maar is schema-gedreven Filament-wizard, geen control-flow-knoop. `AccountSubscriptionManager` (306 LOC) zit op het plafond maar is bewust één state-machine-entrypoint. OCP-laders zijn weg na de Provider-enum (A1).

## 3. DRY (knowledge-duplication)

**Bevestigt A2/A5/A8 — open, deferred.** De provider-axis copy-paste bestaat nog:

- **`app/Http/Middleware/ResolveMollieAccount.php`** ↔ **`ResolveSnelstartAccount.php`** · 🟠 (A2) · identieke header-validatie + Account/Connection-lookup, verschilt alleen in provider-constant + resolver-binding-stijl.
- **`app/Support/Mollie/MollieHeaderForwarder.php`** ↔ **`app/Support/Snelstart/HeaderForwarder.php`** · 🟡 (A2/A8) · identiek iteratie-patroon, verschilt in header-whitelist (Snelstart voegt `If-Match`/`If-None-Match` toe).
- Pass-through `handle()`-lengte (A5) en support-class-naam-asymmetrie (A8) idem.

Geen nieuwe (non-provider) duplicatie gevonden. **Aanbeveling ongewijzigd:** niet nu mergen — 2 intentioneel-divergente paden samenvoegen is broos; de 3e provider (Exact #3) forceert de juiste seam.

## 4. YAGNI / dead-code

**Bevestigt origineel — geen nieuwe findings.** A6 (`ConsumerIdempotencyKeyGenerator`) is verwijderd (commit `4e6658c`). Onafhankelijke callsite-verificatie van `PartnerStatus`, `AccountSubscriptionManager`, alle Gates/Policies/Jobs: allemaal live. Geen verweesde exports.

## 5. Naming + comment-drift

- **`app/Providers/FeatureServiceProvider.php:14`** · 🟡 · **[N2, nieuw]** · comment "Set kill-switch via `Feature::deactivate(...)` of Filament admin (later)" — het "(later)" is een vage forward-looking note zonder ADR/issue-link · fix: link `.docs/decisions/feature-flags-pennant-kill-switch.md` of verwijder "(later)". Laag-risico; comment-hygiëne.
- A10 (`routes/api.php` hard phase-nummer) is al gefixt (`436d766`). A8 naam-asymmetrie: zie §3 (deferred).

## 6. Coupling / cohesion

**Bevestigt origineel — geen nieuwe findings.** A4 (`AccountSubscriptionController::index`) is gepagineerd (`->paginate(25)`, commit `cb35ef4`). Geen N+1 (de geclaimde fan-out-N+1 is een false-positive, zie samenvatting). "Nieuwe provider = shotgun surgery" is de structurele kant van A2 (deferred). Filament-tabellen eager-loaden hun relaties.

## 7. Layer / dependency direction

**Bevestigt origineel — covered, no findings.** Geen `Illuminate\Http\Request` buiten `app/Http/` (domein HTTP-agnostisch: `ConsumerOnboarding`, `AccountSubscriptionManager` nemen pure data). Geen cross-context reach-through (OAuth↛Billing, Mollie↛Snelstart ontkoppeld). Routes `/v1/`-prefixed.

## 8. Error handling / failure modes

- **`app/Jobs/ForwardMollieWebhookToConsumer.php:39`** + **`app/Jobs/Webhooks/ForwardSnelstartWebhookToConsumerJob.php`** · 🟡 · **[N1, nieuw]** · beide fan-out-jobs doen een silent `return` als de consumer geen `webhook_callback_url` heeft, zonder enige log-regel · evidence: `if ($consumer === null || ! $consumer->webhook_callback_url) { return; }` — geen `Log::debug`/audit · gevolg: "consumer krijgt geen webhooks" is niet te diagnosticeren uit de logs · fix: één `Log::debug()` met skip-reden (bijv. `consumer_webhook_callback_url_not_configured`). De skip zélf is intentioneel (gedocumenteerd: "geen retry"); alleen de observability ontbreekt. Verdedigbaar-as-is. Gedeelde wortel over beide jobs.
- Overig schoon: pass-through-controllers wrappen SDK-calls en mappen via `*UpstreamErrorMapper` (geen swallow, audit vóór render), webhook-signature-checks vangen specifiek (`InvalidSignatureException`), `UniqueConstraintViolationException` → 409, OAuth-token-refresh achter `Cache::lock()`, state-transities gevalideerd, `ConsumerOnboarding` in `DB::transaction()`. Geen rauwe token/secret in logs of exception-messages (fingerprint-only).

### Laravel reliability-heuristieken (L8–L21)

| Job | `$tries` | `$backoff` | `failed()`? |
|---|---|---|---|
| `ForwardMollieWebhookToConsumer` | — | — | nee |
| `ForwardSnelstartWebhookToConsumerJob` | — | — | nee |

- **L8** (job met retry zonder `failed()`) — **schoon.** Beide jobs declareren bewust géén `$tries`/`failed()`: ze zijn dunne fire-and-forget wrappers die retry/backoff aan Spatie webhook-server delegeren (eigen `WebhookCall`-retry-config). Geen silent permanent drop in de Laravel-queue zelf.
- **L11** (migration zonder `down()`) — **schoon.** Alle 22 migrations reversibel.
- **L13** (route zonder `throttle:`) — **schoon.** API achter `throttle:api` (prepend in `bootstrap/app.php`); Mollie-webhook erft `api`-group-throttle; Snelstart-webhook strip't `throttle:api` bewust (bursting → gemiste events), gedocumenteerd.
- **L15** (lijst zonder paginatie) — **schoon.** Enige index (`AccountSubscriptionController::index`) gepagineerd.
- **L17** (mutating route zonder auth) — **schoon.** Alle mutating `/v1/`-routes achter `auth:sanctum`; webhook-routes publiek-maar-signature-verified (intentioneel).
- **L19** (queue=sync non-local) — **schoon.** Default `database`, `.env.example`=redis.
- **L20** (db=sqlite non-local) — **schoon.** Default `pgsql` (A9-fix), `.env.example`=pgsql.
- **L21** (`TrimStrings`/`ConvertEmptyStringsToNull` verwijderd) — **schoon.** Niet verwijderd uit de global stack.

## 9. Type safety / contract clarity

**Bevestigt origineel — geen nieuwe findings.** A1 (`App\Enums\Provider`) is geland (`edbd515`): provider is getypeerd, magic-string-vergelijkingen opgeruimd (residu ~0: enum-definitie zelf + cashier-special-case). A7 (`$data`-array) en A11 (`fingerprint()`-guard) blijven wontfix (framework-gebonden / display-pad). De `mixed`-return van `dispatchMollieCall()` is verworpen als finding (zie samenvatting).

---

## Tech-debt rolling table (alleen nieuw)

| ID | Finding | Dim | Severity | Fix direction | Owner |
|----|---------|-----|----------|---------------|-------|
| N1 | Fan-out-jobs silent skip zonder log bij ontbrekende `webhook_callback_url` (Mollie + Snelstart) | 8 | 🟡 | `Log::debug()` met skip-reden in beide jobs | backend |
| N2 | `FeatureServiceProvider:14` vage "(later)"-comment zonder ADR-link | 5 | 🟡 | link Pennant-ADR of verwijder "(later)" | backend |

Al-bekende open items (niet hernummerd): **A2/A5/A8** — provider-axis-duplicatie, deferred tot Exact Online (issue #3). Zie het [originele rapport](./2026-06-15-emeq-hub-architecture-audit.md).

---

## Conclusie

De second-opinion is een **bevestiging, geen correctie**: het originele rapport en de fix-sessie houden stand onder een onafhankelijke walk. De enige nieuwe findings zijn twee minor 🟡-nits (N1 observability, N2 comment-drift), beide ~1-regel en verdedigbaar-as-is. De grootste resterende tech-debt (A2-cluster) is bekend en bewust uitgesteld tot de juiste forceer-moment (3e provider). **Geen actie vereist vóór productie.**
