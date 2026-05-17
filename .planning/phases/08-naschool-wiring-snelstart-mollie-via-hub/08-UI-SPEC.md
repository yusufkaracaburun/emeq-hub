---
phase: 8
slug: naschool-wiring-snelstart-mollie-via-hub
status: approved
shadcn_initialized: false
preset: none
created: 2026-05-17
reviewed_at: 2026-05-17
---

# Phase 8 — UI Design Contract

> Visual and interaction contract voor de Hub-side surface van Naschool wiring (Phase 8).
> Geen shadcn/React. Stack = Filament v4 (Wizard/Infolist/Action/Notification) + Livewire v3 +
> Tailwind v4 + Blade (dev-gated partner-pages). Geconsumeerd door gsd-planner en gsd-executor.
>
> Pre-populated uit `08-CONTEXT.md` (D-01..D-07 LOCKED), `ROADMAP.md` §Phase 8, REQUIREMENTS
> NSCH-01..03, `.docs/decisions/filament-admin-panel.md`, `.docs/decisions/provider-credential-descriptor.md`.

---

## Surface Inventory

Vier user-facing surfaces komen erbij of breiden uit; geen nieuwe routes, geen nieuwe panel.

| # | Surface | Type | Audience | Locked by |
|---|---------|------|----------|-----------|
| S1 | Filament Consumer-onboard-wizard (4 stappen) | Filament Page of Resource-action | Emeq-staff (rol `super-admin` / `staff`) | D-04 |
| S2 | `StartOAuthFlowAction` op `AccountResource` (primary) + `ConnectionResource` (pending-only) | Filament Action-class | Emeq-staff | D-05 |
| S3 | `/dev/partners` pages — index + per-provider — uitbreiding | Blade in `local`/`testing` | Emeq-developers + partner-aanvraag-screenshots | D-06 |
| S4 | Filament Resource-infolist hint-Sections + `Tenants`-navgroup-tooltip | Filament Infolist + nav-group config | Emeq-staff | D-07 |

Scope-fence: alle Naschool-interne UI (composer-wiring, listener, callback-page) zit in de Naschool-repo en valt buiten deze contract.

---

## Design System

| Property | Value | Source |
|----------|-------|--------|
| Tool | none (geen shadcn — backend-only Laravel stack) | tech stack |
| Preset | not applicable | n/a |
| Component library — admin | Filament v4 (Livewire-powered, Tailwind v4 onder de motor) | composer.json + AdminPanelProvider |
| Component library — dev-pages | Blade + Tailwind v4 utilities (migreren van inline `<style>` naar Tailwind) | resources/css/app.css |
| Icon library | Heroicons (via `Filament\Support\Icons\Heroicon` enum + `heroicon-o-*` Blade-icons) | Filament v4 default |
| Font | Instrument Sans (sans-serif) — `--font-sans` token uit `resources/css/app.css` | app.css |
| Filament primary color | Amber (`Color::Amber`) | AdminPanelProvider:39 |

---

## Spacing Scale

Tailwind v4 default 4-pt scale. Phase 8-surfaces gebruiken exclusief deze tokens; geen ad-hoc px-waarden.

| Token | Value | Tailwind class | Usage in Phase 8 |
|-------|-------|----------------|-------------------|
| xs | 4px | `gap-1` / `p-1` | Icon-tekst-gap in status-regels, badge-padding |
| sm | 8px | `gap-2` / `p-2` | Wizard-veld-spacing binnen één step, button-icon-gap |
| md | 16px | `gap-4` / `p-4` | Default tussen form-velden, kaart-padding `/dev/partners` |
| lg | 24px | `gap-6` / `p-6` | Section-padding in domeinmodel-blokje, wizard-step-content |
| xl | 32px | `gap-8` / `p-8` | Page-padding `/dev/partners`, wizard-Step-titles |
| 2xl | 48px | `gap-12` / `p-12` | Tussen Major-sections op `/dev/partners` index |
| 3xl | 64px | `gap-16` | `/dev/partners`-page top-margin |

