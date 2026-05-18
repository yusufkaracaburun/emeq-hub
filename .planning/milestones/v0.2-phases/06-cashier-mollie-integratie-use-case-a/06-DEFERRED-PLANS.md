# Phase 6 — Deferred Plans (06-02 t/m 06-08)

**Status:** ✅ RESOLVED — alle 7 plans geschreven 2026-05-15 (na ADR-conclusie pad-a).
**Originally aangemaakt:** 2026-05-15
**Resolved:** 2026-05-15

## Resolutie

De compat-ADR uit plan 06-01 koos **pad (a) — out-of-box `mollie/laravel-cashier-mollie ^2.20`**. Op basis daarvan zijn alle 7 deferred plans geschreven met expliciete acties per locked decision uit `06-CONTEXT.md`:

| Plan | Titel | File |
|------|-------|------|
| 06-02 | Cashier install + publish migrations & configs + env-skeleton | `06-02-PLAN.md` |
| 06-03 | Billable trait op Consumer + cashier.user_model + factory-state | `06-03-PLAN.md` |
| 06-04 | PlanResolver + config/billing-plans.php + UnknownPlanException | `06-04-PLAN.md` |
| 06-05 | Sanctum billing-abilities + 3 routes + EnsureEmeqAdminToken-middleware | `06-05-PLAN.md` |
| 06-06 | Cashier-webhook hard-fail-guard + Cashier::ignoreRoutes + 3 /cashier/webhook routes | `06-06-PLAN.md` |
| 06-07 | Integration-suite via phpunit.integration.xml + 3 happy-path tests | `06-07-PLAN.md` |
| 06-08 | BLOCKING phase-acceptance + ROADMAP/REQUIREMENTS/STATE updates | `06-08-PLAN.md` |

## Wave-structuur

- **Wave 2** (na Wave 1 = 06-01): `06-02` (install — blocker voor de rest)
- **Wave 3** (parallel): `06-03` + `06-04` (verschillende files, geen overlap)
- **Wave 4** (parallel): `06-05` + `06-06` (verschillende files, beide depend on 06-03+06-04)
- **Wave 5**: `06-07` (integration tests na implementatie compleet)
- **Wave 6**: `06-08` (acceptance gate, `autonomous: false`)

## Padspecifieke aandachtspunten (alleen pad-a relevant)

Pad (b) en pad (c) zijn niet meer relevant; ADR verwierp ze met expliciete onderbouwing. Zie `.docs/decisions/cashier-mollie-compat.md` (gitignored, lokaal op disk).

## Locked decisions die voor alle 7 plans gelden

Verwerkt in elk plan's `<context>` en `<interfaces>` blok:

- **D-03** `Billable` op `App\Models\Consumer` → 06-03
- **D-04** NIET op `App\Models\Account` → 06-03 (Account intact)
- **D-05** Plan-storage via `config/billing-plans.php` → 06-04
- **D-06** PlanResolver::find/all → 06-04
- **D-07** `CASHIER_MOLLIE_KEY` (Emeq's eigen Mollie) → 06-02
- **D-08** `Mollie` + `EmeqMollie` facade coexist → 06-02 + 06-03
- **D-09** `.env.example` placeholders met NL-comment → 06-02
- **D-10** Cashier-webhook separaat path → 06-06
- **D-11** Cashier-webhook stap-0 hard-fail guard → 06-06
- **D-12** Integration tests `@group integration` → 06-07
- **D-14** `BILLING_READ` + `BILLING_WRITE` abilities → 06-05
- **D-15** 3 routes (consumer-read + admin-create + admin-cancel) → 06-05
- **D-16** Cashier-migrations via vendor:publish → 06-02
- **D-18** Phase-acceptance criteria → 06-08

D-13 (pad-c coverage) en D-17 (pad-c migration) zijn n.v.t. door pad-a-keuze.

---

*Dit defer-document is bewaard voor traceability. De 7 plans zijn de canonical bron voor executor; dit document is een overzichts-index.*
