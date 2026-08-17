# Unified API — architectuur

> **Status:** levend document. Elke fase uit `.claude/plans/` vult zijn eigen sectie
> aan. Secties gemarkeerd met 🚧 beschrijven de doelarchitectuur en zijn nog niet
> geïmplementeerd — zie `unified-api-roadmap.md` voor wat er wél draait.

## Waarom

Een consumer integreert **één keer** met de Hub en spreekt het Emeq-canonieke
contract. Welke boekhoudpartner er onder de motorkap hangt — Exact, Moneybird,
Snelstart — is de verantwoordelijkheid van de Hub, niet van de consumer.

```
Consumer ──canoniek──> Emeq Hub ──┬──> Exact Online
                                  ├──> Moneybird      (gepland)
                                  └──> Snelstart      (nu pass-through)
```

Dat betekent concreet: `POST /v1/accounting/documents` werkt identiek ongeacht de
gekoppelde provider, en `GET /v1/accounting/*` levert canonieke resources terug.

Provider-specifieke endpoints (`/v1/exact/*`, `/v1/snelstart/*`) blijven bestaan als
**escape hatch** voor consumers die iets nodig hebben wat het canonieke contract niet
dekt. Ze zijn expliciet niet de hoofdweg.

## Lagen

De scheiding die het systeem overeind houdt:

