# Handmatige API-requests per partner

Eén `.http`-bestand per partner/provider, voor handmatig testen vanuit de IDE
(JetBrains HTTP Client of VS Code "REST Client"-extensie) tegen de lokale Hub.

- `dataforseo.http` — DataForSEO
- nog toe te voegen, zelfde patroon: `snelstart.http`, `mollie.http`,
  `exact.http` (voor uitgebreidere Exact-scenario's tegen productie bestaat
  al `docs/exact/live-scenarios.http` — dat is een apart, ouder bestand, geen
  onderdeel van deze conventie)

## Conventie per bestand

- Bovenaan `@baseUrl`, `@token = {{$dotenv EMEQ_HUB_PAT}}`, `@accountId`.
- De PAT staat in je lokale `.env` (root van dit project), nooit in het
  `.http`-bestand zelf — dat staat in git.
- Minstens één werkend voorbeeld per endpoint, plus de belangrijkste
  foutpaden (ontbrekende parameter, geen PAT).

## Thunder Client (VS Code)

Zelfde requests, als Thunder Client-collectie i.p.v. `.http`-bestand — voor
wie de Thunder Client-extensie i.p.v. REST Client gebruikt.

- `<partner>.thunderclient.json` — één Thunder Client-collectie-export per
  partner, zelfde "één bestand per partner"-conventie als de `.http`'s.
  `dataforseo.thunderclient.json` is de eerste.
- `emeq-hub.thunderclient-env.json` — één gedeelde Thunder Client-omgeving
  (`baseUrl`, `accountId`, `pat`) voor alle partner-collecties. Niet
  per-partner: dat zijn dezelfde lokale dev-waarden voor elke provider,
  net als de herhaalde `@baseUrl`/`@accountId` bovenaan elk `.http`-bestand.
  `pat` staat als leeg, `secret: true`-veld in git — vul 'm lokaal in na
  import, nooit committen.
- Importeren: Thunder Client-icoon in de sidebar → Collections → Import
  (kies het `.thunderclient.json`-bestand) én Env → Import (kies het
  `.thunderclient-env.json`-bestand). Geen VS Code-instellingen nodig —
  dit is Thunder Client's portable export-formaat, geen Git Sync
  workspace-folder.
