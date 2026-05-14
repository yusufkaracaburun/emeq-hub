# Phase 3 — Deferred Items

Out-of-scope discoveries die niet door dit fase-werk veroorzaakt zijn maar tijdens execution zijn opgemerkt. Niet fixen in deze fase — een opvolger of quick-task pakt ze.

## Tijdens 03-01 (2026-05-14)

### Pint formatting-drift op Spatie-published migrations

**Plan:** 03-01
**Files:** `database/migrations/2026_05_13_223628_create_webhook_calls_table.php`, `database/migrations/2026_05_13_223629_add_attachments_to_webhook_calls_table.php`
**Fixers needed:** `class_definition`, `braces_position`, `ordered_imports`
**Reden out-of-scope:** Beide files zijn aangemaakt door `spatie/laravel-webhook-client`-vendor:publish — niet door dit plan. Conformance per `.ai/rules/engineering.md` ("Match bestaande style — ook als je 'm niet mooi vindt") + scope-boundary regel (auto-fix-rules gelden alleen voor zaken die direct door taakwijzigingen worden veroorzaakt).
**Voorgesteld:** Quick-task "pint-published-vendor-migrations" of pakken bij Phase 5a/5b wanneer audit-logging op `webhook_calls` toch wordt aangeraakt.

## Tijdens 03-05 (2026-05-14)

### Pint formatting-drift op routes/web.php

**Plan:** 03-05 (ontdekt tijdens acceptance-Pint-run op gehele app)
**Files:** `routes/web.php`
**Fixers needed:** `fully_qualified_strict_types`, `ordered_imports`
**Reden out-of-scope:** `routes/web.php` is in 03-05 niet aangeraakt; pre-existing drift uit eerdere setup-werk. `--dirty`-Pint heeft 'm niet opgepikt omdat de file niet in deze commits zit.
**Voorgesteld:** Eén-regel quick-task (`vendor/bin/pint routes/web.php`) of meenemen wanneer Phase 4/5a/5b daadwerkelijk webhook-routes toevoegt en `routes/web.php` of `routes/webhooks.php` opnieuw geraakt wordt.

### Pint formatting-drift in packages/*

**Plan:** 03-05 (ontdekt tijdens acceptance-Pint-run)
**Files:** `packages/snelstart-api/**/*.php`, `packages/mollie-api/**/*.php`
**Reden out-of-scope:** `packages/` is per `.ai/packages` rules een gitignored read-clone voor referentie/grep; SDK-changes gebeuren in de eigen GitHub-repos van die packages, niet hier. Pint-drift in de Hub-werkkopie is irrelevant voor het Hub-build-pad.
**Voorgesteld:** Niet fixen in Hub. Pakken in de SDK-repos zelf (`emeq-snelstart-api`, `emeq-mollie-api`) — pas zodra die packages opnieuw aangeraakt worden.
