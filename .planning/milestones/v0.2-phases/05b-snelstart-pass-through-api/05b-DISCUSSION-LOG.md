# Phase 5b Discussion Log

**Date:** 2026-05-14
**Mode:** discuss (default), no flags
**Phase:** 5b — Snelstart-pass-through API

Human reference only — not consumed by downstream agents. Canonical decisions live in `05b-CONTEXT.md`.

## Areas presented

4 gray areas geïdentificeerd, alle 4 door user geselecteerd voor discussie. Andere implementatie-details bewust niet gepresenteerd omdat ze al door HUB-05 ROADMAP-tekst gelocked waren (`X-Account-Id` = external_id, 404-cross-consumer, abilities, provisioning-endpoints, scramble auto-discovery).

## Q1 — Route shape

**Options presented:**
1. Catch-all `Route::any('/v1/snelstart/{path}')` + method-dispatch *(Recommended)*
2. Specifieke routes per Snelstart-resource (`/relaties`, `/verkoopfacturen`, …)
3. Catch-all met expliciete method-whitelist via `Route::match`

**Selection:** Option 1 (Recommended)

**Notes:** Optie 1 past bij "pass-through is dunne laag"-philosophy uit PROJECT.md. Vermijdt code-deploy bij nieuwe Snelstart-endpoints. Scramble-OpenAPI render van wildcard-routes moet door researcher gevalideerd worden.

## Q2 — Resolver binding

**Options presented:**
1. Per-request scoped binding via `ResolveSnelstartAccount`-middleware *(Recommended)*
2. Explicit Account-context bij elke SDK-call (controller-threaded)
3. Static helper met `HubContext::withAccount($a, fn() => …)`-scope

**Selection:** Option 1 (Recommended)

**Notes:** Middleware-laag houdt controllers dun en SDK kent geen Hub-internals. Matched SDK README "Credentials wiring" pattern.

## Q3 — Audit-log table

**Options presented:**
1. Nieuwe `pass_through_calls`-tabel *(Recommended)*
2. Hergebruik bestaande `webhook_calls` met `direction`-kolom
3. Beide: log naar `webhook_calls` + emit `PassThroughCalled`-event

**Selection:** Option 1 (Recommended)

**Notes:** Bewuste deviatie van HUB-05 ROADMAP-tekst die "webhook_calls" zegt. Reden: PROJECT.md beschrijft `webhook_calls` voor inkomend-uitgaand-webhook-stream, niet pass-through-audit. Cleaner schema, betere indexes, geen NULL-kolommen.

## Q4 — Upstream-error mapping

**Options presented:**
1. Rewrap als 502 voor upstream-side, passthrough voor user-input fouten *(Recommended)*
2. Volledige passthrough — Snelstart's status en body verbatim
3. Custom Hub-error-taxonomy met expliciete mapping per Snelstart-foutcode

**Selection:** Option 1 (Recommended)

**Notes:** Voorkomt info-disclosure (Snelstart 401 lekt niet of credentials of PAT ongeldig is). 429 passthrough met Retry-After. Network-errors → 504 (apart van 502 voor monitoring).

## Follow-ups

### F1 — Audit timing
Selection: **Synchroon na response** *(Recommended)*. Async via Horizon overwogen wanneer audit-write een bottleneck wordt — backlog.

### F2 — Header forward
Selection: **Whitelist (Accept, Content-Type, If-Match, If-None-Match)** *(Recommended)*. Voorkomt automatische leak van toekomstige Hub-headers.

### F3 — No active Connection
Selection: **404 met `connection_not_found`** *(Recommended)*. Consistent met cross-Consumer 404-policy, geen Account-existence-disclosure.

## Deferred ideas captured

- Upstream-revoke voor Snelstart (Snelstart heeft geen revoke-endpoint, Hub-only flag voldoende)
- Async audit-writes via Horizon
- Per-Account rate-limit (huidige is per-Consumer)
- Webhook-handling vanuit Snelstart (N/A, Snelstart heeft geen webhook-API)
- `pass_through_calls` retention-policy
- `PassThroughCalled` Laravel-event
- Generic `/v1/{provider}/{path}` super-catchall

Allemaal in CONTEXT.md `<deferred>` sectie.

## No scope creep flagged

Alle vragen bleven binnen HUB-05's gedefinieerde scope. Geen redirects naar andere phases nodig.
