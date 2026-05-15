# Phase 6 — Deferred Plans (06-02 t/m 06-08)

**Status:** Wachten op ADR-conclusie uit plan 06-01.
**Aangemaakt:** 2026-05-15
**Reden defer:** De plan-content van 06-02+ hangt volledig af van het in plan 06-01 gekozen compat-pad (a/b/c). Drie wezenlijk verschillende code-paden parallel uitwerken is verspilling. Zie `.planning/phases/06-cashier-mollie-integratie-use-case-a/06-CONTEXT.md` §D-01 + D-02 voor de decision-tree.

## Trigger om plannen 06-02 t/m 06-08 te schrijven

Na commit van plan 06-01 (ADR `.docs/decisions/cashier-mollie-compat.md` + REQUIREMENTS.md SUB-01 status):

```
/gsd-plan-phase 6 --gaps
```

Of een fresh planner-run met de ADR als locked context. De planner gebruikt de `## Consequences`-sectie van de ADR als bron voor plan-titels, files_modified, en task-acties — dat is bewust zo gestructureerd in plan 06-01 Task 2.

## Deferred plan-set (indicatief, definitieve scope volgt uit ADR)

| Plan | Titel | Afhankelijk van ADR-pad | Hoofd-deliverable |
|------|-------|--------------------------|-------------------|
| 06-02 | Cashier install / fork-pin / Billing-skeleton | (a) install — (b) fork + VCS-entry — (c) `app/Billing/`-namespace | composer-edit OF eigen namespace, geen feature-code |
| 06-03 | Consumer + Billable trait + plan-resolver | alle paden | `Billable` op `App\Models\Consumer` + `config/billing-plans.php` (D-03 + D-05) |
| 06-04 | Sanctum-abilities + routes | alle paden | `billing:read` + `billing:write` in `App\Sanctum\TokenAbilities` + routes (D-14 + D-15) |
| 06-05 | Subscription create/cancel + Mandate-handling | alle paden, implementatie verschilt | Service-laag (Cashier-API bij a/b, eigen orchestrator bij c) |
| 06-06 | Cashier-webhook ingress + signature-verify | alle paden | `/cashier/webhook` met Phase 5a hard-fail-guard pattern (D-10 + D-11) |
| 06-07 | Integration tests met Mollie test-mode | alle paden | `@group integration` PHPUnit-suite (D-12) |
| 06-08 | BLOCKING phase-acceptance | alle paden | 3 SC's bewezen + Pint clean + 0 regressies op Phase 5a tests (D-18) |

## Variant-specifieke aandachtspunten per pad

### Pad (a) — Cashier out-of-box

- Plan 06-02: `composer require mollie/laravel-cashier-mollie:^X.Y` (versie uit ADR) + `php artisan vendor:publish --tag=cashier-migrations` + `migrate`.
- Plan 06-05: gebruik Cashier's eigen `Subscription`-model + `newSubscription()`-builder.
- Plan 06-06: gebruik Cashier's eigen `WebhookController` als basis, met onze hard-fail guard erbovenop (override of decorator).

### Pad (b) — Fork-and-patch

- Plan 06-02: fork naar `github.com:yusufkaracaburun/laravel-cashier-mollie`, fix PHP/Laravel-constraints (`composer.json`), eventueel deprecated `Illuminate\Support\Str`-calls vervangen, VCS-entry toevoegen aan Hub-`composer.json` (zelfde pattern als `emeq/snelstart-api` + `emeq/mollie-api`).
- Plannen 06-03 t/m 06-08: gelijk aan pad (a).

### Pad (c) — Eigen subscription-laag

- Plan 06-02: scaffold `app/Billing/` met `Billable` trait, `Subscription` Eloquent-model, `PlanResolver` (matched Cashier's public API per D-03 + D-06). Geen Cashier-package install.
- Plan 06-02b (extra): migration `consumer_subscriptions` per D-17.
- Plan 06-05: bouw service-laag bovenop `emeq/mollie-api` SDK (Customers + Mandates + Subscriptions resources).
- Plan 06-07: minimaal 80% test-coverage op `app/Billing/`-namespace per D-13.
- Plan 06-06: bouw eigen `Cashier`-style webhook-controller (zonder Cashier-package) met `MollieWebhookSignature::verify` uit `emeq/mollie-api` SDK.

## Bestaande locked decisions die voor alle paden gelden

Uit `06-CONTEXT.md` (allen LOCKED, niet revisiteerbaar zonder fresh `/gsd-discuss-phase 6`):

- **D-03**: `Billable` op `App\Models\Consumer`.
- **D-04**: NIET op `App\Models\Account`.
- **D-05**: Plan-storage via `config/billing-plans.php` (geen Eloquent Plan-model in v0.2).
- **D-07**: Cashier draait op Emeq's eigen Mollie API-key (`EMEQ_MOLLIE_OWN_API_KEY`), niet op Connection-tokens.
- **D-08**: `Mollie`-alias (uit `laravel-mollie` bij pad a/b) coexist met `EmeqMollie` — al gevalideerd in Phase 2 SC-3.
- **D-09**: `.env.example` krijgt `EMEQ_MOLLIE_OWN_API_KEY=test_xxx` placeholder met comment.
- **D-10**: Cashier-webhook-endpoint blijft separaat van Connect-webhook ingress.
- **D-11**: Cashier-webhook-secret hard-fail guard, analoog aan Phase 5a stap-0 pattern.
- **D-12**: Integration tests gemarkeerd als `@group integration`, gescheiden van standaard suite.
- **D-14**: Twee nieuwe Sanctum-abilities: `billing:read` (Consumer) + `billing:write` (Emeq-admin).
- **D-15**: Routes `GET /v1/billing/subscription` (Consumer) + `POST/DELETE /v1/admin/billing/subscriptions/...` (admin).
- **D-16**: Cashier-migrations via `vendor:publish` bij pad (a/b).
- **D-17**: Eigen `consumer_subscriptions`-tabel bij pad (c).
- **D-18**: Phase-acceptance criteria (3 SC's bewezen + 0 regressies op Phase 5a + Pint clean).

## Wat NIET in dit defer-document hoort

- Concrete prijzen voor de Naschool-license / Planny-license plans — komt uit business bij plan 06-03 execute (niet hier).
- Versie-pin voor Cashier-Mollie — komt uit plan 06-01 ADR (niet hier).
- Implementatie-details voor de eigen Billing-namespace — komt uit plan 06-02 bij pad (c) (niet hier).

---

*Defer-note aangemaakt door planner-run 2026-05-15 als onderdeel van plan 06-01 scoping. Vervalt zodra plannen 06-02 t/m 06-08 zijn geschreven en gecommitteerd.*
