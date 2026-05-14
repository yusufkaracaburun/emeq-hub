# Phase 5a: Mollie SDK Resources + Webhooks + Pass-through API - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-14
**Phase:** 05a-mollie-sdk-resources-webhooks-pass-through-api
**Areas discussed:** Route shape · SDK invocation · Audit-log fixes · Idempotency forwarding · Webhook ingress + fan-out · Connect partner-resources scope · OAuth-scope-check policy · Refresh-lock placement · Mollie-error envelope · Mollie-docs research-precondition
**Mode:** autonomous (user directive: "work without stopping for clarifying questions — make the reasonable call and continue")

---

## Route shape

| Option | Description | Selected |
|--------|-------------|----------|
| Catch-all `/v1/mollie/{path}` + method-dispatch (5b-stijl) | Eén `Route::any` wildcard, zero-deploy nieuwe Mollie-endpoints, geen Form Requests | |
| Per-resource routes + Form Requests + abstract base | Expliciete route per Mollie-resource met Form Request edge-validatie; abstract `MolliePassThroughController` deelt tenant-resolutie/audit/exception-mapping | ✓ |
| Hybride (catch-all + optionele per-resource Form Request) | Catch-all blijft, Form Request kicks in als route bekend is | |

**Selected:** Per-resource (D-01).
**Notes:** `mollie-passthrough-api.md` ADR (Consequences) zegt expliciet "Hub Phase 5a-scope = controllers + Form Requests + audit-rows. Een gedeelde abstract `MolliePassthroughController` houdt … DRY." Plus Phase 5b's REVIEW.md heeft drie BLOCKER-issues blootgelegd die uit catch-all-stijl kwamen (raw body niet JSON-veilig, PII in `path`, fingerprint-collision op lege body). Per-resource sluit die klasse fouten uit en levert betere Scramble-OpenAPI-rendering.

---

## SDK invocation style

| Option | Description | Selected |
|--------|-------------|----------|
| Raw HTTP-forward via low-level SDK (`performHttpCall`) | Past bij catch-all; bewaart Mollie's exact wire-format | |
| Typed resource-methods (`Mollie::client()->payments->create($payload)`) | Krijgt Mollie's typed-exception-pad; `MollieExceptionMapper` mapt naar `Emeq\MollieApi\Exceptions\*` deterministisch | ✓ |

**Selected:** Typed SDK-calls (D-04).
**Notes:** Past bij D-01 keuze. SDK v0.1.0-alpha.1 publiceerde `MollieExceptionMapper` precies voor dit pad — niet gebruiken zou waste-werk zijn.

---

## Audit-log strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Reuse `pass_through_calls` met `provider='mollie'` | Per `pass-through-calls-table.md` ADR; pas tegelijk 5b-REVIEW-blocker-fixes toe | ✓ |
| Eigen Mollie-tabel | Aparte indexes/retention | |
| Spatie `webhook_calls` voor alles | Mengt streams; ADR wijst dit af | |

**Selected:** Reuse + 5b-REVIEW-fixes toepassen (D-05).
**Notes:** ADR zegt expliciet "Phase 5a kan dezelfde tabel hergebruiken". Drie fixes meenemen die in 5b-REVIEW gemeld zijn: (1) `path` zonder query-string, query-keys naar `query_keys`-kolom; (2) `request_fingerprint = null` bij empty body; (3) Content-Type JSON-enforcement op writes.

---

## Idempotency forwarding

| Option | Description | Selected |
|--------|-------------|----------|
| Alleen SDK-default (`UuidV7IdempotencyKeyGenerator`) | Hub genereert altijd zelf — geen Consumer-control | |
| Consumer-Idempotency-Key forward + SDK-fallback | Consumer mag eigen key sturen; SDK genereert er één als die ontbreekt | ✓ |
| Geen idempotency | Mollie weet de duplicate niet te onderscheiden | |

**Selected:** Forward + fallback (D-06).
**Notes:** SC-5 ("twee identieke `POST /v1/mollie/payments` met dezelfde idempotency-key retourneren één Mollie-payment-ID") impliceert Consumer-supplied key. SDK fallback dekt het geval waarin Consumer 'm vergeet.

---

## Webhook-ingress shape

| Option | Description | Selected |
|--------|-------------|----------|
| Globale `POST /webhooks/mollie` zonder connection-discriminator | Hub moet Mollie-resource opvragen om Connection te vinden — catch-22 met credentials | |
| Per-Connection URL `POST /webhooks/mollie/{connection_id}` | Hub-PK in URL; verifie via signature + anti-spoofing via resource-fetch met Connection's access_token | ✓ |
| Per-Consumer URL `POST /webhooks/mollie/{consumer_id}` | Multiple Connections per Consumer → Hub kan nog niet routeren | |

