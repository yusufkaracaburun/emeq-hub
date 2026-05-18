# Phase 8: Naschool wiring (Snelstart + Mollie-via-Hub) — Context

**Gathered:** 2026-05-16
**Status:** Ready for planning
**Requirements:** NSCH-01, NSCH-02, NSCH-03

<domain>
## Phase Boundary

Naschool (= eerste concrete Consumer) gaat live op de Hub: school A doet een vrijwillige-bijdrage-checkout via Hub-Connect op haar eigen Mollie-account, en een `EnrollmentConfirmed`-event in Naschool triggert een Snelstart-verkoopfactuur — beide via de in v0.2 gebouwde Hub-pass-through pattern. Phase 8 levert de **Hub-side wiring** die nodig is om dit end-to-end te bewijzen.

Naschool-interne implementatie (Stancl-tenancy, listeners, callback-handlers, demo-seeds in Naschool DB) wordt **niet** in deze repo gedocumenteerd; die plans wonen in de Naschool repo (`/Users/yusufkaracaburun/Sites/localhost/school-activities-hub/backend/`).

**Hub-side scope (in deze repo):**

1. **Consumer-provisioning**: `naschool`-Consumer + `webhook_callback_url` + `webhook_callback_secret` in dev (artisan-command of seeder-update). Productie-pad documenteren.
2. **Filament Consumer-onboard-wizard**: Multi-stap form in Filament voor Emeq-staff: Consumer aanmaken → eerste Account → eerste Connection (OAuth-init voor Mollie / form voor Snelstart) → PAT-uitgifte met plain-token-notification. Eén bron voor alle toekomstige Consumer-onboardings.
3. **Filament OAuth-actions**: "Start OAuth-koppeling"-action op zowel `AccountResource` (primary: koppel deze Account met provider X) als `ConnectionResource` (secondary: voor pending Connections zonder access_token). Eén shared action-class, hergebruikt Phase-4 `Init`/`Callback`-controllers.
4. **Filament Resource-infolist hints + nav-group-uitleg**: korte uitleg-tekst op `ConsumerResource` + `AccountResource` Infolist-pages + `Tenants`-navgroup-label. Voorkomt Consumer/Account/Connection-terminology-verwarring voor Emeq-staff.
5. **`/dev/partners`-pages**: domeinmodel-blokje (Consumer/Account/Connection) bovenaan + per-provider koppel-instructies + live demo-knop (Mollie OAuth-init) + cURL/form (Snelstart) + **live koppel-status-widget** ("school A: Mollie ✅ gekoppeld; Snelstart ⚠ niet"). Routes blijven `local`/`testing`-gated.

**Out of scope** (Naschool-repo, andere phases of backlog):

- Naschool's `StancltenancyCredentialResolver` of `SyncEnrollmentToSnelstartJob`-implementatie — Naschool repo
- Naschool's webhook-callback-endpoint + signature-verify — Naschool repo
- Naschool's demo-seed-data (school1-activiteit met vrijwillige bijdrage) — Naschool repo
- Self-service Consumer-registratie via publieke API — `HUB-ONBOARDING` backlog (v0.3+)
- Productie-grade onboarding-flow met e-mail-confirm + abuse-controles — `HUB-ONBOARDING` backlog
- Public `/portal` dashboard voor Consumers met eigen creds — v1.0+
- 2FA/MFA voor admin login — v1.0+
- Snelstart webhook-handler (`/webhooks/snelstart` HMAC-verified ingress) — Phase 5c (BLOCKED op `partner@snelstart.nl`)

</domain>

<decisions>
## Implementation Decisions

### Snelstart-pad voor Naschool

- **D-01:** Naschool gebruikt **Hub-pass-through** via `/v1/snelstart/{path}` (Phase 5b consumeren), niet een directe Stancl-resolver met eigen Snelstart-creds. Snelstart-credentials (`client_key` + `subscription_key` + `subscription_id`) leven uitsluitend op een Hub-`Connection`, encrypted at rest. NSCH-01-wording uit v0.1-vision (Stancl-resolver in Naschool) wordt geherinterpreteerd als "Naschool resolved welke `X-Account-Id` voor de huidige tenant moet meegestuurd". Rationale: DRY-winst van het pattern, single-source-of-truth voor creds, valideert Phase 5b productie-waardig met dezelfde inspanning.

### Mollie-Connection provisioning voor school A

