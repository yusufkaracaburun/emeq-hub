# Phase 4: Mollie Connect OAuth-broker - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-14
**Phase:** 04-mollie-connect-oauth-broker
**Areas discussed:** State + Connection-lifecycle, Refresh-strategie, Route + auth voor /v1/oauth/mollie/*, Scopes + Test-fixture impl

---

## State + Connection-lifecycle

| Option | Description | Selected |
|--------|-------------|----------|
| Connection pre-create + oauth_state kolom | Bij /authorize maak je een Connection-row aan met status='pending' + oauth_state (random, TTL 30 min). Callback verifieert state tegen die row, vult tokens, status='active'. Cron ruimt pending>30 min op. | ✓ |
| Signed HMAC state + Connection pas bij callback | State = HMAC-signed JWT met account_id + nonce + expiry. Geen DB-row bij /authorize. Callback verifieert HMAC, ruilt token in, maakt Connection-row aan. | |
| Cache-only state + Connection bij callback | State in Laravel-cache (Redis) met TTL. Connection bij callback. | |

**User's choice:** Connection pre-create + oauth_state kolom (recommended)
**Notes:** Geen aanvullende notities. Past bij Hub-patron (alles in DB), audit-friendly, idempotent retry mogelijk.

---

## Refresh-strategie (eerste vraag — pushback)

| Option | Description | Selected |
|--------|-------------|----------|
| Lazy + cron beide | Cron (5 min) + lazy fallback in pass-through. Redis-lock per Connection-ID. | Initieel gekozen, daarna heroverwogen |
| Alleen lazy | Geen cron. Check expires_at vóór elke pass-through-call. | |
| Alleen cron | Scheduler refresht alle near-expiry Connections. | |

**User's choice:** "1 klinkt goed, maar krijgen we dan niet met throttle te maken. en waarom moeten we als de klant het even niet gebruikt, blijven draaien in cron"
**Notes:** Twee zorgen geuit: (1) Mollie OAuth-throttle bij continue cron-refreshes, (2) wasted work voor idle Connections. Beide valide → vervolg-vraag gesteld.

## Refresh-strategie (vervolg — gelocked)

| Option | Description | Selected |
|--------|-------------|----------|
| Pure lazy + Redis-lock | GEEN cron. Bij pass-through-call: expires_at < 5min → sync refresh first. Redis-lock per connection_id. Idle Connections raken nooit een refresh-call. | ✓ |
| Lazy + async pre-emptive job | Sync refresh < 5 min; comfort-window < 1 uur dispatch async RefreshConnectionJob. Geen cron. | |
| Lazy + activity-gated cron | Cron elke 15 min, alleen Connections met last_used_at > now()-24h. Lazy als safety net. | |

**User's choice:** Pure lazy + Redis-lock (recommended)
**Notes:** Pakt beide zorgen uit eerste vraag op: geen cron-throttle-risico, geen wasted work voor idle accounts. Trade-off geaccepteerd: eerste call van een dag op een Connection kan ~300ms trager zijn.

---

## Route + auth voor /v1/oauth/mollie/*

| Option | Description | Selected |
|--------|-------------|----------|
| POST /v1/oauth/mollie/init met Bearer | Consumer roept init aan met Bearer-PAT; Hub maakt Connection (pending) + state, returnt redirect_url als JSON. Callback publiek, state-verified. | ✓ |
| Signed-URL via /v1/oauth/mollie/authorize | Consumer roept authorize aan; Hub returnt redirect met URL::signedRoute-token. | |
| Public /authorize met query-param sigil | HMAC-signed link tussen Consumer en Hub. | |

**User's choice:** POST /v1/oauth/mollie/init met Bearer (recommended)
**Notes:** Geen aanvullende notities. Bearer-veilig, geen signed-URL-magic, mooie separatie tussen init (API-call) en callback (browser-redirect).

---

## Scopes + Test-fixture impl

| Option | Description | Selected |
|--------|-------------|----------|
| Scopes uit config + FakeOAuthFlow in app/ | Scopes hard-coded in config/services.php. Per-Connection scopes opslaan wat Mollie teruggaf. FakeOAuthFlow als runtime-class in app/OAuth/Testing/. | ✓ |
| Per-Consumer scopes in DB | consumers.mollie_scopes jsonb-kolom voor flexibiliteit. | |
| Scopes per-Connection alleen + InterfaceOnly-fixture | Geen vooraf vastgelegde scopes; fixture als alleen interface-assertion. | |

**User's choice:** Scopes uit config + FakeOAuthFlow in app/ (recommended)
**Notes:** Geen aanvullende notities. v0.2 = uniforme scopes voor alle Consumers; per-Consumer differentiation is v1.0+ feature.

---

## Claude's Discretion

Implementatie-keuzes die niet expliciet besproken zijn maar consistent zijn met project-conventies:

- **`OAuthFlow`-contract lokatie** = `app/OAuth/Contracts/` (Hub-laag, multi-provider scope), NIET in `packages/mollie-api/` — past bij `.ai/project rules` invariant "geen partner-business-logic in SDKs"
- **`OAuthFlowRegistry`-pattern** voor provider-keyed lookup (container-tag) — voorbereidend pattern voor v0.3+ providers (Snelstart-OAuth, Exact, Ibanity)
- **Mollie's `mollie/mollie-api-php` heeft géén OAuth-helpers** → directe `Http::post(...)`-calls in `MollieConnectOAuthFlow`, geen extra dep
- **HTTP-status 400 voor CSRF-failure** (per ROADMAP SC-5) ipv 401/403
- **`HubMollieCredentialResolver` bindt al in Phase 4** ipv Phase 5a — zodat Phase 5a meteen `Mollie::client()` kan aanroepen
- **`oauth:prune-pending` artisan-command** ipv automatische scheduled-cleanup — past bij "geen cron filosofie" uit refresh-decision

## Deferred Ideas

- **Activity-based refresh-cron** (`last_used_at`-gated): pure lazy is voldoende voor v0.2; heroverwegen als 300ms eerste-call-latency een UX-issue blijkt
- **Lazy + async pre-emptive refresh-job**: alternatief uit refresh-discussie; gedeferred (pure lazy simpeler)
- **Per-Consumer scope-profielen** (`consumers.mollie_scopes`): v1.0+ feature
- **`MOLL-CONNECT-RES` backlog-item**: Mollie-Connect-partner-resources (Onboarding/Organizations/Profiles/Permissions/ClientLinks) — eigen item in backlog
- **Snelstart-OAuth, Exact-OAuth, Ibanity-OAuth**: tweede + derde + vierde `OAuthFlow`-implementaties, v0.3+
- **Consumer-facing revoke-endpoint** (`DELETE /v1/connections/{id}`): valt onder Phase 5a/5b (HUB-03/HUB-05)
- **Filament admin om pending-Connections te zien + handmatig prunen**: Phase 9 (HUB-04)
