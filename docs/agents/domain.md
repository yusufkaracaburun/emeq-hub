# Domein

De canonieke domeintaal staat in **`CLAUDE.md`** (domeinmodel + invariants) en
**`CONTEXT.md`** (glossary-scaffold). Dit bestand is een pointer, geen tweede bron.

## Kernketen

`Consumer → Account → Connection → SDK-call` — strict, altijd via
`Bearer-token → Consumer → Account → Connection`. Nooit impliciet via session of
query-string. Zie de invariants in `CLAUDE.md`.

## Entiteiten

| Entity | Rol |
| ------ | --- |
| `Consumer` | SaaS-app van Emeq of betalende derde |
| `Account` | Eindgebruiker bij een Consumer (`consumer_id + external_id`) |
| `Connection` | Eén OAuth-koppeling Account ↔ Provider; encrypted tokens |
| `PassThroughCall` | Immutable audit-rij per pass-through-request |
| `WebhookCall` | Spatie fan-out-audit (inkomend partner + uitgaand consumer) |

## Providers

Getypeerd via `App\Enums\Provider`. Metadata via `config/hub-providers.php` +
`App\Support\ProviderCredentialDescriptor`. Nieuwe provider = config-row, geen
nieuwe Resource-class. Zie `.docs/decisions/provider-credential-descriptor.md`.

## Verdere docs

- ADRs: `.docs/decisions/` (let op: niet `docs/adr/` — dat is de ai-kit-scaffold).
- Partner-API-research: `packages/<sdk>/docs/partners/<provider>/` (in de SDK-repos).
- Planning / open werk: GitHub-issues (`/ai:next`).
