---
phase: quick-260514-iai
plan: 01
type: execute
subsystem: http-middleware
tags: [seo, robots, middleware, security-by-obscurity]
requirements_completed:
  - QUICK-260514-iai
key_files:
  created:
    - app/Http/Middleware/SetNoIndexHeaders.php
    - tests/Feature/NoIndexHeaderTest.php
  modified:
    - bootstrap/app.php
    - public/robots.txt
    - resources/views/welcome.blade.php
decisions:
  - "Driedubbele defensie: HTTP-header (middleware) + robots.txt + meta-tag. Geen enkele crawler negeert alle drie."
  - "Middleware globaal geregistreerd via `$middleware->append()` zodat ook /up (health-route) en toekomstige /v1/* (api) gedekt zijn — niet alleen `web`-group."
  - "Test voor /robots.txt op file-niveau, niet via HTTP. Productie serveert public/robots.txt statisch via Caddy/nginx; Laravel-router heeft geen route voor /robots.txt (404 in tests)."
metrics:
  duration_minutes: 7
  completed_date: "2026-05-14"
  tests_added: 3
  tests_passing: 5
commits:
  - hash: c5d801f
    type: feat
    message: "feat(quick-260514-iai): app-wide noindex/nofollow via middleware + robots.txt + meta"
  - hash: 97bc40a
    type: test
    message: "test(quick-260514-iai): voeg PHPUnit feature-test toe voor X-Robots-Tag header"
---

# Quick-task 260514-iai: App-wide noindex/nofollow voor bots Summary

`X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` op elke response via globale middleware, gecombineerd met `Disallow: /` in robots.txt en `<meta name="robots">` in de welcome-view — drie defensieve lagen om te voorkomen dat het interne integration-platform in zoekresultaten verschijnt.

## Wat is geleverd

| Component | Bestand | Status |
|-----------|---------|--------|
| Middleware | `app/Http/Middleware/SetNoIndexHeaders.php` | Nieuw — zet `X-Robots-Tag` na `$next($request)` |
| Globale registratie | `bootstrap/app.php` | Toegevoegd `$middleware->append(SetNoIndexHeaders::class)` in withMiddleware-callback |
| Robots-file | `public/robots.txt` | Vervangen `Disallow:` → `Disallow: /` |
| HTML-meta fallback | `resources/views/welcome.blade.php` | `<meta name="robots" content="noindex,nofollow">` na viewport-meta |
| PHPUnit-test | `tests/Feature/NoIndexHeaderTest.php` | Nieuw — 3 passing tests |

## Tasks uitgevoerd

### Task 1: Middleware + bootstrap-registratie + robots.txt + meta-tag

- `php artisan make:middleware SetNoIndexHeaders --no-interaction` → skeleton
- `handle()` body vervangen: zet `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` op response
- PHPDoc-noise verwijderd conform `.ai/rules` "minimal comments"
- `bootstrap/app.php`: `$middleware->append(\App\Http\Middleware\SetNoIndexHeaders::class)` — Pint fixers (`fully_qualified_strict_types` + `ordered_imports`) hebben het automatisch herschreven naar een `use App\Http\Middleware\SetNoIndexHeaders;` + `$middleware->append(SetNoIndexHeaders::class)`. Beide vormen voldoen aan grep-gate en key_link pattern.
- `public/robots.txt`: 1 regel gewijzigd (`Disallow:` → `Disallow: /`)
- `welcome.blade.php`: 1 regel toegevoegd na viewport-meta
- Pint clean op alle 4 files

**Verify gates (allemaal groen):**

```
vendor/bin/pint --dirty --test --format agent → passed
php artisan route:list --except-vendor → exit 0
grep -q "SetNoIndexHeaders" bootstrap/app.php → match
grep -q "Disallow: /" public/robots.txt → match
grep -q 'name="robots"' resources/views/welcome.blade.php → match
```

**Commit:** `c5d801f` — `feat(quick-260514-iai): app-wide noindex/nofollow via middleware + robots.txt + meta`

### Task 2: PHPUnit feature-test voor X-Robots-Tag header

