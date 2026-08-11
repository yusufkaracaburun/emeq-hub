# Unified API — roadmap

> Niets staat onder **IMPLEMENTED** zonder werkende code én tests. Bij twijfel gaat
> het naar NEXT. Architectuur staat in `unified-api-architecture.md`.

Laatste update: 2026-08-11 · suite: 1249 passed, 1 incomplete, 0 failures.
Startbaseline vóór dit traject: 1120 passed · PHPStan-baseline 206 → 125.

---

## IMPLEMENTED

Wat vandaag draait en gedekt is door tests.

### Canoniek schrijfdomein
`FinancialDocument`, `FinancialDocumentLine`, `Party`, `Attachment` — provider-neutrale
`readonly` DTO's met `DocumentType`, `TaxTreatment`, `SyncStatus`. Nul partner-termen.
→ `app/Accounting/`, `tests/Unit/Accounting/`

### Provider-onafhankelijk schrijfpad
`POST /v1/accounting/documents` → `AccountingSyncRunner` → `AccountingTargetRegistry`
→ adapter. De controller kent geen providernaam; de registry mapt provider → adapter
en past de Pennant-kill-switch toe.
→ `tests/Feature/Api/V1/Accounting/StoreDocumentTest.php` (34 tests)

### Async schrijfpad
`Prefer: respond-async` → 202 `pending` → `SyncAccountingDocumentJob` → dezelfde runner
→ resultaat via consumer-webhook.
→ `tests/Feature/Api/V1/Accounting/AsyncStoreDocumentTest.php`

### Exact-adapter
`ExactAccountingTarget` — verkoop- en inkoopboekingen, bijlage-upload, verlegde btw,
kostenplaats/-drager, `external_number`, provenance in `YourRef`.
→ `tests/Feature/Api/V1/Accounting/StoreDocumentTest.php`

### Referentie-mirror + auto-mapping
`connection_accounting_refs` (provider-neutraal schema) gevuld door `ExactReferenceSync`;
default-mapping automatisch afgeleid door `ExactMappingDeriver`; handmatig te
overschrijven via `GET|PUT /v1/accounting/mapping`.
→ `tests/Feature/Accounting/ExactReferenceSyncTest.php`,
`tests/Feature/Accounting/ExactMappingDeriverTest.php`,
`tests/Unit/Accounting/ConnectionMappingExactReferenceResolverTest.php`

### Dry-run-validatie
`POST /v1/accounting/documents/validate` — provider-agnostische `DocumentInspector` +
Exact-specifieke enrichment. Rapporteert findings zonder te boeken.
→ `tests/Feature/Api/V1/Accounting/ValidateDocumentTest.php`

### Idempotentie met claim-lock
`Idempotency-Key` op `POST /v1/accounting/documents`. De rij wordt met één INSERT
geclaimd vóórdat de handler draait; de unique index op `(consumer_id, key)` is de
mutex. Twee gelijktijdige requests boeken dus één keer — de tweede krijgt `409
idempotency_request_in_progress` met `Retry-After` in plaats van een tweede boeking
gevolgd door een 500. Zelfde sleutel met een ander document → `422
idempotency_key_reuse` in plaats van een stille verkeerde replay. Een herhaalde
respons draagt `Idempotent-Replayed: true`. Een gecrasht request blokkeert de sleutel
niet permanent: de lease is over te nemen, en `model:prune` ruimt vervallen rijen op —
die groeiden voorheen onbegrensd.
→ `tests/Feature/Api/V1/Accounting/IdempotencyTest.php`,
`ModelPruningTest::test_prunes_expired_idempotency_keys`

### Provider-entity-links + dedupe
`provider_entity_links` legt per Connection vast welk canoniek `external_id` bij welke
partner-entity hoort, met een semantische `DocumentFingerprint` van het document.
Tweede verdedigingslijn naast de idempotency-key: die vervalt, deze niet. De check
zit in `AccountingSyncRunner`, dus het sync- én het async-pad krijgen hem.
Gelijke inhoud → `200 deduplicated: true` met de eerdere referentie, zonder de partner
te raken. Afwijkende inhoud onder hetzelfde `external_id` → `409
document_already_posted`; de adapters kennen geen update-pad, dus dat zou een tweede
boeking voor één brondocument worden. Beide takken landen in `pass_through_calls`.
→ `tests/Feature/Api/V1/Accounting/ProviderEntityLinkTest.php`,
`tests/Unit/Accounting/DocumentFingerprintTest.php`,
`AsyncStoreDocumentTest::test_async_job_deduplicates_a_repeat_of_the_same_document`

