# Phase 6: Cashier-Mollie integratie (use-case A) - Context

**Gathered:** 2026-05-15
**Status:** Ready for planning
**Discussion mode:** --auto (Claude koos recommended option per gray area, single-pass)

<domain>
## Phase Boundary

**Wat deze fase levert:** Emeq factureert recurring aan zijn eigen Consumers (Naschool, Planny, derde-partijen) via Emeq's eigen Mollie-account (NIET via Consumer-Connections — die zijn voor use-case B in Phase 7). Eindstand: een test-Consumer kan via Cashier-pattern een subscription starten op een test-plan, eerste Mandate + recurring Payment is zichtbaar in Emeq's eigen Mollie test-dashboard, en Mollie-webhooks updaten subscription-status correct.

**Strikt buiten scope:** Account-level subscriptions (= Phase 7), Naschool-specifieke wiring (= Phase 8), Filament UI voor billing-overview (= Phase 9), plan-changes/upgrades/downgrades (deferred), trial-periods (deferred), multi-currency (alleen EUR), Cashier admin-UI van Emeq-medewerkers (= Phase 9).

</domain>

<decisions>
## Implementation Decisions

### Compat-strategie voor `mollie/laravel-cashier-mollie`

- **D-01: Plan 1 = compat-check `mollie/laravel-cashier-mollie` tegen PHP 8.4 / Laravel 13.** Master-branch hangt historisch op PHP 7.2 / Laravel 6-8 (bekend risico — memory ref `reference_cashier_mollie_compat_risk.md`). Plan 1 levert een ADR in `.docs/decisions/cashier-mollie-compat.md` met conclusie + gekozen pad (a/b/c). Recommended-default route: probeer eerst `composer require mollie/laravel-cashier-mollie:^2 --dry-run` + check de `master` HEAD's `composer.json` PHP/Laravel constraints; rapporteer concreet welk pad nodig is.
- **D-02: Decision-tree voor plan-2 en verder (afhankelijk van plan-1 uitkomst):**
  - **Pad (a) — werkt out-of-box:** `composer require mollie/laravel-cashier-mollie`; gebruik officiële package; pin minor version.
  - **Pad (b) — werkt met patch/fork:** fork naar `github.com:yusufkaracaburun/laravel-cashier-mollie`, fix PHP/Laravel constraints + eventueel deprecated calls, pin via VCS-entry in `composer.json` (zelfde pattern als `emeq/snelstart-api` + `emeq/mollie-api`).
  - **Pad (c) — niet haalbaar:** bouw minimale eigen subscription-laag in `app/Billing/` met **dezelfde public interface** als Cashier (`Billable` trait + `Subscription` model + `Plan` resolver + `subscribed()`/`subscription()` methods). Reuse `emeq/mollie-api` voor Mollie API calls (Customers, Mandates, Subscriptions resources zijn al beschikbaar via pass-through pattern).
  - **Plan 2 t/m N worden pas geschreven na plan-1 conclusie** — Phase 6 PLAN.md wordt in twee waves:
    - Wave 1 = Plan 1 (compat-check ADR)
    - Plan 2-N worden door `gsd-plan-phase 6 --gaps` of een revisie-step toegevoegd zodra plan-1 conclusie binnen is.

### Billable target

- **D-03: `Billable` trait op `App\Models\Consumer`.** Een Consumer = SaaS-app van Emeq (Naschool, Planny). `Consumer->subscribed('naschool-license')` geeft true/false. Subscription-state hangt op `consumer_id`, niet op een per-Account-Connection (dit is bewust use-case A: Emeq's eigen Mollie, niet Consumer's eigen Connection).
- **D-04: NIET op `Account`.** Accounts (= eindklanten van Consumers, bv. één school) factureren via Phase 7's `AccountSubscription`-pattern via Connect — niet via Cashier. Reden: Cashier is single-tenant by design; multi-tenant subscription-state per Account-Connection vergt eigen model (Phase 7).

### Plan-definitie en storage

- **D-05: Plan-storage = config-driven via `config/billing-plans.php`.** Voor v0.2 zijn er max 2-3 plans (Naschool-license, Planny-license, eventueel een third-party tier). Config-array is immutable, version-controlled, geen admin-CRUD nodig in v0.2.
  - Schema: `['naschool-license' => ['amount' => ['currency' => 'EUR', 'value' => '49.00'], 'interval' => '1 month', 'description' => 'Naschool SaaS license — Emeq Hub access']]`.
  - Concrete prijzen → TBD bij plan-execute (komt uit business); CONTEXT.md commit't geen prijzen.
