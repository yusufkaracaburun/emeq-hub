---
phase: 08-naschool-wiring-snelstart-mollie-via-hub
plan: 04
subsystem: ui
tags: [filament, infolist, navigation-group, accessibility, copy-deck]

# Dependency graph
requires:
  - phase: 09-filament-admin-paneel
    provides: AccountInfolist + AccountResource view-page; AdminPanelProvider panel-config; Spatie permission `manage-consumers`
provides:
  - ConsumerInfolist schema-class (eerste van zijn soort op Consumer)
  - ViewConsumer Filament Page (read-only detail-view)
  - 'Wat is een Consumer?' + 'Wat is een Account?' hint-Sections met canonical D-07 / UI-SPEC §S4 copy (default-collapsed)
  - Tenants-navgroup tooltip via NavigationGroup::extraSidebarAttributes(['title' => ...])
  - 9 nieuwe feature-tests onder tests/Feature/Admin/* (Consumer 5 + Account 4)
affects: [08-05, future-onboarding-flows, hub-staff-onboarding-docs]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "NavigationGroup config via panel->navigationGroups([NavigationGroup::make('Tenants')->extraSidebarAttributes(['title' => '...']), …]) — alle navgroups expliciet declareren voor render-order"
    - "Hint-Section pattern: Section::make('Wat is X?')->description('…')->collapsed()->schema([]) als eerste component in Infolist — D-07 canonical copy"

key-files:
  created:
    - app/Filament/Resources/Consumers/Schemas/ConsumerInfolist.php
    - app/Filament/Resources/Consumers/Pages/ViewConsumer.php
    - tests/Feature/Admin/ConsumerInfolistHintTest.php
    - tests/Feature/Admin/AccountInfolistHintTest.php
  modified:
    - app/Filament/Resources/Consumers/ConsumerResource.php
    - app/Filament/Resources/Accounts/Schemas/AccountInfolist.php
    - app/Providers/Filament/AdminPanelProvider.php

key-decisions:
  - "Filament v4.11 NavigationGroup API-pad = `extra-sidebar-attrs` (geen native ->description()-slot in v4.11). `php -r 'echo method_exists(\\Filament\\Navigation\\NavigationGroup::class, \"description\") ? … : (method_exists(…, \"extraSidebarAttributes\") ? \"extra-sidebar-attrs\" : \"render-hook\");'` retourneerde `extra-sidebar-attrs` — geen tri-fallback in productie-code."
  - "Bij ->navigationGroups([…]) MOETEN álle 4 nav-groups (Tenants/Integraties/Abonnementen/Beheer) expliciet worden gedeclareerd; anders verliezen ze hun gegarandeerde render-volgorde."
  - "Collapsed-state-assertie checkt `isCollapsed:  true` (let op: dubbele spatie tussen `:` en `true` — Filament-v4 @js-helper rendert het zo). Volgorde-assertie zet de x-data-marker vóór de heading omdat <section x-data> in de DOM vóór de <h2>-heading komt."
  - "ConsumerResource krijgt ViewAction in recordActions vóór EditAction (analog ConnectionResource regel 149) zodat de View-page bereikbaar is via de table-rij — anders is de Infolist alleen via URL-typing bereikbaar."
  - "ViewConsumer::getHeaderActions() retourneert lege array — read-only detail-view; Edit blijft via `/edit`-pad of table-row EditAction beschikbaar."

patterns-established:
  - "Hint-Section recipe voor toekomstige Resource-Infolists: prepend `Section::make('Wat is een X?')->description('canonical NL-copy')->collapsed()->schema([])` als eerste components-entry"
  - "NavigationGroup-tooltip recipe (Filament v4.11): `extraSidebarAttributes(['title' => '…'])` landt op de sidebar-`<li data-group-label=\"X\">` als native HTML `title`-attribuut"

requirements-completed: [NSCH-01]

# Metrics
duration: 10min
completed: 2026-05-17
---

# Phase 8 Plan 04: Filament Resource-infolist hints + Tenants-navgroup-tooltip Summary

**Hint-Sections op Consumer + Account Infolists met canonical D-07 copy, plus Tenants-navgroup tooltip via Filament v4 `extraSidebarAttributes(['title' => …])` — onboarding-clarity voor Emeq-staff zonder planning-docs te raadplegen.**

## Performance

- **Duration:** ~10 min (2026-05-17 14:34Z → 14:44Z)
- **Started:** 2026-05-17T14:34:28Z
- **Completed:** 2026-05-17T14:43:56Z
- **Tasks:** 2
- **Files modified:** 7 (4 created + 3 modified)

## Accomplishments

- **ConsumerInfolist + ViewConsumer-page** — eerste Filament Infolist op `ConsumerResource`; canonical "Wat is een Consumer?"-Section bovenaan (default-collapsed) gevolgd door basis-velden (id, name, slug, webhook_callback_url, created_at, updated_at). Table-row ViewAction + `'view' => ViewConsumer::route('/{record}')` in `getPages()`. RBAC-gating via bestaande `canAccess()` op de Resource.
- **AccountInfolist hint-Section** — additieve prepend zonder bestaande TextEntry-volgorde te raken; canonical "Wat is een Account?"-Section bovenaan, default-collapsed.
- **Tenants-navgroup tooltip** — `AdminPanelProvider` registreert nu expliciet alle 4 navgroups (Tenants/Integraties/Abonnementen/Beheer). Tenants krijgt `extraSidebarAttributes(['title' => 'SaaS-apps (Consumer) → hun klanten (Account) → partner-koppelingen (Connection)'])`. API-pad gepind via Filament-tinker-check vóór implementatie.
- **9 nieuwe feature-tests** — 5 Consumer-hint + 4 Account-hint. Tests dekken heading+body, default-collapsed-state, bestaande veld-rendering (geen Phase-9 regressie), route-registratie en RBAC (403 zonder `manage-consumers`).

## Task Commits

Elke task volgt strict RED → GREEN:

1. **Task 1 RED**: `8b2fa7b` (test) — falende `ConsumerInfolistHintTest` met 5 tests
2. **Task 1 GREEN**: `d3a6da4` (feat) — `ConsumerInfolist` + `ViewConsumer` + `ConsumerResource`-wiring; 5/5 tests groen
3. **Task 2 RED**: `8391348` (test) — falende `AccountInfolistHintTest` met 4 tests + verscherpte collapsed-assert op Task-1-test (vermijdt false-positive uit sidebar `fi-collapsed`)
4. **Task 2 GREEN**: `b3bd923` (feat) — `AccountInfolist` hint-Section + `AdminPanelProvider` navgroup-config + collapsed-assert finetune voor dubbele-spatie `isCollapsed:  true`; 9/9 hint-tests groen, 109/109 admin-suite, 468/468 volledige suite

**Plan metadata-commit volgt** na deze SUMMARY (separate commit met SUMMARY + STATE + ROADMAP + REQUIREMENTS).

## Files Created/Modified

**Created (4):**
- `app/Filament/Resources/Consumers/Schemas/ConsumerInfolist.php` — schema-class met D-07 hint-Section bovenaan + 6 basis-velden
- `app/Filament/Resources/Consumers/Pages/ViewConsumer.php` — `ViewRecord`-subclass, lege `getHeaderActions()` (read-only)
- `tests/Feature/Admin/ConsumerInfolistHintTest.php` — 5 tests (heading+body / collapsed / basis-velden / route-registered / RBAC-403)
- `tests/Feature/Admin/AccountInfolistHintTest.php` — 4 tests (heading+body / collapsed / bestaande velden / Tenants-navgroup-tooltip)

**Modified (3):**
- `app/Filament/Resources/Consumers/ConsumerResource.php` — `use ConsumerInfolist, ViewConsumer, ViewAction`; nieuwe `infolist()`-method; `ViewAction` toegevoegd aan `recordActions` vóór `EditAction`; `'view' => ViewConsumer::route('/{record}')` toegevoegd aan `getPages()`
- `app/Filament/Resources/Accounts/Schemas/AccountInfolist.php` — `use Section`; hint-Section geprepend aan `components([…])`
- `app/Providers/Filament/AdminPanelProvider.php` — `use NavigationGroup`; `->navigationGroups([…])`-chain met 4 expliciete groups (Tenants met `extraSidebarAttributes`)

## Decisions Made

- **NavigationGroup API pinning vóór implementatie** — uitvoer `extra-sidebar-attrs` van de tinker-check geeft directe `NavigationGroup::make('Tenants')->extraSidebarAttributes(['title' => '…'])` pad. Geen tri-fallback in code; ADR-comment refereert naar `vendor/filament/filament/src/Navigation/NavigationGroup.php` zodat een Filament-upgrade waar deze API wijzigt een grep-treffer geeft.
- **Volgorde-assertie x-data vóór heading** — Filament-v4 zet `<section x-data="{ isCollapsed: true, …">` op de outer container; de `<h2 class="fi-section-header-heading">` met de heading-tekst komt verderop in de DOM. `assertSeeInOrder(['isCollapsed:  true', 'Wat is een X?'])` matcht daardoor de juiste volgorde.
- **Dubbele spatie in collapsed-assert** — Filament's `@js($collapsed)` helper rendert `true` met een leading space (oorzaak: `@if/@else` rendering whitespace), waardoor de uitgevoerde HTML letterlijk `isCollapsed:  true` (twee spaties) bevat. Test-assertie matcht het exacte byte-patroon — een toekomstige Filament-update die de whitespace normaliseert geeft een test-failure die de regressie aanwijst.
- **ViewAction vóór EditAction in `recordActions`** — analoog aan `ConnectionResource` regel 149-150 (`ViewAction::make()` vóór `Action::make('revoke')`). Geeft staff een snelle pad naar de read-only detail-page zonder de Edit-flow te triggeren.
- **`ViewConsumer::getHeaderActions()` retourneert lege array** — read-only intentie expliciet uitspreken. Edit blijft bereikbaar via `/admin/consumers/{record}/edit` of table-row EditAction.

## Deviations from Plan

None — plan executed exactly as written. Beide tasks volgden de exacte `<read_first>` + `<action>` + `<acceptance_criteria>` specificatie. De plan-author's `<behavior>`-tests waren al voorzien van het juiste signaal (heading-body + collapsed + bestaande-velden + RBAC + navgroup-tooltip) en de tests-as-written zijn 1:1 doorgezet. De enige interne fine-tune zat in het exacte byte-patroon voor de collapsed-state-assertie (`isCollapsed:  true` met dubbele spatie) — dit valt binnen "test-as-evidence" en niet onder een deviation-rule.

## Issues Encountered

- **Eerste collapsed-state-assertie was te zwak** — initiële assertie `assertSee('fi-collapsed')` had een false-positive: de class `fi-collapsed` kwam al 6× voor in sidebar-groups die default-collapsed zijn. Opgelost door scherpere assertie op `isCollapsed:  true` (met dubbele spatie) gecombineerd met `assertSeeInOrder` om de Section-scope te garanderen.
- **Heading-volgorde in DOM** — initieel `['heading', 'isCollapsed: true']` order-assertie faalde omdat de `x-data` op de outer `<section>` vóór de header-tekst komt. Order omgedraaid naar `['isCollapsed:  true', 'heading']`.

## User Setup Required

None — geen externe service-configuratie nodig. Effect is direct zichtbaar voor staff in `/admin/consumers/{id}`, `/admin/accounts/{id}`, en de Tenants-navgroup in de sidebar (hover voor browser-native tooltip).

## Next Phase Readiness

- **Plan 08-05** (de volgende plan in deze phase, indien aanwezig) kan op deze Infolist-pages bouwen voor verdere onboarding-hints.
- **Onboarding-flow voor staff** is nu één klik dichterbij: de Tenants-navgroup-tooltip geeft de Consumer/Account/Connection-mental-model direct in de sidebar.
- **Geen blockers.** Geen open vragen.

## Self-Check: PASSED

Alle 8 geclaimde files aanwezig op disk (4 created + 3 modified + 1 SUMMARY) en alle 4 task-commits (`8b2fa7b`, `d3a6da4`, `8391348`, `b3bd923`) aanwezig in git-log.

---
*Phase: 08-naschool-wiring-snelstart-mollie-via-hub*
*Completed: 2026-05-17*
