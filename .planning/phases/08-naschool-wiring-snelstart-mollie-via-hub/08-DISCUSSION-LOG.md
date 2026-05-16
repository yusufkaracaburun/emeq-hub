# Phase 8: Naschool wiring — Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-16
**Phase:** 8-naschool-wiring-snelstart-mollie-via-hub
**Areas discussed:** Snelstart-pad, Plan-artifact placement, Mollie-Connection provisioning, Hub-side touch-ups scope, Consumer-onboarding-flow, Partner-pages content, Filament OAuth-action-locatie, Domeinmodel-uitleg in UI

---

## Snelstart-pad

| Option | Description | Selected |
|--------|-------------|----------|
| Hub-pass-through via /v1/snelstart/{path} | Naschool POSTs naar Hub met Consumer-PAT + X-Account-Id; Snelstart-creds alleen in Hub-Connection; valideert Phase 5b productie-waardig | ✓ |
| Direct-SDK in Naschool met Stancl-resolver | Naschool installeert emeq/snelstart-api en bindt eigen StancltenancyCredentialResolver; creds in Naschool's tenant->settings() | |
| Hybride (Snelstart direct, Mollie via Hub) | Pragmatisch maar leunt minder zwaar op Hub-pass-through-pattern | |

**User's choice:** Hub-pass-through (optie 1)
**Notes:** User vroeg meteen: "hoe moeten ze het bij de eerste keer doen? is er ook een plan voor het onboarden naar Emeq Hub?" — leidde tot follow-up question over Consumer-onboarding-flow.

---

## Plan-artifact placement

| Option | Description | Selected |
|--------|-------------|----------|
| Hier, alleen Hub-side decisions | CONTEXT.md in emeq-hub beschrijft alleen Hub-side; Naschool-interne implementatie out-of-scope; respecteert scope-memory | ✓ |
| Hier, full cross-repo plan | Beschrijft én Hub-side én Naschool-side; strijdt met scope-feedback | |
| Volledig in Naschool repo | Geen Phase-8 directory hier; verbreekt GSD-state-tracking voor v0.2-progress | |

**User's choice:** Optie 1 + extra wens: uitleg op partner-pagina's over hoe Consumers/Accounts hun partners kunnen koppelen
**Notes:** Leidde tot follow-up over partner-pages-content + domeinmodel-uitleg.

---

## Mollie-Connection provisioning

| Option | Description | Selected |
|--------|-------------|----------|
| Live OAuth-roundtrip via /v1/oauth/mollie/init | Phase 4-flow echt doorlopen in Mollie test-mode | ✓ |
| Artisan-command (hub:connection:provision) | Handmatige token-setting voor dev/test | |
| Filament admin handmatig | Klik door OAuth-init-button vanuit Filament | ✓ |
| DatabaseSeeder dev-state | Dummy-tokens in seeder; dekt SC-3 niet | |

**User's choice:** 1 + 3 (Live OAuth + Filament admin handmatig)
**Notes:** Geen aparte artisan-command nodig; Filament-action triggert dezelfde server-side OAuth-flow. Geen fake-token-seeder (zou SC-3 niet bewijzen).

---

## Hub-side touch-ups scope

| Option | Description | Selected |
|--------|-------------|----------|
| Minimaal: Consumer + callback-URL config | Eenmalig: 'naschool'-Consumer + webhook_callback_url + secret; geen code-changes anders dan seeder-tweak | |
| Plus dev-reachability helper | Caddyfile-snippet of docker-compose entry voor Hub→Naschool dev-host | |
| Plus demo-Connection seeder | DatabaseSeeder zet Mollie-Connection met test-tokens op school1-Account | |
| Volle Hub-side wiring | Alles inclusief Filament 'start OAuth-action' + response-tweaks | ✓ |

**User's choice:** Volle Hub-side wiring
**Notes:** User wil dat het complete pakket geleverd wordt. Leidde tot uitwerking in vervolgvragen.

---

## Consumer-onboarding-flow (follow-up)

| Option | Description | Selected |
|--------|-------------|----------|
| Manueel walkthrough nu, self-service naar HUB-ONBOARDING backlog | Documenteer onboarding-runbook in .docs/; self-service v0.3+ | |
| Mini self-signup-route nu (POST /v1/consumers) | Publieke registratie-flow; scope-uitbreiding | |
| Filament-aanvraag-flow | Multi-stap form in Filament voor Emeq-staff: Consumer→Account→Connection→PAT | ✓ |

**User's choice:** Filament-aanvraag-flow
**Notes:** Past bij Phase 9's admin-paneel; Phase 8 levert deze als nieuwe Filament-wizard. Self-service blijft `HUB-ONBOARDING` backlog.

---

## Partner-pages content (follow-up)

| Option | Description | Selected |
|--------|-------------|----------|
| Koppel-instructies + live demo-knop + domeinmodel-blokje | Per provider: domeinmodel-uitleg + provider-uitleg + stappenplan + live OAuth-knop (Mollie) of cURL-snippet (Snelstart) | |
| Alleen tekstuele uitleg + domeinmodel-blokje | Pure documentatie zonder interactieve knoppen | |
| Interactieve demo + screenshots | Uitgebreidere variant; scope-zwaar | |