- **D-06: Plan-resolver** = simpele `App\Billing\PlanResolver::find(string $slug): array` die naar config('billing-plans.{$slug}') wijst. Geen Eloquent-model voor Plans in v0.2.

### Facade en credential-isolation

- **D-07: Cashier draait op Emeq's eigen Mollie API-key, niet op Consumer-Connection-tokens.** Cashier-config (`config/cashier.php` of merge in `config/mollie.php`) leest `EMEQ_MOLLIE_OWN_API_KEY` uit env. Connection-tokens (uit Phase 4 OAuth-broker) worden hier NIET geraakt — die zijn voor use-case B (Phase 7 / NSCH-03).
- **D-08: Cashier gebruikt Mollie facade (uit `laravel-mollie`, pad a/b) of `Emeq\MollieApi\Facades\Mollie` (pad c).** Plan 1 verifieert dat als pad (a) of (b) gekozen wordt, de `Mollie`-alias uit `laravel-mollie` niet botst met onze eigen `EmeqMollie`-alias (Phase 2 sluit dit al uit per success criterion 3, maar her-verify in compat-check).
- **D-09: Plan 1 voegt env-variable `EMEQ_MOLLIE_OWN_API_KEY` toe aan `.env.example`** met `test_xxx` placeholder + duidelijke comment dat deze key Emeq's EIGEN Mollie test-account is (geen Connect, geen Consumer).

### Webhook routing

