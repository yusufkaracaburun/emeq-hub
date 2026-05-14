---
quick_id: 260514-tny
description: Import Mollie API docs into .docs/partners/mollie/ as Phase 5a research-precondition
date: 2026-05-14
mode: quick-direct
---

# Plan: Import Mollie API docs

## Goal

Vul `.docs/partners/mollie/` met de officiële Mollie API-docs voor de 7 Phase 5a-resources + webhooks + idempotency + errors + Connect-overview. Voorkomen dat Phase 5a planning verzonnen partner-features genereert (zie `.ai/rules/global.md` "geen verzonnen partner-features").

## Scope

- 11 WebFetches naar `docs.mollie.com`
- 11 nieuwe markdown-files in `.docs/partners/mollie/` met frontmatter (source-URL + fetched-date + scope)
- Update `.docs/partners/mollie/README.md` van stub naar index met links naar de 11 files
- Géén code-changes in `app/`, `packages/`, of `routes/`

## URLs

| # | URL | Doel-bestand |
|---|---|---|
| 1 | https://docs.mollie.com/reference/payments-api | `payments-api.md` |
| 2 | https://docs.mollie.com/reference/customers-api | `customers-api.md` |
| 3 | https://docs.mollie.com/reference/payment-methods-api | `payment-methods-api.md` |
| 4 | https://docs.mollie.com/reference/refunds-api | `refunds-api.md` |
| 5 | https://docs.mollie.com/reference/mandates-api | `mandates-api.md` |
| 6 | https://docs.mollie.com/reference/subscriptions-api | `subscriptions-api.md` |
| 7 | https://docs.mollie.com/reference/payment-links-api | `payment-links-api.md` |
| 8 | https://docs.mollie.com/reference/webhooks-overview | `webhooks-overview.md` |
| 9 | https://docs.mollie.com/reference/api-idempotency | `api-idempotency.md` |
| 10 | https://docs.mollie.com/reference/errors | `errors.md` |
| 11 | https://docs.mollie.com/oauth/overview | `oauth-overview.md` |

## Commits (logische groepen)

1. `docs(partners/mollie): import 7 resource-API references` — bestanden 1-7
2. `docs(partners/mollie): import webhooks-overview` — bestand 8
3. `docs(partners/mollie): import idempotency + error-codes` — bestanden 9-10
4. `docs(partners/mollie): import Connect OAuth overview + update README index` — bestand 11 + README

## Verify

- 11 nieuwe `.md`-files in `.docs/partners/mollie/`
- README.md is geen stub meer; verwijst naar alle 11
- Geen wijzigingen buiten `.docs/partners/mollie/` en `.planning/quick/`
