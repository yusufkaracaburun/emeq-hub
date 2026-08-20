# Active rules

Canonical rules enabled for this project. Source of truth: `~/.local/share/ai-kit/standards/rules/*.mini.md`. Re-emit with `bin/emit-rules.sh`.

<!-- ai-kit:active-rules:start -->

| Rule | Mode | Description |
| ---- | ---- | ----------- |
| aposd | on-demand | A Philosophy of Software Design — depth, complexity, naming, abstraction |
| code-audit | on-demand | Whole-codebase architecture-quality audit checklist — 9 dimensions (patterns, SOLID, DRY, YAGNI, naming+comment-drift, coupling, layering, error-handling, type-safety) |
| context-discipline | always-on | Token-budget discipline — grep before read, delegate wide exploration, lean on CONTEXT.md |
| context7 | always-on | Use the ctx7 CLI for live library/framework/SDK documentation lookups instead of relying on training data |
| domain-model-first | always-on | Before proposing any architecture or data-model change, read the canonical domain entities the change would touch — don't extrapolate from the surface layer (form, controller, route) |
| error-handling | always-on | Fail fast at boundaries, trust internal code, never swallow errors |
| git-hygiene | always-on | Branch naming, Conventional Commits, PR conventions, merge strategy |
| grill-first | always-on | Never jump to plan or implementation from an issue/PRD/spec — open with grill questions first, even when the source doc looks complete |
| observability | always-on | Structured logs, metrics, and traces — every request observable end-to-end |
| project-lifecycle | always-on | Calibrate agent caution to project lifecycle phase (development vs production) |
| refactoring | on-demand | Catalog of safe, mechanical refactorings (extract, inline, move, rename) |
| secrets-hygiene | always-on | Never commit, log, or hard-code secrets — single source for credentials, rotation playbook |
| semver | always-on | Semantic versioning — MAJOR.MINOR.PATCH discipline for any released artifact |
| testing-pyramid | always-on | Test mix discipline — many fast unit tests, fewer integration tests, very few E2E tests |
| pragmatic | on-demand | Pragmatic Programmer baseline — DRY, orthogonality, broken windows, tracer bullets |
| branch-cleanup-after-merge | always-on | Delete merged feature branches locally and on remote immediately after the PR merges |
| bsd-sed-word-boundary | always-on | macOS / BSD sed does not support \b for word boundaries; use [[:<:]] / [[:>:]], grep -w, perl, or awk instead |
| deployment-on-demand | always-on | Never deploy without explicit user request; green CI is not authorisation to ship |
| gitignore-public-assets-trap | always-on | Don't blanket-gitignore paths under public/ (or equivalent web-root); many assets are source-controlled and required at build time |
| latest-stable-deps | always-on | When adding or bumping a dependency, prefer the latest stable release; in production-phase repos pair the bump with a migration plan |
| mark-recommended-option | always-on | When presenting choices via AskUserQuestion, put the recommended option first and label it "(Recommended)" |
| minimal-comments | always-on | Default to no comments; only add one when the WHY is non-obvious and would surprise a future reader |
| phase-scope-discipline | always-on | Stay within the scope of the current phase or task; capture out-of-scope finds as deferred follow-ups instead of doing them inline |
| release-it | on-demand | Production stability — timeouts, circuit breakers, bulkheads, backpressure |
| ddd-distilled | on-demand | DDD Distilled — bounded contexts, aggregates, ubiquitous language |
| api-design | always-on | REST API conventions — resource modelling, status codes, versioning, OpenAPI source-of-truth |
| laravel-conventions | always-on | Laravel idioms — Eloquent, Artisan, queues, validation, multi-tenancy patterns the framework expects |

<!-- ai-kit:active-rules:end -->
