# Phase 3 — Deferred Items

Out-of-scope discoveries die niet door dit fase-werk veroorzaakt zijn maar tijdens execution zijn opgemerkt. Niet fixen in deze fase — een opvolger of quick-task pakt ze.

## Tijdens 03-01 (2026-05-14)

### Pint formatting-drift op Spatie-published migrations

**Plan:** 03-01
**Files:** `database/migrations/2026_05_13_223628_create_webhook_calls_table.php`, `database/migrations/2026_05_13_223629_add_attachments_to_webhook_calls_table.php`
**Fixers needed:** `class_definition`, `braces_position`, `ordered_imports`
**Reden out-of-scope:** Beide files zijn aangemaakt door `spatie/laravel-webhook-client`-vendor:publish — niet door dit plan. Conformance per `.ai/rules/engineering.md` ("Match bestaande style — ook als je 'm niet mooi vindt") + scope-boundary regel (auto-fix-rules gelden alleen voor zaken die direct door taakwijzigingen worden veroorzaakt).
**Voorgesteld:** Quick-task "pint-published-vendor-migrations" of pakken bij Phase 5a/5b wanneer audit-logging op `webhook_calls` toch wordt aangeraakt.
