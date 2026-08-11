# Context — emeq-hub

Domein-glossary + grenzen voor agents. Authoritative architectuur staat in `CLAUDE.md`; per-onderwerp docs in `docs/agents/`. Partner-domeintermen volgen de partner-API (Snelstart = NL, Mollie = EN) — niet vertalen.

## Wat dit is

Multi-tenant integration-platform: één Laravel-Hub die OAuth-koppelingen, webhook-routing en een uniforme pass-through REST-API exposeert naar boekhoud-/betaal-partner-API's (Exact Online, Snelstart, Mollie; gepland: Moneybird, Ibanity, Twinfield). Losse dunne `emeq/*` SDK-packages leveren de partner-specifieke HTTP-laag.

## Glossary

| Term | Betekenis |
|------|-----------|
| **Consumer** | Eén SaaS-app van Emeq óf een betalende derde. Authentiseert met een Sanctum-PAT. Bezit `accounts`. |
| **Account** | Eindgebruiker bij een Consumer (klant van die SaaS-app). Uniek op `(consumer_id, external_id)`. Bezit `connections`. |
| **Connection** | Eén koppeling tussen één Account en één Provider. OAuth2 (Mollie: access/refresh-token + scopes) óf clientkey (Snelstart: client_key + subscription_key/id). Tokens encrypted at rest. |
| **Provider** | De **identiteit** van een partner: een case van `App\Enums\Provider`, de key in `config/hub-providers.php`, de waarde in `connections.provider`. |
| **Integration** | De **code** die een Provider implementeert: `app/Integrations/<Provider>/`. Provider is wie, Integration is hoe. Een map onder `app/Integrations` heet zoals een Provider-case óf is gedeeld — en gedeelde code noemt geen provider. |
| **PassThroughCall** | Immutable audit-rij per Consumer→Hub→Partner-pass-through. Eén rij per request; endpoint-template als `path`, nooit query-string/concrete-id. Geschreven op precies één plek: `PassThroughRecorder`. |
| **InboundWebhookEvent** | Metadata-only audit van partner→Hub-webhooks via `InboundWebhookRecorder` — géén payload of headers (AVG: de Hub is processor). Outbound fan-out persisteert geen rij. |
| **CanonicalEvent** | Het providerneutrale event in de webhook-envelope naar de consumer (`{event, provider, account_id, occurred_at, data}`). Per provider vertaalt één `ResolvesCanonicalEvent` de partner-vorm; ontbreekt die, dan `unmapped` — nooit een verzonnen naam. |
| **OAuthFlow** | Provider-agnostisch OAuth2-contract; per provider één implementatie, geresolved via `OAuthFlowRegistry`. |
| **CredentialResolver** | SDK-contract dat de Hub per-request bindt aan de juiste Connection. SDK kent géén Hub-domein. |
| **PAT / ability** | Sanctum Personal Access Token + abilities (`snelstart:*`, `mollie:*`, `billing:*`, `admin`). `TokenAbilities` is een `final class` met consts, geen enum. |
| **X-Account-Id** | De enige toegestane tenant-routing-header. Connection-resolutie loopt altijd `Bearer → Consumer → Account → Connection`. |
| **FinancialDocument** | Hub-owned canonical financieel document dat een Consumer POST naar `/v1/accounting/documents`. Provider- én consumer-agnostisch: apps buigen hierheen, de Hub buigt naar de provider. Velden: `type`, `party`, `lines[]`, `issueDate`/`dueDate`, `number`, `currency`, `attachments[]`. |
| **DocumentType** | Canonical documentsoort: `sales_invoice`, `purchase_invoice`, `credit_note`, `income`, `expense`. `income`/`expense` = een zelfstandige boeking **zónder** commerciële factuur (memoriaal), niet de betaling/afhandeling van een bestaand document. |
| **Party** | Debiteur (`role=debtor`) of crediteur (`role=creditor`) op een FinancialDocument. Draagt géén provider-GUID; de adapter resolvet `externalId`/`vatNumber` → de relatie van de gekoppelde administratie. |
| **FinancialDocumentLine** | Regel: `description`, `amount` (netto, leidend), optioneel `quantity`/`unitPrice` (informatief), `taxRate` (tarief, geen provider-VATCode), `category` (vrije grootboek-hint → GLAccount via mapping). |
| **Attachment** | Bewijsstuk (PDF/scan, bv. bonnetje) bij een FinancialDocument, inline base64. De Exact-adapter zet het via `documents/Documents` + `DocumentAttachments` bij de boeking. |
| **AccountingTarget** | Provider-adapter die canonical → boekhoudpakket-body mapt, geresolved via `AccountingTargetRegistry` (spiegel van `OAuthFlowRegistry` + dezelfde Pennant-gate). |
| **Brondocument / Afhandeling** | Sync-grens: brondocumenten (facturen, losse income/expense) worden gesynct; afhandelingen (de betaling van een al-gesynct document) niet — die boekt de provider via bankreconciliatie. |
| **EnsureIdempotency** | Hub-brede write-idempotentie (middleware-alias `idempotent`). Consumer stuurt een `Idempotency-Key`-header; de Hub bewaart de eerste 2xx-respons per `(consumer, key)` in `idempotency_keys` en herhaalt die bij retry i.p.v. opnieuw uit te voeren. `idempotent:required` waar dubbel-uitvoeren schadelijk is (accounting); één alias, herbruikbaar op elke write-route — geen partner-duplicatie. |

## Grenzen / invariants

- **SDK-grens:** Hub-domeinmodellen (`Consumer`/`Account`/`Connection`) bestaan **alleen** in `emeq-hub`. De `emeq/*` SDK's zijn dun (HTTP/auth/DTO) en mogen Hub-modellen niet importeren.
- **Multi-tenant:** geen impliciete resolutie via session/query-string; alleen via `X-Account-Id` + Consumer-scoped lookup. Cross-consumer-lek = security-incident.
- **Encryption-at-rest:** secrets encrypted cast; nooit raw in DB/logs/exceptions; fingerprint-only voor debugging.
- **Forward-only migrations:** geen `down()` in prod-pad; schema-change = nieuwe migration.
- **Geen verzonnen partner-features:** code/docs moeten exact kloppen met de partner-research in de SDK-repos (`packages/<sdk>/docs/partners/<provider>/`).
- **Canonical is consumer-agnostisch:** het `FinancialDocument`-contract bevat nooit consumer-specifieke velden of -termen. Consumer-variatie wordt opgevangen in de consumer-side mapping (laag 1) + de per-Connection `accounting_mapping`, niet in het contract. De Hub kent z'n consumers niet — dat is de anti-corruptie-invariant.
- **"Account" is overladen:** in de Hub betekent `Account` **altijd** de eindgebruiker van een Consumer (bezit Connections). Een consumer-intern "account"-begrip (bank-/grootboekrekening, debiteur/crediteur-record) is iets ánders en mapt op `Party`, nooit op de Hub-`Account`.

## Docs-kaart

- Architectuur/lagen: `docs/agents/architecture.md` · domein: `docs/agents/domain.md` · dev: `docs/agents/dev-environment.md` · docker: `docs/agents/docker.md` · workflow: `docs/agents/workflow.md`.
- ADRs: `.docs/decisions/` (let op: niet `docs/adr/`). Partner-research: `packages/<sdk>/docs/partners/<provider>/` (in de SDK-repos).
- Authoritative regels: `.ai/rules/` (auto-loaded).
