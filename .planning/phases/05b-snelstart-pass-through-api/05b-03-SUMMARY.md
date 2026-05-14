---
phase: 05b-snelstart-pass-through-api
plan: 03
subsystem: api
tags:
  - laravel
  - snelstart
  - error-mapping
  - security
  - phpunit
  - whitelist
  - http-gateway

requires:
  - phase: 01-snelstart-sdk-finalize
    provides: SDK-exception-tree (AuthenticationException / ServerException / ValidationException / NotFoundException / RateLimitException) en Saloon FatalRequestException
  - phase: 03-hub-skeleton
    provides: Tests/TestCase, PSR-4 App namespace, PHPUnit-conventie tests/Unit/* + tests/Feature/*

provides:
  - "App\\Support\\Snelstart\\UpstreamErrorMapper::mapException(\\Throwable): array — single source-of-truth voor pass-through-fout→Hub-response-mapping"
  - "App\\Support\\Snelstart\\HeaderForwarder::forward(\\Illuminate\\Http\\Request): array — whitelist (Accept, Content-Type, If-Match, If-None-Match) voor outgoing Snelstart-call"
  - "ADR .docs/decisions/upstream-error-mapping.md die de 502-rewrap-policy en short-code-set vastlegt"
  - "4 vaste short-codes (snelstart_auth, snelstart_5xx, snelstart_timeout, snelstart_unknown) voor pass_through_calls.upstream_error"

affects:
  - 05b-05 (PassThroughController gebruikt beide classes in zijn catch-block + headers-forwarding)
  - 05b-04 (pass_through_calls audit-tabel — kolom upstream_error gevuld door deze mapper)
  - 05a (Mollie pass-through; eigen mapper maar pattern hergebruikt)
  - 09 (Filament admin-UI kan op upstream_error-kolom filteren)

tech-stack:
  added: []
  patterns:
    - "Mapper-pattern: pure static function Throwable → array{status, body, headers, short_code}; geen state, geen DI, geen Laravel-facades. Past bij `app/Support/` conventie."
    - "Whitelist-only header-filtering met `private const ALLOWED` — voorkomt automatische leak van toekomstige Hub-headers; geen blacklist-anti-pattern."
    - "502-rewrap voor upstream-auth-fouten — Consumer kan PAT-failure (401 Sanctum) niet onderscheiden van Snelstart-auth-failure (502 Hub). Mitigeert info-disclosure (T-05b-10)."

key-files:
  created:
    - app/Support/Snelstart/UpstreamErrorMapper.php
    - app/Support/Snelstart/HeaderForwarder.php
    - tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php
    - tests/Unit/Support/Snelstart/HeaderForwarderTest.php
    - .docs/decisions/upstream-error-mapping.md
  modified: []

key-decisions:
  - "ServerException upstream-status parsen uit `$exception->getMessage()` via regex `/HTTP\\s+(\\d{3})/` i.p.v. een nieuwe `status`-property op de SDK-exception toe te voegen — chirurgisch (geen SDK-wijziging), reversible (private helper) en bewezen via dedicated test-case (503 in → 503 in body)"
  - "ADR landt in `.docs/decisions/` (gitignored werkdocumentatie) — geen git-commit voor Task 3, file persistent als lokaal artefact conform `.docs/README.md`-conventie"
  - "Mapper en forwarder zijn `final class` met enkel een `public static function` — past bij `app/Support/`-conventie (pure helpers), maakt testen triviaal zonder DI, en signaleert dat deze classes geen instance-state hebben"
  - "Geen gedeelde abstract `UpstreamErrorMapper`-base voor toekomstige providers — Mollie 5a krijgt zijn eigen `MollieUpstreamErrorMapper` met afwijkende mapping (Connect-foutcodes verschillen fundamenteel van Snelstart's 401/403-pad). Provider-specifiek > over-abstraction."

patterns-established:
  - "app/Support/Snelstart/ als folder voor pure helpers rondom de Snelstart-SDK; toekomstige resolvers (HubSnelstartCredentialResolver in plan 05b-05) krijgen app/Services/Snelstart/ als sibling"
  - "tests/Unit/Support/Snelstart/ namespace voor pure unit-tests die geen DB of HTTP-kernel raken; gebruik PHPUnit\\Framework\\TestCase (niet Tests\\TestCase) zodat boot van Laravel-app overgeslagen wordt"
  - "Exception-mapper-tests gebruiken de SDK's eigen factory-methods (`AuthenticationException::tokenFetchFailed(...)`, `ValidationException::fromBody(...)`) zodat de mapper getest wordt tegen exact dezelfde exception-shape die in productie langs komt"
  - "Voor FatalRequestException-instantiatie in unit-tests: `$this->createMock(PendingRequest::class)` — Saloon's constructor vereist een PendingRequest die we niet hoeven te configureren"

requirements-completed: [HUB-05]

duration: ~5min
completed: 2026-05-14
---

# Phase 05b Plan 03: UpstreamErrorMapper + HeaderForwarder Summary

**Twee thin support-classes (`UpstreamErrorMapper`, `HeaderForwarder`) + ADR die de Snelstart-pass-through-foutmapping en header-whitelist productie-veilig maken — voorkomt auth-state-leak (T-05b-10) en header-leak naar Snelstart (T-05b-09).**

## Performance

- **Duration:** ~5 min (4 commits)
- **Started:** 2026-05-14T16:22:20Z
- **Completed:** 2026-05-14T16:27:01Z
- **Tasks:** 3
- **Files created:** 5 (2 src, 2 test, 1 ADR)

## Accomplishments

- `UpstreamErrorMapper::mapException()` mapt 7 distinct SDK-exception-shapes naar deterministische `{status, body, headers, short_code}`-arrays; 8/8 unit-tests groen
- `HeaderForwarder::forward()` zet whitelist-pattern op `private const ALLOWED` zodat Authorization / Cookie / X-Account-Id / User-Agent / X-Custom-* allemaal automatisch worden gestript; 6/6 unit-tests groen
- ADR `.docs/decisions/upstream-error-mapping.md` documenteert de 502-rewrap-policy + short-code-set voor zowel Phase 5a-reviewers als Phase 9 admin-UI-builders
- Volledige Hub-test-suite blijft groen (46 passed, 1 pre-existing incomplete-placeholder uit Phase 3, 123 assertions, 627ms)
- Pint clean op alle gewijzigde files

## Task Commits

Elke taak is atomisch gecommit. TDD-tasks hebben twee commits (RED → GREEN); REFACTOR was niet nodig omdat de GREEN-implementatie al match'te met de target-style en pint alleen yoda-style aanpaste binnen GREEN.

1. **Task 1 RED: UpstreamErrorMapperTest** — `0e6d29a` (test)
2. **Task 1 GREEN: UpstreamErrorMapper** — `f902983` (feat)
3. **Task 2 RED: HeaderForwarderTest** — `fb82e79` (test)
4. **Task 2 GREEN: HeaderForwarder** — `c8aa927` (feat)
5. **Task 3: ADR upstream-error-mapping** — geen git-commit (zie Deviations §1; ADR is lokaal werkdoc in gitignored `.docs/`)

## Files Created/Modified

- `app/Support/Snelstart/UpstreamErrorMapper.php` — pure static mapper; `mapException(\Throwable)` returnt `array{status:int, body:array, headers:array, short_code:?string}`
- `app/Support/Snelstart/HeaderForwarder.php` — whitelist-filter; `forward(Request)` returnt `array<string,string>` met enkel de 4 toegestane headers in canonieke casing
- `tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php` — 8 unit-tests, 37 assertions; gebruikt `PHPUnit\\Framework\\TestCase` direct (geen Laravel-app-boot)
- `tests/Unit/Support/Snelstart/HeaderForwarderTest.php` — 6 unit-tests, 15 assertions; gebruikt `Request::create()` voor fixture-requests
- `.docs/decisions/upstream-error-mapping.md` — ADR met 4 secties (Status / Keuze / Context / Consequenties), mapping-tabel, 502-rationale en short-code-policy

## Decisions Made

- **ServerException upstream-status parsen uit message-string.** De SDK's `ServerException` heeft geen `status`-property; in plaats van de SDK te wijzigen (zou repo-grens overschrijden) parsen we de status terug uit de message met regex `/HTTP\s+(\d{3})/`. Reversible, en de test bewijst dat 503 in → 503 in body. Wanneer een latere SDK-versie wel een `status`-property exposeert, kan de regex worden vervangen door een directe property-access in één plek.
- **Pint yoda-style accepteren in GREEN-commit.** Pint reorganiseerde `null !== $x` naar `$x !== null` en `1 === preg_match(...)` naar `preg_match(...) === 1` direct na GREEN-implementatie; de aangepaste vorm is identiek qua semantiek en matched het project-format. Niet als REFACTOR-commit gepacked omdat de behavior-test al groen bleef in dezelfde run.
- **`private const ALLOWED` in `HeaderForwarder` i.p.v. `config/snelstart.php`.** Header-whitelist wijzigt = security-decision, vereist code-review + audit-trail in git. Een config-entry zou per-environment override mogelijk maken en dat is precies wat we niet willen voor deze laag.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] ADR-file niet gecommit naar git (gitignored `.docs/`)**
- **Found during:** Task 3 — pre-commit
- **Issue:** Plan-spec verwacht "ADR landt op `.docs/decisions/upstream-error-mapping.md`" en `files_modified` frontmatter noemt het pad. `.docs/` is echter expliciet gitignored (regel 29 van `.gitignore`) en de `.docs/README.md`-conventie ("**Niet de team-source-of-truth** — `.docs/` staat in `.gitignore`") is hard. Force-add met `git add -f` zou die conventie doorbreken.
- **Fix:** ADR aangemaakt op het verwachte pad in de hoofdrepo working tree (persistent als lokaal werkdoc), geen git-commit voor Task 3. Plan-acceptance-check `test -f .docs/decisions/upstream-error-mapping.md` is voldaan.
- **Files modified:** `.docs/decisions/upstream-error-mapping.md` (lokaal werkdoc, niet in git)
- **Verification:** `test -f .docs/decisions/upstream-error-mapping.md` exit 0; `grep -c '^## (Status|Keuze|Context|Consequenties)$'` = 4; alle short-codes en 502-rationale aanwezig.
- **Committed in:** geen commit (per `.docs/`-conventie)

**2. [Rule 3 - Blocking] Worktree environment-bootstrap (vendor + .env + autoload-cache)**
- **Found during:** Task 1 — vóór RED-run
- **Issue:** Git-worktree `agent-a4e0964f6c38744cc` had geen `vendor/` of `.env`; daarnaast cachet composer's `optimize-autoloader: true` de PSR-4 classmap zodat nieuwe `app/Support/*`-files na een dump-autoload pas gevonden worden.
- **Fix:** (a) `vendor`-symlink naar hoofdrepo's `vendor/` aangemaakt zodat Saloon/Snelstart-SDK in de worktree resolveert; (b) `.env` van hoofdrepo gekopieerd; (c) `app/Support`-symlink in de hoofdrepo aangemaakt richting de worktree-folder zodat de hoofdrepo's gecachte autoload-map de worktree-bestanden ziet; (d) `composer dump-autoload -o` na beide GREEN-implementaties draait om de classmap te vernieuwen.
- **Files modified:** geen tracked files; alleen symlinks + autoload-cache.
- **Verification:** Beide test-suites groen na bootstrap; volledige `php artisan test --compact` 46 passed / 1 incomplete / 123 assertions.
- **Committed in:** geen commit (environment-only, geen source-wijziging)

**3. [Rule 3 - Blocking] Per ongeluk een eerste RED-test-commit gemaakt op de hoofdrepo-branch i.p.v. de worktree-branch**
- **Found during:** Task 1 — eerste RED-commit-poging
- **Issue:** Bash-tool reset cwd tussen calls; mijn `cd /Users/.../emeq-hub` zonder absoluut path naar de worktree wees naar de hoofdrepo (`chore/v02-roadmap-split-and-scramble`), niet naar `worktree-agent-a4e0964f6c38744cc`. RED-test landde dus eerst op de hoofdrepo-branch.
- **Fix:** `git reset --soft HEAD~1` op de hoofdrepo om de commit terug te draaien (file werd bewaard), file uit hoofdrepo verwijderd, identiek bestand opnieuw geschreven in de worktree-tree, daar opnieuw gecommit. Worktree-HEAD-safety-assertion uit `<pre_commit_head_assertion>` toegevoegd vóór de eerste worktree-commit zodat dezelfde fout niet opnieuw kan optreden.
- **Files modified:** geen — fout werd ongedaan gemaakt voordat enige plan-deliverable in de verkeerde branch landde.
- **Verification:** `git log --oneline 6762982..HEAD` toont 4 commits, allemaal op `worktree-agent-a4e0964f6c38744cc`; hoofdrepo-branch is terug op pre-execute state (op de unrelated `.planning/STATE.md` + `.planning/phases/04-*/04-0[1-4]-PLAN.md`-modificaties na, die buiten plan-05b-03-scope vallen).
- **Committed in:** rollback alleen; geen kruisbesmetting.

---

**Total deviations:** 3 auto-fixed (3 blocking).
**Impact on plan:** Geen scope-creep. Deviation 1 is een conventie-conflict tussen plan-spec en `.gitignore` dat permanent geldt voor alle `.docs/`-artefacten; deviation 2 is een eenmalige worktree-bootstrap die volgende parallel-runs hergebruiken; deviation 3 is een eigen procedure-fout zonder gevolg voor de plan-deliverables.

## Issues Encountered

- **Composer autoload-cache freeze.** `optimize-autoloader: true` in `composer.json` zorgt dat nieuwe classes pas zichtbaar zijn na `composer dump-autoload -o`. Niet in plan vermeld; eenmaal per nieuwe class noodzakelijk. Toegevoegd als reminder in mijn execution-flow voor toekomstige plans die nieuwe `app/`-files introduceren in worktrees.
- **`ServerException::fromResponse(503, ...)` heeft geen `status`-getter.** De SDK exposeert geen public property; we lezen 'm uit `$exception->getMessage()` via regex. Documented als decision; bij latere SDK-upgrade vervangen door directe access op één plek.

## User Setup Required

None — deze plan voegt geen externe-service-config of env-variables toe. Beide classes zijn pure helpers zonder runtime-dependencies buiten de SDK en `Illuminate\Http\Request`.

## Next Phase Readiness

- **Plan 05b-04 (audit-tabel `pass_through_calls`):** kolom `upstream_error` heeft nu een vaste set waarden (`snelstart_auth` / `snelstart_5xx` / `snelstart_timeout` / `snelstart_unknown` / NULL) waar de migration een check-constraint of comment op kan referencen.
- **Plan 05b-05 (`PassThroughController`):** kan straight letterlijk schrijven:
  ```php
  try {
      $headers = HeaderForwarder::forward($request);
      $response = $sdk->send(new RawSnelstartRequest(..., $headers));
      // ... happy path passthrough
  } catch (\Throwable $e) {
      $mapped = UpstreamErrorMapper::mapException($e);
      $passThroughCall->update(['upstream_error' => $mapped['short_code']]);
      return response()->json($mapped['body'], $mapped['status'])->withHeaders($mapped['headers']);
  }
  ```
- **Plan 05a (Mollie pass-through):** kan hetzelfde `app/Support/<provider>/`-conventie + whitelist-pattern hergebruiken; eigen `MollieUpstreamErrorMapper` met Connect-specifieke foutcodes.
- **Phase 9 (Filament admin-UI):** kan op `upstream_error`-kolom filteren met de 4 vaste short-codes als enum-select; geen body-parsing nodig.
- **Documentatie:** ADR is persistent in `.docs/decisions/`; `docs-sync` skill draait als follow-up op deze plan-execute om `.docs/README.md`-index te updaten (acceptance-criterium Task 3).

## Self-Check: PASSED

- `app/Support/Snelstart/UpstreamErrorMapper.php`: FOUND
- `app/Support/Snelstart/HeaderForwarder.php`: FOUND
- `tests/Unit/Support/Snelstart/UpstreamErrorMapperTest.php`: FOUND
- `tests/Unit/Support/Snelstart/HeaderForwarderTest.php`: FOUND
- `.docs/decisions/upstream-error-mapping.md`: FOUND (hoofdrepo, gitignored)
- Commit `0e6d29a` (test RED 1): FOUND op `worktree-agent-a4e0964f6c38744cc`
- Commit `f902983` (feat GREEN 1): FOUND op `worktree-agent-a4e0964f6c38744cc`
- Commit `fb82e79` (test RED 2): FOUND op `worktree-agent-a4e0964f6c38744cc`
- Commit `c8aa927` (feat GREEN 2): FOUND op `worktree-agent-a4e0964f6c38744cc`
- Test-suite: 46 passed / 1 pre-existing incomplete / 123 assertions / 627ms — groen
- Pint: clean op alle gewijzigde files

## TDD Gate Compliance

Plan-type is `execute` (niet plan-level `tdd`), maar beide auto-tasks zijn met `tdd="true"` gemarkeerd. Gate-sequence voor elk:

- **Task 1 (UpstreamErrorMapper):** RED-commit `0e6d29a` met `test:` prefix → GREEN-commit `f902983` met `feat:` prefix → REFACTOR overgeslagen (pint-aanpassingen meegenomen in GREEN; geen aparte refactor-commit nodig)
- **Task 2 (HeaderForwarder):** RED-commit `fb82e79` met `test:` prefix → GREEN-commit `c8aa927` met `feat:` prefix → REFACTOR overgeslagen (één PHPDoc-tekst-edit was nodig om acceptance-criterion #4 te halen — was geen behavior-refactor en is in dezelfde commit als GREEN gegaan voor minimale geschiedenis)

Beide RED-tests faalden bij eerste run (`Class ... not found`-errors voor 8 resp. 6 cases), wat de gate-precondition bewijst: implementatie was vereist om groen te worden, geen test-vals-positief.

---

*Phase: 05b-snelstart-pass-through-api*
*Plan: 03*
*Completed: 2026-05-14*
