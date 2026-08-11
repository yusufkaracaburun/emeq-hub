# Unified API — roadmap

> Niets staat onder **IMPLEMENTED** zonder werkende code én tests. Bij twijfel gaat
> het naar NEXT. Architectuur staat in `unified-api-architecture.md`.

Laatste update: 2026-08-11 · suite: 1165 passed, 1 incomplete, 0 failures.
Startbaseline vóór dit traject: 1120 passed · PHPStan-baseline 206 → 135.

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

### Basale idempotentie
`Idempotency-Key` op `POST /v1/accounting/documents`; een tweede request met dezelfde
key replay't de eerste 2xx-respons.
**Beperkingen, eerlijk:** geen bescherming tegen gelijktijdige requests (race →
dubbele boeking), geen payload-check bij key-hergebruik, geen verval, geen pruning.
Zie NEXT.
→ `StoreDocumentTest::test_retry_with_same_idempotency_key_books_once`

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

### Statische analyse
PHPStan/Larastan level 5 op `app/`, `database/factories/` en `routes/`, met een
baseline van 135 bestaande hits. Nieuwe code moet schoon zijn; bestaande schuld
blokkeert de build niet. Draait in CI naast Pint, tests en `composer audit`.
→ `phpstan.neon`, `.github/workflows/ci.yml`

---

## NEXT

Gepland en ontworpen; volgorde is bindend vanwege harde afhankelijkheden.

### 1. Idempotency-hardening
Claim-first insert met de unique index als mutex; `in_flight`/`completed`-staat met
lease en takeover; payload-fingerprint-guard; verval en pruning.
Nieuwe responses: `409 idempotency_request_in_progress`, `422 idempotency_key_reuse`.
**Breaking** — consumers moeten meebewegen.

### 2. Capability-registry
Providers declareren wat ze kunnen via `implements`, niet via config.
`GET /v1/accounting/capabilities`. Haalt de laatste twee provider-conditionals uit de
accounting-controllers en sluit het gat waarin de dry-run Exact belt terwijl de
kill-switch uit staat.

### 3. Error-normalisatie
Canonieke foutcategorieën naast de bestaande `error`-sleutel; drie bijna-identieke
`UpstreamErrorMapper`s achter één contract.

### 4. Canonieke read-resources
`GET /v1/accounting/{ledger-accounts,tax-codes,customers,suppliers}` uit mirror en
SDK. Daarna `GET /v1/accounting/invoices` — vereist eerst nieuwe read-requests in
`emeq/exact-api` (die bestaan nog niet).

---

## LATER

Ontworpen, nog niet ingepland op een datum.

### Transformation-pipeline
Gefaseerde canoniek→provider-transformatie met provider-hooks voor echte semantische
verschillen. Bewust ná het lees-pad: met alleen het schrijf-pad generaliseer je op
één richting en één provider.

### Event-normalisatie
Canonieke event-envelope (`accounting.*`, `payment.*`), provider-event-adapters die
zo nodig de volle resource ophalen, atomaire dedupe en loop-detectie.
**Breaking** voor bestaande consumers — vereist coördinatie met emeq-app.

### Bidirectionele sync-state
`provider_entity_links` uitgebreid met versies en staleness-detectie; read-back-probe
die het herboek-venster dicht dat overblijft wanneer de partner commit maar de
respons ons niet bereikt.

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
