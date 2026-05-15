---
phase: 02-emeq-mollie-api-foundation
plan: 08
status: complete
completed: 2026-05-14T12:45:00.000Z
duration_min: 15
self_check: PASSED
requirements:
  - MOLL-01
---

# Plan 02-08 — Sub-repo push + Hub composer.json path-repo + VCS-smoke

## Wat is opgeleverd

Phase 2 afgesloten met:

1. **Sub-repo `packages/mollie-api/` gepusht naar GitHub** op branch `feat/foundation` (= nieuwe default branch op `yusufkaracaburun/emeq-mollie-api`).
2. **GitHub repo-description bijgewerkt** — van "Saloon v3" stale-tekst naar accurate wrap-mollie-api-php beschrijving.
3. **Hub composer.json** krijgt `mollie-path` repository entry (mirror van snelstart-path).
4. **VCS-smoke bewijs** in `/tmp/mollie-vcs-smoke/` — `composer require emeq/mollie-api:dev-feat/foundation` slaagt zonder authenticatie tegen de publieke GitHub-repo (ROADMAP Phase 2 success criterion 1).
5. **README sectie "Facade-alias collision"** uitgebreid met Phase 6 SUB-01 deferral-uitleg (W-1).

## Tasks uitgevoerd

| # | Task | Result |
|---|------|--------|
| 1 | README expand + final Pest + Pint + sub-repo wrap-commit | `829766c` op `feat/foundation`; Pest 33/33 groen; Pint passed |
| 2 | **Checkpoint** — user-approval voor push | Approved: "Push als default branch + description-update" |
| 2a | `git push -u origin feat/foundation` | ✓ `[new branch] feat/foundation -> feat/foundation`; upstream tracking ingesteld |
| 2b | `gh api PATCH default_branch=feat/foundation` | ✓ |
| 2c | `gh repo edit --description "..."` | ✓ Verified via `gh repo view --json description` |
| 3 | Hub composer.json mollie-path entry + master-branch guard | `e8490d6` op `gsd/phase-2-emeq-mollie-api-foundation`; `composer validate` valid |
| 4 | /tmp VCS-smoke | ✓ `vendor/emeq/mollie-api/composer.json` exists; src/ populated (Contracts, Data, Exceptions, Facades, Mollie.php, MollieServiceProvider.php) |

## Commits

### Sub-repo `packages/mollie-api/` op `feat/foundation`

Totaal 17 commits gepusht naar `github.com:yusufkaracaburun/emeq-mollie-api`:

```
829766c docs(02-08): uitgebreide Facade-alias collision sectie in README
4914ca3 test(02-07): ErrorMappingTest — ValidationException::getField + Bearer wiring (B-4 + B-5)
820996c chore(02-07): gitignore *.notes.md voor plan-execution FQCN-inspectie
1c9687e test(02-06): MollieServiceProviderTest — SP binding-shape contracts
a51ebae test(02-06): MollieTest — multi-tenant key-wissel + env-guard + idempotency-alias
4b11258 test(02-05): ArchTest + PackageSmokeTest groene Pest-suite
a86898a test(02-05): Testbench TestCase + Pest bootstrap + FakeMollieCredentialResolver
13cbe53 feat(02-04): Facades\Mollie alias met union-typed credentials() (W-7)
63a8cab feat(02-04): MollieServiceProvider met container-bindings
2fb8c22 feat(02-04): Mollie facade-target met type-discriminator + idempotency dual-path
57a726c chore(02-04): post-autoload-dump + prepare scripts (W-2 Optie B)
2c848d2 feat(02-03): MollieException + MissingCredentialResolverException
e60c556 feat(02-02): MollieApiKeyCredentials + MollieOAuthCredentials met prefix-validatie
2022802 feat(02-02): contract + abstract MollieCredentials base met fingerprint()
64c1406 chore(02-02): disable mb_str_functions in pint.json
95aef8a chore(02-01): tooling-config + skeleton-metadata + config/mollie.php
e1cf185 feat(02-01): composer.json + dotfiles voor emeq/mollie-api skeleton
```