- **D-02:** Twee paden naast elkaar — **live OAuth-roundtrip** via `/v1/oauth/mollie/init` (Phase 4-flow, productie-pad) **én** Filament-admin-action (D-04, voor Emeq-staff in dev/test/UAT). Beide leiden tot dezelfde server-side OAuth-uitwisseling; alleen de trigger verschilt. Geen aparte artisan-command of fake-token-seeder — die zouden SC-3 (live Mollie-checkout op school A's account) niet bewijzen.

### Plan-artifact placement

- **D-03:** CONTEXT.md, PLAN.md en plan-stubs leven hier in `.planning/phases/08-naschool-wiring-snelstart-mollie-via-hub/`, maar beschrijven **alleen Hub-side wijzigingen**. Naschool-side werk-plans wonen in de Naschool-repo. Cross-repo coordinatie loopt via deze CONTEXT.md (wat Naschool van de Hub mag verwachten), niet door Naschool-internals hier te documenteren. Respecteert de scope-feedback: geen Naschool-detail in `emeq-hub`-artefacten.

### Filament Consumer-onboard-flow

- **D-04:** Voeg een Filament-wizard toe (nieuwe Filament-page of multi-stap-Resource-action onder `ConsumerResource`) die Emeq-staff door de eerste-keer-onboarding loodst:
  1. Stap 1 — Consumer-velden (`name`, `slug`, `webhook_callback_url`, optional `webhook_callback_secret`)
  2. Stap 2 — eerste `Account` (`external_id`, `display_name`)
  3. Stap 3 — eerste `Connection`: provider-keuze (Mollie → OAuth-init-knop; Snelstart → 3 credential-velden via form)
  4. Stap 4 — PAT-uitgifte: ability-selectie (`snelstart:*`, `mollie:*`, `billing:*`) + plain-token-notification (eenmalig, conform Phase 9 `Issue PAT`-pattern)

  Self-service registratie voor derde-partij-Consumers (= publieke `POST /v1/consumers`) blijft `HUB-ONBOARDING` backlog (v0.3+); Phase 8 levert alleen de admin-tool.

### Filament OAuth-action — beide ingangen

- **D-05:** Voeg een `StartOAuthFlowAction` toe (shared Filament-action-class) die op twee plekken landt:
  - **`AccountResource`**: action "Koppel met provider…" — opens een selector met beschikbare providers met OAuth (= Mollie in v0.2). Klik → maakt pending Connection aan + redirect naar Mollie authorize-URL via bestaande Phase-4 `InitController`-logica.
  - **`ConnectionResource`**: action "Start OAuth-koppeling" — alleen actief voor Connections met `provider=mollie` zonder `access_token` (status pending). Triggert dezelfde flow.

  Beide ingangen hergebruiken Phase-4 controllers; geen duplicate flow-implementatie. Filament-action roept de controllers server-side aan en streamt de redirect naar de browser (Livewire `redirect()->away()`).

### Partner-pages content

- **D-06:** Per-provider pagina (`resources/views/partners/mollie/example.blade.php` + `resources/views/partners/snelstart/example.blade.php`) krijgt:
  1. **Domeinmodel-blokje bovenaan** — tabel Consumer / Account / Connection met één-liner uitleg + voorbeeld (Naschool / school A / school A's Mollie).
  2. **Provider-uitleg** — wat is Mollie/Snelstart, wat heeft een Account nodig (test-account, AppShortName, etc.).
  3. **Koppel-stappen** — concreet pad: Mollie via OAuth Connect (knop "Start OAuth-flow" → `/v1/oauth/mollie/init` met een pre-selected demo-Account); Snelstart via form (3 credential-velden) of cURL-snippet richting `POST /v1/connections`.
  4. **Live koppel-status-widget** — toont voor de in dev geseede demo-Account de huidige status per provider (`✅ gekoppeld; expires_at ...`, `⚠ pending`, `❌ revoked`, `— nog niet`). Read-only, leest `connections`-tabel.

  `resources/views/partners/index.blade.php` krijgt hetzelfde domeinmodel-blokje + index van provider-cards met status-totaal ("Mollie: 1/2 Accounts gekoppeld").

  Routes blijven `local`/`testing`-gated (`routes/web.php` regel 27-56). Productie-variant valt onder `HUB-DOCS` (backlog).

### Filament Resource-infolist hints + nav-group-uitleg

- **D-07:** Voeg korte uitleg-tekst toe aan drie plekken in Filament:
  - `ConsumerResource` Infolist: `Section::make('Wat is een Consumer?')->description('Eén SaaS-app die de Hub gebruikt (Naschool, Planny, externe app). Authenticeert met een Bearer-PAT. Een Consumer heeft Accounts (zijn klanten) en die Accounts hebben Connections (partner-koppelingen).')->collapsed()`.
  - `AccountResource` Infolist: vergelijkbare Section "Wat is een Account?" — *"Een klant van een Consumer (bv. school A bij Naschool). Niet de individuele eindgebruiker/ouder."*
  - `AdminPanelProvider` of nav-group-definitie: `Tenants`-navgroup krijgt een tooltip/description *"SaaS-apps (Consumer) → hun klanten (Account) → partner-koppelingen (Connection)"*.

  Geen breaking changes voor bestaande Phase-9 tests; nieuwe Infolist-Sections zijn additief.

### Claude's Discretion

- Partner-pages-styling (lay-out, kleur, iconen) — match Tailwind v4 + Filament-look-and-feel; geen aparte design-systeem.
- Status-widget refresh: server-render bij page-load (geen Livewire/polling) voor v0.2 — eenvoudig + voldoende, partners-pages worden niet vaak open gehouden.
- Filament-wizard layout: Filament's ingebouwde `Wizard` component is de standaard; eventueel multi-stap-action als dat compacter is.

### Folded Todos

Geen todos uit `gsd-sdk todo.match-phase 8` (0 matches).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning of implementeren.**

### Roadmap & Requirements
- `.planning/ROADMAP.md` §"Phase 8: Naschool wiring" — phase goal, dependencies, SC-1..SC-5
- `.planning/REQUIREMENTS.md` §"Naschool wiring" — NSCH-01 / NSCH-02 / NSCH-03 acceptance-criteria
- `.planning/PROJECT.md` §"Architectuur" — Consumer/Account/Connection domeinmodel + invariants

### Hub-side Pass-through (gebruikt door Naschool)
- `.docs/decisions/mollie-passthrough-api.md` — `/v1/mollie/*` pass-through-pattern (Phase 5a)
- `.docs/decisions/pass-through-calls-table.md` — audit-table-schema (Phase 5b)
- `.docs/decisions/account-subscriptions.md` — multi-tenant subscription state (Phase 7; Phase 8 raakt dit niet, maar Naschool kan ernaar refereren)

### OAuth Connect (gebruikt door Filament-action + onboard-wizard)
- `app/Http/Controllers/Api/V1/OAuth/InitController.php` — Phase-4 OAuth-init (re-use)
- `app/Http/Controllers/Api/V1/OAuth/CallbackController.php` — Phase-4 OAuth-callback (re-use)
- `app/OAuth/Contracts/OAuthFlow.php` — provider-agnostisch contract
- `app/OAuth/Mollie/MollieConnectOAuthFlow.php` — Mollie implementatie
- `app/OAuth/OAuthFlowRegistry.php` — provider → flow-lookup

### Provider-credential-laag
- `.docs/decisions/provider-credential-descriptor.md` — single source of truth voor per-provider credential-metadata
- `config/hub-providers.php` — provider-registry (gebruikt door `/dev/partners`-index)
- `app/Support/ProviderCredentialDescriptor.php` — descriptor value-object

### Filament admin (Phase 9)
- `.docs/decisions/filament-admin-panel.md` — Phase-9 ADR (Resources, RBAC, no-secret-leak invariant)
- `app/Filament/Resources/Consumers/ConsumerResource.php` — re-use Issue-PAT-action voor onboard-wizard
- `app/Filament/Resources/Connections/ConnectionResource.php` — re-use revoke-action; voeg start-OAuth-action toe
- `app/Filament/Resources/Accounts/AccountResource.php` — voeg start-OAuth-action toe
- `app/Providers/Filament/AdminPanelProvider.php` — nav-group + Resource-registratie

### Hub bootstrap & seeding
- `app/Console/Commands/HubConsumerCreate.php` — Phase-3 consumer-create (hergebruik of refactor naar shared service)
- `database/seeders/DatabaseSeeder.php` — Naschool-Consumer + callback-config dev-seed
- `routes/web.php` regel 39-55 — `/dev/partners` routes (local/testing-gated)
- `resources/views/partners/index.blade.php` + `partners/{provider}/example.blade.php` — bestaande Blade-pagina's om uit te breiden

### Rules & invariants
- `.ai/rules/global.md` — taal, anti-AI-cliché's, security (encrypted at rest, fingerprint-only logging), geen verzonnen partner-features
- `.ai/rules/engineering.md` — chirurgisch wijzigen, conflicten oppervlakken, lezen vóór schrijven
- `CLAUDE.md` §"Architectuur" — Consumer/Account/Connection chain + invariants

### Snelstart Phase 5b (Naschool consumeert dit)
- `.planning/phases/05b-snelstart-pass-through-api/05b-CONTEXT.md` — pass-through-design + audit-pattern
- `app/Http/Controllers/Api/V1/Snelstart/PassThroughController.php` — `/v1/snelstart/{path}`-handler
- `app/Http/Middleware/ResolveSnelstartAccount.php` (impl per Phase 5b) — `X-Account-Id`-flow

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`HubConsumerCreate` artisan-command** (`app/Console/Commands/HubConsumerCreate.php`) — bestaande Consumer-create-logica. Refactor naar shared `App\Services\ConsumerOnboarding`-service (of Action-class), hergebruikt door CLI + Filament-wizard. Geen breaking change op bestaande command-signature.
- **Phase-4 OAuth-controllers** (`app/Http/Controllers/Api/V1/OAuth/{Init,Callback}Controller.php`) — al productie-getest met 7 feature-tests. Filament-action roept deze server-side aan via internal request of een gedeelde service-method.
- **Phase-9 `Issue PAT`-pattern op `ConsumerResource`** — token-eenmalig-tonen via `Notification` is al gevalideerd. Reusable in onboard-wizard stap 4.
- **`ProviderCredentialDescriptor`-laag** (`config/hub-providers.php` + `app/Support/ProviderCredentialDescriptor.php`) — onboard-wizard stap 3 leest welke credential-velden per provider nodig zijn uit de descriptor (Mollie → OAuth-flow; Snelstart → 3 form-velden). Geen hardcoded provider-switch in de wizard.
- **`/dev/partners`-routes + Blade-views** (`routes/web.php` regel 39-55 + `resources/views/partners/`) — bestaande structuur. Phase 8 vult inhoud aan; geen route-wijzigingen.
- **`webhook_callback_url` + `webhook_callback_secret` op `consumers`-tabel** (migratie `2026_05_16_000001_*` uit Phase 5a-01) — kolommen bestaan al, encrypted cast actief. Onboard-wizard hoeft alleen waardes te vullen.

### Established Patterns

- **Filament-Resource-actions** (Phase 9): action-class onder `App\Filament\Resources\<Resource>\Actions\<Action>.php`, gebruikt `Notification::make()` voor user-feedback. `StartOAuthFlowAction` volgt dit pattern.
- **Filament `canAccess()`-gating** (Phase 9 deferred CR-02): nieuwe actions/wizards moeten Spatie-permissies enforcen. Onboard-wizard → `view-consumers` + `manage-consumers`; StartOAuthFlowAction → `manage-connections`.
- **Encrypted cast op secret-velden** (`Connection`, `Consumer.webhook_callback_secret`): nooit raw in form/table/notification. Plain `webhook_callback_secret` wordt eenmalig getoond bij onboard-wizard stap 1 of bij dedicated rotate-action — niet daarna.
- **No-secret-leak feature-tests** (Phase 9): elke nieuwe Resource-action met token-exposure krijgt een assertion `assertDontSee($plainToken)` op de list/detail-pagina nadat de notification dismissed is.
- **Dev-routes `local`/`testing`-gated** (`routes/web.php` regel 27): `/dev/partners`-pages worden niet auto-deploybaar naar productie. Status-widget mag live data tonen zonder lekkage-risico in deze envs.

### Integration Points

- **Filament `AdminPanelProvider`** (`app/Providers/Filament/AdminPanelProvider.php`): nav-group-tooltip + onboard-wizard-page-registratie landen hier.
- **Resource-Pages** (`app/Filament/Resources/<R>/Pages/`): infolist-Section + action-binding op `View<R>::infolist()`.
- **`ConsumerOnboarding`-service**: nieuwe service onder `app/Services/` met method `onboard(array $data): array` die Consumer+Account+Connection+PAT atomisch (DB-transaction) aanmaakt. Failure → DB-rollback + structured error voor Filament-wizard.
- **`StartOAuthFlowAction`**: nieuwe class onder `app/Filament/Actions/StartOAuthFlowAction.php`, parameter `Account|Connection $record` + `string $provider`. Resolveert `OAuthFlowRegistry`, maakt pending Connection of hergebruikt bestaande, retourneert authorize-URL via Livewire-redirect.
- **`PartnerStatus`-service** (optional): kleine helper voor `/dev/partners`-status-widget die per provider + Account de Connection-status leest. Read-only, kan ook inline in de Blade.

</code_context>

<specifics>
## Specific Ideas

- **Domeinmodel-blokje (Consumer/Account/Connection-uitleg)**: gewenst op `/dev/partners` index + per-provider pagina én in Filament `ConsumerResource`/`AccountResource` Infolist + `Tenants`-navgroup-tooltip. Tekst-template (Nederlandstalig, kort):
  > **Consumer** → jouw SaaS-app (Naschool, Planny, externe app). Authenticeert met een Bearer-PAT.
  > **Account** → een klant van jouw app (school A, vereniging C).
  > **Connection** → de partner-koppeling van die Account (school A's eigen Mollie- of Snelstart-account).

- **Status-widget op partner-pages**: toont per demo-Account een statusregel per provider:
  - `✅ gekoppeld — expires {expires_at}` (alleen Mollie, OAuth)
  - `✅ gekoppeld — clientkey {fingerprint}` (alleen Snelstart, key-based)
  - `⚠ pending — wacht op OAuth-callback`
  - `❌ revoked at {revoked_at}`
  - `— nog niet gekoppeld`

- **Filament onboard-wizard "happy path"**: één rij in de Consumer-list → klik "Open" → wizard met 4 stappen + success-screen met PAT-eenmalig + redirect naar de net-aangemaakte Connection.

- **Phase 8 SC-mapping** (uit ROADMAP):
  - SC-1 (composer-resolve in Naschool zonder auth) — **out of scope hier**, bewijs in Naschool repo
  - SC-2 (`EnrollmentConfirmed` → Snelstart-factuur) — **out of scope hier**, Naschool-job
  - SC-3 (ouder doorloopt Mollie-checkout op school A's account) — **Hub-side klaar uit Phase 5a**; Phase 8 levert geen nieuwe Hub-code voor SC-3, alleen de onboard-tools om school A's Mollie te koppelen
  - SC-4 (webhook → Hub → Naschool-callback → status `paid`) — **Hub-side klaar uit Phase 5a**; Phase 8 levert dev-reachability-helper als plan-deliverable
  - SC-5 (end-to-end smoke gedocumenteerd) — **shared**; runbook in Naschool repo, Hub-side runbook-pointer in `.docs/`

</specifics>

<deferred>
## Deferred Ideas

- **Self-service Consumer-registratie** (`POST /v1/consumers`) → `HUB-ONBOARDING` backlog (v0.3+). Phase 8 doet alleen admin-tool.
- **Productie-pagina's voor partners** (publieke `docs.hub.emeq.nl` met koppel-instructies) → `HUB-DOCS` backlog.
- **E-mail-confirm + abuse-controles** voor Consumer-aanvraag → `HUB-ONBOARDING` backlog.
- **2FA/MFA voor admin login** → v1.0+.
- **Filament Resource voor `PassThroughCall`-audit** → `HUB-OBSERVABILITY` backlog.
- **OAuth-status-polling op partner-pages** (Livewire/wire:poll voor live updates terwijl ouder Mollie-roundtrip doet) → kan in `HUB-DOCS` of Phase 9-uitbreiding.
- **Rotate-action voor `webhook_callback_secret`** in Filament → klein, kan als `HUB-AUDIT`-aanvullend item in v0.2.1 polish.
- **`StartOAuthFlowAction` voor Snelstart** wanneer Snelstart-OAuth ooit landt (v0.3+: Snelstart-OAuth-Connect uitrol) — `OAuthFlowRegistry` is al provider-agnostisch.
- **Naschool-zijde implementatie** (Stancl-resolver-refactor naar Hub-pass-through-call, `EnrollmentConfirmed`-listener, callback-handler, demo-seed) → Naschool repo, niet hier.

### Reviewed Todos (not folded)
Geen — `gsd-sdk todo.match-phase 8` retourneerde 0 matches.

</deferred>

---

*Phase: 8-naschool-wiring-snelstart-mollie-via-hub*
*Context gathered: 2026-05-16*