### Capability-laag
`Capability` (gesloten enum) + drie 1-methode-contracten in `App\Accounting\Contracts`.
Een capability is aanwezig dan en slechts dan als de geregistreerde adapter het
contract implementeert — dus geen lijst in config die kan liegen tegen de code. De
registry beantwoordt de vraag met reflectie, zonder de adapter te bouwen.
`GET /v1/accounting/capabilities` geeft `{provider, enabled, capabilities[]}`; `enabled`
is de losse Pennant-as, want een uitgeschakelde provider declareert nog steeds wat hij
kan. Hiermee zijn beide provider-conditionals uit de accounting-controllers verdwenen,
en belt de dry-run Exact niet meer terwijl de kill-switch uit staat.
Bijvangst: `ExactReferenceResolver` → `App\Accounting\Contracts\ReferenceResolver` met
`relationRef`/`glAccountRef` in plaats van `…Guid` (een GUID is een Exact-vorm, geen
canonieke). Nul wijzigingen aan de mapping-key-formaten in
`connections.metadata.accounting_mapping` — daar staat productiedata.
→ `tests/Unit/Accounting/AccountingTargetRegistryCapabilityTest.php`,
`tests/Feature/Api/V1/Accounting/CapabilitiesApiTest.php`,
`MappingApiTest::test_sync_returns_422_when_the_provider_cannot_sync_references`,
`ValidateDocumentTest::test_enrichment_is_skipped_and_no_partner_call_is_made_when_the_provider_is_off`

### Pass-through escape hatch
`/v1/exact/*` en `/v1/snelstart/*` met path-whitelist, ability-guard en audit naar
`pass_through_calls`. Bedoeld als uitzondering, niet als hoofdweg.
→ `tests/Feature/Api/V1/Exact/`, `tests/Feature/Api/V1/Snelstart/`

### Inbound webhook-audit
Metadata-only registratie in `inbound_webhook_events` via `InboundWebhookRecorder`
(AVG: de Hub is verwerker, geen payload of headers opgeslagen).
→ `tests/Feature/Webhooks/InboundWebhookRecorderTest.php`

### Correlation-id end-to-end
Eén `request_id` (ULID, of een gevalideerde inbound `X-Request-Id`) van
consumer-request tot consumer-webhook: in `Context` en dus in elke logregel en elke
queued job, op `pass_through_calls.request_id` en `inbound_webhook_events.request_id`
via een model-hook, terug op de response-header, en als `X-Emeq-Request-Id` op alle
vijf de outbound fan-outs. Zichtbaar in beide Filament-infolists.
→ `tests/Feature/Api/RequestIdTest.php`,
`tests/Feature/Api/V1/Accounting/RequestIdCorrelationTest.php`,
`tests/Unit/Webhooks/ConsumerWebhookHeadersTest.php`

### Error-normalisatie
Elke `/v1/*`-fout draagt naast `error` een provider-onafhankelijke `category` en het
`request_id`. `NormalizeApiErrors` legt die envelope buitenom over de hele stack heen,
dus ook over framework-fouten en over `abort_unless`-paden die alleen een `message`
teruggaven. De categorie volgt uit de status, met een override waar de code méér zegt.
Additief — `error` en de historische `code` op de 401 blijven onaangeroerd.
→ `tests/Unit/Support/Errors/ErrorCodeTest.php`,
`tests/Feature/Api/ErrorEnvelopeTest.php`