`composer.lock` correct uitgesloten (gitignored, SDK best-practice — B-3).

### Hub worktree `gsd/phase-2-emeq-mollie-api-foundation`

```
e8490d6 chore(02): voeg mollie-path repository entry toe in Hub composer.json
b648f46 docs(02-07): complete ErrorMappingTest plan
5a75e56 docs(02-06): complete Pest core-coverage plan
5445602 docs(02-05): summary voor Pest-testinfrastructuur + smoke-suite
367d29c docs(02-04): complete ServiceProvider + Mollie + Facade plan
492eed7 docs(02-03): summary voor MollieException + MissingCredentialResolverException
0f050b5 docs(02-02): summary van MollieCredentialResolver-contract + dual-credential Data-classes
15ad411 docs(02-01): SUMMARY voor emeq/mollie-api package-skeleton
```

## Verifications

- ✓ Sub-repo working tree clean (`git status` empty afgezien van gitignored composer.lock + notes.md)
- ✓ `git rev-parse --abbrev-ref HEAD` = `feat/foundation` (geen master/main)
- ✓ Master-branch guard ran in Task 3 vóór add/commit (current = `gsd/phase-2-emeq-mollie-api-foundation`)
- ✓ `composer validate --no-check-publish` slaagt (lock-warning is OK — geen require yet)
- ✓ GitHub default_branch = `feat/foundation` (was leeg vóór push)
- ✓ GitHub description = "Laravel SDK wrap around mollie/mollie-api-php met multi-tenant credential resolver en OAuth support" (was "Saloon v3 ..." stale)
- ✓ VCS-smoke `/tmp/mollie-vcs-smoke/vendor/emeq/mollie-api/composer.json` exists; emeq/mollie-api name verified; src/ populated
- ✓ Pest 33/33 (86 assertions) groen vóór commit + push
- ✓ Pint --dirty passed

## Deviations from Plan

**[D] Task 1 — "single first commit" assumption vs atomic per-task commits.**
Plan must_haves stelden "eerste commit op feat/foundation (~16 files)". In de praktijk: prior plans (02-01 t/m 02-07) committen elk hun werk atomically per task (GSD execute-plan standaard pattern). De `feat/foundation` branch heeft daarom 17 commits i.p.v. 1. Functioneel equivalent: dezelfde files, dezelfde hooks, dezelfde validatie. De single-commit-assumptie was descriptief, niet normatief — geen impact op success criteria.

**Geen andere deviations.**

## Success Criteria (Phase 2 ROADMAP)

| # | Criterion | Status |
|---|-----------|--------|
| 1 | `composer require emeq/mollie-api` via VCS slaagt zonder auth | ✓ Bewezen via /tmp VCS-smoke (Task 4) |
| 2 | `MollieCredentialResolver` runtime-swap zonder cross-tenant lekkage | ✓ Bewezen in 02-06 `MollieTest` |
| 3 | `Mollie`-facade en laravel-mollie `Mollie`-facade coexistence | ⏸ Bewust gedeferred naar Phase 6 SUB-01 (gedocumenteerd in README) |
| 4 | Pest-suite ≥10 tests groen op auth-laag + resolver + error-mapping | ✓ 33 tests / 86 assertions groen |
| 5 | Geen raw API-key/access-token in logs of exception-messages — alleen sha256-fingerprint (eerste 12 chars) | ✓ Bewezen in 02-02 fingerprint tests + 02-03 exception-content tests + 02-07 error-mapping |

4 van 5 success criteria volledig gevalideerd in Phase 2. SC #3 expliciet gedeferred naar Phase 6 met user-approval impliciet via gedocumenteerde Phase 6 SUB-01 plan.

## Self-Check: PASSED

Alle artefacten geverifieerd, alle commits gemaakt, alle pushes uitgevoerd na approval, VCS-smoke bewezen, geen master-commits.

## Next

Orchestrator runs post-execution gates: code-review (`/gsd-code-review 02`), verifier (`gsd-verifier` agent), roadmap update (`gsd-sdk phase.complete 02`).
