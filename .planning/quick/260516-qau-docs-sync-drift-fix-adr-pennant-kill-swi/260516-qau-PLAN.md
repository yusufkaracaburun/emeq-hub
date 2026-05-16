---
phase: 260516-qau-docs-sync-drift-fix
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - .docs/decisions/feature-flags-pennant-kill-switch.md
  - .docs/README.md
autonomous: true
requirements:
  - DOCS-SYNC-01
  - DOCS-SYNC-02
must_haves:
  truths:
    - "ADR `.docs/decisions/feature-flags-pennant-kill-switch.md` bestaat en documenteert de Pennant kill-switch zoals geland in Phase 8 (commits 53a6c90 + bff6454)."
    - "ADR vermeldt exact de drie integratie-punten met juiste class-paden (FeatureServiceProvider, EnsureProviderEnabled middleware, OAuthFlowRegistry + ProviderDisabledException)."
    - "ADR matcht de Nederlandstalige toon en sections-volgorde van `.docs/decisions/provider-credential-descriptor.md`."
    - "`.docs/README.md` indeling-tabel bevat een rij voor `strategy/` met beschrijving en levensduur."
    - "Bestaande tabel-rijen in `.docs/README.md` zijn onveranderd."
  artifacts:
    - path: ".docs/decisions/feature-flags-pennant-kill-switch.md"
      provides: "ADR voor Pennant-based provider kill-switch"
      contains: "Status: Accepted 2026-05-16"
    - path: ".docs/README.md"
      provides: "Indeling-tabel met `strategy/` rij toegevoegd"
      contains: "strategy/"
  key_links:
    - from: ".docs/decisions/feature-flags-pennant-kill-switch.md"
      to: "app/Providers/FeatureServiceProvider.php"
      via: "Integratie-punten section"
      pattern: "FeatureServiceProvider"
    - from: ".docs/decisions/feature-flags-pennant-kill-switch.md"
      to: "app/Http/Middleware/EnsureProviderEnabled.php"
      via: "Integratie-punten section"
      pattern: "EnsureProviderEnabled"
    - from: ".docs/decisions/feature-flags-pennant-kill-switch.md"
      to: "app/OAuth/OAuthFlowRegistry.php"
      via: "Integratie-punten section"
      pattern: "OAuthFlowRegistry"
---

<objective>
Twee discrete docs-sync drift-fixes uit eerdere analyse:
1. ADR schrijven voor de Pennant kill-switch die in Phase 8 landde (rationale leeft nu alleen in commit-messages + CONTEXT.md).
2. `.docs/README.md` indeling-tabel uitbreiden met de bestaande `strategy/` folder.

Purpose: drift opheffen — architectuur-rationale stollen in een ADR (lange levensduur) + repo-index volledig houden zodat nieuwe contributors de strategy-folder vinden.
Output: één nieuwe ADR-file + één gewijzigde regel in README-tabel. Geen code-changes.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md

# Style reference for ADR
@.docs/decisions/provider-credential-descriptor.md

# README-tabel structuur (alleen indeling-section, regels 1-30)
@.docs/README.md

# Verifieerbare integratie-punten (lees alleen voor file-path/class-naam-validatie tijdens schrijven)
@app/Providers/FeatureServiceProvider.php
@app/Http/Middleware/EnsureProviderEnabled.php
@app/OAuth/OAuthFlowRegistry.php
@app/OAuth/Exceptions/ProviderDisabledException.php
@bootstrap/app.php
@routes/api.php
@config/hub-providers.php

<interfaces>
<!-- Style/structuur die de ADR moet matchen (uit provider-credential-descriptor.md) -->

ADR-header:
- `# ADR — <Titel> (<korte beschrijving>)`
- `**Status:** Accepted 2026-05-16 (Phase 8)`
- `**Phase:** 8 (<context>)`
- `**Scope:** hub-wide / provider-laag`
- `**Related:** lijst met bullets naar verwante ADRs`

