# Architecture audit — emeq-hub — 2026-06-23

**Scope:** whole-repo, first-party layer (`app/` 341 PHP / ~24k LOC · `resources/js` 28 TS/TSX · `routes/` · `database/`). SDK packages (`packages/**`) out of scope — separate repos.
**Stack:** Laravel 13.9 · PHP 8.4 · Octane (FrankenPHP worker-mode) · Filament v4 · Inertia v3 + React 19 + shadcn/ui + TS · Postgres 16 · Sanctum v4 · Pennant v1.
**Extensions loaded:** laravel, react, shadcn, typescript.
**Files walked:** ~369 first-party (341 PHP + 28 TS/TSX) · **LOC:** ~24k app PHP · **Method:** 3 parallel dimension passes → skeptical deep-dig on un-audited surface (Books + accounting) → direct re-verification of every severity-defining claim.
**Auditor:** /ai:audit-architecture (read-only — no code changes in this run).

## Summary

- **0 🔴 · 4 🟠 · 5 🟡 · 3 🟢/notes** across 6 dimensions with findings (3 dimensions covered-clean).
- **Two sub-agent claims were downgraded on direct verification** (N1, N2) — the first exploration round leaned on the Jun-15 prior audits, which never saw the Books module or most of the accounting layer.

### Top 3 themes
1. **Provider-axis triplication has reached the rule of three.** Middleware, error-mappers, and webhook fan-out jobs each exist in 3 near-identical copies that have already drifted in naming/placement. Deferred since the first audit "until provider 3" — Exact *is* provider 3.
2. **Books posted-document immutability is unenforced.** A posted invoice/bill can be edited or deleted through Filament with no model/form/page guard → the ledger silently diverges from the document. Internal-back-office-only → 🟠 not 🔴, but a real money-correctness integrity gap.
3. **A silent-skip habit in posting + mapping paths.** `LedgerPoster` returns silently on null accounts; several fail-soft paths are correct by design but the pattern needs a documented boundary.

---

## 1. Design patterns
**Covered, no findings.** `OAuthFlowRegistry` / `AccountingTargetRegistry` (parallel seams), `ProviderCredentialDescriptor` (config-driven multi-provider form), Observer→Service in Books — all earn their keep. No pattern-named class that doesn't implement its pattern.

## 2. SOLID
**Covered, no material findings.** Posting logic lives in testable `app/Books/Services/*Poster` classes (not trapped in Filament). `AccountingTarget` is a 1-impl interface but the seam is earned (ADR plans Snelstart/Moneybird adapters; tests mock it). `ExactReferenceResolver` correctly inverted (constructor-injected). No switch-on-type ladders remaining post Provider-enum.

## 3. DRY (knowledge-duplication)
- `[laravel]` **`app/Http/Middleware/Resolve{Mollie,Snelstart,Exact}Account.php`** · 🟠 High · ~70 LOC of identical shape ×3 (header parse → Account lookup → Connection lookup → container bind → request attributes); only the credential-binding step differs (Mollie scoped `MollieConnectionContext::set()` vs Exact/Snelstart `app()->instance()` + `forgetInstance()`). Three copies = rule of three reached. · **Fix:** extract `ResolveProviderAccount` base + a per-provider binding strategy. *(verified: 3 files exist)*
- `[laravel]` **`app/Support/{Mollie,Exact,Snelstart}/UpstreamErrorMapper.php`** · 🟠 High · ~95% identical exception→`{status,body,headers,short_code}` dispatch ×3 (same mask-401→502 policy, same catch-all). DRY + naming asymmetry (see dim 5). · **Fix:** `BaseUpstreamErrorMapper` template + per-provider exception map. *(verified: 3 files exist)*
- **VAT account-map duplicated in Books** · 🟡 Medium · `app/Books/Services/InvoicePoster.php` `REVENUE_BY_RATE` and `app/Books/Services/BtwService.php` rubrieken-map both encode rate→GL. A rate change must touch both. · **Fix:** single `VatAccountMap`/config.
- **VAT composite-key `reverse_charge:tarief` split across 3 classes** · 🟡 Medium · `ConnectionMappingExactReferenceResolver::vatKey()`, `ExactMappingDeriver::deriveVat()`, `ExactReportEnricher` dedup-key all build the same key; tariff normalization (`21`→`"21"`) is a helper in one, inline in others. ADR-acknowledged (deliberate flat key across write/derive/validate). · **Fix:** shared `const`/enum for the prefix + a single reused `rateKey()`.

## 4. YAGNI / dead-code
**Covered, no findings.** All interfaces have live impls or test substitutes (`AccountingTarget`, `OAuthFlow`+`FakeOAuthFlow`, the 6 `DocumentValidator`s all registered in `DocumentInspector::defaults()`). No `env()` calls in `app/` (config:cache-safe — verified). Prior dead-code (A6) confirmed removed.

