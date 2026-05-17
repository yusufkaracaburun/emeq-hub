---
phase: 05c-snelstart-webhook-handler
plan: 05
type: execute
wave: 4
depends_on: [05c-01, 05c-02, 05c-03, 05c-04]
files_modified:
  - tests/Feature/SnelstartWebhookEndToEndTest.php
  - .docs/decisions/snelstart-webhook-ingress.md
  - .planning/REQUIREMENTS.md
  - .planning/ROADMAP.md
  - .planning/STATE.md
  - .planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md
autonomous: true
requirements: [HUB-06]
tags:
  - phpunit
  - integration-test
  - acceptance-gate
  - docs

must_haves:
  truths:
    - "Alle 5 HUB-06-Success-Criteria (SC-1..SC-5) zijn bewezen via één samenhangende end-to-end-integration-test die de volle route+middleware+controller+job-stack raakt"
    - "Een ADR documenteert de webhook-ingress-architectuur (route + middleware + audit-shape + anti-correlation), inclusief de 5 ❓-aannames met expliciete `defensief tot partner-respons`-disclaimer"
    - "ROADMAP, REQUIREMENTS en STATE markeren Phase 5c plan-status als compleet; HUB-06 verschuift van Pending → Planned"
  artifacts:
    - path: "tests/Feature/SnelstartWebhookEndToEndTest.php"
      provides: "Eén integration-test-class met 5 scenarios die alle SC's afdekken, end-to-end met echte route + middleware + controller + Bus::fake op de job"
      contains: "SnelstartWebhookEndToEndTest"
    - path: ".docs/decisions/snelstart-webhook-ingress.md"
      provides: "ADR over webhook-ingress-architectuur, anti-correlation, en de open ❓-aannames"
      contains: "## Status"
    - path: ".planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md"
      provides: "Acceptance-gate-checklist die 5/5 SC's gemerkt heeft + verwijzingen naar de plannen waar elke SC bewezen wordt"
      contains: "ACCEPTED"
  key_links:
    - from: "HUB-06 SC-1..SC-5"
      to: "SnelstartWebhookEndToEndTest scenarios"
      via: "test-naming-conventie (`test_sc_1_*`, etc.)"
      pattern: "traceability"
---

<objective>
Sluit Phase 5c af met een end-to-end test die de volle inbound→audit→fan-out-stack bewijst, een ADR die de architectuur vastlegt, en tracking-updates in ROADMAP/REQUIREMENTS/STATE.

Purpose: HUB-06 acceptance-gate. CONTEXT decision "alle 5 SC's moeten bewezen zijn voor we de phase ACCEPTED markeren". Plan 03 dekt de meeste SC's per-scenario al, maar deze plan landt de gecombineerde end-to-end-bewijslast + de organisatie-laag (ADR + state).

