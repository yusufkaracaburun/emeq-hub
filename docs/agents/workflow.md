# Workflow

**Single-maintainer** repo (Yusuf Karacaburun). Lichtgewicht, geen team-ceremonie.

## Branch- en merge-policy

- Nooit direct op `master` werken (`.agents/git-policy.md`).
- Feature-/fix-branch → verifier PASS → **ff-merge naar `master`**. Geen
  verplichte PR voor solo-fases.
- Nooit pushen zonder expliciete toestemming. Nooit `--no-verify` / force-push.
- Max 3 gewijzigde files per commit zonder approval.

> Daarom **geen branch-protection op `master`**: dat zou de ff-merge-flow breken.
> De ai-kit GH-hygiene is bewust met `--no-protection` gedraaid.

## Planning-flow (ai-kit)

Werk loopt via de ai-kit-lifecycle + GitHub-issues, niet ad-hoc edits:

- `/ai:next` — ranked backlog (open issues + labels).
- `/ai:tdd` — feature/bugfix via red-green-refactor.
- `/ai:diagnose` — onderzoek + bugfixing.
- `/ai:to-issues` / `/ai:to-prd` — plan → issues.
- `/ai:review` — pre-merge review.

Bron-van-waarheid voor open + forward-werk: **GitHub-issues** (`P*`/`epic/*`/`area/*`-labels). Elk issue draagt precies één epic: `providers`, `lookup`, `books`, `marketing` of `platform`.

`dor-dod-enforcement.yml` bewaakt de checkboxes in de issue-templates. Sluit je een issue met een onafgevinkte Definition of Done, dan heropent hij het met een lijst van wat er nog open staat; werk dat niet meer relevant is sluit je als **not planned**, want die state_reason passeert. Zet je `status:in-progress` terwijl de Definition of Ready nog gaten heeft, dan haalt hij het label er weer af. Issues zonder die secties passeren ongehinderd als legacy.

`.planning/` is terug als lokale, gitignored werkmap voor agent-concepten; de historische GSD-planning onder diezelfde naam zit in git-history.

## Tests

Elke wijziging programmatisch getest (PHPUnit in Hub, Pest in SDK-packages).
`vendor/bin/pint --dirty --format agent` vóór commit.
