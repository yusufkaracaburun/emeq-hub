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