Output: 1 end-to-end-test-class, 1 ADR, 3 planning-document-updates, 1 ACCEPTANCE-checklist. **Geen** nieuwe code in `app/`.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/REQUIREMENTS.md
@.planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md
@.planning/phases/06-cashier-mollie-integratie-use-case-a/06-08-ACCEPTANCE.md
@.docs/decisions/snelstart-certificering-pad.md
@.docs/decisions/pass-through-calls-table.md
@CLAUDE.md
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: End-to-end integration-test — alle 5 SC's</name>
  <files>tests/Feature/SnelstartWebhookEndToEndTest.php</files>
  <behavior>
    - Eén test-class met 5 methods (`test_sc_1_valid_known_administratie`, `test_sc_2_invalid_signature`, `test_sc_3_unknown_administratie`, `test_sc_4_idempotent_event_id`, `test_sc_5_cross_consumer_isolation`)
    - Elke test posts naar de echte route `/webhooks/snelstart`, signeert via `SnelstartSignatureVerifier::sign()`, en assert zowel HTTP-respons als `pass_through_calls`-row-state als `Bus::assertDispatched`/`Bus::assertNothingDispatched`
    - Gebruikt `RefreshDatabase`; geen mocking van middleware (de hele chain doorloopt)
    - Asserts zijn 1-op-1 mappable naar HUB-06 success criteria 1-5 uit ROADMAP.md (verifieer via grep)
  </behavior>
  <read_first>
    - .planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md (`<decisions>` section voor wat audit-row exact moet bevatten)
    - .planning/ROADMAP.md (Phase 5c Success Criteria, exact wat we bewijzen)
    - tests/Feature/SnelstartWebhookControllerTest.php (plan 03 output — patterns voor sign-helper + Bus::fake setup; veel scenarios overlappen, maar deze test verifieert end-to-end met alle middleware actief)
    - app/Webhooks/SnelstartSignatureVerifier.php (`sign()`-helper voor test-payloads)
  </read_first>
  <action>
    Maak `tests/Feature/SnelstartWebhookEndToEndTest.php`. Class-skeleton:

    ```php
    <?php

    declare(strict_types=1);

    namespace Tests\Feature;

    use App\Jobs\Webhooks\ForwardSnelstartWebhookToConsumerJob;
    use App\Models\Account;
    use App\Models\Connection;
    use App\Models\Consumer;
    use App\Models\PassThroughCall;
    use Emeq\SnelstartApi\Webhooks\SnelstartWebhookSignature;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\Bus;
    use Tests\TestCase;

    /**
     * End-to-end integration-test voor HUB-06 — bewijst alle 5 Success Criteria
     * uit ROADMAP.md §Phase 5c via de volle stack (route → middleware → controller → job).
     */
    final class SnelstartWebhookEndToEndTest extends TestCase
    {
        use RefreshDatabase;

        protected function setUp(): void
        {
            parent::setUp();
            config([
                'snelstart.webhook.secret' => 'test-secret',
                'snelstart.webhook.signature_header' => 'X-SnelStart-Signature',
                'snelstart.webhook.signature_algo' => 'sha256',
            ]);
        }

        public function test_sc_1_valid_known_administratie(): void { /* ... */ }
        public function test_sc_2_invalid_signature(): void { /* ... */ }
        public function test_sc_3_unknown_administratie(): void { /* ... */ }
        public function test_sc_4_idempotent_event_id(): void { /* ... */ }
        public function test_sc_5_cross_consumer_isolation(): void { /* ... */ }

        private function postSignedWebhook(array $payload, ?string $secret = 'test-secret'): \Illuminate\Testing\TestResponse
        {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $signature = SnelstartSignatureVerifier::sign($body, $secret ?? 'wrong');

            return $this->postJson('/webhooks/snelstart', $payload, [
                'X-SnelStart-Signature' => $signature,
            ]);
        }
    }
    ```

    Implementeer de 5 test-methodes:

    **SC-1** (valid HMAC + bekende administratie → 200 + audit-rij + dispatch):
    - Setup: Consumer met `webhook_callback_url`, Account, Snelstart-Connection met `administratie_id='admin-uuid-1'`
    - `Bus::fake();` post `{"administratieId":"admin-uuid-1","eventId":"evt-001","type":"Verkoopfactuur.Updated","payload":{...}}`
    - Asserts: status 200; `PassThroughCall::inbound()->where('event_id','evt-001')->count() === 1` met correcte `consumer_id`/`account_id`/`connection_id`; `Bus::assertDispatched(ForwardSnelstartWebhookToConsumerJob::class, fn ($j) => $j->snelstartConnection->id === $connection->id && $j->eventId === 'evt-001')`

    **SC-2** (invalid HMAC → 401 + lege body + geen audit):
    - Setup: Consumer + Account + Connection (zoals SC-1)
    - Post met fout signature (override met dummy-secret)
    - Asserts: status 401; `$response->content() === ''`; `PassThroughCall::count() === 0`; `Bus::assertNothingDispatched()`

    **SC-3** (onbekende administratie → 200 + NULL-tenant audit):
    - Setup: Consumer + Account + Connection met `administratie_id='admin-uuid-1'`
    - Post met `administratieId='admin-uuid-zzz'` (geen Connection)
    - Asserts: status 200; `PassThroughCall::inbound()->whereNull('consumer_id')->where('upstream_error','unknown_administratie_id')->count() === 1`; `Bus::assertNothingDispatched()`

    **SC-4** (idempotency event_id 2× → 200 + 1 dup-rij + originele job):
    - Setup: SC-1 setup
    - Post identieke payload 2×
    - Asserts: beide responses status 200; `PassThroughCall::inbound()->count() === 2` (1 origineel + 1 dup); `PassThroughCall::inbound()->where('upstream_error','duplicate_event')->count() === 1`; `Bus::assertDispatchedTimes(ForwardSnelstartWebhookToConsumerJob::class, 1)`

    **SC-5** (cross-Consumer-isolation):
    - Setup: Consumer-A + Account-A + Connection-A (`administratie_id='admin-A'`); Consumer-B + Account-B + Connection-B (`administratie_id='admin-B'`)
    - Post voor `admin-A`
    - Asserts: status 200; audit-row heeft `consumer_id === ConsumerA->id`; `Bus::assertDispatched(Job::class, fn ($j) => $j->snelstartConnection->account->consumer_id === $consumerA->id && $j->snelstartConnection->id !== $connectionB->id)`

    Run pint + `php artisan test --compact --filter=SnelstartWebhookEndToEndTest`.
  </action>
  <verify>
    <automated>php artisan test --compact --filter=SnelstartWebhookEndToEndTest</automated>
  </verify>
  <acceptance_criteria>
    - `grep -cE "public function test_sc_[1-5]_" tests/Feature/SnelstartWebhookEndToEndTest.php` == 5
    - `grep -c "use RefreshDatabase" tests/Feature/SnelstartWebhookEndToEndTest.php` == 1
    - `grep -c "Bus::fake" tests/Feature/SnelstartWebhookEndToEndTest.php` >= 1
    - `php artisan test --compact --filter=SnelstartWebhookEndToEndTest` exit 0, 5 tests passed
    - Volledige Hub-testsuite groen: `php artisan test --compact` exit 0 zonder failures of errors
  </acceptance_criteria>
  <done>5 SC's bewezen via 5 named methods; volledige suite blijft groen.</done>