## 5. Naming + comment-drift
- **Webhook fan-out job naming/namespace asymmetry** · 🟡 Medium · `app/Jobs/ForwardMollieWebhookToConsumer.php` (namespace `App\Jobs`, no suffix) vs `app/Jobs/Webhooks/Forward{Exact,Snelstart}WebhookToConsumerJob.php` (namespace `App\Jobs\Webhooks`, `…Job` suffix). Three providers, two conventions. · **Fix:** standardize to `App\Jobs\Webhooks\Forward{Provider}WebhookToConsumerJob`. *(verified via find)*
- **Error-mapper class naming asymmetry** · 🟡 Medium · `MollieUpstreamErrorMapper` (provider-prefixed) vs `UpstreamErrorMapper` (Exact + Snelstart, suffix-only, disambiguated only by namespace). The first sub-agent pass claimed this was "resolved by design" — it is **not**. · **Fix:** pick one convention. *(verified via ls)*
- Otherwise covered: Dutch domain naming consistent with the partner APIs; `Phase N` comment backlinks all resolve to ADRs (not stale). Minimal-comment policy respected — not flagging missing comments.

## 6. Coupling / cohesion (local / structural)
- **`ExactAccountingTarget::ensureMapping()` lazy-init coupling** · 🟡 Medium · `app/Accounting/Exact/ExactAccountingTarget.php` (~L202-203) reaches into `ExactReferenceSync` + `ExactMappingDeriver` on first write. Pragmatic and commented, but the write path now depends on sync infra. · **Fix:** acceptable for now; document the coupling; revisit if lazy-init grows.
- **God-module watch (no action):** `OnboardConsumer` 475, `ExactReferenceData` 425, `ManageAccountingMappingAction` 330, `ExactOAuthTracerController` 328 (dev-only). All decomposed into focused helpers; none doing >3 unrelated jobs. 🟢 watch-only.
- **Octane static/singleton state:** clean — registries are boot-time-immutable; per-request SDK binding uses scoped/`forgetInstance` patterns. No request-state leak. 🟢.

## 7. Layering / dependency-direction (global / architectural)
**Covered, no findings.** SDK↔Hub boundary intact (no `App\Models` imports in `packages/**`). Multi-tenant resolution explicit via `X-Account-Id` middleware (no `?connection_id=` bypass). `Illuminate\Http\Request` confined to the Http layer. Books module cleanly bounded (`App\Books\*`, `books_` table prefix, no reach into Connection/partner-SDK). No circular deps.

## 8. Error handling / failure modes
- **`app/Books/Services/LedgerPoster.php:28-30` silent-skip on null accounts** · 🟠 High · `if ($debitAccount === null || $creditAccount === null) { return; }` — a transaction with an unresolved chart/bank account produces a `Transaction` row that is **never posted** to the ledger (zero journal entries, no error), despite the class comment promising "elke standaard-boeking levert exact één debet + één credit". · **Caveat:** real-world reachability is low if the Filament transaction form requires both accounts; severity is for the silent-drop pattern, not a confirmed live break. · **Fix:** throw on null and validate account FKs at the call site instead of returning. *(verified directly — `LedgerPoster::post()`)*
- **Idempotency residual race** · 🟡 Medium · the `Idempotency-Key` is persisted *after* the Exact write succeeds; a Hub crash between "Exact booked" and "key stored" lets a retry double-book. ADR-acknowledged ("Restrisico … geaccepteerd voor nu"). Real for high-volume third-party consumers. · **Fix (v-next):** reserve key before forward.
- **Attachment upload best-effort** · 🟢 note · `ExactAccountingTarget::uploadAttachments()` per-file `catch (Throwable)` + `report()`, entry already persisted, failure echoed in the response. Intentional and surfaced to the consumer — acceptable.
- **N1 (downgraded — not a defect):** async accounting silent-drop. `DocumentsController.php:75` returns `400 webhook_required` *before* dispatching when the Consumer has no `webhook_callback_url`; the job-level skip-log (`SyncAccountingDocumentJob` ~L57-68) is defense-in-depth, not the primary guard. *(verified directly)*

## 9. Type safety / contract clarity
- **N2 (downgraded → 🟢):** `FinancialDocumentLine::fromArray()` loose float casts and `TaxTreatment::tryFrom() ?? Standard` silent default are **mooted at the real edge** — `StoreDocumentRequest` validates `lines.*.amount|numeric`, `tax_rate|numeric|min:0`, `tax_treatment|Rule::in(TaxTreatment::values())`, so garbage is rejected before hydration. Residual: `fromArray()` is `public`; if reused outside the validated edge there's no numeric guard. · **Fix (optional):** add strict coercion in `fromArray()` to harden the public surface. *(verified directly against `StoreDocumentRequest.php`)*
- Otherwise covered: money in Books is integer-cents end-to-end (`'integer'` casts, explicit euro→cents in forms), `JournalEntryType`/`TransactionType`/`SyncStatus`/`Provider`/`TaxTreatment` enums enforced, no remaining domain magic-strings in logic paths.

---

## Per-stack appendix — Books posted-document immutability (🟠 B1, headline)

Surfaced under dim 8/9 but documented here in full because it's the highest-correctness finding and never appeared in the prior audits (Books landed Jun 21-22).

