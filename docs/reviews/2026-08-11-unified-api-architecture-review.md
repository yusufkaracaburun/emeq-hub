# Unified-API architectuurreview — 2026-08-11

Scope: de Unified-API-kern (canoniek documentmodel, capability-laag, pass-through, webhooks,
idempotentie, provider-resolutie) op branch `feat/unified-api-foundation`.

Lens: **provider #2**. Eén vraag — wat zou onverwacht moeten wijzigen als er een tweede
boekhoudprovider bij komt? Alles wat daarop een verrassend antwoord gaf is een bevinding.

**Score: 7,5/10.**

## Wat goed staat

- **Capability-laag.** `Capability::contract()` + `instanceof` kan niet liegen: een adapter die
  een capability claimt zonder het contract te implementeren wordt niet geresolved.
- **Idempotentie in drie lagen.** `Idempotency-Key` → claim met unique index →
  read-back-probe. De derde laag dicht het venster waarin de key al weg is maar de boeking wel
  bestond.
- **Webhook-dedupe**, de **tenant-keten** (Bearer → Consumer → Account → Connection) en het
  **canonieke documentmodel** hielden stand onder de provider-#2-vraag.

## Bevindingen en afhandeling

| # | Bevinding | Status |
|---|---|---|
| C1 | Providerkeuze impliciet: `->first()` zonder `orderBy` bij meerdere koppelingen | opgelost — `X-Provider` + 409 |
| C2a | Runner en lees-endpoints importeerden Exact's foutmapper rechtstreeks | opgelost — `UpstreamErrorMapperRegistry` |
| C3 | Canonieke endpoints eisten `{provider}:write` | opgelost — `accounting:read`/`accounting:write` |
| H1 | Documenttype ontbrak in de canonieke unique | opgelost — `entity_subtype` in de sleutel |
| H2 | Webhook ging naar één koppeling van een administratie | opgelost — naar álle, cross-consumer-lek dicht |
| H3 | Twee eventmodellen: ruwe partner-payload naast canoniek | opgelost — één envelope, `CanonicalEventRegistry` |
| H5a | Drie kopieën van de fan-out-job | opgelost — één `ForwardWebhookToConsumerJob` |
| H5b | Zeven schrijfplekken voor `pass_through_calls` | opgelost — één `PassThroughRecorder` |
| H5c | Pass-through-guards/timing/response 4× gekopieerd (769 LOC) | **open** |
| H4 | `UpdatesDocuments`-capability | **bewust niet gebouwd** — nul implementaties, nul aanroepers; bouwen bij Moneybird |

C1, H2 en H1 zijn bewezen door de fix te stashen en de test te zien falen (409 vs 201,
1 vs 2 dispatches, 200 vs 409).

## Ingetrokken bevindingen

- **M4** — de lege `UploadsAttachments`-marker is een gedocumenteerde keuze, geen smell.
- **Mollie-fan-out op de default-queue** — bewuste capaciteitskeuze, vastgelegd in `b0c612c`.
  Twee keer als defect benoemd; dat was fout.

## Structurele opvolging

De C2a-bevinding was geen slordigheid maar een gevolg van de indeling: met alle foutmappers in
één techniekmap is de verkeerde kiezen één regel werk. Daarom verhuisde de integratielaag naar
`app/Integrations` (één map per provider, gedeelde laag zonder providernamen), met
`tests/Architecture/` als handhaving. ADR: `.docs/decisions/integration-layer-structure.md`.

## Naamgeving

`Provider` = identiteit (enum-case, config-key, `connections.provider`). `Integration` = de
code die die provider implementeert. `/v1/integrations` en `integrations:manage` houden hun
betekenis; nul renames nodig.