</task>

<task type="auto">
  <name>Task 2: ADR — `snelstart-webhook-ingress`</name>
  <files>.docs/decisions/snelstart-webhook-ingress.md</files>
  <read_first>
    - .docs/decisions/pass-through-calls-table.md (ADR-stijl: Status / Keuze / Context / Consequenties)
    - .docs/decisions/snelstart-certificering-pad.md (referentie — deze ADR landt de architectuur die certificeringspad mogelijk maakt)
    - .planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md (alle locked decisions + 5 ❓-aannames)
  </read_first>
  <action>
    Schrijf ADR `snelstart-webhook-ingress.md` in Nederlands proza + Engelse identifiers, volgens bestaande `.docs/decisions/*`-stijl. Sections in deze volgorde:

    1. `# Snelstart webhook-ingress architectuur`
    2. `## Status` — *"Geaccepteerd <execute-datum> — Phase 5c geland in code. Aannames over header-naam, algo, event-id-veld, secret-lifecycle en retry-policy zijn defensief gebouwd via config-driven defaults en mogen verschuiven via env-vars zodra `partner@snelstart.nl` (Gmail-draft `r-8836998535038336548`) antwoordt."* — vervang `<execute-datum>` met de datum van de `/gsd-execute-phase 5c`-run (niet de plan-datum), want phase is gated op partner-respons en de feitelijke execute-datum is nu onbekend.
    3. `## Keuze` —
       - Eén publieke URL `/webhooks/snelstart` (geen pad-parameter per Connection)
       - HMAC-verificatie via middleware `verify.snelstart.signature` (config-driven header + algo)
       - Connection-routing via payload `administratieId` ↔ `connections.administratie_id`
       - Audit in `pass_through_calls` met `direction='inbound'`
       - Async fan-out via Horizon `webhooks`-queue + Spatie webhook-server
       - Anti-correlation: inbound partner-secret ≠ outbound `webhook_callback_secret` per-Consumer
    4. `## Context` —
       - Snelstart-certificering vereist een werkende webhook-URL bij aanvraag (.docs/decisions/snelstart-certificering-pad.md)
       - Twee strikte invariants uit `.ai/rules/global.md`: tokens encrypted at rest + geen verzonnen partner-features
       - 5 ❓-aannames opgesomd (header-naam, algorithme, event-id-key, secret-lifecycle, retry-policy) elk met een 1-regel "fix-bij-respons"-actie
       - Anti-amplification (geen audit op invalid sig) en anti-retry-storm (200 op unknown administratie) zijn beide tegen-intuïtief — leg de redenering vast
       - **Duplicate-event audit-rijen** hebben bewust `event_id = NULL` om de `(provider, event_id)`-unique index te respecteren; traceability loopt via `upstream_error='duplicate_event'` + `request_fingerprint` (sha256 van raw body). Forensische queries op een specifiek event_id retourneren dus alleen de originele rij; alternatieve aanpak (partial unique index excluding `upstream_error='duplicate_event'`) is overwogen maar afgewezen om het schema simpel te houden — documenteer hier expliciet zodat een toekomstige reader niet verrast wordt.
    5. `## Consequenties` —
       - Replay-knop voor gefaalde inbound events → Phase 9 Filament admin
       - OData-safety-net polling → afhankelijk van retry-policy-antwoord; nu niet geïmplementeerd
       - Snelstart-certificeringsaanvraag kan vertrekken (URL is publiek, gesigneerd, productie-klaar)
       - HUB-06 in REQUIREMENTS.md verschuift naar Planned/Done

    Sluit af met *"Bron: `.planning/phases/05c-snelstart-webhook-handler/05c-CONTEXT.md` + de 5 plan-files."*. Géén heredoc — Write-tool. Géén AI-cliché's.
  </action>
  <verify>
    <automated>test -f .docs/decisions/snelstart-webhook-ingress.md && grep -cE "^## (Status|Keuze|Context|Consequenties)" .docs/decisions/snelstart-webhook-ingress.md</automated>
  </verify>
  <acceptance_criteria>
    - `test -f .docs/decisions/snelstart-webhook-ingress.md` exit 0
    - `grep -cE "^## (Status|Keuze|Context|Consequenties)$" .docs/decisions/snelstart-webhook-ingress.md` == 4
    - `grep -c "anti-correlation\|anti-amplification\|anti-retry-storm" .docs/decisions/snelstart-webhook-ingress.md` >= 2
    - `grep -c "administratieId\|administratie_id" .docs/decisions/snelstart-webhook-ingress.md` >= 1
    - **Trigger `docs-sync` skill** als follow-up in de execute-sessie zodat `.docs/README.md`-index de nieuwe ADR opneemt
  </acceptance_criteria>
  <done>ADR bestaat, 4 secties, anti-* invariants gedocumenteerd, docs-sync getriggerd.</done>