**Selected:** Per-Connection (D-07/D-08).
**Notes:** Connect-flow = platform-signed (één Mollie-secret). Per-Connection URL geeft Hub direct de Connection zonder lookup. Anti-spoofing-stap (resource-fetch via Connection's token) sluit het pad dat een aanvaller met de platform-secret elke `id` naar elke URL post.

---

## Webhook fan-out + Consumer-callback location

| Option | Description | Selected |
|--------|-------------|----------|
| Per-Connection `webhook_callback_url` | Fijnste granulariteit, ook overkill voor v0.2 | |
| Per-Consumer `webhook_callback_url` (één URL) | Past bij ADR-tekst "fan-out naar Naschool's callback"; één SaaS-app = één URL | ✓ |
| Pass-through `webhookUrl` van Consumer direct naar Mollie | Geen Hub-audit, geen signature-verifie, geen fan-out | |

**Selected:** Per-Consumer (D-09).
**Notes:** Voegt `consumers.webhook_callback_url` + `consumers.webhook_callback_secret` (encrypted, Hub-uitgegeven Hub→Consumer-secret) toe. Outgoing via `spatie/laravel-webhook-server`-queue. Per-Connection override → backlog.

---

## Connect partner-resources scope

| Option | Description | Selected |
|--------|-------------|----------|
| Allebei: 7 in-scope-resources + Connect partner-resources (Onboarding/Organizations/Profiles/…) | Verbreedt 5a met ~5 extra resources | |
| Alleen de 7 ROADMAP-genoemde resources | Backlog `MOLL-CONNECT-RES` blijft staan tot een host-app het productie-blockend maakt | ✓ |

**Selected:** Alleen 7 (D-10).
**Notes:** Pass-through-pattern strekt zich straks zonder schema-change naar Connect-resources uit. Promoten als een merchant via Hub onboard moet.

---

## OAuth-scope-check policy

| Option | Description | Selected |
|--------|-------------|----------|
| Pre-flight scope-check in Hub | Faalt sneller, betere error-hint voor Consumer | |
| Reactief: laat Mollie de 403 sturen, Hub mapt naar 502 | Eénvoudig, geen scope-set-duplicatie tussen Hub en Mollie | ✓ |

**Selected:** Reactief (D-11).
**Notes:** Phase 4 D-11 (hint-response over missende scope) blijft een idee maar landt pas als productie-friction toont dat scope-403's regelmatig optreden. v0.2 wint éénvoud.

---

## Refresh-lock implementation

| Option | Description | Selected |
|--------|-------------|----------|
| Implementeer Phase 4 D-05 (Redis `Cache::lock`) in 5a | Voorkomt dubbele refresh-roundtrips bij parallel-calls op één Connection | |
| Defer lock | v0.2-schaal heeft geen meetbare concurrency per Connection per minuut; lock landt bij eerste incident of Phase 7 | ✓ |

**Selected:** Defer (D-12).
**Notes:** Phase 4 D-04/D-06 lazy refresh werkt al; lock is een optimization-laag.

---

## Mollie upstream-error envelope

| Option | Description | Selected |
|--------|-------------|----------|
| Hergebruik `Snelstart\UpstreamErrorMapper` | Cross-provider sharing | |
| Dedicated `App\Support\Mollie\MollieUpstreamErrorMapper` met eigen tabel | Mollie-exceptions wijken af; provider-specifiek per `upstream-error-mapping.md` ADR | ✓ |

**Selected:** Dedicated mapper (D-13).
**Notes:** `upstream-error-mapping.md` ADR zegt expliciet "Mollie Phase 5a maakt zijn eigen `MollieUpstreamErrorMapper` met afwijkende mapping. Geen gedeelde abstract-class — provider-specifieke logica blijft per provider." `MollieExceptionMapper` (SDK) zet Mollie-side exceptions om naar Emeq-subtypes; Hub-mapper matched op die subtypes.

---

## Mollie-Connection-create endpoint

| Option | Description | Selected |
|--------|-------------|----------|
| Voeg Mollie toe aan `StoreConnectionRequest` (`Rule::in(['snelstart','mollie'])`) | Consumer kan rauwe Mollie-access_token POSTen naar `/v1/connections` | |
| Geen wijziging — Mollie-Connections ontstaan via `/v1/oauth/mollie/init` (Phase 4 D-01) | Connect-flow is de canonical create-route; geen rauwe-token-shortcut | ✓ |

**Selected:** Geen wijziging (D-15).
**Notes:** Een rauwe-access_token-shortcut zou OAuth-flow ondergraven en is een security-anti-pattern (Consumer zou tokens moeten kennen voordat ze door Connect lopen). Documenteer in OpenAPI dat Mollie-Connection-create alleen via OAuth gaat.

---

## Mollie-docs research-precondition

| Option | Description | Selected |
|--------|-------------|----------|
| Plan eerst, research-on-demand tijdens executie | Risico op verzonnen partner-features | |
| Importeer `.docs/partners/mollie/` vóór `/gsd-plan-phase 5a` als blocking precondition | Past bij PROJECT.md / `.ai/rules/global.md` "geen verzonnen partner-features" | ✓ |

**Selected:** Importeer eerst (D-10 ref in CONTEXT).
**Notes:** `.docs/partners/mollie/` heeft nu alleen een README-stub. `/gsd-plan-phase 5a` moet via `gsd-phase-researcher` (of een handmatige `/gsd-research`-pass) eerst de Mollie-API-pagina's voor de 7 resources + webhooks + idempotency + errors landen. Anders schenden we de invariant uit `.ai/rules/global.md`.

---

## Claude's Discretion

- Exacte controller-shape per resource (single-action `__invoke` vs resource-controller met meerdere methods) — bij planning.
- Form Request-veld-diepgang — alleen verplichte velden + types valideren; Mollie zelf valideert de rest.
- Test-organisatie per resource + webhook-ingress-test + audit-test.
- Of `MollieHeaderForwarder` analoog aan `Snelstart\HeaderForwarder` nodig is (Mollie heeft géén If-Match-pad) — waarschijnlijk dunnere whitelist of helemaal niet nodig.

## Deferred Ideas

Volledige lijst in `05a-CONTEXT.md` `<deferred>`. Highlights: `MOLL-CONNECT-RES` backlog-promotion, refresh-lock per-Connection, per-Connection callback-URL-override, scope-hint-response (Phase 4 D-11), retention/partitioning, per-Account rate-limit, cron-based pre-emptive refresh.