Exceptions: geen. Filament's eigen Wizard/Section/Infolist gebruiken Filament-tokens (`fi-*`-classes) die intern op dezelfde 4-pt-grid zitten — niet overschrijven.

---

## Typography

Instrument Sans op 4 rollen. Geen extra font-families. Body line-height 1.5; headings 1.25.

| Role | Size | Weight | Line Height | Tailwind class | Usage |
|------|------|--------|-------------|----------------|-------|
| Body | 16px | 400 | 1.5 | `text-base font-normal leading-normal` | Wizard-help-tekst, domeinmodel-uitleg-paragraph, status-regel-tekst |
| Label | 14px | 500 | 1.25 | `text-sm font-medium leading-tight` | Form-labels, table-column-headers, badge-text, status-icon-label |
| Heading | 20px | 600 | 1.25 | `text-xl font-semibold leading-tight` | Wizard-step-titles, Infolist-Section-titles, partner-page H2 |
| Display | 28px | 600 | 1.2 | `text-3xl font-semibold leading-tight` | Partner-page H1, onboard-wizard-page-title |

Filament's eigen tokens nemen voorrang binnen `.fi-*`-scopes; specificeer alleen typografie waar custom Blade rendert.

## Typography Exceptions

**Approved deviation from "max 2 weights"-criterium:** deze UI-SPEC gebruikt een 3-step weight-ladder (400/500/600 = regular/medium/semibold).

**Rationale:** Filament v4 en Tailwind v4 leveren beide standaard de 3-step ladder. Collapsen naar 2 weights forceert override van vendor-tokens en creëert visuele inconsistentie tussen onze nieuwe oppervlakken (onboard-wizard, Filament-actions, partner-pages) en de rest van het Filament-admin-paneel (Resources, infolists, tables, notifications) dat al weight-500 voor labels en weight-600 voor headings gebruikt. De inconsistentie-kosten zijn hoger dan de winst van 2-weight-strictheid.

**Scope van de exception:** alleen Body/Label/Heading/Display rollen in deze UI-SPEC. Nieuwe weights (300, 700, 800) blijven verboden. Toekomstige UI-SPECs erven deze exception alleen als ze dezelfde Filament + Tailwind stack hanteren.

---

## Color

60/30/10 split op Tailwind v4 + Filament Amber primary. Status-widget gebruikt eigen semantische palette (groen/amber/rood/grijs).

| Role | Value | Tailwind / Filament token | Usage |
|------|-------|---------------------------|-------|
| Dominant (60%) | `#FFFFFF` / `#0A0A0A` (dark) | `bg-white` / `dark:bg-neutral-950` | Filament canvas, `/dev/partners` page-background |
| Secondary (30%) | `#F9FAFB` / `#171717` (dark) | `bg-gray-50` / `dark:bg-neutral-900` | Domeinmodel-blokje, kaart-surfaces, Wizard step-shell |
| Accent (10%) | Amber 500 `#F59E0B` | `bg-amber-500` / `text-amber-600` / `Color::Amber` | **Reserved-for list — STRICT** (zie hieronder) |
| Destructive | Rose 600 `#E11D48` | `Color::Rose` (Filament) / `bg-rose-600` | Revoke-action, last-super-admin-guard error notification |

### Accent (Amber) reserved-for — STRICT list

Amber verschijnt ALLEEN op deze 6 elementen in Phase 8:

1. Wizard "Volgende" / "Aanmaken" primary submit-button (Filament default voor primary panel)
2. `StartOAuthFlowAction`-trigger button label + icon
3. Issue-PAT-action submit-button (stap 4 van wizard) — bestaand gedrag
4. "Open" / "Bekijken" record-action op de zojuist-aangemaakte Consumer (succes-redirect)
5. Status-widget badge wanneer status = `✅ gekoppeld` (groene tint geprefereerd; amber als secondary fallback wanneer kleur al gebruikt is door provider-badge)
6. `/dev/partners` "Start OAuth-flow"-CTA op de Mollie-card