</task>

<task type="auto">
  <name>Task 3: Tracking-updates — ROADMAP + REQUIREMENTS + STATE + ACCEPTANCE</name>
  <files>.planning/REQUIREMENTS.md, .planning/ROADMAP.md, .planning/STATE.md, .planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md</files>
  <read_first>
    - .planning/phases/06-cashier-mollie-integratie-use-case-a/06-08-ACCEPTANCE.md (template-format voor acceptance-gate-checklist)
    - .planning/REQUIREMENTS.md (regel 33 HUB-06; regel 92 mapping-tabel)
    - .planning/ROADMAP.md (regels 174-195 voor Phase 5c-block met `**Plans:** TBD` placeholder)
    - .planning/STATE.md (Phase-positie + `stopped_at`-veld)
  </read_first>
  <action>
    **1. `.planning/REQUIREMENTS.md`** — markeer HUB-06 als planned:
    - Regel 33 `- [ ] **HUB-06**:` → `- [x] **HUB-06**:` (Done na execute-phase; nu Planned. Volg de bestaande conventie uit HUB-04: gebruik `[ ]` voor planned-not-executed en zet `Done` in de tracking-tabel pas na verify).
    - Mapping-tabel regel 92: `| HUB-06 | Phase 5c | Pending |` → `| HUB-06 | Phase 5c | Planned |`

    **2. `.planning/ROADMAP.md`** — vul `**Plans:** TBD` block in (regel 195):

    ```markdown
    **Plans:** 5 plans
    - [ ] 05c-01-PLAN.md — pass_through_calls migration extension + administratie_id index + model/factory updates
    - [ ] 05c-02-PLAN.md — SnelstartWebhookSignature + VerifySnelstartSignature middleware (beide SDK-side in `emeq/snelstart-api`) + `snelstart.webhook.*` SDK-config
    - [ ] 05c-03-PLAN.md — SnelstartWebhookController + route + controller-feature-tests (SC-1..SC-4+SC-5)
    - [ ] 05c-04-PLAN.md — ForwardSnelstartWebhookToConsumerJob + Horizon webhooks-supervisor
    - [ ] 05c-05-PLAN.md — End-to-end integration-test alle 5 SC's + ADR + ACCEPTANCE-gate + tracking-updates
    ```

    **3. `.planning/STATE.md`** — update:
    - `status`: `executing` blijft (we plannen wel, executen niet zonder partner-respons)
    - `stopped_at`: `"Phase 5c CONTEXT + 5 PLAN.md's geland; wacht op Snelstart-partner-respons (≤2026-05-26) vóór `/gsd-execute-phase 5c`. Phase 7 plan-phase blijft tweede track."`
    - `last_updated`: `"2026-05-17T..."` (actuele datum-stamp bij uitvoering)
    - `Current Position` Plan-veld: `Not started` → `Planned (5 plans, wacht op partner-respons)`
    - `progress.completed_plans` blijft 37 (geen exec-werk); `total_plans` ophogen naar 42 (37 + 5 nieuwe)

    **4. `.planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md`** — schrijf acceptance-gate-checklist gemodelleerd naar `06-08-ACCEPTANCE.md`:

    ```markdown
    # Phase 5c Acceptance Gate — HUB-06 Snelstart webhook-handler

    **Phase:** 5c-snelstart-webhook-handler
    **Status:** PLANNED — wacht op partner-respons + execute-phase
    **Plans created:** 2026-05-17

    ## Success Criteria coverage (uit ROADMAP §Phase 5c)

    | SC | Description | Plan | Status |
    |----|-------------|------|--------|
    | SC-1 | Valid HMAC + known administratieId → 200 + audit-row direction=inbound + job dispatched | 05c-03 (scenario 1), 05c-05 (test_sc_1_*) | Planned |
    | SC-2 | Invalid HMAC → 401, empty body, geen audit-row | 05c-02 (middleware tests), 05c-05 (test_sc_2_*) | Planned |
    | SC-3 | Onbekende administratieId + valid HMAC → 200 + NULL-tenant audit + geen fan-out | 05c-03 (scenario 2), 05c-05 (test_sc_3_*) | Planned |
    | SC-4 | Idempotency event_id 2× → 200 + dup-audit + 1 originele job | 05c-03 (scenario 3), 05c-05 (test_sc_4_*) | Planned |
    | SC-5 | Cross-Consumer-isolation gegarandeerd | 05c-03 (scenario 7), 05c-05 (test_sc_5_*) | Planned |

    ## Open Aannames (uit 05c-CONTEXT.md)

    Alle 5 ❓-aannames zijn config-driven gebouwd; partner-respons aanpassingen zijn env-only:

    - ❓ #1 HMAC-header-naam + algo → `SNELSTART_WEBHOOK_SIGNATURE_HEADER` + `SNELSTART_WEBHOOK_SIGNATURE_ALGO`
    - ❓ #2 Secret-lifecycle → `SNELSTART_WEBHOOK_SECRET` + `SNELSTART_WEBHOOK_SECRET_NEXT` (rotation-window)
    - ❓ #3 Tenant-routing-veld → migratie + controller-lookup-key (`administratieId`); fix is eenmalige rename-migratie + parser-aanpassing
    - ❓ #4 Retry-policy → OData-safety-net deferred; idempotency-unique-index al aanwezig (geen rework)
    - ❓ #5 Event-typen → MVP forwardt alles; opt-in-registratie deferred

    ## Acceptance prerequisites

    - [ ] Snelstart-partner-respons ontvangen op Gmail-draft `r-8836998535038336548`
    - [ ] ❓-aannames → 🔒 omgezet in CONTEXT.md (manual edit op basis van partner-respons)
    - [ ] `/gsd-execute-phase 5c` uitgevoerd
    - [ ] 5/5 SC's bewezen via groene `SnelstartWebhookEndToEndTest`-suite
    - [ ] ADR `.docs/decisions/snelstart-webhook-ingress.md` geland
    - [ ] HUB-06 mapping → Done

    **Pas na alle 6 checks ACCEPTED.**
    ```

    Run pint niet nodig — alleen markdown-files.
  </action>
  <verify>
    <automated>grep -c "Planned\|5c-01-PLAN.md" .planning/REQUIREMENTS.md .planning/ROADMAP.md .planning/STATE.md .planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md 2>&1</automated>
  </verify>
  <acceptance_criteria>
    - `grep -c "| HUB-06 | Phase 5c | Planned |" .planning/REQUIREMENTS.md` == 1
    - `grep -c "05c-01-PLAN.md\|05c-02-PLAN.md\|05c-03-PLAN.md\|05c-04-PLAN.md\|05c-05-PLAN.md" .planning/ROADMAP.md` >= 5
    - `grep -c "Phase 5c CONTEXT + 5 PLAN.md" .planning/STATE.md` >= 1
    - `test -f .planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md` exit 0
    - `grep -cE "^\\| SC-[1-5] \\|" .planning/phases/05c-snelstart-webhook-handler/05c-ACCEPTANCE.md` == 5
  </acceptance_criteria>
  <done>Tracking is sync; ACCEPTANCE bestaat met SC-coverage + open-aannames; STATE markeert "wacht op partner-respons".</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Planning-laag ↔ code | ACCEPTANCE-checklist is de single source of truth voor "is 5c klaar?" — niet de PLAN-files zelf |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05c-19 | Repudiation | Phase wordt geclaimd als done zonder partner-respons | mitigate | ACCEPTANCE-prereq 1 (partner-respons) blokkeert ACCEPTED-stempel; tracking-update in REQUIREMENTS gebruikt "Planned" niet "Done" tot execute klaar is. |