- `app/Books/Models/Invoice.php` / `Bill.php` expose `isPosted()` (`transaction_id !== null`) but have **no `updating`/`deleting` model guard**.
- `app/Filament/Books/Resources/Invoices/Schemas/InvoiceForm.php` has **no `disabled()`/`visible()` guard** keyed on posted state (grep returned empty); `BillForm.php` likewise.
- `app/Filament/Books/Resources/Invoices/Pages/EditInvoice.php` is a bare `EditRecord` exposing only `DeleteAction::make()` — no `mount()` guard, no authorization, no redirect-if-posted; `EditBill.php` likewise.

**Consequence:** a super-admin/boekhouder can open a *posted* invoice in the Filament edit form, change a line/amount (observers recalc the invoice total) **without re-posting** → the `books_journal_entries` for that transaction keep the old amount → invoice and ledger diverge. The `DeleteAction` has the same gap (delete a posted document → cascade behaviour on its transaction/journal entries is unguarded).

**Severity rationale:** money-correctness integrity gap, but the Books module is the Hub's *own* single-company back-office, gated behind trusted internal admin access with no external trigger → **🟠 High**, not 🔴. Escalates to 🔴 if Books goes into real production bookkeeping use.

**Fix direction:** guard `static::updating()` / `static::deleting()` on `Invoice`/`Bill` to throw when `transaction_id !== null`; `->disabled(fn ($record) => $record?->isPosted())` on the form line-repeater + header fields; remove or guard `DeleteAction` when posted (offer an explicit "unpost" path instead).

---

## Cross-cutting root cause

Findings under dim 3 (middleware, error-mapper) and dim 5 (job + error-mapper naming) all trace to **one root cause: no provider-axis abstraction.** Every new provider adds a parallel middleware + error-mapper + fan-out job, and the copies have already drifted in naming/placement. The prior audit deferred this "until provider 3"; **Exact is provider 3.** Recommendation: make the next provider (Moneybird, planned) the trigger to extract `ResolveProviderAccount` + `BaseUpstreamErrorMapper` + a unified `Forward…WebhookToConsumerJob`, and standardize naming in the same sweep — don't add a 4th copy first.

---

## Tech-debt rolling table

| ID | Finding | Severity | Fix direction | Suggested owner |
|----|---------|----------|---------------|-----------------|
| B1 | Posted Books invoice/bill editable & deletable — no model/form/page guard → ledger diverges (`Invoice.php`/`Bill.php`/`InvoiceForm.php`/`BillForm.php`/`EditInvoice.php`/`EditBill.php`) | 🟠 | Guard `updating`/`deleting` when `transaction_id !== null`; `disabled()` repeater + guard `DeleteAction` when posted | Books |
| B2 | `LedgerPoster:28-30` silent `return` on null debit/credit account → unposted, no error | 🟠 | Throw on null; validate account FKs upstream | Books |
| L1 | `Resolve{Mollie,Snelstart,Exact}Account` ~70 LOC ×3 identical except binding step | 🟠 | Extract `ResolveProviderAccount` base + binding strategy | Hub/OAuth |
| L2 | `UpstreamErrorMapper` ~95% dup ×3 + naming asymmetry (`MollieUpstreamErrorMapper` vs `UpstreamErrorMapper`) | 🟠 | `BaseUpstreamErrorMapper` template + one naming convention | Hub/Support |
| L3 | Webhook fan-out job naming/namespace asymmetry (Mollie outside `App\Jobs\Webhooks`, no `…Job` suffix) | 🟡 | Standardize to `App\Jobs\Webhooks\Forward{Provider}WebhookToConsumerJob` | Hub/Jobs |
| A1 | VAT account-map duplicated (`InvoicePoster::REVENUE_BY_RATE` + `BtwService`) | 🟡 | Single `VatAccountMap`/config | Books |
| A2 | VAT composite-key `reverse_charge:tarief` encoded in 3 classes | 🟡 | Shared `const`/enum prefix + reuse `rateKey()` | Accounting |
| A3 | Idempotency key stored after Exact write → crash-window double-book (ADR-accepted) | 🟡 | Reserve key before forward (v-next) | Accounting |
| A4 | `ExactAccountingTarget::ensureMapping()` couples write path to sync/derive infra | 🟡 | Document coupling; revisit if it grows | Accounting |
| N1 | `FinancialDocumentLine::fromArray()` loose casts (mooted at edge; public surface unguarded) | 🟢 | Optional: strict coercion in `fromArray()` | Accounting |

---

## Notes on the audit itself
- Severity sanity: 4 🟠 / ~11 ≈ 36% high+ → not pedantry (<10%), not catastrophising (>40% 🔴). 0 🔴 defensible: the one money-correctness gap (B1) is internal-back-office-only, no external trigger.
- The Jun-15 prior audits + their fix session remain valid for the surface they covered; this pass adds the Books module and the post-Jun-16 accounting layer they never saw.
- Reproduce file-existence claims with the session greps; N1/N2 downgrades verified against `DocumentsController.php:75` and `StoreDocumentRequest.php`; B1/B2 verified against the cited Books files.