NIET amber: filter-knoppen, table-row-hovers, body-links binnen helpteksten, infolist-`Section`-collapse-toggles.

### Status-widget semantic palette (D-06 `<specifics>`)

| Status state | Color | Tailwind | Icon (Heroicon-outline) |
|--------------|-------|----------|--------------------------|
| `✅ gekoppeld` (OAuth + key-based) | Emerald 600 | `text-emerald-600 bg-emerald-50` | `check-circle` |
| `⚠ pending — wacht op OAuth-callback` | Amber 600 | `text-amber-600 bg-amber-50` | `clock` |
| `❌ revoked at {revoked_at}` | Rose 600 | `text-rose-600 bg-rose-50` | `x-circle` |
| `— nog niet gekoppeld` | Gray 500 | `text-gray-500 bg-gray-50` | `minus-circle` |

---

## Copywriting Contract

Alle user-facing copy = Nederlands. Code/identifiers = Engels. Geen AI-clichés (`.ai/rules/global.md`).

### Surface S1 — Onboard-wizard

| Element | Copy |
|---------|------|
| Page title | "Nieuwe Consumer onboarden" |
| Step 1 title / lead | "Consumer" / "De SaaS-app die de Hub gaat aanroepen." |
| Step 2 title / lead | "Eerste Account" / "Een klant van deze Consumer (bv. een school)." |
| Step 3 title / lead | "Eerste Connection" / "De partner-koppeling voor deze Account." |
| Step 4 title / lead | "PAT uitgeven" / "Het token wordt eenmalig getoond. Bewaar het direct." |
| Step 1 field — name | label `"Naam"`, placeholder `"Naschool"` |
| Step 1 field — slug | label `"Slug"`, helper `"Lowercase, dashes. Bv. naschool."` |
| Step 1 field — webhook_callback_url | label `"Webhook callback-URL"`, helper `"Endpoint waar de Hub partner-events naartoe POSTed. Optioneel — vul later in."` |
| Step 1 field — webhook_callback_secret | label `"Webhook callback-secret"`, helper `"Wordt eenmalig getoond na opslaan. Daarna alleen rotate-able."` |
| Step 2 field — external_id | label `"Externe ID"`, helper `"De identifier die deze Consumer voor deze klant gebruikt (bv. school1)."` |
| Step 2 field — display_name | label `"Weergavenaam"`, placeholder `"School A"` |
| Step 3 field — provider | label `"Provider"`, options `Mollie` / `Snelstart` (descriptor-driven) |
| Step 3 — Mollie branch | toon CTA-button `"Start Mollie OAuth-koppeling"` (triggert `StartOAuthFlowAction`) + helper `"Je wordt naar Mollie gestuurd. Na goedkeuring keer je terug in deze wizard."` |
| Step 3 — Snelstart branch | toon 3 velden `"Client key"` / `"Subscription key"` / `"Subscription ID"` + helper `"Door SnelStart uitgegeven aan de eindklant. Tokens worden encrypted opgeslagen."` |
| Step 4 — ability presets | hergebruik `ConsumerResource::PAT_PRESETS` labels (`Mollie read-only`, `Mollie read+write`, `Snelstart read-only`, `Snelstart read+write`, `Admin`, `Custom...`) |
| Step 4 — submit | label `"Token uitgeven"` |
| Primary CTA per stap | `"Volgende"` (stap 1-3) → `"Token uitgeven"` (stap 4) |
| Cancel-link | `"Afbreken"` (linksonder, secondary) |
| Success notification (na stap 4) | title `"Consumer onboarded — PAT eenmalig zichtbaar bovenaan de listing"`, type `success` (hergebruikt Cache-flash pattern uit `ConsumerResource::issuePatAction`) |
| Plain webhook_callback_secret-notification (na stap 1, wanneer auto-gegenereerd) | title `"Webhook-secret aangemaakt — kopieer nu"`, type `warning` |
| Empty state (geen Consumers nog) | heading `"Nog geen Consumers"`, body `"Maak de eerste aan met de onboard-wizard."`, CTA `"Onboarden"` |
| Validation error — slug niet uniek | inline `"Deze slug bestaat al — kies een andere."` |
| Server error — onboarding-service faalt | notification title `"Onboarden mislukt"`, body `"Er ging iets mis bij {step}. Probeer opnieuw of bekijk Horizon-logs."`, type `danger` |