### Canoniek lees-pad (referentiedata + relaties)
`GET /v1/accounting/{ledger-accounts,tax-codes,customers,suppliers}` met één
antwoordvorm: `{data, next_cursor, has_more}`. Capability-gated, dus de controller
kent geen providernaam. Grootboek en btw komen uit de mirror (geen partner-call, met
een test die dat vastlegt); relaties live en fail-hard — een lege lijst teruggeven
terwijl de partner plat ligt is een leugen. Cursor-paginatie, ondoorzichtig voor de
consumer: keyset op `code` voor de mirror, Exact's `$skiptoken` voor de live lijst.
Canonieke types `LedgerAccount`, `TaxCode`, `Relation`.
→ `tests/Feature/Api/V1/Accounting/ReadResourcesTest.php`,
`tests/Unit/Accounting/Read/CursorTest.php`

### Canoniek lezen van geboekte documenten
`GET /v1/accounting/documents?type=` — de leeskant van de POST op datzelfde pad, met
`PostedDocument` + `PostedDocumentLine`. Leest uit de resources waar de Hub naartoe
schrijft, dus je krijgt terug wat je stuurde; `external_id` komt uit de provenance in
`YourRef` (null voor documenten die buiten de Hub om zijn ingevoerd). Alleen bewezen
Exact-velden worden opgevraagd, en het totaal wordt uit de regels berekend in plaats
van uit een header-veld waarvan de betekenis per pakket verschilt. Relatienamen uit de
mirror in één query per pagina — met een test die dat vastlegt.
Vereist `emeq/exact-api` v0.3.0 (`Envelope::nextSkipToken`, `GetSalesEntries`,
`GetPurchaseEntries`).
→ `ReadResourcesTest::test_documents_are_read_back_from_the_resource_they_were_written_to`
en vier andere

### Statische analyse
PHPStan/Larastan level 5 op `app/`, `database/factories/` en `routes/`, met een
baseline van 125 bestaande hits. Nieuwe code moet schoon zijn; bestaande schuld
blokkeert de build niet. Draait in CI naast Pint, tests en `composer audit`.
→ `phpstan.neon`, `.github/workflows/ci.yml`

---

## NEXT

Gepland en ontworpen; volgorde is bindend vanwege harde afhankelijkheden.

### 1. Event-normalisatie
Canonieke event-envelope (`accounting.*`, `payment.*`), provider-event-adapters die zo
nodig de volle resource ophalen, atomaire dedupe en loop-detectie.
**Breaking** voor bestaande consumers — vereist coördinatie met emeq-app.

### 2. Bidirectionele sync-state
`provider_entity_links` uitgebreid met versies en staleness-detectie; read-back-probe
die het herboek-venster dicht dat overblijft wanneer de partner commit maar de respons
ons niet bereikt.

---

## LATER

Ontworpen, nog niet ingepland op een datum.

### Provider #2 — Moneybird
Adapter + `ReferenceResolver` + registratie. De SDK-repo `emeq/moneybird-api` bestaat
maar is leeg. Dit is de test of de abstracties kloppen.

### Overige canonieke resources
`Payment`, `JournalEntry`, `Item`, `BankAccount`, `BankTransaction`, `Project`,
`PaymentTerm`, `Currency` — pas bouwen wanneer er een consumer of provider is die ze
nodig heeft. Interfaces zonder aanroeper zijn schuld, geen fundament.

### Providers zonder code
AFAS, Twinfield, Yuki. Genoemd in de productvisie, nul regels code. Krijgen geen
stub-implementatie.

---

## Expliciet niet gebouwd

| Wat | Waarom niet |
|---|---|
| `App\Domain\Accounting`-namespace | De repo is capability-at-root; dit zou de enige DDD-prefix zijn |
| Capability-lijst in config | Config kan liegen tegen de code; `implements` niet |
| Enums voor mapping-keys | `gl_accounts`-keys zijn open consumer-input; de andere twee hebben al een getypeerde producent |
| Stub-adapters voor ongebouwde providers | Nul aanroepers, en ze verbergen dat een provider ontbreekt |
| Polling-adapters | Geen provider die het nu nodig heeft |
| Gedistribueerde consistentie / vector clocks | Last-writer-wins met stale-check dekt de werkelijke behoefte |
| Exactly-once-garantie | De partner garandeert het niet; we modelleren wat we echt leveren |
| Transformation-pipeline | Gemeten: 9 van de 48 transformatie-regels zijn deelbaar, de rest ís de providerkeuze. De adapter zou er groter van worden. Heroverwegen bij Moneybird — zie `unified-api-architecture.md` |
