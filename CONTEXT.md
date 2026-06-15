# Context — emeq-hub

Domein-glossary + grenzen voor agents. Authoritative architectuur staat in `CLAUDE.md`; per-onderwerp docs in `docs/agents/`. Partner-domeintermen volgen de partner-API (Snelstart = NL, Mollie = EN) — niet vertalen.

## Wat dit is

Multi-tenant integration-platform: één Laravel-Hub die OAuth-koppelingen, webhook-routing en een uniforme pass-through REST-API exposeert naar boekhoud-/betaal-partner-API's (Snelstart, Mollie; gepland: Moneybird, Exact, Ibanity, Twinfield). Losse dunne `emeq/*` SDK-packages leveren de partner-specifieke HTTP-laag.

## Glossary

| Term | Betekenis |
|------|-----------|
| **Consumer** | Eén SaaS-app van Emeq óf een betalende derde. Authentiseert met een Sanctum-PAT. Bezit `accounts`. |
| **Account** | Eindgebruiker bij een Consumer (klant van die SaaS-app). Uniek op `(consumer_id, external_id)`. Bezit `connections`. |
| **Connection** | Eén koppeling tussen één Account en één Provider. OAuth2 (Mollie: access/refresh-token + scopes) óf clientkey (Snelstart: client_key + subscription_key/id). Tokens encrypted at rest. |
| **Provider** | Een partner-integratie, getypeerd via `App\Enums\Provider`. Metadata via `config/hub-providers.php` + `ProviderCredentialDescriptor`. |
| **PassThroughCall** | Immutable audit-rij per Consumer→Hub→Partner-pass-through. Eén rij per request; endpoint-template als `path`, nooit query-string/concrete-id. |
| **WebhookCall** | Spatie fan-out-audit: inkomende partner-webhook + uitgaande consumer-callback. |
| **OAuthFlow** | Provider-agnostisch OAuth2-contract; per provider één implementatie, geresolved via `OAuthFlowRegistry`. |
| **CredentialResolver** | SDK-contract dat de Hub per-request bindt aan de juiste Connection. SDK kent géén Hub-domein. |
| **PAT / ability** | Sanctum Personal Access Token + abilities (`snelstart:*`, `mollie:*`, `billing:*`, `admin`). `TokenAbilities` is een `final class` met consts, geen enum. |
| **X-Account-Id** | De enige toegestane tenant-routing-header. Connection-resolutie loopt altijd `Bearer → Consumer → Account → Connection`. |

## Grenzen / invariants

- **SDK-grens:** Hub-domeinmodellen (`Consumer`/`Account`/`Connection`) bestaan **alleen** in `emeq-hub`. De `emeq/*` SDK's zijn dun (HTTP/auth/DTO) en mogen Hub-modellen niet importeren.
- **Multi-tenant:** geen impliciete resolutie via session/query-string; alleen via `X-Account-Id` + Consumer-scoped lookup. Cross-consumer-lek = security-incident.
- **Encryption-at-rest:** secrets encrypted cast; nooit raw in DB/logs/exceptions; fingerprint-only voor debugging.
- **Forward-only migrations:** geen `down()` in prod-pad; schema-change = nieuwe migration.
- **Geen verzonnen partner-features:** code/docs moeten exact kloppen met de partner-research in de SDK-repos (`packages/<sdk>/docs/partners/<provider>/`).

## Docs-kaart

- Architectuur/lagen: `docs/agents/architecture.md` · domein: `docs/agents/domain.md` · dev: `docs/agents/dev-environment.md` · docker: `docs/agents/docker.md` · workflow: `docs/agents/workflow.md`.
- ADRs: `.docs/decisions/` (let op: niet `docs/adr/`). Partner-research: `packages/<sdk>/docs/partners/<provider>/` (in de SDK-repos).
- Authoritative regels: `.ai/rules/` (auto-loaded).
