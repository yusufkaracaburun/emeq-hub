# Workflow

**Single-maintainer** repo (Yusuf Karacaburun). Lichtgewicht, geen team-ceremonie.

## Branch- en merge-policy

- Nooit direct op `master` werken (`.ai/git-policy.md`).
- Feature-/fix-branch → verifier PASS → **ff-merge naar `master`**. Geen
  verplichte PR voor solo-fases.
- Nooit pushen zonder expliciete toestemming. Nooit `--no-verify` / force-push.
- Max 3 gewijzigde files per commit zonder approval.

> Daarom **geen branch-protection op `master`**: dat zou de ff-merge-flow breken.
> De ai-kit GH-hygiene is bewust met `--no-protection` gedraaid.

## Planning-flow (GSD)

Werk loopt via GSD-commands, niet ad-hoc edits:

- `/gsd-quick` — kleine fixes, docs, ad-hoc.
- `/gsd-debug` — onderzoek + bugfixing.
- `/gsd-execute-phase` — geplande fase-uitvoering.

Artefacten: `.planning/ROADMAP.md`, `.planning/STATE.md`, `.planning/phases/<NN>-<slug>/`.

## Tests

Elke wijziging programmatisch getest (PHPUnit in Hub, Pest in SDK-packages).
`vendor/bin/pint --dirty --format agent` vóór commit.