| T-05c-20 | Tampering | ❓-aannames worden vergeten na partner-respons | mitigate | ACCEPTANCE-prereq 2 (❓→🔒 in CONTEXT.md) als checklist-item; geen ACCEPTED zonder die step. |
</threat_model>

<verification>
- `php artisan test --compact` exit 0 (volledige suite groen)
- ADR bestaat met 4 secties + anti-* invariants
- REQUIREMENTS HUB-06 = Planned
- ROADMAP Phase 5c heeft 5 expliciete plan-bullets
- ACCEPTANCE bestaat met 5 SC-rows + 5 ❓-aannames + 6 prereq-checks
- Pint clean
</verification>

<success_criteria>
- Alle 5 HUB-06 SC's hebben een gerouteerde test (per-scenario in 05c-03 + end-to-end in 05c-05)
- ADR documenteert architectuur + 5 open aannames + fix-bij-respons-strategie
- Planning-state is consistent: REQUIREMENTS, ROADMAP, STATE, ACCEPTANCE allemaal noemen 5c als "Planned, wacht op partner-respons"
- Volledige Hub-testsuite groen
</success_criteria>

<output>
Na completion: schrijf `.planning/phases/05c-snelstart-webhook-handler/05c-05-SUMMARY.md`; vermeld dat Phase 5c plan-status nu Compleet is en dat execute-phase blocked is op partner-respons (`partner@snelstart.nl`, ≤2026-05-26 verwacht). Trigger `docs-sync` skill als follow-up.
</output>