- `php artisan make:test --phpunit NoIndexHeaderTest --no-interaction` → skeleton
- 3 test-methods geschreven:
  1. `test_up_endpoint_has_x_robots_tag_header` — health-route `/up`
  2. `test_root_endpoint_has_x_robots_tag_header` — root `/` (JSON-response, bewijst dat middleware globaal werkt)
  3. `test_robots_txt_disallows_all` — file-niveau check op `public/robots.txt` (zie deviatie)

**Verify gates:**

```
php artisan test --compact --filter=NoIndexHeaderTest → 3 passed, 9 assertions
php artisan test --compact (volledige suite) → 5 passed, 11 assertions
vendor/bin/pint --dirty --format agent → passed
```

**Commit:** `97bc40a` — `test(quick-260514-iai): voeg PHPUnit feature-test toe voor X-Robots-Tag header`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug in plan-spec] `/robots.txt` is geen Laravel-route**

- **Found during:** Task 2 — `$this->get('/robots.txt')->assertOk()` retourneerde 404
- **Issue:** De plan-spec verwacht dat Laravel `/robots.txt` serveert, maar in Laravel 13 worden statische files in `public/` door de webserver (Caddy/nginx) afgehandeld vóórdat PHP wordt aangeroepen. PHPUnit's `$this->get()` gaat door de Laravel-router en die heeft geen route voor `/robots.txt` → 404.
- **Fix:** Test herschreven om `public_path('robots.txt')` direct te lezen via `assertFileExists` + `assertStringContainsString`. Dit test wat we daadwerkelijk willen valideren (file bestaat met juiste inhoud) zonder een onnodige route te registreren die de productie-static-serving zou shadowen.
- **Files modified:** `tests/Feature/NoIndexHeaderTest.php` (alleen test #3)
- **Commit:** `97bc40a` (test-commit zelf)

### Out-of-scope / niet aangeraakt

- **Bestaande `feat/noindex-bot-exclusion` branch in main repo**: tijdens setup zijn er per ongeluk edits in het main-repo werkdir terechtgekomen (paden zonder worktree-prefix). Deze zijn ge-`git checkout`'t voordat het werk in de worktree is gestart. Geen impact op de feature-branch zelf.
- **`packages/` + `vendor/` symlinks in worktree**: nodig om artisan/pint/phpunit te draaien (worktree heeft geen vendor/). Beide zijn gitignored en niet in commits opgenomen. Vendor is uiteindelijk via `rsync` als echte directory in de worktree gezet (en `composer dump-autoload` uitgevoerd) omdat PSR-4 base-dir via een vendor-symlink naar het main-repo `app/` resolved en niet naar het worktree-`app/`.
- **Branch-base rebase**: de worktree-branch `worktree-agent-a1380f8792689a419` startte op `9355811` (master tip), maar de orchestrator verwachtte basis `fd7a111` (`feat/noindex-bot-exclusion`, bevat de PLAN.md). De initiële `<worktree_branch_check>` step 2 (`merge-base` vergelijking) was overgeslagen; oorspronkelijke commits `cdfee73` + `f0bdedc` zijn na detectie ge-rebased naar `c5d801f` + `97bc40a` bovenop `fd7a111`. Geen conflicten — `fd7a111` voegt alleen plan-docs toe in `.planning/quick/` en `.planning/phases/`, geen overlap met code-files in deze quick-task.

## Auth gates

Geen — geen externe authenticatie vereist.

## Self-Check: PASSED

**Created files (in worktree):**
- `app/Http/Middleware/SetNoIndexHeaders.php` → FOUND
- `tests/Feature/NoIndexHeaderTest.php` → FOUND

**Modified files (in worktree):**
- `bootstrap/app.php` → FOUND (contains `SetNoIndexHeaders`)
- `public/robots.txt` → FOUND (contains `Disallow: /`)
- `resources/views/welcome.blade.php` → FOUND (contains `name="robots"`)

**Commits:**
- `c5d801f` → FOUND in `git log` (Task 1)
- `97bc40a` → FOUND in `git log` (Task 2)

**Verification commands all green:**
- `php artisan test --compact --filter=NoIndexHeaderTest` → 3 passed
- `php artisan test --compact` → 5 passed (full suite)
- `vendor/bin/pint --dirty --test --format agent` → passed
- End-to-end kernel-handle smoke: `/up` 200 + header, `/` 200 + header