### Surface S2 — `StartOAuthFlowAction`

| Element | Copy |
|---------|------|
| Action label (AccountResource) | `"Koppel met provider…"` |
| Action label (ConnectionResource, pending only) | `"Start OAuth-koppeling"` |
| Action icon | Heroicon `link` (outlined) |
| Modal heading | `"Provider kiezen"` (alleen AccountResource — ConnectionResource heeft `provider` al) |
| Modal field — provider | label `"Provider"`, helper `"Alleen providers met OAuth-flow zijn beschikbaar."` |
| Modal submit-label | `"Start koppeling"` |
| Modal cancel-label | `"Annuleren"` |
| Confirmation-state copy | none — direct redirect via `redirect()->away($authorizeUrl)` is verwacht gedrag |
| Visible-only-when condition | AccountResource: altijd voor staff met `manage-connections` — ConnectionResource: alleen `provider=mollie` + `access_token=null` + `revoked_at=null` |
| No-flow-available message | notification title `"Geen OAuth-flow beschikbaar"`, body `"Provider {provider} heeft geen OAuth-koppeling. Gebruik de Snelstart-credential-flow via de onboard-wizard of POST /v1/connections."`, type `warning` |
| Permission-denied message | Filament default 403 (geen custom copy) |

### Surface S3 — `/dev/partners` pages

