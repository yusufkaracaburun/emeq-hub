# Grenzen — Hub, SDK, consumer

Wie bezit wat, en waarom daar. Niet de lagen *binnen* de Hub (die staan in
[`unified-api-architecture.md`](unified-api-architecture.md) § Lagen) maar de
drie partijen die samen een boeking doen:

| | |
|---|---|
| **Hub** | `emeq-hub`. Kent de partners, bezit de canonieke betekenis, mint `request_id`. |
| **SDK** | `emeq/hub-sdk`. Eén Laravel-package, geïnstalleerd in elke consumer. Bestaat voor hergebruik en snelle onboarding. |
| **Consumer** | Een SaaS-app: `emeq/system`, naschool, … Bezit zijn eigen documenten en zijn eigen gebruikers. |

## De toetsen

Bij elke "waar hoort dit?" in deze volgorde:

**1. Wie moet er iets doen als dit verandert?**

Een Hub-deploy raakt nul consumers. Een SDK-release raakt ze allemaal: taggen,
`composer update` per consumer, deployen per consumer. Alles wat meebeweegt met
een nieuwe partner of een gewijzigd Hub-antwoord hoort daarom in de Hub — ook
als het "presentatie" lijkt. Wat één keer vaststaat mag in de SDK.

Het aantal providers bepaalt hóe vaak iets verandert; het aantal consumers
bepaalt wat elke verandering kost. De Hub is de goedkope kant van die som.

**2. Lost dit iets op dat élke consumer anders zelf zou bouwen?**

Zo ja: SDK. Dat is waar het package voor bestaat — een nieuwe consumer koppelt
in een middag in plaats van een sprint. Het ledger en de backlog-join staan er
om die reden in, niet omdat de Hub ze niet zou kunnen ([ADR-0003][adr3]).

**3. Is dit het domein van één consumer?**

Dan hoort het dáár. Wat een verkoopfactuur is, wie 'm mag boeken, hoe de PDF
eruitziet — dat weet alleen de consumer.

## De vorm die erbij hoort

**De bron beslist, de laag eronder valt terug wanneer de bron niets zegt.**

Nooit een harde lijst in de SDK die stilzwijgend veroudert. Altijd een fallback
die luid genoeg is. Het patroon staat uitgeschreven in
`HubWebhookEvent::fromWire()`: een event dat de Hub later toevoegt decodeert
naar `UNMAPPED` in plaats van te gooien, dus een nieuwe partner vergt geen
SDK-release. Zo horen `retryable` (`null` = geen mening), `category` en de
foutcopy zich ook te gedragen.

## Wie bezit wat

| | Hub | SDK | Consumer |
|---|---|---|---|
| Partner-wire (OAuth, HTTP, tokens) | ✅ enige | ❌ | ❌ |
| Providerlijst | ✅ `/v1/integrations` | leest | rendert |
| Foutcodes, `category`, `retryable` | ✅ definieert | consumeert | consumeert |
| Foutcopy per code | ✅ bron | geeft door | overschrijft optioneel |
| Copy voor SDK-eigen uitkomsten | ❌ | ✅ bron | overschrijft optioneel |
| `request_id` | ✅ mint | draagt door + persisteert | logt |
| Canonieke document-shape | ✅ definieert + valideert aan de rand | geeft door | vult |
| Ledger + backlog | ❌ | ✅ mechaniek | ✅ bronnen |
| Boekbaar? autorisatie? | ❌ | ❌ | ✅ |
| Domein → canoniek mappen | ❌ | ❌ | ✅ |
| Bijlagen renderen | ❌ | ❌ | ✅ |

### Twee die vaak verkeerd gaan

**Foutcopy hoort in de Hub.** Tegenintuïtief — copy voelt als presentatie. Maar
de Hub weet wélke relatie of grootboekcode ontbreekt (*"Grootboek-code '8000'
niet in de mirror — draai POST /v1/accounting/sync"*), en een regel in de SDK
kan dat niet. Bovendien kost elke herformulering daar een release plus vier
updates, en hier een deploy. De SDK schrijft alleen copy voor wat hij zelf
beslist: de Hub was onbereikbaar, er loopt al een boeking, de bijlage faalde.

**Het ledger hoort in de consumer.** Ook tegenintuïtief — de Hub weet toch wat
er geboekt is? Wel, maar een backlog is een join over de documenten van de
consumer, geen lookup; weigeringen zijn state die de Hub niet consumer-leesbaar
bewaart; en sommige antwoorden beslissen niets. Volledig uitgeschreven in
[ADR-0003][adr3].

## Bewust geaccepteerd

Niet elke duplicatie is de moeite van het opruimen waard. Deze zijn afgewogen en
blijven staan — heropenen alleen met nieuwe aanleiding:

| Wat | Waarom het blijft |
|---|---|
| Endpoint-paden als Request-klassen in de SDK | Inherent aan een getypte client. `Hub::connector()` is de escape-hatch voor alles wat niet gewrapt is; zie `docs/hub-api-coverage.md` in de SDK. |
| Response-vormen in `Testing/fixtures/*.json` | Handmatige refresh staat in dezelfde coverage-doc (§ "Refreshing it"), en hoort in de release-checklist. Een automatische drift-detector is meer machinerie dan hij op deze schaal opbrengt. |
| Canoniek document als untyped `array` | De `errors`-bag uit de 422 geeft de veldnamen bij een shape-fout. Een gegenereerde DTO pas overwegen als mapper-bugs blijven terugkomen. |
| Provider-specifieke endpoints (`/v1/exact/*`, `/v1/mollie/*`) zonder wrapper | Bewust: [ADR-0001][adr1] houdt de SDK provider-agnostisch. Wie provider-specifiek werkt, is beter af met `connector()` dan met een wrapper die neutraliteit suggereert. |

## Zie ook

- [`unified-api-architecture.md`](unified-api-architecture.md) — de lagen bínnen de Hub, en de error-envelope
- [`consumer-onboarding.md`](consumer-onboarding.md) — een consumer aansluiten, stap voor stap
- SDK-ADR's: [0001 provider-agnostisch][adr1], 0002 publish-only migraties, [0003 ledger in de consumer][adr3]

[adr1]: https://github.com/yusufkaracaburun/emeq-hub-sdk/blob/master/docs/adr/0001-provider-agnostic-surface.md
[adr3]: https://github.com/yusufkaracaburun/emeq-hub-sdk/blob/master/docs/adr/0003-the-booking-ledger-lives-in-the-consumer.md
