# Handmatige API-requests per partner

Eén map per provider (`docs/<provider>/`), met daarin een `partner/`- en een
`consumer/`-submap, voor handmatig testen vanuit de IDE (JetBrains HTTP Client
of VS Code "REST Client"). Deze map is de DataForSEO-invulling van dat patroon.

## DataForSEO — twee fasen

| Fase | Map | Wat je test |
| --- | --- | --- |
| **1 · partner** | `partner/*.http` | Jouw credentials → `api.dataforseo.com` (geen Hub, geen PAT) |
| **2 · consumer** | `consumer/*.http` | Consumer-contract → Hub `/v1/dataforseo/*` (PAT + Connection) |

Fase 2 vereist de Hub-implementatie op branch `agent/open-seo` (of na merge).
Fase 1 kan altijd, ook op `master`.

### Fase 1 — `partner/` checklist

1. `cp .env.example .env` en vul `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD`,
   `DATAFORSEO_BASIC` (base64 van `login:password`).
2. Draai `partner/domain-overview.http` → verwacht `tasks_error` **0** en
   `tasks[0].status_code` **20000** (top-level 20000 alleen = request aangekomen).
   Gebruik `LOCATION_NAME=Netherlands`; `LANGUAGE_CODE` hoort niet bij dit endpoint.
3. Nieuw account? DataForSEO geeft **40104** tot verificatie in
   [app.dataforseo.com](https://app.dataforseo.com/) klaar is.
4. Eén bestand per DataForSEO-categorie (zelfde `.env`, geen extra setup):
   `partner/domain-overview.http`, `partner/backlinks.http`, `partner/keywords.http`,
   `partner/serp.http`. Die laatste is **async** (task_post → task_get met een
   task-id), geen synchrone call — zie de kop van dat bestand.

### Fase 2 — `consumer/` checklist

1. Checkout `agent/open-seo` (of merge), `composer install`, Hub draait.
2. Root-`.env`: `HUB_PROVIDER_DATAFORSEO_ENABLED=true`.
3. Connection met DataForSEO login:password voor een Account (zelfde credentials
   als fase 1, opgeslagen encrypted op de Connection).
4. `EMEQ_HUB_PAT` in `docs/dataforseo/.env`; `@accountId` = `external_id` van dat
   Account in `consumer/domain-overview.http`.
5. Draai `consumer/domain-overview.http`.

## Secrets (alleen deze map)

PAT en partner-credentials horen **niet** in de root-`.env` van Laravel (behalve
`HUB_PROVIDER_DATAFORSEO_ENABLED` voor de Hub-runtime).

| Bestand | In git | Gebruik |
| --- | --- | --- |
| `.env.example` | ja | template |
| `.env` | nee | REST Client (`{{$dotenv …}}`) |
| `http-client.env.json` | ja | JetBrains — `baseUrl`, `accountId` |
| `http-client.private.env.json.example` | ja | template |
| `http-client.private.env.json` | nee | JetBrains — `token` |

## Overige providers

- Zelfde structuur elders herhalen: `docs/<provider>/partner/*.http` +
  `docs/<provider>/consumer/*.http` — nog toe te voegen voor Snelstart, Mollie, Exact.
- `docs/exact/live-scenarios.http` is een apart, ouder bestand (productie Exact),
  geen onderdeel van deze conventie.

## Conventie per Hub-bestand (fase 2)

- Bovenaan `@baseUrl`, `@token = {{$dotenv EMEQ_HUB_PAT}}`, `@accountId`.
- Minstens één werkend voorbeeld per endpoint, plus belangrijkste foutpaden.
