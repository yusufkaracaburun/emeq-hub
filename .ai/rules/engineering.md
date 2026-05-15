# Engineering Rules — emeq-hub

## Chirurgisch wijzigen

- Raak alleen wat moet voor de taak. Geen "improvements" in adjacent code, comments of formatting.
- Geen refactor van werkende code mee in een bug-fix of feature-PR.
- Match bestaande style — ook als je 'm niet mooi vindt. Conformance > smaak.
- SDK-grenzen niet doorbreken om snel klaar te zijn: Hub-domeinmodellen horen NIET in een SDK-package. Liever taak splitsen dan de scheiding zachter maken.

## Conflicten oppervlakken, niet uitmiddelen

- Bij twee tegenstrijdige patronen in de codebase: kies één (meest recent / meest getest / matcht de actieve refactor-richting). Niet blenden.
- Leg uit waaróm je die kant kiest in commit-msg of PR-body.
- Flag het andere pattern voor cleanup als TODO of memory-feedback, niet stilzwijgend laten staan.

## Lezen vóór schrijven

- Voor nieuwe code: lees eerst exports, directe callers en gedeelde utilities die je raakt.
- "Lijkt orthogonaal" is een rode vlag — als je niet weet waarom code zo opgebouwd is, vraag.
- Partner-API werk: lees de officiële partner-docs **vóór** je een endpoint of payload-shape verzint. Snelstart's foutcodes, Mollie's Connect-flows, etc. hebben subtiele afwijkingen van wat in OSS-SDKs zit.
- SDK-werk: de SDK draait via Composer-VCS (zie packages-conventie). Lees `vendor/emeq/<naam>/` voor de versie die nu draait, of clone als referentie naar `packages/<naam>/`. Edits gebeuren in de eigen SDK-repo en landen in de Hub via `composer update emeq/<naam>`.

## Wat NIET hier hoort

- Stylistische conventies (formatting, naming) — die staan in `.editorconfig`, Pint.
- Testdiscipline — `superpowers:test-driven-development` skill.
- Performance/refactor-checklists — `react-doctor` + per-stack audit-skills.