Sections-volgorde (Nederlandstalig, ## headings):
1. Context
2. Keuze
3. Scope-design
4. Kill-switch-mechaniek
5. Integratie-punten
6. Invariant
7. Niet-keuzes
8. Wanneer herzien
9. Geschiedenis

Toon: zakelijk Nederlands, korte directe zinnen, code-snippets in fenced blocks waar relevant (zie provider-credential-descriptor.md `config/hub-providers.php` voorbeeld).
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Schrijf ADR voor Pennant provider kill-switch</name>
  <files>.docs/decisions/feature-flags-pennant-kill-switch.md</files>
  <action>
Maak een nieuwe ADR-file met dezelfde structuur en Nederlandstalige toon als `.docs/decisions/provider-credential-descriptor.md`. Niet de implementatie opnieuw verifiëren — file-paden + class-namen zijn al in `<files_to_read>` boven uitgeschreven en de integratie-punten staan in deze action. Open de target-files alleen om de exacte regel-nummers / alias-namen te bevestigen voor de Integratie-punten-section.

**Header:**
- Titel: `# ADR — Pennant provider kill-switch (config-driven feature-flag per provider)`
- Status: `Accepted 2026-05-16 (Phase 8)`
- Phase: `8 (Naschool wiring — eerste consumer-context waar provider-uitval/rollout-gate operationeel werd)`
- Scope: `hub-wide; alle provider-routes en OAuth-flow-resolution`
- Related: bullets naar `.docs/decisions/provider-credential-descriptor.md` (spiegel-pattern: config-driven, geen edit van middleware/registry bij nieuwe provider) en commits `53a6c90` (install laravel/pennant ^1.23) + `bff6454` (provider kill-switch via Pennant).

**Section-content (volg deze volgorde exact):**

1. **Context** — Waarom feature-flags nodig waren: kill-switch bij partner-outage (Mollie/Snelstart down → Hub moet 503 returnen zonder code-deploy) en rollout-gate (nieuwe provider achter flag tot smoke-test geslaagd). Voor v0.2 was er nog geen mechanisme; provider-config in `config/hub-providers.php` was statisch zonder runtime-toggle.

2. **Keuze** — Laravel Pennant. Twee alternatieven afgewezen:
   - vs eigen config-toggle (`config('hub-providers.X.enabled')` + `config:cache` clear): Pennant heeft een runtime store + per-scope storage zonder cache-bust; admin-UI kan straks toggles direct in DB schrijven.
   - vs custom Eloquent-flag-model: Pennant levert de scope-abstractie (null/Consumer/Account) cadeau + is officiële Laravel-package, dus geen onderhoud.

3. **Scope-design** — Twee fasen:
   - **Globaal nu (v0.2)**: `Feature::define('provider-{key}-enabled', fn () => true)` — scope `null`, één switch per provider hub-breed.
   - **Per-Consumer later (v0.2.1)**: dezelfde feature-key, scope = Consumer-instance, override-resolver leest uit een toekomstige `consumer_feature_flags`-tabel of Consumer-billing-tier.

4. **Kill-switch-mechaniek** — Twee modi:
   - **Production**: `Feature::deactivateForEveryone('provider-mollie-enabled')` (later via Filament admin-action in v0.2.1).
   - **Test**: resolver-override via `Feature::define('provider-mollie-enabled', fn () => false)` in `setUp()`. Reden: `deactivateForEveryone` werkt alleen voor reeds-geresolved scopes en is daarom onbetrouwbaar in tests.

5. **Integratie-punten** — Drie plekken, allemaal verifieerbaar in de codebase:
   - `app/Providers/FeatureServiceProvider.php` — definieert per-provider feature dynamisch door over `config('hub-providers')` keys te itereren.
   - `app/Http/Middleware/EnsureProviderEnabled.php` — middleware met alias `feature.provider:{provider}` (geregistreerd in `bootstrap/app.php`), returnt 503 (geen Consumer-fout — Hub-side besluit).
   - `app/OAuth/OAuthFlowRegistry::for()` — gooit `App\OAuth\Exceptions\ProviderDisabledException` als de feature inactive is, vóór de flow-instance teruggegeven wordt.
   - Mount-points: `routes/api.php` past het middleware-alias toe op `/v1/mollie/*` en `/v1/snelstart/*` route-groups.

6. **Invariant** — Nieuwe provider toevoegen = nieuwe rij in `config/hub-providers.php` → feature wordt auto-gedefinieerd in `FeatureServiceProvider::boot()`. **GEEN edit van middleware, registry of exception nodig.** Spiegel-pattern van `provider-credential-descriptor.md` (zelfde config-driven discovery-principe, andere laag).

7. **Niet-keuzes**:
   - **Geen Filament admin-UI voor flags nu** — backlog v0.2.1.
   - **Geen per-Account scope** — te fijnmazig zonder concrete use-case; current scope-pyramide stopt bij Consumer.
   - **Geen `Feature::for($consumer)->active()` in middleware** — Consumer-scope is nog niet geactiveerd; middleware checkt globale scope tot v0.2.1.

8. **Wanneer herzien**:
   - Eerste use-case die per-Consumer rollout vraagt → activeer scope-resolver + breid middleware uit met Consumer-resolution na Sanctum-auth.
   - Meer dan 5 features per provider → overweeg generieke feature-prefix-conventie (`provider-{key}-{capability}`) in plaats van flat keys.

9. **Geschiedenis**:
   - 2026-05-16 — Commits `53a6c90` (install) + `bff6454` (kill-switch) tijdens Phase 8 Naschool-wiring sessie.
   - 2026-05-16 — Deze ADR geëxtraheerd uit commit-messages + Phase 8 CONTEXT.md (docs-sync drift-fix).

**Niet doen:**
- Geen verzonnen class-namen of file-paden — gebruik exact wat boven staat. Open de file alleen ter validatie van de middleware-alias regel-nummer in `bootstrap/app.php` (regel 37) en de drie route-mount-regels in `routes/api.php` (regels 39, 46, 63) als je die in de Integratie-punten-section noemt.
- Geen scope-creep — niet over implementatie-details van Pennant-internals uitwijden, niet refereren naar andere ADRs die niet in Related staan.
- Geen AI-cliché's (zie `.ai/rules/global.md`: vermijd "Furthermore", "Naadloos", "Discover how", etc.).
  </action>
  <verify>
    <automated>test -f .docs/decisions/feature-flags-pennant-kill-switch.md &amp;&amp; grep -q "Accepted 2026-05-16" .docs/decisions/feature-flags-pennant-kill-switch.md &amp;&amp; grep -q "FeatureServiceProvider" .docs/decisions/feature-flags-pennant-kill-switch.md &amp;&amp; grep -q "EnsureProviderEnabled" .docs/decisions/feature-flags-pennant-kill-switch.md &amp;&amp; grep -q "OAuthFlowRegistry" .docs/decisions/feature-flags-pennant-kill-switch.md &amp;&amp; grep -q "ProviderDisabledException" .docs/decisions/feature-flags-pennant-kill-switch.md &amp;&amp; grep -q "## Integratie-punten" .docs/decisions/feature-flags-pennant-kill-switch.md &amp;&amp; grep -q "## Invariant" .docs/decisions/feature-flags-pennant-kill-switch.md &amp;&amp; grep -q "## Wanneer herzien" .docs/decisions/feature-flags-pennant-kill-switch.md</automated>
  </verify>
  <done>ADR-file bestaat op `.docs/decisions/feature-flags-pennant-kill-switch.md`, bevat alle 9 sections in juiste volgorde, vermeldt de exacte drie integratie-punt-classes, matcht Nederlandstalige toon van `provider-credential-descriptor.md`.</done>
</task>

<task type="auto">
  <name>Task 2: Voeg `strategy/` rij toe aan `.docs/README.md` indeling-tabel</name>
  <files>.docs/README.md</files>
  <action>
Edit `.docs/README.md`: voeg precies één rij toe aan de indeling-tabel (regels 7-15). Plaats de nieuwe rij **direct na de `partners/`-rij** (logisch: persistent doc-folder na partner-research, vóór `.archive/`).

**Toe te voegen rij (exact deze tekst):**

```
| `strategy/` | Productrichting, verdienmodel, platform-positionering, lange-termijn-strategie. Niet over architectuur (→ `decisions/`) maar over wat we bouwen en voor wie. | Persistent — updaten bij strategie-shifts. |
```

**Niet doen:**
- Geen andere tabel-rijen wijzigen (decisions, plans, todos, errors, stack, partners, .archive blijven exact zoals ze zijn).
- Geen Workflow-section onderaan wijzigen.
- Geen leesheading boven de tabel aanpassen.
  </action>
  <verify>
    <automated>grep -q "^| \`strategy/\`" .docs/README.md &amp;&amp; grep -q "verdienmodel" .docs/README.md &amp;&amp; grep -c "^|" .docs/README.md | grep -q "^10$"</automated>
  </verify>
  <done>Tabel in `.docs/README.md` bevat één extra rij voor `strategy/`, totaal 10 tabel-regels (1 header + 1 separator + 8 data-rijen = 10 regels die met `|` starten). Alle bestaande rijen onveranderd.</done>
</task>

</tasks>

<verification>
Na beide tasks:
1. `test -f .docs/decisions/feature-flags-pennant-kill-switch.md` — ADR bestaat.
2. `grep -c "^|" .docs/README.md` returnt `10` — strategy-rij toegevoegd zonder andere rijen aan te raken.
3. `git diff --stat .docs/README.md` toont +1/-0 (één regel toegevoegd, geen verwijderingen).
</verification>

<success_criteria>
- ADR voor Pennant kill-switch bestaat met alle 9 sections, juiste status, exacte integratie-punt-classes.
- `.docs/README.md` indeling-tabel bevat `strategy/` rij; bestaande rijen onveranderd.
- Geen code-changes (alleen `.docs/`).
- Beide files committen via één commit met message in Nederlands.
</success_criteria>

<output>
Create `.planning/quick/260516-qau-docs-sync-drift-fix-adr-pennant-kill-swi/260516-qau-SUMMARY.md` when done.
</output>
