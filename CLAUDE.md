# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**Emeq integration stack (v0.2)**

Een Hub-platform en losse, Saloon-gebaseerde Laravel SDK-packages (`emeq/snelstart-api`, `emeq/mollie-api`) voor Nederlandse boekhoud- en betaal-partner-API's. De Hub (`emeq-hub`) host multi-tenant OAuth-koppelingen, webhook-routing en een pass-through REST-API; SDKs leveren de partner-specifieke wrapping. v0.2 bouwt Mollie + Connect + Subscriptions + Hub-skeleton bovenop het in v0.1 gevalideerde Snelstart-pattern, met Naschool als eerste concrete consumer-feature. Doelgroep v0.2: Emeq's eigen SaaS-apps die nu ad-hoc partner-integraties hebben. Doelgroep v1.0+ (later): commercieel beschikbaar voor andere NL dev-shops.

**Core Value:** **Twee fundamenteel verschillende providers (OData/clientkey + REST/OAuth2) productie-gevalideerd via één SDK-pattern, en beide live in één concrete Naschool-feature.** Dat valideert het pattern voor toekomstige SDKs en levert directe DRY-winst in Naschool.

### Constraints

- **Tech stack**: PHP 8.4, Laravel 13.9, Saloon v4 (gebruikt in `emeq/snelstart-api`; `emeq/mollie-api` wrapt `mollie/mollie-api-php` rechtstreeks, geen Saloon-laag), Spatie laravel-data. Tests: PHPUnit 12 in de Hub, Pest in SDK-packages (`packages/snelstart-api/`, straks `packages/mollie-api/`). Geen afwijking zonder approval.
- **Timeline**: v0.2-indicatie ~8-10 weken vanaf milestone-kickoff 2026-05-14.
- **Repo-grenzen**: SDK-packages krijgen géén Hub-domeinmodellen (`Connection`, `Account`, etc.) — invariant uit CLAUDE.md.
- **Tokens encrypted at rest**: gevoelige credentials (clientkey, subscription-key, API-key) nooit raw in DB of logs. Fingerprint-only voor debugging.
- **Geen verzonnen partner-features**: code moet exact kloppen met officiële Snelstart/Mollie docs (per partner gebundeld in de SDK-repo's onder `packages/<sdk>/docs/partners/`).
- **Git-policy**: nooit op `master` werken, nooit pushen zonder approval, geen `--no-verify`.

## Technology Stack

- **PHP 8.4**, **Laravel 13.9**
- **Postgres 16** (eigen credentials + connections + audit-tabellen)
- **Redis 7** (queue + cache + session via predis)
- **FrankenPHP** (app-server, = Caddy + PHP; worker-mode via Octane) — vervangt de losse Caddy-reverse-proxy
- **Laravel Octane** v2 (FrankenPHP worker-mode, dev én prod — runtime-parity)
- **Sanctum** v4 — consumer-app auth (Personal Access Tokens)
- **Horizon** v5 — queue-dashboard + supervisor
- **Spatie webhook-server/client** — partner-event-fan-out naar consumer-callback-URLs
- **dedoc/scramble** — auto-OpenAPI op `/docs/api`
- **SDK-laag**: Saloon v4 (in `emeq/snelstart-api`; `emeq/mollie-api` wrapt `mollie/mollie-api-php` rechtstreeks) + Spatie laravel-data

Lokaal draait **de hele stack in Docker** (app + worker + vite + db + redis): `docker compose up -d --build` → `http://hub.emeq.test:8092`. Dev = FrankenPHP worker-mode met `watch` (instant code-reload, geen rebuild) + Vite-HMR; identiek aan prod op runtime-niveau. Host-`php artisan serve` is enkel nog fallback. Zie `docker/Caddyfile{,.dev}` + `Dockerfile` (multi-stage). Prod: `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build` (HTTP-origin op `:80` achter Cloudflare-TLS + horizon; `trustProxies` aan).

Stack-details voor agents: `docs/agents/dev-environment.md` (commands + doc-URLs) en `docs/agents/architecture.md` (lagen + componenten). Framework-/package-guidelines (PHP, Laravel, Pint, PHPUnit, Boost) staan in de Laravel Boost-block onderaan dit bestand.

## Conventions

Authoritative regels staan in `.ai/rules/` (auto-loaded):

- **Taal**: code/identifiers Engels, commits/PRs/docs/conversatie Nederlands, partner-domeintermen volgen de partner-API (`.ai/rules/global.md`).
- **Engineering**: chirurgisch wijzigen, conflicten oppervlakken niet uitmiddelen, lezen vóór schrijven (`.ai/rules/engineering.md`).
- **Security**: tokens encrypted at rest, fingerprint-only in logs, per-Connection webhook-secrets (`.ai/rules/global.md`).
- **Geen verzonnen partner-features**: alles moet kloppen met de partner-research in de SDK-repos (`packages/<sdk>/docs/partners/<provider>/`).

Projectspecifieke conventies stollen in `.ai/rules/`; er is geen aparte conventions-tracker meer.

## Architecture

`emeq/hub` is een multi-tenant integration platform: één centrale Laravel-app die OAuth-koppelingen, webhook-routing en een uniforme REST-API exposeert naar boekhoud-/betaal-partner-API's:

- **Snelstart** (boekhouden, NL) — via eigen SDK `emeq/snelstart-api` (VCS-repo, zie packages-conventie)
- **Mollie** (betalingen, NL/EU) — via eigen SDK `emeq/mollie-api` (VCS-repo) bovenop officiële `mollie/mollie-api-php` + `mollie/laravel-cashier-mollie` voor Subscriptions
- **Moneybird** (boekhouden, NL) — gepland, via toekomstige `emeq/moneybird-api` SDK
- **Ibanity** (PSD2/banking) — gepland
- **Exact Online** (boekhouden, NL/BE) — via eigen SDK `emeq/exact-api` (VCS-repo, Saloon); OAuth2-lifecycle + division-aware pass-through + named read-resources + accounting-sync zijn live

```
┌─────────────┐  HTTP/REST    ┌──────────────────────────┐  SDK calls   ┌─────────────┐
│ Consumer    │ ─────────────►│   emeq/hub (this app)    │ ───────────► │ Partner API │
│ (= SaaS app │               │                          │              │  (Snelstart,│
│  van Emeq   │ ◄─────────────│  Routes Bearer + ConnID  │              │   Mollie,   │
│  of derde)  │  webhooks     │  → right Connection →    │  webhooks    │   Moneybird,│
└─────────────┘               │  right SDK + tokens      │ ◄─────────── │   …)        │
                              │  → forward + audit       │              └─────────────┘
                              │                          │
                              │  Tables:                 │
                              │  - consumers             │
                              │  - personal_access_tokens│
                              │  - accounts              │
                              │  - connections           │
                              │  - pass_through_calls    │
                              │  - inbound_webhook_events│
                              └──────────────────────────┘
```

**Domeinmodel:**

| Entity | Rol |
|---|---|
| **Consumer** | Eén van Emeq's 3 SaaS-apps, óf een betalende derde |
| **PersonalAccessToken** | Sanctum-token waarmee Consumer authentiseert |
| **Account** | Eindgebruiker bij een Consumer (= klant van die SaaS-app) — opgeslagen by `consumer_id + external_id` |
| **Connection** | Eén OAuth-koppeling tussen één Account en één Provider (Mollie/Snelstart/…). Encrypted tokens + expires_at + scopes |
| **PassThroughCall** | Audit-log voor Hub-pass-through-calls (Consumer → Hub → Partner → Consumer). Eén rij per request, immutable. Zie `.docs/decisions/pass-through-calls-table.md`. |
| **InboundWebhookEvent** | Metadata-only audit van **inkomende** partner→Hub-webhooks (Snelstart/Exact/Mollie/Cashier) via `App\Webhooks\InboundWebhookRecorder`. **Géén payload/headers** (AVG, de Hub is processor). Getypt voor incident-triage (provider/topic/action/outcome/status/fanout). Outbound fan-out (Hub→consumer) loopt via `spatie/laravel-webhook-server` (persisteert geen rij). |

**Architectuur-invariants — niet zonder approval doorbreken:**

- **Consumer ↔ Account ↔ Connection chain is strict.** Een endpoint dat een Connection resolved doet dat altijd via `Bearer-token → Consumer → Account → Connection`. Nooit query-string `?connection_id=`, nooit X-headers zonder Consumer-validatie.
- **Tokens zijn versleuteld at rest.** `access_token`, `refresh_token`, `client_key` etc. op het Connection-model krijgen `protected $casts = ['access_token' => 'encrypted', …]`. Geen rauwe tokens in DB.
- **Geen partner-business-logic in SDK-packages.** SDKs zijn dun: HTTP-laag, auth-laag, DTOs. Webhook-routing, multi-tenancy, audit — leeft in de Hub.
- **Migrations zijn forward-only in prod.** Geen `down()` aanroepen na merge; voor schema-changes nieuwe migration.

Lees dit vóór architecturele beslissingen.

Snelle pointers:
- **Planning / open werk**: GitHub-issues (`P*`/`area/*`-labels) zijn de bron voor open + forward-werk; `/ai:next` rankt ze. Historische GSD-planning leeft in git-history (verwijderd uit de werkboom bij de ai-kit-overgang).
- **Werkdocumentatie** (lokaal, gitignored): `.docs/decisions/` (ADRs), `.docs/plans/`, `.docs/errors/`, `.docs/stack/`. Lees `.docs/README.md` voor de indeling. Partner-research is verplaatst naar de SDK-repos (`packages/<sdk>/docs/partners/`).
- **Routes**: `routes/web.php` (smoke `/`, `/up`; publiek `/oauth/connected/{connection}` + `/oauth/failed` — signed OAuth-landing na de partner-callback; publiek `/partners` + `/partners/{provider}` — Inertia/React integraties-showcase, indexeerbaar via `SetNoIndexHeaders`-uitzondering op `partners.*`; in `local`/`testing`-env ook `/admin/quick-login/{role?}` + `/dev/exact/*` OAuth-tracer), `routes/console.php`, `routes/api.php` (`/v1/*` consumer-API achter Sanctum + `throttle:api`) en `routes/webhooks.php` (`/webhooks/{provider}/{...}` + Cashier-webhooks, publiek signature-verified) zijn geland.
- **Admin-paneel**: Filament v4 op `/admin` (Phase 9, HUB-04). `User` implementeert `FilamentUser` + `HasRoles` (Spatie); admin-access via Spatie-rollen `super-admin`/`staff`/`boekhouder` (zie `EmeqStaffSeeder`). Resource-management voor `manage-staff` ge-gate via gate in `AppServiceProvider::boot()`. Hub-resources gegroepeerd in 4 NL navigation-groups (**Koppelingen** / **Abonnementen** / **Boekhouding** / **Beheer**); Boekhouding + Beheer staan `collapsed()`-by-default en de desktop-sidebar is inklapbaar tot een icon-rail (`sidebarCollapsibleOnDesktop()` + group-icons). **Koppelingen** = de hub-chain Consumer→Account→Connection + audit (incl. de read-only `PassThroughCallResource`, gate `view-pass-through-calls`, en `InboundWebhookEventResource`). De **boekhouding** ("Books") leeft top-level in ditzelfde paneel onder de ene collapsed groep **Boekhouding** (Facturen/Terugkerend/Inkoopfacturen/Klanten/Leveranciers + Grootboek/Transacties/Memoriaal/Overzichten/BTW-aangifte) — géén aparte cluster meer, resources op `/admin/{invoices,bills,clients,…}`; functiescheiding per-resource via trait `App\Filament\Books\Concerns\GatedToBoekhouding` (super-admin/boekhouder zien de boekhoud-groep, staff niet). Zie `.docs/decisions/books-module.md`.
- **Provider-credential-laag** (D-04): `config/hub-providers.php` + `App\Support\ProviderCredentialDescriptor` is de single source of truth voor per-provider credential-**metadata**. `Connection::fingerprint()` + Filament-views + `ConnectionStatsWidget` consumen via descriptor. Nieuwe provider = config-row + factory-state + infolist Section, geen nieuwe Resource-class. Zie `.docs/decisions/provider-credential-descriptor.md`. De provider-**identiteit** is getypeerd via `App\Enums\Provider` (string-backed, Filament `HasLabel`/`HasColor`); `Connection::provider` is hierop gecast en de enum vervangt verspreide `'mollie'`/`'snelstart'`-literals (audit A1, `docs/reviews/2026-06-15-emeq-hub-architecture-audit.md`).
- **Feature-flags / kill-switch** (Phase 8): Pennant-based provider kill-switch via `feature.provider:{provider}` middleware-alias (`bootstrap/app.php:37` → `EnsureProviderEnabled`) op `/v1/{mollie,snelstart}/*`. `OAuthFlowRegistry::for()` checkt dezelfde feature en gooit `ProviderDisabledException` als inactive. Features auto-gedefinieerd in `FeatureServiceProvider` op basis van `config('hub-providers')` keys — nieuwe provider = nieuwe config-row, geen middleware/registry-edit. Zie `.docs/decisions/feature-flags-pennant-kill-switch.md`.
- **Accounting-sync** (provider-agnostisch): consumers POSTen een Hub-canonical `App\Accounting\FinancialDocument` op `POST /v1/accounting/documents`; de Hub resolvet de boekhoud-Connection van de Account en dispatcht via `App\Accounting\AccountingTargetRegistry` (spiegel van `OAuthFlowRegistry` + dezelfde Pennant-gate) naar de `AccountingTarget`-adapter van die provider. `ExactAccountingTarget` mapt de 5 doc-types op 2 GL-based entries: `sales_invoice`/`credit_note`/`income` → `salesentry` (verkoopboeking, géén Item; relatie = debiteur), `purchase_invoice`/`expense` → `purchaseentry` (relatie = crediteur). **`income`/`expense` zijn géén memoriaal** — ze dragen altijd een relatie + categorie + (eventueel) BTW, dus gewone verkoop-/inkoopboekingen met openstaande post (later via Exact-bankreconciliatie afgeletterd), niet een relatieloze `generaljournalentry` (#12 zo opgelost, niet via memoriaal-balancering; de SDK-`CreateGeneralJournalEntry` blijft ongebruikt voor later). income/expense mogen een eigen dagboek (`journals.income`/`.expense`) maar vallen terug op `sales`/`purchase`. Apps leveren in het Hub-formaat; de Hub buigt niet mee. De canonical regel draagt een leidend `amount` (qty/price optioneel) en optioneel `cost_center`/`cost_unit` — kostenplaats-/kostendrager-**Codes** die de adapter ongewijzigd op de Exact-regel zet (`CostCenter`/`CostUnit` = `Edm.String`, Code i.p.v. GUID — anders dan GLAccount); de resolver valideert de Code tegen de mirror (onbekend → 422), géén mapping-laag. Zie `.docs/decisions/accounting-cost-centers.md`. De regel draagt ook optioneel `tax_treatment` (`App\Accounting\Enums\TaxTreatment`, default `standard`; v1 + `reverse_charge`): "21% verlegd" ≠ "21% gewoon" → de VATCode-mapping is behandeling-aware via een **platte composite-key** (`reverse_charge:21`→`6`, `reverse_charge:9`→`7`; standard leest de platte `tarief`-key, backward-compat; géén fallback van verlegd op standard). `ExactMappingDeriver` leidt 6/7 af uit de mirror-labels "BTW verlegd hoog/laag"; SDK ongewijzigd (VATCode-passthrough). Zie `.docs/decisions/accounting-tax-treatment.md`. Het document draagt optioneel `due_date` → Exact-header-`DueDate` (vervaldatum openstaande post; writable op SalesEntry/PurchaseEntry); ontbreekt 'ie, dan zet `FinancialDocument::fromArray` standaard `issue_date + 1 maand` — géén PaymentCondition-mappinglaag (de datum is de universele sleutel, niet een per-administratie conditie-code). Zie `.docs/decisions/accounting-due-date.md`. Optionele `attachments[]` (inline base64; `App\Accounting\Attachment`) worden ná de boeking als Exact `documents/Documents` + `documents/DocumentAttachments` geüpload (best-effort, per stuk gerapporteerd in de respons), gekoppeld via `FinancialTransactionEntryID` (de boeking) + `Account` (de relatie); doc-type→Exact-`DocumentType`-id via SDK-enum `ExactDocumentType` (10/20/55). **Inkoop hergebruikt het Document dat Exact zelf bij een PurchaseEntry aanmaakt** (`Envelope::documentRef` → `d.Document`) i.p.v. een tweede aan te maken; verkoop heeft geen auto-Document → maakt er zelf één. De adapter stempelt herkomst in Exact `YourRef` = `{consumer-app} · {external_id}` (traceability voor de boekhouder). De respons draagt `status` (`App\Accounting\Enums\SyncStatus`: posted/rejected/failed/pending), `external_id` (echo, voor de consumer-sync-ledger) en `external_ref`. Default synchroon (`201` + `posted`); met header `Prefer: respond-async` draait de push in `App\Jobs\Accounting\SyncAccountingDocumentJob` (queue `webhooks`, `$tries=1` → geen retry, dus geen dubbel-boeking) die het resultaat per webhook terugmeldt aan `consumer.webhook_callback_url` (event `accounting.document.synced`, anti-correlation-HMAC met `webhook_callback_secret`), en de edge antwoordt direct `202` + `pending` — async zonder geregistreerde callback wordt `400 webhook_required` geweigerd. De gedeelde push+audit-logica leeft in `App\Accounting\AccountingSyncRunner` (sync-edge + job). SDK retryt **geen** POST op 5xx/verbroken verbinding (Exact heeft geen idempotency-key → zou dubbel boeken). De per-Connection mapping (tarief→VATCode, categorie→GL-Code, doc-type→dagboek) leeft als **stabiele Codes** in `connection.metadata.accounting_mapping` en wordt **automatisch afgeleid na connect**: `ExactOAuthFlow::exchangeCode()` dispatcht `App\Jobs\Accounting\SyncExactReferenceJob` (queue `default`) → `App\Accounting\Exact\ExactReferenceSync` mirrort Exact's GL/BTW/dagboeken naar `connection_accounting_refs` (`App\Models\ConnectionAccountingRef`) → `App\Accounting\Exact\ExactMappingDeriver` leidt een default-mapping af (21/9/0→VATCode, GL-prefix 8xxx/4xxx→omzet/kosten/_default, dagboek-type→sales/purchase). GL-Code→GUID en relaties resolven lokaal tegen de mirror; **relaties zijn lazy** (resolve-or-learn via `App\Accounting\Exact\ExactRelationResolver` bij de eerste boeking, géén match → 422). `ExactAccountingTarget::ensureMapping()` is de fallback bij een gefaalde sync. Admin-overrides via de `ManageAccountingMappingAction`-table-action; consumer-self-service via `POST /v1/accounting/sync`, `GET /v1/accounting/reference-data` en `GET|PUT /v1/accounting/mapping` (`App\Http\Controllers\Api\V1\Accounting\MappingController`). Nieuwe provider = nieuwe adapter + 1 registratie-regel. Zie `.docs/decisions/accounting-mapping-sync-mirror.md` (auto-derive) + `.docs/decisions/provider-agnostic-accounting-sync.md` (canonical contract) + `.docs/decisions/accounting-canonical-contract-hardening.md`.
- **Accounting dry-run validatie** ("Scan & herstel"): `POST /v1/accounting/documents/validate` controleert een geëxtraheerd draft-document **zónder te boeken** en geeft een findings-rapport terug (`valid` + `summary` + `findings[]{code,severity,path,message,current,suggestion}`; altijd `200`). De provider-agnostische laag (`App\Accounting\Validation\DocumentInspector` + 6 pure validators: rekenkundig/IBAN/BTW-nummer/BTW-behandeling/geografie/valuta) draait altijd; daarná verrijkt `App\Accounting\Validation\Enrichment\ExactReportEnricher` (gated op `Provider::Exact`, gemerged via `InspectionReport::with()`) het rapport Exact-specifiek: per ongekoppeld tarief `exact.vat_code.unmapped` (een gekoppeld tarief levert géén ruis-finding — de interne VATCode zegt de consument niets; uit `accounting_mapping.vat_codes` via fail-soft `vatCodeOrNull()`) en per party `exact.relation.matched|new` (live `ExactReferenceData::findRelation()`, read-only — een Exact-storing levert géén finding, een dry-run breekt nooit). De mirror + auto-derive (`accounting-mapping-sync-mirror.md`) is live; het enrichment-rapport en de boeking lezen dezelfde `accounting_mapping` + `connection_accounting_refs`.
- **Idempotency (Hub-breed)**: write-idempotentie via `App\Http\Middleware\EnsureIdempotency` (alias `idempotent` in `bootstrap/app.php`). Consumer stuurt een `Idempotency-Key`-header; de eerste 2xx-respons wordt bewaard per `(consumer, key)` in `idempotency_keys` (raw body, herbruikbaar voor niet-JSON) en bij retry herhaald i.p.v. opnieuw uitgevoerd. `idempotent:required` op `/v1/accounting/documents`; pass-through writes volgen met `idempotent`. Eén alias, geen partner-duplicatie — Exact heeft geen native idempotency. Zie `.docs/decisions/accounting-canonical-contract-hardening.md`.
- **Exact pass-through + named resources**: consumer-calls naar Exact lopen via `/v1/exact/*` (Bearer-PAT + `X-Account-Id`, `feature.provider:exact`-gate, `resolve.exact.account`-middleware). Elke call gaat door `App\Support\Exact\ExactForwarder` — die neemt een Saloon-`Request` van de SDK, doet division-scope + `UpstreamErrorMapper` + één `pass_through_calls`-auditrij per request en audit't het pad via `resolveEndpoint()`. `Route::any('/{path}')` is de generieke escape-hatch (`RawExactRequest`); daarvóór staan **named read-resources** (eigen Scramble-groep + gegronde OData-endpoint): `GET /v1/exact/gl-accounts` (`financial/GLAccounts`), `/vat-codes` (`vat/VATCodes`), `/relations` (`crm/Accounts`), `/journals` (`financial/Journals`). De Exact-wire (paden, veldnamen, `AmountFC`/`AmountDC`, OData-envelope) leeft in de `emeq/exact-api` named requests (`Http/Request/Read|Write/*`) + `OData\Envelope`, niet in de Hub — zie `.docs/decisions/sdk-named-request-contract.md`. Nieuwe named-resource = SDK-read-request + dun controllertje (`#[Group]` → `ExactForwarder::forward()` met die request) + 1 route-regel vóór de catch-all + test. De Filament boekhoud-mapping-UI vult zijn keuzelijsten via `App\Services\Exact\ExactReferenceData` (server-side, fail-soft) uit dezelfde Exact-data.
- **Partner-credentials in DB** (niet `.env`): Exact-app-credentials leven in `App\Settings\ExactSettings` (spatie/laravel-settings, secrets encrypted at rest), gehydrateerd naar `config('services.exact.*')` via `SettingsHydrationServiceProvider`; beheer in admin → Beheer → Integratie-instellingen (`ManageIntegrationSettings`). Geen env-fallback in de runtime — de DB is de bron. **Dev-only**: `ExactDevSettingsSeeder` spiegelt bij `migrate:fresh --seed` lege velden uit `.env` zodat een dev-koppeling werkt zonder admin-getik (skipt op `isProduction()`). Zie `.docs/decisions/db-managed-credentials.md`.
- **Exact webhooks** (#10): Exact POST't notificaties naar één publieke URL `POST /webhooks/exact` (mirror Snelstart; routing op `Content.Division`). Gatekeeper = `verify.exact.signature` (SDK-middleware, `emeq/exact-api`): `HashCode = UPPERCASE-HEX(HMAC-SHA256)` (64 chars, géén base64 — live-geverifieerd) over de letterlijke `Content`-node-JSON met de **app-brede** Webhook-secret (`config('exact.webhook.secret')`, gehydrateerd uit `ExactSettings`) — gedocumenteerde uitzondering op per-Connection-secrets, zie `.docs/decisions/exact-webhook-app-secret.md`. De lege-body-validatieping bij subscribe krijgt 200. `ExactWebhookController` parseert, idempotency op hash van de raw body, audit via `App\Webhooks\InboundWebhookRecorder` → **`inbound_webhook_events`** (metadata-only, AVG — zie de inbound-webhook-audit-pointer), resolvet de Connection op division, en dispatcht `ForwardExactWebhookToConsumerJob` (fan-out via spatie webhook-**server**, per-Consumer secret). Subscriptions worden in de OAuth-lifecycle beheerd: `ExactOAuthFlow::exchangeCode()` → `RegisterExactWebhookSubscriptionsJob`, `revoke()` → `DeleteExactWebhookSubscriptionsJob`, beide via `App\Services\Exact\ExactWebhookSubscriptionManager` (per-connection SDK-binding, idempotent, IDs in `connection.metadata['exact_webhooks']`). Topic-set config-driven in `services.exact.webhook_topics` (default `BankEntries`+`CashEntries` = afletter-events; live-geverifieerde topic-strings, géén entry-types want die boekt de Hub zelf → feedback-loop); SDK-requests in `emeq/exact-api` `Http/Request/{Read,Write,Delete}/*WebhookSubscription(s)`. **Live-gotchas (2026-06-18, end-to-end geverifieerd tegen echte Exact)**: HashCode = **uppercase hex** van HMAC-SHA256, géén base64 (Exact's "byte array of length 40"-doc misleidend); `IsInstant` mag alléén voor topic `GoodsDeliveries` (manager stuurt 'm niet mee); CallbackURL moet het redirect_uri-domein delen (manager leidt 'm daarvan af, niet van APP_URL); de Exact-validatieping + notificaties zijn **server-to-server POST vanaf datacenter-IP** → Cloudflare **Bot Fight Mode** blokkeert ze (403) tenzij `/webhooks/exact` (+ `/v1/oauth/exact/callback`) ge-skipt is — geldt **ook in prod** (edge-config-eis, geen code-issue). `Action` is title-case (`Update`/`Delete`).
- **Inbound-webhook-audit** (provider-agnostisch): álle inkomende partner→Hub-webhooks (Snelstart/Exact/Mollie/Cashier) auditen via `App\Webhooks\InboundWebhookRecorder` → `inbound_webhook_events` — één write-path, **metadata-only** (provider/topic/action/outcome/status/fanout_status/connection/consumer + `(provider,event_id)`-idempotency; **géén payload/headers**, AVG: de Hub is processor, niet owner). Bewust gescheiden van `pass_through_calls` (= Consumer→Hub→Partner pass-through + accounting, andere kolommen → kolom-cohesie). `outcome` ∈ processed/duplicate/unknown_tenant/malformed/invalid_signature/misconfigured. Read-only incident-triage-UI: Filament `InboundWebhookEventResource` (gate `view-webhooks`). Nieuwe provider = `recorder->record(...)`-call, geen nieuwe tabel.
- **Return-to-consumer na OAuth-connect**: de Consumer registreert `consumers.app_url` (admin-beheerbaar via de `ConsumerResource`-form + de onboarding-wizard, die 'm uit de intake-`AccessRequest.app_url` overneemt) en mag bij init (`/v1/oauth/{provider}/init`) een `return_url` meegeven; `App\Support\OAuth\ReturnUrlResolver` accepteert die als de host == `app_url`-host **of een subdomein van hetzelfde basisdomein** (open-redirect-guard), valt anders terug op `app_url`, persisteert op `connections.oauth_return_url`. De Exact-landing redirect/linkt na connect (+ bij `exchange_failed`) terug naar de consumer-app; zonder `app_url` blijft de Hub-admin-fallback (admin/dev-pad). Mollie's JSON-callback echo't `return_url`. Zie `.docs/decisions/oauth-return-to-consumer.md`.
- **Eén-knop-onboarding (init)**: `/v1/oauth/{provider}/init` **auto-provisiont** het Account (`firstOrCreate` op `$consumer->accounts()`, per-Consumer genamespaced via `unique(consumer_id, external_id)`) — de consumer-app hoeft het Account niet vooraf te POSTen; `POST /v1/accounts` blijft bestaan maar de Hub hangt er niet op. Vereiste PAT-abilities: `integrations:manage` (+ `consumer:manage-accounts` voor expliciete account-create).
- **`/v1/*` error-contract**: alle fouten zijn JSON (`shouldRenderJsonWhen('v1/*')` in `bootstrap/app.php`), ook zonder `Accept: application/json`-header; een ontbrekende/ongeldige PAT → `401 {code:"unauthenticated"}` i.p.v. een redirect naar de niet-bestaande `login`-route (`redirectGuestsTo('v1/*' → null)` — voorkomt de 500).

De gedetailleerde laag-/componentkaart staat in `docs/agents/architecture.md`.

## Dev-setup

### Lokale dev — eerste keer

```bash
# 0. Eenmalig: /etc/hosts toevoegen
echo "127.0.0.1 hub.emeq.test" | sudo tee -a /etc/hosts

# 1. .env van .env.example
cp .env.example .env
php artisan key:generate

# 2. Composer-deps op host (voor IDE/grep; container regenereert vendor zelf)
composer install

# 3. Hele stack omhoog in Docker (app + worker + vite + db + redis)
docker compose up -d --build

# 4. Migraties draaien in de app-container
docker compose exec app php artisan migrate

# 5. (Optioneel) SDK clonen in packages/ voor referentie/grep — geen live-edit-link
mkdir -p packages
git clone git@github.com:yusufkaracaburun/emeq-snelstart-api.git packages/snelstart-api

# 6. SDK-changes: edit in de SDK-repo zelf, commit + push, daarna in de Hub:
#    composer update emeq/snelstart-api
```

Open `http://hub.emeq.test:8092/up` → moet `{"status":"up","database":"ok","redis":"ok"}` teruggeven.

Dev draait in worker-mode met `watch`: PHP-changes zijn na een korte worker-restart zichtbaar (geen rebuild), React via Vite-HMR op `:5173`. Tests in de container: `docker compose exec app php artisan test --compact`.

### Veelgebruikte commando's

```bash
# DB
php artisan migrate
php artisan migrate:fresh --seed

# Tests — Hub (PHPUnit)
php artisan test --compact
php artisan test --compact --filter=ExampleTest

# Tests — SDK-package (Pest, eigen vendor)
cd packages/snelstart-api && ./vendor/bin/pest

# Format
./vendor/bin/pint --dirty --format agent   # voor commit

# Horizon
php artisan horizon
php artisan horizon:status

# Routes
php artisan route:list --except-vendor

# Composer audit
composer audit                              # zie ignored advisories in composer.json
```

### Routes

```
routes/web.php       smoke: GET /, GET /up; publiek /oauth/{connected/{connection},failed} (signed OAuth-landing)
routes/console.php   artisan-only commands (inspire)
routes/api.php       /v1/* — consumer-API (Bearer Sanctum + throttle:api)
routes/webhooks.php  /webhooks/{provider}/{...} + /cashier/webhook* — publiek, signature-verified
```

## Packages-conventie

**`packages/` is gitignored** en is een **lees-clone** voor referentie/grep. SDK-packages hebben elk een eigen GitHub-repo:

- `packages/snelstart-api/` ← `github.com:yusufkaracaburun/emeq-snelstart-api`
- `packages/mollie-api/` ← `github.com:yusufkaracaburun/emeq-mollie-api`

Composer require't de SDKs via een **VCS repository** in `composer.json` — niet meer via een path-symlink. Reden: `packages/` bestaat niet op Laravel Cloud, dus een path-dist in `composer.lock` breekt de deploy.

**Workflow voor SDK-changes:**

1. Edit in de SDK-repo (eigen clone, kan `packages/<name>/` zijn).
2. Commit + push naar de SDK GitHub-repo.
3. In de Hub: `composer update emeq/<name>` — pinst de nieuwe VCS-reference in `composer.lock`.
4. Commit `composer.lock` in de Hub.

Geen live-edit-symlink meer. Voor snelle iteratie in de SDK: werk daar gewoon zelf met `./vendor/bin/pest` in de SDK-repo, en sync pas naar de Hub als de change stabiel is.

## Git policy — harde regels

- Nooit op `master` werken.
- Nooit `git push` zonder expliciete user-toestemming.
- Nooit `--no-verify`, `--no-gpg-sign`, of force-push tenzij user expliciet vraagt.
- Nooit secrets committen. Nooit `.env` aanpassen zonder approval.
- Nooit >3 files wijzigen in één commit zonder approval.

## Project Skills

| Skill | Description | Path |
|-------|-------------|------|
| docs-sync | Detecteert en herstelt documentatie-drift én organisatie-issues in `.docs/`, `CLAUDE.md` en memory voor de emeq-hub repo. Triggert proactief na domein-wijzigingen — niet wachten op merge: model/entity hernoemd, kolom verplaatst, nieuwe migration, nieuwe Sanctum-ability of Connection-provider, OAuth-flow gewijzigd, SDK-package toegevoegd of verwijderd uit `packages/`, route toegevoegd of verwijderd. Triggert ook bij doc-toevoegingen of -verplaatsingen in `.docs/`. Reactief op vragen als "check de docs", "update de docs", "klopt de documentatie nog", "synchroniseer docs", "klaar voor commit?", "ruim de docs op". Vangt zes problemen af: (1) stale class-/file-references, (2) ontbrekende ADR voor architecturele wijzigingen, (3) completed TODOs die niet als ✅ zijn gemarkeerd, (4) structuur-drift (nieuwe folders/files niet in `.docs/README.md` index, files op verkeerde plek), (5) verweesde docs (gemergde plans nog in `plans/`, lange ongewijzigde files), en (6) dode links (markdown-links naar non-existing files of code-paden). Use proactively whenever the user wraps up a domein-wijziging, just merged a branch, ran a refactor, added/moved a doc, or before any commit/push. | `.claude/skills/docs-sync/SKILL.md` |
| add-provider | Step-by-step voor het toevoegen van een nieuwe partner-provider: een dunne `emeq/<provider>-api` SDK bouwen (Connector + auth + named requests + decoder + partner-docs) én aan de Hub koppelen (Provider-enum + `config/hub-providers.php` + `OAuthFlow` + `*Settings` + optioneel `AccountingTarget`/named resources). Codificeert de laag-grens **state→Hub, protocol→SDK**. Gebruik bij "voeg provider X toe", "nieuwe SDK koppelen", "nieuwe boekhoud-/betaal-integratie". | `.claude/skills/add-provider/SKILL.md` |
| change-sdk | Beslis-gids + cross-repo werkwijze voor het wijzigen van een bestaande `emeq/<provider>-api` SDK, met de tabel **"raak ik de Hub aan?"** (wire-only = SDK-only `composer update`; nieuwe input/named-resource = SDK + dunne Hub-touch; nieuw canonical begrip = SDK + Hub). Gebruik bij "wijzig de SDK", "endpoint toevoegen", "moet ik nu ook de Hub aanpassen", "veld hernoemen". | `.claude/skills/change-sdk/SKILL.md` |

## Workflow & Agent skills

ai-kit draait als plugin (`/ai:*`-skills beschikbaar), geconfigureerd via `.ai-kit-setup` (`tier=full`, `mode=solo-global`). Lifecycle-fase: **development** — schema-migraties vrij te wijzigen, geen backwards-compat-eis vóór productie.

- **Werkwijze**: feature-/fix-branch → tests groen → ff-merge naar `master` (geen PR-ceremonie voor solo-werk). Detail in `docs/agents/workflow.md`. Open + forward-werk staat in GitHub-issues (`/ai:next`).
- **Entrypoints**: `/ai:tdd` (feature/bugfix TDD), `/ai:diagnose` (onderzoek/bug), `/ai:to-issues` (plan → issues), `/ai:review` (pre-merge). De `branch-guard`-hook blokkeert edits op `master`.
- **Docs**: per-onderwerp in `docs/agents/`; authoritative regels in `.ai/rules/` (auto-loaded); ai-kit canonical rules in `.claude/rules/` (gitignored, aanvullend).

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v4
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/nightwatch (NIGHTWATCH) - v1
- laravel/octane (OCTANE) - v2
- laravel/pennant (PENNANT) - v1
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- livewire/livewire (LIVEWIRE) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== octane/core rules ===

# Laravel Octane

This application uses Laravel Octane, a long-running PHP server. The application bootstraps once and handles many requests within the same process.

- Never store request-specific state in singletons or static properties, because it can leak across requests.
- Use `config('octane.server')` to detect the active driver (`swoole`, `roadrunner`, or `frankenphp`).
- Prefer scoped bindings (`$this->app->scoped()`) over singletons for per-request services.

When working on Octane-specific features (concurrency, shared tables, memory, driver configuration, testing), invoke `octane-development` for detailed rules.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
