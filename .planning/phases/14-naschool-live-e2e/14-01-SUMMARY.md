---
phase: 14-naschool-live-e2e
plan: 01
subsystem: naschool-foundation
tags: [composer, vcs-distribution, env-config, multi-tenancy, cross-repo]

requires:
  - phase: 11-snelstart-saloon-v4-upgrade
    provides: emeq/snelstart-api v0.2.0 tag (Saloon-v4-baseline + SNEL-03/04 advisories closure)
provides:
  - Naschool composer.json met emeq/snelstart-api ^0.2.0 + emeq/mollie-api ^0.2.0 via publieke VCS-repositories (geen auth.json)
  - Naschool config/services.php emeq_hub-block + .env.example keys (base_url, pat, naschool_account_id)
  - App\Support\EmeqHub\EmeqHubConfig final readonly value-object met fail-fast factory + 4 feature-tests
  - emeq/mollie-api v0.2.0 tag op SDK-remote (stable promotion van v0.1.0-alpha.2)
affects: [14-02 EmeqHubClient, 14-03 listener-DI, NSCH-04 closure]

tech-stack:
  added: [emeq/snelstart-api ^0.2.0, emeq/mollie-api ^0.2.0]
  patterns: [config-reader value-object pattern, env-only no-defaults fail-fast]

key-files:
  created:
    - "[NASCHOOL-REPO] app/Support/EmeqHub/EmeqHubConfig.php"
    - "[NASCHOOL-REPO] tests/Feature/Support/EmeqHub/EmeqHubConfigTest.php"
  modified:
    - "[NASCHOOL-REPO] composer.json"
    - "[NASCHOOL-REPO] composer.lock"
    - "[NASCHOOL-REPO] config/services.php"
    - "[NASCHOOL-REPO] .env.example"
    - "packages/mollie-api/CHANGELOG.md (side-task)"

key-decisions:
  - "Tag emeq/mollie-api v0.2.0 nu (stable promotion van alpha.2) i.p.v. constraint lowering — behoudt Plan 14-01 must_haves intact en geeft mollie-SDK een caret-vriendelijke baseline voor downstream consumers"
  - "Boost-auto-update drift (AGENTS.md, CLAUDE.md in Naschool) uit Task-1 commit gehouden — die files horen niet bij NSCH-04 scope, blijven uncommitted op feature-branch voor losse Boost-cleanup"
  - "feature-branch feat/nsch-04-emeq-hub-foundation in Naschool — geen direct master-commit, geen push (volgt Naschool's git-policy net als emeq-hub)"

patterns-established:
  - "Cross-repo plan-execution: Hub-side .planning/ + SUMMARY.md, alle code-edits in target-repo op feature-branch met atomic per-task commits"
  - "EmeqHubConfig fail-fast pattern: env-only resolution, throwt met exacte env-key name in error message zodat ops-team direct weet welke key te zetten"

requirements-completed: [NSCH-04]

duration: ~30 min
completed: 2026-05-20
---

# Phase 14 Plan 01: Naschool emeq-hub foundation Summary

**Naschool require't beide Hub-SDKs publiek via VCS (snelstart-api v0.2.0, mollie-api v0.2.0) en heeft een env-driven EmeqHubConfig-helper met fail-fast factory + groene feature-suite — fundament voor StancltenancyCredentialResolver (Plan 14-02) en listener-DI (Plan 14-03).**

## Performance

- **Duration:** ~30 min
- **Tasks:** 2 + 1 side-task
- **Files modified:** 6 (4 Naschool + 1 mollie-SDK + 1 emeq-hub planning)

## Accomplishments

- Naschool's composer-tree heeft beide Hub-SDKs publiek geïnstalleerd met concrete versie-pins op v0.2.x — `composer show emeq/snelstart-api emeq/mollie-api` retourneert beide met `version: v0.2.0`.
- emeq/snelstart-api gepind op ref `ce7c66c2179ad794a7df1cbd8ddfb2c10c4b1d45` (= Phase-11 release-commit). Saloon-v4-baseline + SNEL-03/04 advisories-closure blijft intact via transitive consumer.
- emeq/mollie-api gepind op ref `8c2c0ff12724e3cea87e9d85e7af0e0e3278c596` (= nieuwe v0.2.0 stable-promotion tag, functioneel identiek aan v0.1.0-alpha.2).
- EmeqHubConfig is DI-resolvable en throw-bewezen via 4 feature-tests (1 happy + 3 missing-field cases, 9 assertions, 360ms).

## Task Commits

Atomic per-task, op feature-branch `feat/nsch-04-emeq-hub-foundation` in Naschool-repo:

1. **Task 1: Naschool composer.json VCS-entries + composer update** — `2e562325` (chore: `chore(deps): add emeq/snelstart-api ^0.2.0 + emeq/mollie-api ^0.2.0 SDK-dependencies`)
2. **Task 2: config/services.php emeq_hub-block + EmeqHubConfig helper + feature-test** — `5e1fb0e6` (feat: `feat(emeq-hub): config + EmeqHubConfig helper + feature-test`)

**Side-task: emeq/mollie-api v0.2.0 tag** — `8c2c0ff` (docs: `docs(changelog): v0.2.0 — promote alpha.2 baseline to stable`) op `feat/foundation` branch in emeq-mollie-api repo + annotated tag `v0.2.0` pushed naar origin.