- **D-10: Cashier webhook-endpoint blijft separaat van Connect-webhook ingress.** Cashier verwacht standaard `/cashier/webhook` (in Cashier-Mollie config). Dat blijft op die route — apart van Phase 5a's `/webhooks/mollie/{connection_id}` (Connect-scoped per Account). Reden:
  - Cashier-webhook gebruikt Emeq's eigen Mollie webhook-secret (anders dan platform-Connect-secret uit Phase 5a's D-08).
  - Cashier handle't de webhook intern (subscription state-machine); geen fan-out naar Consumer-callbacks nodig.
  - Geen audit-rij in `pass_through_calls` (dat is voor Connect-pass-through requests, niet voor Emeq's eigen billing).
- **D-11: Cashier-webhook-secret hard-fail guard.** Plan-N krijgt dezelfde guard als Phase 5a's MollieWebhookController stap-0: empty/null `CASHIER_WEBHOOK_SECRET` → 500 + audit. Reden: zelfde D-08 stap 1 reasoning (open ingress bij vergeten env-var).

### Test-strategie

- **D-12: Mollie test-mode key in `.env.example` + CI secret.** Pure unit tests (Billable trait gedrag, PlanResolver, webhook-signature) draaien in standaard suite met stubs (analoog aan Phase 5a's `StubsMollieClient`). Integration tests die echte Mollie test-mode API hitten worden gemarkeerd als `@group integration` (PHPUnit) + skippen in standaard `php artisan test`. CI run't de integration-group apart in een eigen workflow-step die alleen op main/PR draait, niet op feature-branches.
- **D-13: Bij path (c) — eigen subscription-laag — minimaal 80% test-coverage op de eigen `Billing/`-namespace.** Reden: dit is een nieuwe laag, niet een battle-tested package.

### Sanctum-abilities voor billing-routes

- **D-14: Twee nieuwe abilities** in `App\Sanctum\TokenAbilities`:
  - `billing:read` — Consumer mag eigen subscription-state lezen (`GET /v1/billing/subscription`).
  - `billing:write` — Emeq-admin-tokens mogen subscriptions create/cancel (geen Consumer kan zelf subscribe — dat is Emeq-onboarding/admin).
- **D-15: Routes:** `GET /v1/billing/subscription` (Consumer, `billing:read`) + `POST /v1/admin/billing/subscriptions` + `DELETE /v1/admin/billing/subscriptions/{id}` (Emeq-admin, `billing:write` + admin-guard van Phase 9, te scaffold'en in deze fase als simpele config-allowlist tot Phase 9 Filament-panel landt).

### Database schema

- **D-16: Cashier's eigen migrations** (pad a/b) draaien via `php artisan vendor:publish --tag=cashier-migrations` + `migrate`. Tabellen: `subscriptions`, `subscription_items` (of welke Cashier-versie ook publiceert). Consumer-FK wordt op `subscriptions.user_id` gemapt via Cashier-config (`cashier.user_model = App\Models\Consumer`).
- **D-17: Bij path (c) — eigen migration** voor `consumer_subscriptions` tabel. Schema: `id, consumer_id (FK), plan_slug, mollie_subscription_id, mollie_customer_id, mollie_mandate_id, status (active/paused/cancelled), starts_at, ends_at, created_at, updated_at`.

### Acceptance + done-criteria

- **D-18: Phase 6 is klaar als:**
  1. Compat-ADR `.docs/decisions/cashier-mollie-compat.md` exists met gekozen pad + onderbouwing.
  2. `Consumer->subscribed('naschool-license')` retourneert true voor een test-Consumer met actieve subscription (unit-test + integration-test).
  3. `POST /v1/admin/billing/subscriptions` voor een test-Consumer + test-plan resulteert in echte Mollie test-mode Subscription (integration-test, run in `@group integration`).
  4. Cashier-webhook handle't `subscription.payment.succeeded` event + updates `consumer.subscription.status`.
  5. Standaard `php artisan test` blijft groen (de eerder 207 tests + nieuwe phase-6 tests, exclusief integration-group).
  6. Geen regressie op `php artisan test tests/Feature/Api/V1/Mollie/` of `tests/Feature/Webhooks/` (Phase 5a paths intact).
  7. Pint clean.

### Claude's Discretion

- Exacte plan-prijzen (D-05) — uit business, niet uit deze CONTEXT.md.
- Pad-keuze (a/b/c) — uit plan-1 compat-check, niet vooraf.
- Cashier-versie / fork-branch — uit plan-1.
- Of `Plan`-Eloquent-model alsnog nodig is — alleen als pad (a/b) Cashier dat per default verwacht; anders config-only (D-05).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements + roadmap

- `.planning/REQUIREMENTS.md` §SUB-01 — `Billable` trait op `Consumer`, plans via Cashier's `Plan` model, recurring billing via Mandates. PHP 8.4 / Laravel 13 compatibiliteit gevalideerd of fork-and-update uitgevoerd.
- `.planning/ROADMAP.md` §Phase 6 — Goal, Depends-on (Phase 5a SDK Mandates + Subscriptions wrapping), drie compat-paden (a/b/c) reeds genoemd, 3 Success Criteria.
- `.planning/PROJECT.md` §"Current Milestone v0.2" — use-case A definitie (Emeq → Consumers via Emeq's eigen Mollie).

### Phase 5a deliverables die hier hergebruikt worden

- `app/Http/Controllers/Api/V1/Mollie/AbstractMolliePassThroughController.php` — pattern voor controller-base met `buildClient(Request)`-helper (D-06-pattern); herbruikbaar als pad (c) gekozen wordt en we eigen Mollie-calls binnen `app/Billing/` doen.
- `app/Http/Controllers/Webhooks/MollieWebhookController.php` — stap-0 hard-fail guard pattern voor webhook-secret (D-11).
- `tests/Concerns/StubsMollieClient.php` — Mollie SDK stub-helper voor unit-tests; herbruikbaar in Cashier-unit-tests.
- `app/Sanctum/TokenAbilities.php` — toe te breiden met `billing:read` + `billing:write` (D-14).
- `app/Models/Consumer.php` — krijgt `Billable` trait (D-03).

### SDK

- `packages/mollie-api/src/` — wraps `mollie/mollie-api-php` ^3.11. Bij pad (c) gebruiken we deze direct in `app/Billing/`.
- `packages/mollie-api/src/Idempotency/UuidV7IdempotencyKeyGenerator.php` — bij subscription-create direct te hergebruiken (analoog aan Phase 5a Idempotency-Key forward).

### Externe risk-references

- Memory: `reference_cashier_mollie_compat_risk.md` — master-branch Cashier-Mollie hangt op PHP 7.2 / Laravel 6-8. Check recente versies + fork-bereidheid in plan-1.
- Memory: `feedback_pass_through_sdk_pattern.md` — emeq/* SDK's blijven dun, resource-wrapping hoort in de Hub. **Implication:** Cashier-laag bouwt op de Hub-laag, niet op de SDK direct (tenzij pad c, dan vermijden we Cashier en gebruiken we SDK + eigen orchestration in Hub).
- Mollie-docs lokaal: `.docs/partners/mollie/` (indexed README, 11 refs) — voor Mollie Subscriptions + Mandates API specifics.

### Te creëren (Plan-1 deliverable)

- `.docs/decisions/cashier-mollie-compat.md` — ADR met compat-check uitkomst (pad a/b/c) + onderbouwing.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`AbstractMolliePassThroughController::buildClient(Request)`** — Idempotency-Key forward pattern (Phase 5a). Bij pad (c) (eigen subscription-laag) gebruiken we hetzelfde pattern voor subscription-create-calls naar Mollie.
- **`StubsMollieClient` trait** (`tests/Concerns/StubsMollieClient.php`) — heeft stubs voor `customers`, `paymentRefunds`, `subscriptions`, `paymentLinks`, `mandates`. Plan-N kan deze 1:1 hergebruiken voor Cashier-unit-tests (Cashier-subscriptions-create roept dezelfde resources aan).
- **`MollieWebhookSignature::verify`** (uit `emeq/mollie-api` SDK Webhooks-namespace) — voor Cashier-webhook signature-verify (D-10/D-11).
- **`UuidV7IdempotencyKeyGenerator`** — voor write-calls in Cashier-flow (subscription-create, mandate-create).

### Established Patterns

- **Hub-laag bevat business-logic, SDK blijft dun** (Phase 2/5a invariant) — Cashier-state-machine + plan-resolution + webhook-routing leven in `emeq-hub`, niet in `emeq/mollie-api`.
- **Encrypted-at-rest voor credentials** (Phase 3 invariant) — `EMEQ_MOLLIE_OWN_API_KEY` is env-only (read via `config()`), NIET opgeslagen in DB.
- **Sanctum-ability gating per route** (Phase 3 + 5a pattern) — `billing:read` + `billing:write` toevoegen aan `App\Sanctum\TokenAbilities` constants-class.
- **Stap-0 hard-fail webhook guards** (Phase 5a D-08 stap 1) — Cashier-webhook krijgt zelfde guard voor `CASHIER_WEBHOOK_SECRET`.
- **TDD RED-first per task** (Phase 5a 05a-06 pattern, gsd-executor default) — alle nieuwe tests RED-first committen.
- **Pint vóór commit** (`./vendor/bin/pint --dirty --format agent`).

### Integration Points

- **`Consumer`-model** — `Billable` trait toevoegen (pad a/b) of eigen `App\Billing\Billable`-trait (pad c). Bestaande factory + tests blijven werken.
- **`bootstrap/app.php`** — eventueel Cashier route-loader of admin-billing route-prefix toevoegen.
- **`config/services.php`** — `EMEQ_MOLLIE_OWN_API_KEY` config-entry (apart van `services.mollie.webhook_secret` uit Phase 5a, die is voor platform-Connect).
- **`composer.json`** — `mollie/laravel-cashier-mollie` toevoegen (pad a/b) of NIETS toevoegen (pad c).

</code_context>

<specifics>
## Specific Ideas

- **Multi-currency expliciet uit scope** — alleen EUR voor v0.2. NL-context, business-niveau.
- **BTW-hardcoded 21%** — Nederlandse standaard. Tax-rules / multi-country-tax = expliciet deferred.
- **Geen trial periods** — Emeq's onboarding voor Naschool/Planny is een separate B2B-deal, niet een SaaS-trial-funnel.
- **Plan-set v0.2:** maximaal 3 (naschool-license, planny-license, optional third-party-base). Niet groter; admin-UI komt pas in v0.3 of later.
- **Idempotency-Key forward op subscription-create** — analoog aan Phase 5a D-06 pattern, zelfde reasoning (retry-storm = dubbele subscription = financieel risico).

</specifics>

<deferred>
## Deferred Ideas

- **Plan changes / upgrades / downgrades** — geen scope voor v0.2; Emeq's onboarding is handmatig. Belandt in latere milestone of v0.3.
- **Trial periods** — out-of-scope (zie specifics).
- **Multi-currency / multi-country tax** — out-of-scope (alleen EUR + NL BTW hardcoded).
- **Customer self-service portal voor Consumers** — out-of-scope; Emeq-admin doet subscription-CRUD via Phase 9 Filament.
- **Cashier admin-UI** — Phase 9 (Filament BillingResource, te scaffold'en daar).
- **Dunning / failed-payment retry-strategie tuning** — gebruik Cashier defaults; geen custom dunning-flow in v0.2.
- **Coupons / discounts** — out-of-scope v0.2.
- **Invoicing PDF generation** — out-of-scope; Mollie's eigen invoice-emails volstaan voor v0.2.
- **Account-level subscriptions** — Phase 7 (SUB-02), aparte fase.

</deferred>

---

*Phase: 06-cashier-mollie-integratie-use-case-a*
*Context gathered: 2026-05-15*