| Laag | Waar | Verantwoordelijkheid |
|---|---|---|
| Canoniek domein | `App\Accounting\*` (DTO's + enums) | Provider-neutrale betekenis. Nul partner-termen |
| Unified API | `App\Http\Controllers\Api\V1\Accounting\*` | HTTP-contract. Kent géén providernaam |
| Capability-registry | `App\Accounting\AccountingTargetRegistry` | Wat kan deze provider? |
| Provider-adapter | `App\Integrations\Exact\Accounting\ExactAccountingTarget` | Canoniek ⇄ partner-payload |
| Mapping / transformatie | in de adapter — zie hieronder | Canoniek → partner-payload |
| Referentie-resolutie | `App\Accounting\Contracts\ReferenceResolver` | Canonieke sleutel → provider-identiteit |
| Sync-state | `provider_entity_links` | Canonieke entity ⇄ provider-entity |
| Event-normalisatie | `App\Integrations\Webhooks\CanonicalEventRegistry` | Partner-webhook → canoniek event |
| Transport / auth | `emeq/*-api` SDK's + `Connection` | HTTP, OAuth, tokens |

De regel die alles bij elkaar houdt: **provider-kennis daalt naar de adapter**. Een
`if ($provider === 'exact')` buiten `app/*/Exact/` is een bug, geen stijlkwestie.

## Canoniek domein

Plain `final readonly` PHP-klassen in `App\Accounting`, geen Eloquent — een canoniek
document is een waarde, geen rij.

**Schrijfzijde** (bestaat):

| Type | Velden (kern) |
|---|---|
| `FinancialDocument` | `type`, `externalId`, `party`, `lines[]`, `issueDate`, `dueDate`, `number`, `reference`, `currency`, `pricesIncludeTax`, `attachments[]` |
| `FinancialDocumentLine` | `description`, `amount` (netto), `taxRate`, `quantity`, `unitPrice`, `category`, `costCenter`, `costUnit`, `taxTreatment` |
| `Party` | `role` (`debtor`/`creditor`), `name`, `vatNumber`, `iban`, `externalId` — draagt bewust géén provider-GUID |
| `Attachment` | `filename`, `mimeType`, `content` (base64) |

Enums: `DocumentType` (`sales_invoice`, `purchase_invoice`, `income`, `expense`,
`credit_note`), `TaxTreatment` (`standard`, `reverse_charge`), `SyncStatus`.

**Leeszijde**: `LedgerAccount`, `TaxCode`, `Relation` (debiteur én crediteur — één
type met een `role`, want beide partners kennen één relatie-entiteit met rolvlaggen),
en `PostedDocument` + `PostedDocumentLine`.

Elk read-type draagt een `id` dat **ondoorzichtig** is: gebruik 'm om terug te
verwijzen, lees er geen betekenis in. Vandaag is het de partner-identiteit; dat is
geen belofte. Wat wél betekenis heeft staat apart — `code` op een grootboekrekening
is het nummer dat de boekhouder kent en dat je in `line.category` kunt terugleggen.

Waarom géén `App\Domain\Accounting`: de repo is capability-at-root
(`App\Accounting`, `App\Books`, `App\Billing`, `App\Webhooks`), met de provider als
sub-namespace. Een DDD-prefix zou de enige in de boom zijn.

## Schrijfpad

```
POST /v1/accounting/documents
  → EnsureIdempotency            claim-first, unique index als mutex
  → DocumentsController          resolve Consumer→Account→Connection
  → StoreDocumentRequest         edge-validatie (snake_case wire)
  → FinancialDocument::fromArray canoniek object
  → AccountingSyncRunner         dedupe-check op provider_entity_links
  → AccountingTargetRegistry     provider → adapter, Pennant kill-switch
  → ExactAccountingTarget        canoniek → Exact-payload
      → ReferenceResolver        canonieke sleutel → Exact-identiteit
      → emeq/exact-api (Saloon)  HTTP
  ← AccountingResult
  → provider_entity_links        link vastleggen
  → PassThroughCall              audit-rij (altijd, ook bij falen)
```

Async-variant: `Prefer: respond-async` → 202 `pending` → `SyncAccountingDocumentJob`
→ zelfde `AccountingSyncRunner` → resultaat via consumer-webhook. De dedupe- en
audit-bescherming zit in de runner, niet in de controller, juist zodat beide paden
hem krijgen.

## Leespad

```
GET /v1/accounting/{ledger-accounts,tax-codes,customers,suppliers}
  → ReadController               resolve connection, ability, capability
  → AccountingTargetRegistry     contract of null
  → ExactAccountingTarget        Reads*-implementatie
      ├─ mirror   (grootboek, btw)   → MirrorReader, geen partner-call
      └─ live     (relaties)         → emeq/exact-api
  ← ReadPage<T> → { data, next_cursor, has_more }
```

Twee bronnen, bewust verschillend:

- **Mirror** (`connection_accounting_refs`) voor stabiele referentiedata. Geen
  partner-call: die zou verspilling zijn én een extra faalpunt. `MirrorReader` is
  gedeeld — het schema is niet provider-specifiek, dus de leescode ook niet. Wat per
  provider verschilt is hoe de mirror gevúld wordt.
- **Live** via de SDK voor bewegende data (relaties). Faalt hard: een lege lijst
  teruggeven terwijl de partner plat ligt is een leugen. Dat is het verschil met
  `ExactReferenceData`, dat bewust fail-soft is voor de admin-UI.

`/customers` en `/suppliers` zijn twee ingangen op één bron — beide partners die we
kennen hebben één relatie-entiteit met rolvlaggen, net als het canonieke `Party` op
de schrijfzijde.

**Paginatie** is cursor-based (`?cursor=&limit=`), niet offset: Exact OData pagineert
met `$skiptoken` en `?page=3` zou een leugen zijn die de Hub zou moeten nabouwen. De
cursor is voor de consumer ondoorzichtig — voor de mirror is het de laatst geziene
code (keyset op de unieke index), voor een live lijst het `$skiptoken` uit Exact's
`__next`. Door 'm te verpakken belooft het contract niets over die vorm.

Bewust géén `total`: Exact levert dat niet zonder tweede call, en een veld dat bij de
ene provider klopt en bij de andere ontbreekt is erger dan geen veld.

`GET /v1/accounting/documents` is de leeskant van de POST op datzelfde pad — één
begrip, één resource, `?type=` als filter. Geen `/invoices` plus `/bills`: verkoop en
inkoop zijn hetzelfde soort ding met een andere richting, en twee paden zouden twee
canonieke concepten suggereren waar er één is.

Het leest terug uit precies de resources waar `push()` naartoe schrijft
(`salesentry/SalesEntries`, `purchaseentry/PurchaseEntries`), dus wat je stuurde krijg
je terug. Twee keuzes daarbij:

- **Alleen bewezen velden worden opgevraagd** — die uit de write-body plus
  `EntryID`/`EntryNumber`, die de SDK al uit de create-respons leest. Geen gegokte
  Exact-veldnamen.
- **Het totaal komt uit de regels, niet uit een header-veld.** Zo'n veld betekent per
  pakket iets anders (met of zonder btw, in valuta of in administratie-valuta) en dat
  verschil hoort niet in een canoniek antwoord.

`PostedDocument` is bewust een ander type dan `FinancialDocument`: dat is wat je
stuurt, dit is wat er ligt. Een geboekt document heeft een partner-identiteit en een
boekstuknummer, en mist de bijlagen en de vrije `category`-hint die alleen bij het
schrijven bestaan. Eén type voor allebei zou op elk veld een slag om de arm nodig
hebben.

`external_id` wordt teruggelezen uit de provenance die de Hub bij het boeken in
`YourRef` meeschreef. Documenten die buiten de Hub om zijn ingevoerd hebben er geen —
dan is `null` het eerlijke antwoord. Relatienamen komen uit de mirror in één query per
pagina, want Exact levert bij een boeking alleen de relatie-GUID.

## Waarom er geen transformation-pipeline is

Het oorspronkelijke plan had een gefaseerde pipeline (validatie → normalisatie →
monetair → datum → reference-resolutie → provider-hook). Die is er niet gekomen, en
dat is een meting en geen vergetelheid.

De volledige canoniek→Exact-transformatie is 48 regels: `buildRequest()`, `lines()` en
`provenance()`. Uitgesplitst:

| Onderdeel | Regels | Deelbaar met een tweede provider? |
|---|---|---|
| `match ($type)` → SDK-request-klasse | 24 | Nee — dit ís de providerkeuze |
| Regelmapping via `ReferenceResolver` | 13 | Zit al achter een seam; een stage voegt alleen indirectie toe |
| Datumformaat | 2 | Een configwaarde, geen stage |
| `description = number ?? externalId` | 1 | Ja |
| Provenance-stempel | 5 | Concept wel, `YourRef` + de limiet van 50 niet |

Netto zo'n negen deelbare regels, waarvan zes one-liners. Een pipeline zou die negen
regels in stages verpakken en daarbovenop een hook-mechanisme nodig hebben voor alles
wat wél provider-specifiek is — dus voor het merendeel. De adapter wordt daar groter
van, niet kleiner.

Het criterium was: de adapter moet krimpen en geen stage mag provider-only zijn zonder
als hook gemarkeerd te staan. Dat haalt hij niet. De seam die er wél toe doet bestaat
al — `ReferenceResolver` — en die vangt precies het deel op dat per provider verschilt
zonder de rest te abstraheren.

Heroverwegen zodra Moneybird er is. Dán is er een tweede datapunt en blijkt vanzelf
wat werkelijk gedeeld is; nu zou elke stage-grens een gok zijn met Exact als enige
voorbeeld.

## Capabilities

Een capability is aanwezig **dan en slechts dan als** de geregistreerde adapter het
bijbehorende interface implementeert. Declaratie en gedrag kunnen niet uit elkaar
lopen, want het is hetzelfde feit. Geen capability-lijst in config — config kan
liegen tegen de code, `implements` niet.

```
GET /v1/accounting/capabilities
{
  "provider": "exact",
  "enabled": true,
  "capabilities": ["accounting.documents.write", "accounting.documents.attachments",
                   "accounting.ledger_accounts.read", "…"]
}
```

`enabled` is de orthogonale as: de Pennant-kill-switch. Een uitgeschakelde provider
*declareert* nog steeds wat hij kan.

## Referentie-resolutie

Canonieke sleutels zijn provider-onafhankelijk; de vertaling naar een
partner-identiteit gebeurt per connectie.

| Canoniek | Bron | Exact |
|---|---|---|
| `"21"`, `"9"`, `"0"` | `connections.metadata.accounting_mapping.vat_codes` | VATCode |
| `"reverse_charge:21"` | idem | VATCode voor verlegde btw |
| `"_default"`, `"omzet"`, `"kosten"`, `<category>` | `…gl_accounts` | GL-Code → GUID via mirror |
| `"sales"`, `"purchase"`, `"income"`, `"expense"` | `…journals` | Dagboek-code |

De mirror (`connection_accounting_refs`) is al provider-neutraal:
`connection_id · kind · code · native_id · label · attrs · synced_at`, uniek op
`(connection_id, kind, code)`. `kind` ∈ `gl | vat | journal | relation |
cost_center | cost_unit`.

De mapping wordt na connect automatisch afgeleid uit de mirror
(`ExactMappingDeriver`) en is daarna handmatig te overschrijven via
`PUT /v1/accounting/mapping`.

## Sync-state

`provider_entity_links` beantwoordt vandaag: welke canonieke entity hoort bij welke
provider-entity, met welke inhoud is die geschreven, en wie was de bron.

Provider-neutraal schema, uniek op `(connection_id, entity_type, external_id)` én
op `(connection_id, entity_type, provider_entity_id)` — beide richtingen 1:1.

Bij een schrijfactie beslist de tabel vóór de partner-call:

| link-staat | gedrag |
|---|---|
| geen link | normaal boeken, daarna vastleggen |
| link, gelijke `payload_fingerprint` | niet boeken → `200` + `deduplicated: true` |
| link, andere fingerprint | niet boeken → `409 document_already_posted` |

De fingerprint (`DocumentFingerprint`) hasht de **betekenis** van het document, niet
de HTTP-bytes: sleutelvolgorde en `200` versus `200.00` mogen het antwoord niet
veranderen. Regelvolgorde telt wél mee — de adapters kennen geen update-pad, dus
omgekeerde regels zijn een andere boeking.

Bij een onbeslist antwoord (502/503/504 — géén antwoord, niet "geweigerd") vraagt de
runner de partner na via `ProbesPostedDocuments`, op de herkomst die de adapter bij het
boeken meeschreef. Dat dicht het laatste herboek-venster. Bewust géén
`provider_version`/`canonical_version`-kolommen: er is nog niets dat ze leest, en een
kolom zonder lezer is schuld.

## Idempotentie

De consumer stuurt een `Idempotency-Key`. De Hub claimt die met één INSERT vóórdat de
handler draait; de unique index op `(consumer_id, key)` is de mutex. Geen transactie om
de handler heen — die zou een DB-connectie vasthouden voor de duur van een HTTP-call
naar de partner.

```
INSERT slaagt ────────────────────► in_flight ──2xx──► completed ──expires_at──► pruned
     │                                  │  ▲               │
     │ unique violation                 │  │ takeover      │ replay + Idempotent-Replayed
     ▼                                  │  │ (lease weg)   ▼
  bestaande rij bekijken ───────────────┴──┘          retry met zelfde key
     ├─ completed              → replay
     ├─ andere fingerprint     → 422 idempotency_key_reuse
     ├─ in_flight, lease leeft → 409 + Retry-After
     └─ rij verdwenen          → 409 + Retry-After: 1
```

Non-2xx **verwijdert** de rij: een mislukte poging mag opnieuw.

De lease-invariant staat in `config/hub.php` en is niet vrijblijvend: te lang kost
uitstel, te kort veroorzaakt dubbele boekingen. Daarom is het aantal bijlagen per
document begrensd — anders is de maximale request-duur niet te bepalen.

Wat de Hub wél en niet garandeert:

| Garantie | Status |
|---|---|
| Twee gelijktijdige requests met dezelfde key boeken één keer | ja — 409 op de tweede |
| Retry na netwerkfout replay't de eerste respons | ja, binnen de retentie (24u) |
| Retry ná key-verval boekt niet opnieuw | ja — via `provider_entity_links` |
| Zelfde key met een ander payload | 422, geen stille verkeerde replay |
| Gecrasht request blokkeert de sleutel niet permanent | ja — lease-takeover, en prune als achtervang |
| Provider commit + timeout richting Hub | **niet** gedekt zonder read-back-probe (fase 9) |

Exactly-once bestaat niet zolang de partner het niet garandeert. Dit is wat we
werkelijk leveren, expliciet gemodelleerd.

## Event-normalisatie 🚧

```
Partner-webhook → Provider Event Adapter → canoniek event → dedupe
                                                          → loop-detectie
                                                          → consumer-webhook
```

Waar een partner alleen een id stuurt (Exact), haalt de adapter de volle resource op
vóór normalisatie — dat hoort in de adapter, nergens anders.

Envelope: `event_id`, `event_type`, `occurred_at`, `connection_id`, `resource_type`,
`resource_id`, `origin`, `data`, `metadata`.

Event-types per domein: `accounting.document.*`, `accounting.customer.*`,
`payment.*`. Betalingen worden **niet** in het accounting-domein geperst.

## Error-normalisatie

Een consumer hoeft Exact's foutvormen niet te kennen. Elke `/v1/*`-fout draagt naast
de bestaande `error`-sleutel een `category`, een `retryable` en het `request_id`:

```json
{ "error": "mapping_failed", "category": "REFERENCE_MAPPING_MISSING",
  "retryable": false, "message": "…", "request_id": "01H…" }
```

Categorieën: `VALIDATION_ERROR`, `AUTHENTICATION_ERROR`, `AUTHORIZATION_ERROR`,
`RATE_LIMITED`, `RESOURCE_NOT_FOUND`, `CONFLICT`, `PROVIDER_UNAVAILABLE`,
`UNSUPPORTED_CAPABILITY`, `REFERENCE_MAPPING_MISSING`, `PROVIDER_ERROR`,
`INTERNAL_ERROR`. Die laatste is bewust apart van `PROVIDER_ERROR`: onze storing en
die van de partner sturen een consumer een andere kant op.

De categorie volgt in de regel uit de HTTP-status; alleen waar de code méér zegt dan
de status staat een override (`mapping_failed` is geen invoerfout, `upstream_rejected`
is de partner die weigert, `idempotency_key_reuse` is een conflict).

`retryable` beantwoordt de enige vraag die een consumer echt moet stellen: heeft
hetzelfde request nog eens sturen zin? Het staat in de envelope omdat het antwoord
hier hoort en niet daar — een SDK die het zelf afleidt houdt een lijst foutcodes bij
die stilzwijgend veroudert zodra de Hub er één toevoegt, in de richting van "voorgoed
mislukt" voor een document dat niemand geweigerd heeft.

Ook hier wint de code van de categorie waar hij meer weet:
`idempotency_request_in_progress` en `document_sync_in_progress` zijn 409's, dus
`CONFLICT`, dus normaal definitief — maar ze betekenen "wacht even". Zie
`ErrorCode::retryableFor()`.

De `message` is de zin die de eindgebruiker leest, en die hoort hier omdat de Hub
weet wélke relatie of grootboekcode ontbreekt en wat eraan te doen is. Een SDK-regel
kan dat niet, en zou elke herformulering bovendien een release plus een update per
consumer kosten. Zie `docs/architecture-boundaries.md`.

`NormalizeApiErrors` legt de envelope er buitenom overheen, dus ook over
framework-fouten (validatie, 404, throttle) en over `abort_unless`-paden die alleen
een `message` produceerden. Additief: `error` verandert niet, en de historische
`code`-sleutel op de 401 blijft staan.

**Wat bewust níet gedeeld is:** de drie `UpstreamErrorMapper`s (Exact, Snelstart,
Mollie) blijven los. Ze delen een return-shape, geen logica — Exact parseert
OData-enveloppen en humaniseert btw-meldingen, de andere twee hebben eigen
exception-hiërarchieën. Samenvoegen zou provider-semantiek in gedeelde code duwen.
Partner-diagnostiek gaat naar logs, nooit tokens of headers.

## Observability

Eén `request_id` (ULID) van consumer-request tot consumer-webhook:

```
X-Request-Id → Context → logs
                       → pass_through_calls.request_id
                       → inbound_webhook_events.request_id
                       → queued jobs (via Context-dehydrate)
                       → X-Emeq-Request-Id op de consumer-webhook
```

Onder Octane is `Context` een `scoped()` binding die per request geflusht wordt —
geen statics, geen lekkende proces-globals.

## Nieuwe provider toevoegen

1. SDK-package `emeq/<provider>-api` — HTTP, auth, DTO's. **Geen** Hub-domeinmodellen,
   geen webhook-routing, geen multi-tenancy. Zie skill `add-provider`.
2. `Provider`-enum-case + `config/hub-providers.php`-entry (credentials + Pennant-vlag).
3. `OAuthFlow`-implementatie, geregistreerd in `AppServiceProvider`.
4. Adapter `App\Accounting\<Provider>\<Provider>AccountingTarget` die
   `AccountingTarget` implementeert, plus de capability-interfaces die hij écht
   waarmaakt. Niets stubben.
5. Eén registratieregel in `AppServiceProvider`.
6. `ReferenceResolver`-implementatie + mirror-vulling.

Wat je **niet** hoeft aan te raken: controllers, routes, canonieke DTO's, de
idempotency-laag, de audit-laag.

## Nieuwe resource toevoegen

1. Canonieke DTO in `App\Accounting`, zonder partner-term.
2. Capability-case + het bijbehorende lees- of schrijfcontract.
3. Adapter-implementatie per provider die hem ondersteunt.
4. Endpoint dat de capability opvraagt en `422 unsupported_capability` geeft als hij
   ontbreekt.
5. Tests: canoniek ⇄ provider op unit-niveau, HTTP-gedrag op feature-niveau.

## Zie ook

- `unified-api-roadmap.md` — wat draait, wat volgt
- `consumer-integration-guide.md` — het contract vanuit de consumer
- `agents/architecture.md` — lagen en componenten Hub-breed
- `agents/subsystems.md` — per subsysteem de gotchas