## Files Created/Modified

### Naschool (`/Users/yusufkaracaburun/Sites/localhost/school-activities-hub/backend/`, branch `feat/nsch-04-emeq-hub-foundation`)

- `composer.json` — 2 VCS-repository entries (snelstart-api, mollie-api) naast bestaande 4 path-repos; 2 require keys `emeq/snelstart-api ^0.2.0` + `emeq/mollie-api ^0.2.0`.
- `composer.lock` — herpinst met concrete dist.url's naar github.com tarballs; pakt saloonphp/laravel-plugin als snelstart-transitive (Saloon v4).
- `config/services.php` — `'emeq_hub' => [...]` block met `base_url` + `pat` + `naschool_account_id`, env-only zonder defaults.
- `.env.example` — drie `EMEQ_HUB_*` keys met Nederlandstalig één-regel-comment per key.
- `app/Support/EmeqHub/EmeqHubConfig.php` — `final readonly class` met constructor-property-promotion + static `fromConfig(): self` die config leest en throwt bij ontbrekende velden.
- `tests/Feature/Support/EmeqHub/EmeqHubConfigTest.php` — `test_from_config_reads_all_three_fields` + 3 throw-tests (base_url, pat, account_id missing).

### emeq-mollie-api SDK (`/Users/yusufkaracaburun/Sites/localhost/emeq-hub/packages/mollie-api/`, branch `feat/foundation`)

- `CHANGELOG.md` — `[0.2.0] - 2026-05-20` section (stable promotion zonder API-wijzigingen sinds alpha.2).
- Annotated git-tag `v0.2.0` op commit `8c2c0ff`, pushed naar `origin/v0.2.0`.

## Deviations from Plan

1. **Plan 14-01 baseline-claim faalde:** Plan asserteerde `^0.2.0` voor emeq/mollie-api maar SDK had alleen `v0.1.0-alpha.{1,2}`. Surface via AskUserQuestion (engineering-rule: "conflicten oppervlakken niet uitmiddelen"). User koos optie 1 (tag v0.2.0 nu, ~5 min side-task). Resultaat: plan must_haves blijven intact, mollie-SDK heeft nu caret-vriendelijke stable-baseline.
2. **composer.json `sort-packages: true`:** Naschool sorteert packages alfabetisch — `emeq/mollie-api` en `emeq/snelstart-api` ingevoegd tussen `dedoc/scramble` en `fakerphp/faker`. Geen plan-deviatie, alleen relevante vermelding voor reviewer.

## Tested / Verified

- `composer show emeq/snelstart-api emeq/mollie-api` retourneert beide met concrete v0.2.0 (acceptance #1 + #2 ✓).
- `composer.lock` pinst emeq/snelstart-api op v0.2.0 ref `ce7c66c2…` (Phase-11-baseline behouden — acceptance #3 ✓).
- `composer.lock` bevat dist.url-regels naar github.com voor beide SDKs (acceptance #4 ✓).
- `composer update --no-cache` slaagde zonder auth-prompt (acceptance #5 ✓, beide SDK-repos publiek).
- `php artisan test --compact --filter=EmeqHubConfigTest`: 4 passed, 9 assertions, 360ms (acceptance Task-2 #3 ✓).
- Pint exit 0 op nieuwe files (Yoda-style + brace-position fixes, acceptance Task-2 #5 ✓).
- `EmeqHubConfig::fromConfig()` retourneert instance bij volle config + throwt RuntimeException met exacte env-key name bij elk missing field (acceptance Task-2 #2 ✓).

## Open Items / Non-blocking Notes

- **Boost-auto-update drift in Naschool:** `composer post-update-cmd` triggerde `php artisan boost:update` wat `AGENTS.md` + `CLAUDE.md` in Naschool wijzigde. Niet onderdeel van NSCH-04 — staat uncommitted op feature-branch en wordt apart opgepakt (los van Phase 14).
- **composer audit: 3 advisories op symfony/yaml** (CVE-2026-45304/45305/45133, gerapporteerd 2026-05-20). Reeds aanwezig in Naschool's dep-tree via Laravel framework, niet door deze plan geïntroduceerd. Aparte security-task in Naschool nodig — niet binnen scope Phase 14.

## Self-Check: PASSED

- [x] Both SDK-packages publiek installeerbaar in Naschool zonder auth-prompt
- [x] emeq/snelstart-api gepinst op v0.2.0 (Phase-11 Saloon-v4-baseline behouden)
- [x] emeq/mollie-api gepinst op v0.2.0 (stable promotion uitgevoerd)
- [x] EmeqHubConfig DI-resolvable, fail-fast bij missing env
- [x] 4 feature-tests groen (9 assertions)
- [x] Pint clean op nieuwe files
- [x] Atomic per-task commits op Naschool feature-branch (geen master-push)
- [x] Hub-repo: alleen dit SUMMARY (geen code-edits)

Listener (Plan 14-03) kan deze helper via DI consumeren; Plan 14-02 kan `EmeqHubConfig::$pat` + `$baseUrl` consumeren voor EmeqHubClient en `EmeqHubConfig::$naschoolAccountId` voor StancltenancyCredentialResolver.
