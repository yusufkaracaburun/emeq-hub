# Engineering Rules — emeq-hub

## Denken vóór schrijven

- Assumpties expliciet maken, niet stilzwijgend kiezen. Twee lezingen van een spec of van
  bestaande code? Benoem beide, kies er één, zeg waarom.
- Verwarring niet wegmoffelen in code die "waarschijnlijk goed" is.
- Onduidelijk = eerst onderzoeken in code, tests, documentatie en git history. Alleen vragen
  wanneer de ambiguïteit daarna nog bestaat en de keuze materiële gevolgen heeft.
- Bewijs uit de codebase gaat vóór redenering over de codebase. Ga kijken.
- Duwt een opdracht richting onnodige complexiteit, zeg dat vóórdat je 'm uitvoert.

## Eenvoud eerst

- Bouw het minimum dat het echte probleem oplost. Geen speculatieve features.
- Geen abstractie zonder aangetoonde noodzaak. Eén implementatie is op zichzelf onvoldoende
  reden voor een generieke abstractie. Abstracteer vroeg alleen wanneer er al een duidelijke
  stabiele contractgrens of concrete tweede use-case bestaat.
- Onderscheid een echt schaalprobleem van een theoretisch: is het gemeten, of bedacht?
- Toets vóór afleveren: zou een senior engineer dit over-engineered noemen? Zo ja, versimpel.

## Verifieerbaar doel

- Elke niet-triviale wijziging of aanbeveling krijgt een succescriterium dat falsifieerbaar is.
  "Provider-resolutie is generiek" is er geen; "een tweede adapter registreren vergt nul
  wijzigingen in `app/Http/Controllers/Api/V1/Accounting/*`" wel.
- Bij voorkeur uitgedrukt als test. Anders als concreet waarneembaar feit.

## Chirurgisch wijzigen

- Raak alleen wat moet voor de taak. Geen "improvements" in adjacent code, comments of formatting.
- Geen refactor van werkende code mee in een bug-fix of feature-PR.
- Match bestaande style — ook als je 'm niet mooi vindt. Conformance > smaak.
- SDK-grenzen niet doorbreken om snel klaar te zijn: Hub-domeinmodellen horen NIET in een
  SDK-package. Liever taak splitsen dan de scheiding zachter maken.

## Conflicten oppervlakken, niet uitmiddelen

- Bij twee tegenstrijdige patronen in de codebase: kies één (meest recent / meest getest /
  matcht de actieve refactor-richting). Niet blenden.
- Leg uit waaróm je die kant kiest in commit-msg of PR-body.
- Meld het andere patroon in de eindrapportage/PR-body. Voeg alleen een TODO in code toe als
  die TODO direct relevant is voor de huidige wijziging.

## Lezen vóór schrijven

- Voor nieuwe code: lees eerst exports, directe callers en gedeelde utilities die je raakt.
- "Lijkt orthogonaal" is een rode vlag — weet je niet waarom code zo opgebouwd is, zoek het uit
  (git blame, tests, ADR's) en vraag pas als het dan nog onduidelijk is.
- Partner-API werk: lees de officiële partner-docs vóór je een endpoint of payload-shape
  verzint. Snelstart's foutcodes, Mollie's Connect-flows, etc. hebben subtiele afwijkingen van
  wat in OSS-SDKs zit.
- SDK-werk: de SDK draait via Composer-VCS (zie packages-conventie). Lees
  `vendor/emeq/<naam>/` voor de versie die nu draait, of clone als referentie naar
  `packages/<naam>/`. Edits gebeuren in de eigen SDK-repo en landen in de Hub via
  `composer update emeq/<naam>`.

## Wat NIET hier hoort

- Stylistische conventies (formatting, naming) — die staan in `.editorconfig`, Pint.
- Testdiscipline — `superpowers:test-driven-development` skill.
- Performance/refactor-checklists — `react-doctor` + per-stack audit-skills.