**User's choice:** Discretie aan Claude — "kies jij maar, ik wil dat het zo compleet mogelijk is. misschien dat we ze ook de status van hun koppeling kunnen laten zien?"
**Claude's choice:** Optie 1 + status-widget — combineert domeinmodel-blokje + koppel-instructies + live OAuth-knop + cURL-snippet + live koppel-status-widget (Account×Provider matrix met `✅`/`⚠`/`❌`/`—` per Connection). Routes blijven local/testing-gated.

---

## Filament OAuth-action-locatie (follow-up)

| Option | Description | Selected |
|--------|-------------|----------|
| Action op ConnectionResource voor pending Connections | Past bij Phase 9 ConnectionResource; secondary-flow voor incomplete Connections | |
| Action op AccountResource | Primary user-flow "koppel deze Account met Mollie"; dubbel pad naast ConnectionResource | |
| Aparte 'OAuth-flows'-page in Filament | Standalone Filament-page; meest flexibel, meeste werk | |

**User's choice:** Discretie aan Claude — "Kies welke het meest compleet is."
**Claude's choice:** Action op **beide** Resources — één shared `StartOAuthFlowAction`-class die op zowel `AccountResource` (primary user-flow) als `ConnectionResource` (voor pending Connections) landt. Hergebruikt Phase-4-controllers.

---

## Domeinmodel-uitleg in UI (user-initiated tijdens follow-up)

**User's request:** "Kan je dit in de ui ook uitleggen. Ik kan snappen dat het verwarrend is."
**Claude's proposal (geaccepteerd):**
- `/dev/partners` index + per-provider pagina krijgt domeinmodel-blokje bovenaan
- Filament `ConsumerResource` + `AccountResource` Infolist krijgt korte uitleg-Section
- `Tenants`-navgroup krijgt tooltip/description

**Notes:** Gecaptureerd als D-07 in CONTEXT.md.

---

## Hub-side deliverables (multiSelect-bevestiging)

| Option | Description | Selected |
|--------|-------------|----------|
| Naschool-Consumer + callback-config geprovisioneerd | Artisan-command of seeder-update | ✓ |
| Filament 'start OAuth'-action | Implementatie van shared action-class | ✓ |
| Partner-pages content + domeinmodel-blokje | /dev/partners-uitbreiding | ✓ |
| Filament Resource-infolist hints + nav-group-uitleg | Korte uitleg-tekst op Resources + navgroup | ✓ |

**User's choice:** Alle vier
**Notes:** Bevestigt scope-uitbreiding boven 'minimal' Hub-side touch-ups; planner moet hier 4 plan-stubs voor opzetten.

---

## Claude's Discretion

- Partner-pages-styling (lay-out, kleur, iconen): match Tailwind v4 + Filament-look-and-feel.
- Status-widget-refresh: server-render bij page-load (geen Livewire/polling) voor v0.2.
- Filament-wizard layout: Filament's `Wizard` component; eventueel multi-stap-action.
- `StartOAuthFlowAction` implementatiedetails: shared class onder `app/Filament/Actions/`.

---

## Deferred Ideas

- Self-service Consumer-registratie (`POST /v1/consumers`) → `HUB-ONBOARDING` backlog (v0.3+)
- Productie-pagina's voor partners (publieke `docs.hub.emeq.nl`) → `HUB-DOCS` backlog
- E-mail-confirm + abuse-controles voor Consumer-aanvraag → `HUB-ONBOARDING` backlog
- 2FA/MFA voor admin login → v1.0+
- Filament Resource voor `PassThroughCall`-audit → `HUB-OBSERVABILITY` backlog
- OAuth-status-polling op partner-pages (wire:poll) → `HUB-DOCS` of Phase 9-uitbreiding
- Rotate-action voor `webhook_callback_secret` in Filament → v0.2.1 polish
- `StartOAuthFlowAction` voor Snelstart-OAuth → wanneer Snelstart-OAuth-Connect landt (v0.3+)
- Naschool-zijde implementatie (Stancl-refactor, listeners, callbacks, demo-seed) → Naschool repo

---

## Open follow-up (na deze sessie bespreken)

User bracht in het slot van de discussie strategische vragen op die buiten Phase 8 scope vallen maar belangrijk zijn voor de Hub-platform-richting (v0.3+ / v1.0+):

1. **Verdienmodel** voor de Hub-as-multi-partner-platform — opportunity-mapping voor commerciële uitrol.
2. **Feature flags voor services/partners/SDKs** (Laravel Pennant) — per-Consumer enablement, gradual rollout, A/B-test.
3. **Service-availability & error/log-strategy** — circuit-breakers, retry-policies, structured logging, alerting.
4. **Hub-SRE: availability, stability, security, performance** — uptime-targets, observability-stack, security-hardening, capacity-planning.

Niet in Phase 8 CONTEXT.md opgenomen; gemarkeerd als open-discussion-item voor volgende sessie.
