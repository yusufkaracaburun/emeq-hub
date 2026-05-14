---
quick_id: 260514-tny
description: Import Mollie API docs into .docs/partners/mollie/ as Phase 5a research-precondition
date: 2026-05-14
mode: quick-direct
status: completed
---

# Summary: Import Mollie API docs

## Outcome

`.docs/partners/mollie/` bevat nu **11 references + indexed README** als research-precondition voor `/gsd-plan-phase 5a`. Phase 5a planner kan vanaf hier valideren tegen authoritatieve Mollie-bronnen i.p.v. partner-features verzinnen.

## Files (12 totaal — `.docs/` is gitignored)

| # | Bestand | Bron | Hoe verkregen |
|---|---------|------|---------------|
| 1 | `payments-api.md` | docs.mollie.com/reference/payments-api | WebFetch — live |
| 2 | `customers-api.md` | docs.mollie.com/reference/customers-api | WebFetch — live |
| 3 | `payment-methods-api.md` | docs.mollie.com/reference/payment-methods-api | WebFetch — live |
| 4 | `refunds-api.md` | docs.mollie.com/reference/refunds-api | WebFetch — live |
| 5 | `mandates-api.md` | docs.mollie.com/reference/mandates-api | WebFetch — live |
| 6 | `subscriptions-api.md` | docs.mollie.com/reference/subscriptions-api | WebFetch — live |
| 7 | `payment-links-api.md` | docs.mollie.com/reference/payment-links-api | WebFetch — live |
| 8 | `webhooks-overview.md` | docs.mollie.com/reference/webhooks-overview | WebFetch — live |
| 9 | `api-idempotency.md` | docs.mollie.com/reference/api-idempotency | WebFetch — hybrid (page JS-rendered; aangevuld met December-2022 changelog + SDK source) |
| 10 | `errors.md` | docs.mollie.com/reference/errors | WebFetch — live |
| 11 | `oauth-overview.md` | docs.mollie.com/oauth/overview | WebFetch — hybrid (overview-page is JS-rendered; technische URLs uit `app/OAuth/Mollie/MollieConnectOAuthFlow.php` Phase-4-implementatie + scope-tabel uit `/docs/permissions`) |
| – | `README.md` | n.v.t. | Herwritten van stub naar geïndexeerde landing-page met Phase 5a-rol per file |

## Deviation van plan

**Plan zei:** 4 logische commits voor de 11 imports + README-update.

**Reality:** `.docs/` is `gitignored` (line 29 of `.gitignore: /.docs`). Geen commits nodig op de imports zelf. De plan-secties "Commits (logische groepen)" zijn daarmee niet van toepassing — alleen de meta-artefacten (deze SUMMARY + STATE-update + quick-task-dir) zijn committable.

**Plan zei:** 11 files te fetchen.

**Reality:** 10 van 11 bestonden al pre-resume (door eerdere ad-hoc work tussen sessies). Alleen `oauth-overview.md` was open + README stond nog op stub. Resume-werk = 1 nieuwe file + README-rewrite.

**Hybrid-status voor oauth-overview.md:** Mollie's `/reference/oauth2-authorize`, `/reference/oauth2-tokens` en `/connect/getting-started` retourneren via WebFetch alleen JS-stub-content (de echte content laadt client-side). Endpoint-URLs en body-shapes komen daarom uit de productie-getest Phase-4-implementatie (`MollieConnectOAuthFlow`, 4 commits, 7 feature-tests groen) — dat is per definitie wat in productie werkt. De 37-scopes-tabel komt wél direct uit `/docs/permissions` (HTML-rendered).

## Verify

```bash
ls .docs/partners/mollie/ | wc -l        # → 12 (11 references + README.md)
grep -L "^source_url:" .docs/partners/mollie/*.md | grep -v README  # → leeg (alle references hebben frontmatter)
```

## Next

`/clear` → `/gsd-plan-phase 5a`. CONTEXT.md staat al; planner kan direct doorlopen met authoritatieve Mollie-bronnen op disk.