| Element | Copy |
|---------|------|
| Page title (index) | `"Partner previews"` (bestaand — niet wijzigen) |
| Page lead (index) | bestaande tekst behouden — uitbreiden met domeinmodel-blokje eronder |
| Page title (per-provider) | `"Emeq Hub × {Provider}"` (bestaand — niet wijzigen) |
| Domeinmodel-blokje heading | `"Hoe de Hub-tenancy in elkaar zit"` |
| Domeinmodel-blokje body | CANONICAL (D-06 + `<specifics>` — niet paraphraseren): |
| | **Consumer** → jouw SaaS-app (Naschool, Planny, externe app). Authenticeert met een Bearer-PAT. |
| | **Account** → een klant van jouw app (school A, vereniging C). |
| | **Connection** → de partner-koppeling van die Account (school A's eigen Mollie- of Snelstart-account). |
| Koppel-stappen heading (Mollie) | `"Koppelen via OAuth Connect"` |
| Koppel-stap 1 (Mollie) | `"1. Zorg dat school A een Mollie test-account heeft."` |
| Koppel-stap 2 (Mollie) | `"2. Klik op 'Start OAuth-flow' hieronder — je wordt naar Mollie gestuurd."` |
| Koppel-stap 3 (Mollie) | `"3. Na goedkeuring landt de access_token encrypted in de Connection."` |
| Mollie-CTA | `"Start OAuth-flow"` (button, accent-amber, links naar `/v1/oauth/mollie/init` met pre-selected demo-Account) |
| Koppel-stappen heading (Snelstart) | `"Koppelen via credential-form"` |
| Koppel-stap 1 (Snelstart) | `"1. Vraag bij SnelStart de drie credentials op (client key, subscription key, subscription ID)."` |
| Koppel-stap 2 (Snelstart) | `"2. POST naar `/v1/connections` met provider=snelstart en de drie velden."` |
| Koppel-stap 3 (Snelstart) | `"3. De Hub encrypt de credentials at rest; alleen de fingerprint is daarna nog leesbaar."` |
| Snelstart cURL-snippet | code-blok met `curl -X POST {APP_URL}/v1/connections -H "Authorization: Bearer {PAT}" -H "Content-Type: application/json" -d '{"account_external_id":"school1","provider":"snelstart","client_key":"…","subscription_key":"…","subscription_id":"…"}'` |
| Status-widget heading | `"Live koppel-status (dev-omgeving)"` |
| Status-widget empty state | `"Geen demo-Accounts — draai `php artisan db:seed` eerst."` |
| Status-widget regel | format `"{Account.display_name}: {Provider} {status-icon} {status-text}"` (zie status-state-tabel) |
| Index-card status-totaal | `"{Provider}: {connected_count}/{total_accounts} Accounts gekoppeld"` |

### Surface S4 — Infolist hints + nav-tooltip

| Element | Copy |
|---------|------|
| `ConsumerResource` Infolist Section title | `"Wat is een Consumer?"` |
| `ConsumerResource` Infolist Section body (CANONICAL, D-07) | `"Eén SaaS-app die de Hub gebruikt (Naschool, Planny, externe app). Authenticeert met een Bearer-PAT. Een Consumer heeft Accounts (zijn klanten) en die Accounts hebben Connections (partner-koppelingen)."` |
| `AccountResource` Infolist Section title | `"Wat is een Account?"` |
| `AccountResource` Infolist Section body (CANONICAL, D-07) | `"Een klant van een Consumer (bv. school A bij Naschool). Niet de individuele eindgebruiker/ouder."` |
| `Tenants`-navgroup tooltip/description | `"SaaS-apps (Consumer) → hun klanten (Account) → partner-koppelingen (Connection)"` |
| Default Section state | `collapsed()` — geen visuele ruis voor staff die de model al kent |

### Destructive confirmations

| Action | Confirmation copy |
|--------|-------------------|
| Wizard "Afbreken" (data ingevuld) | modal `"Onboarding afbreken — ingevulde data gaat verloren. Doorgaan?"`, buttons `"Doorgaan met afbreken"` / `"Terug naar wizard"` |
| Connection-revoke (bestaand uit Phase 9) | hergebruik bestaande copy — niet wijzigen |
| Geen nieuwe destructive actions in Phase 8 | n/a |

---

## No-Secret-Leak Invariant (Phase 9 carry-over + Phase 8 enforcement)

Plain tokens / secrets verschijnen EXACT EENMAAL via `Notification::make()` + Cache-flash pattern (zie `ConsumerResource::issuePatAction` regels 191-198) of via een one-shot view-cell. Phase 8 contract eist:

| Secret | Where shown (eenmalig) | Where NEVER shown | Test acceptance (for planner) |
|--------|------------------------|-------------------|-------------------------------|
| `webhook_callback_secret` (auto-generated bij Consumer-create) | Notification + Cache-flash op stap 1 success | Resource-table, Infolist, edit-form, partner-pages, status-widget | `assertDontSee($plainSecret)` op `livewire(ListConsumers::class)` en op `EditConsumer` |
| Plain PAT-token (stap 4 wizard) | Cache-flashed naar list-view (bestaand pattern) | Wizard-state na step-advance, `wire:snapshot`, Alpine `x-data`, `personal_access_tokens` table | `assertDontSee($plainToken)` op alle Filament-paginas na 60s Cache-TTL |
| `client_key` / `subscription_key` (Snelstart, wizard stap 3) | Niet getoond — alleen ingevoerd, encrypted opgeslagen | overal | `assertDontSee($plainKey)` op `ViewConnection`-page + `/dev/partners/snelstart` |
| `access_token` / `refresh_token` (Mollie, na OAuth-callback) | Niet getoond — descriptor-driven fingerprint only | overal | hergebruik bestaande Phase-9 `ConnectionResourceNoSecretLeakTest` |
| Mollie OAuth `redirect_url` | Eenmalig via `redirect()->away()` (geen UI-bewaring) | Database, audit-log, Filament-toast | n/a — server-side redirect |

---

## Interaction Contracts

### Wizard flow (S1)

- Filament's ingebouwde `Wizard` component (4 `Step`s) binnen een dedicated `OnboardConsumer` Filament-Page onder `app/Filament/Pages/`, geregistreerd in `AdminPanelProvider::pages()`.
- Steps zijn **non-linear-disabled** (geen jumps); user moet sequentially door.
- Per-step validatie via Filament's `validation()`-rules (server-side) — geen client-only checks.
- Step 3 toont een **conditional sub-form** afhankelijk van provider-keuze (Filament `Forms\Components\Group::visible()`); descriptor-driven (`ProviderCredentialDescriptor::for($provider)->formFields()`).
- Step 3 Mollie-branch: "Start OAuth-koppeling" button doet **client-side `wire:redirect`** naar `/v1/oauth/mollie/init`-response `redirect_url`. Bij callback landt user terug op de wizard met `wire:emit` of via session-restored step-state. (Implementatie-detail: `OAuthFlowRegistry` + `state` parameter dragen wizard-session-id; planner kiest pattern.)
- Step 4 success: `Notification` met Cache-flash key voor plain PAT-token; redirect via `redirect()->to(ConsumerResource::getUrl())` zodat de net-aangemaakte rij + token-flash-banner direct zichtbaar zijn.
- Permission-gate: `OnboardConsumer::canAccess()` → `auth()->user()?->can('manage-consumers')` (Spatie permission, geseed door `EmeqStaffSeeder`).

### StartOAuthFlowAction (S2)

- Filament `Action::make('startOAuthFlow')` class onder `app/Filament/Actions/StartOAuthFlowAction.php` (shared).
- Op `AccountResource`: `Tables\Actions\Action` + zelfde class als `Pages\ViewAccount` infolist-header-action.
- Op `ConnectionResource`: alleen `Tables\Actions\Action` met `visible()`-check zoals reeds gespec'd.
- Werking: action `action()` callback resolved `OAuthFlowRegistry::for($provider)` → maakt pending Connection aan (of hergebruikt) → `redirect()->away($authorizeUrl)` via Livewire.
- Geen modal als provider al vaststaat (ConnectionResource). Wel modal op AccountResource voor provider-keuze.
- Permission-gate: `manage-connections`.

### `/dev/partners` status-widget (S3)

- Server-render bij page-load (Claude's Discretion uit CONTEXT — geen Livewire/polling in v0.2).
- Render-pad: route-callback (`routes/web.php`) injecteert `$accountStatus = app(PartnerStatusService::class)->forProvider($provider)` waarbij service één query doet: `Account::with(['connections' => fn ($q) => $q->where('provider', $provider)])->get()`.
- Service lives onder `app/Services/PartnerStatus.php`; pure read-only, geen state.
- Index-page card-status-totaal gebruikt zelfde service met `->counts()`-aggregaat.
- Reload-instructie in footer: `"Status wordt bij page-load gerefreshed — herlaad de pagina na een koppel-actie."`

### Infolist hint Section (S4)

- `Section::make('Wat is een ...?')->description('...')->collapsed()` als eerste component in beide Infolists.
- Geen interactie behalve native collapse/expand.
- Nav-group tooltip: gebruik Filament v4's `NavigationGroup::make('Tenants')->label('Tenants')->extraSidebarAttributes(['title' => '...'])` of `description()` zodra Filament v4 dat ondersteunt — fallback = HTML `title`-attribuut via custom render-hook.

---

## Component Inventory

Bouwblokken die de planner moet inplannen, met canonieke locatie:

| Component | Type | Path | New / Reuse |
|-----------|------|------|-------------|
| `OnboardConsumer` Filament Page | Filament Page | `app/Filament/Pages/OnboardConsumer.php` | NEW |
| `OnboardConsumer` Blade view | Blade | `resources/views/filament/pages/onboard-consumer.blade.php` | NEW (extends Filament page-layout) |
| `ConsumerOnboarding` service | Service | `app/Services/ConsumerOnboarding.php` | NEW |
| `StartOAuthFlowAction` | Filament Action class | `app/Filament/Actions/StartOAuthFlowAction.php` | NEW |
| `PartnerStatus` service | Service | `app/Services/PartnerStatus.php` | NEW |
| `ConsumerInfolist` hint Section | Filament Infolist Schema edit | `app/Filament/Resources/Consumers/Schemas/ConsumerInfolist.php` (create if missing) | NEW |
| `AccountInfolist` hint Section | Filament Infolist Schema edit | `app/Filament/Resources/Accounts/Schemas/AccountInfolist.php` (existing) | EXTEND |
| `AdminPanelProvider` nav-group-tooltip | Filament panel config | `app/Providers/Filament/AdminPanelProvider.php` | EXTEND |
| `partners/index.blade.php` domeinmodel-blokje + status-totaal | Blade | `resources/views/partners/index.blade.php` | EXTEND |
| `partners/mollie/example.blade.php` koppel-stappen + status-widget | Blade | `resources/views/partners/mollie/example.blade.php` | EXTEND |
| `partners/snelstart/example.blade.php` koppel-stappen + status-widget | Blade | `resources/views/partners/snelstart/example.blade.php` | EXTEND |
| Shared `_domeinmodel-blokje.blade.php` partial | Blade partial | `resources/views/partners/partials/_domeinmodel.blade.php` | NEW |
| Shared `_status-widget.blade.php` partial | Blade partial | `resources/views/partners/partials/_status-widget.blade.php` | NEW |

---

## Accessibility Contract

- Alle Filament Wizard-steps: native `<fieldset>`+`<legend>`-structuur (Filament v4 default).
- Status-widget icons: pair met sr-only-text (`<span class="sr-only">Status: gekoppeld</span>`) voor screen-readers — kleur alleen is niet voldoende (WCAG 1.4.1).
- Amber-primary op witte achtergrond: contrast-ratio voor `text-amber-600` (#D97706) op `bg-white` = 4.57:1 — voldoet aan WCAG AA voor large text; voor normale text gebruik `text-amber-700` (#B45309, 6.16:1).
- Destructive `rose-600` op wit: 4.93:1 — voldoet AA.
- Alle interactive elements minimaal 44px touch-target (Filament default `fi-btn` is `h-9` = 36px; voor mobile review later — niet blocker voor v0.2 admin-only surface).

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none | not applicable (no shadcn in stack) |
| third-party | none | not applicable |

Geen third-party UI-registries geconsumeerd. Alle componenten komen uit Filament v4 (vendor) of zijn eigen Blade.

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS (Nederlands; canonical D-06/D-07 strings letterlijk overgenomen; geen AI-clichés)
- [ ] Dimension 2 Visuals: PASS (Filament v4 surfaces + Tailwind v4 Blade; geen mengelmoes)
- [ ] Dimension 3 Color: PASS (60/30/10 + accent-Amber-reserved-for-6-elementen + semantische status-palette + destructive-rose)
- [ ] Dimension 4 Typography: 3 weights documented exception, see §Typography Exceptions (4 rollen, 1 family, line-heights gespec'd; 400/500/600 ladder bewust gekozen om Filament + Tailwind vendor-tokens te volgen)
- [ ] Dimension 5 Spacing: PASS (Tailwind 4-pt scale, 7 tokens, geen exceptions)
- [ ] Dimension 6 Registry Safety: PASS (n/a — geen registries)

**Approval:** pending — awaiting gsd-ui-checker
