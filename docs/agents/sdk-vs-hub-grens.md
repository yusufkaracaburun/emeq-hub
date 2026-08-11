# Waarom Exact-code in de Hub staat en niet in de SDK

Aanleiding: *"waarom zet je alle Exact-code niet in `packages/exact-api` maar in
emeq-hub zelf? Als ik meerdere apps ga koppelen, wordt emeq-hub gigantisch."*

Terechte zorg. Hieronder de meting in plaats van een principe.

## Wat het nu is

**4.501 van 28.431 regels in `app/` dragen Exact in hun pad — 15%, over 25 bestanden.**

De grens die `CLAUDE.md` stelt: een SDK is *HTTP + auth + DTO's*. Hub-domeinmodellen
(`Connection`, `Account`, `Consumer`), multi-tenancy, audit en webhook-routing horen
in de Hub. Het criterium per bestand is dus simpel: **raakt het een Hub-tabel of een
Hub-begrip? Dan kan het niet naar de SDK.**

| Bestand | Regels | Waarom het in de Hub hoort |
|---|---:|---|
| `Integrations/Exact/Accounting/ExactAccountingTarget` | 585 | Canoniek `FinancialDocument` → Exact; leest `Connection.administratie_id`, spreekt `ReferenceResolver` aan |
| `Integrations/Exact/ExactReferenceData` | 463 | **Deels SDK-materiaal** — zie hieronder |
| `Http/Controllers/Dev/ExactOAuthTracerController` | 328 | Dev-tooling tegen Hub-Connections |
| `Integrations/Exact/Accounting/ExactReportEnricher` | 250 | Leest de mirror-tabel |
| `Integrations/Exact/Errors/UpstreamErrorMapper` | 235 | Vertaalt naar het Hub-foutcontract |
| `Integrations/Exact/ExactWebhookSubscriptionManager` | 223 | Schrijft in `connections.metadata`, leidt de callback-URL af |
| `Integrations/Exact/OAuth/ExactOAuthFlow` | 216 | Maakt en muteert `Connection`-rijen |
| `Integrations/Exact/Accounting/ConnectionMappingExactReferenceResolver` | 199 | Leest `connections.metadata.accounting_mapping` |
| `Integrations/Exact/Accounting/ExactRelationResolver` | 196 | Leest en schrijft `connection_accounting_refs` |
| `Integrations/Exact/PassThrough/ExactForwarder` | 161 | Schrijft `pass_through_calls`, past de circuit-breaker toe |
| `Http/Controllers/ExactDeprovisionController` | 154 | App Center-lifecycle tegen Connections |
| `Console/Commands/Exact/*` | 220 | Operaties op Hub-tabellen |
| `Integrations/Exact/Accounting/ExactMappingDeriver` | 141 | Schrijft `connections.metadata` |
| `Http/Controllers/Webhooks/ExactWebhookController` | 105 | Tenant-routing Division → Connection |
| Vier named-resource-controllers + pass-through | ~250 | HTTP, abilities, audit |
| Rest (jobs, tokenstore, whitelist, error-budget) | ~575 | Queue, Connection-tokens, Hub-config |

Zet je dit in de SDK, dan moeten `Connection`, `Account`, `Consumer`,
`pass_through_calls` en `connection_accounting_refs` mee. Dan is het geen SDK meer maar
een tweede Hub, en zou elke andere consument van dat pakket die tabellen erbij krijgen.

## Wat wél naar de SDK kan

`ExactReferenceData` (463 regels) is grotendeels OData-query-bouw en
respons-normalisatie: `mirrorRows()`, percentage × 100, `attrs.type`. Dat is wire-kennis
en hoort per de eigen laag-grens in de SDK. Wat in de Hub moet blijven is de dunne schil
eromheen die per Connection bindt.

Grove schatting: **250–300 regels verplaatsbaar.** Dat is 6% van de Exact-code, geen
oplossing voor de groeizorg — maar wel de juiste plek.

## Wat provider #2 werkelijk kost

Niet nog eens 4.501 regels. Een flink deel is eenmalig Exact-specifiek en heeft geen
tegenhanger: de App Center-deprovision (154), de OAuth-tracer (328), de purge- en
backfill-commands (220), de path-whitelist en het error-budget (136). Samen ~840 regels
die niet meeschalen.

Wat een tweede provider wél kost: een adapter, een OAuth-flow, een reference-resolver,
een credential-resolver en een tokenstore. Naar de omvang van de Exact-equivalenten:
**1.200 à 1.500 regels.**

Bij vijf providers kom je daarmee rond de 34.000 regels in `app/`. Dat is een normale
Laravel-app, geen monster.

## Wat wél slecht schaalt

Niet het aantal regels — de koppeling. Vijf providers is pas een probleem als hun code
zich door de gedeelde lagen heen weeft: `if ($provider === …)` in controllers,
provider-termen in canonieke modellen, per-provider takken in de audit.

Dat is exact wat dit traject heeft aangepakt. De stand nu:

- nul provider-conditionals in de accounting-controllers
- capabilities afgeleid uit `implements`, niet uit een lijst die kan verlopen
- canonieke DTO's zonder partner-term in enig veld
- één `ReferenceResolver`-contract in plaats van een Exact-specifieke seam

Daardoor is provider-code een blad in de boom en niet een draad erdoorheen. Vijf
providers zijn dan vijf mappen, niet vijf keer de complexiteit.

## De uitweg die dit openhoudt

Zodra de contracten stabiel zijn — en dat weet je pas na provider #2 — kan
`App\Accounting\<Provider>\` een los pakket worden: `emeq/<provider>-hub-adapter`, dat
tegen `App\Accounting\Contracts\*` aan bouwt. De Hub houdt dan de canonieke laag, de
registry en de tabellen; elke provider wordt een installeerbare afhankelijkheid.

Dat kán nu pas overwogen worden dankzij die contracten. Vóór dit traject zat de
Exact-kennis zo door de controllers heen dat er niets uit te lichten viel.

Aanbeveling: niet nu doen. Eén pakket per provider met één implementatie is
overhead zonder opbrengst. Heroverwegen bij provider #3, of eerder als een provider een
eigen release-ritme nodig heeft.
