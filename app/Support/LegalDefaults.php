<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Default-teksten voor de publieke juridische pagina's (privacy + voorwaarden).
 * Gedeelde bron zodat zowel de settings-migraties (seed + refresh) als een reset
 * dezelfde markdown gebruiken. De live tekst is daarna beheerbaar via de admin.
 */
final class LegalDefaults
{
    public const UPDATED_AT = '2026-07-20';

    public static function privacyStatement(): string
    {
        return <<<'MARKDOWN'
# Privacyverklaring — Emeq Hub

Deze verklaring beschrijft hoe **Emeq Hub** ("de Hub") persoonsgegevens en
bedrijfsgegevens verwerkt bij het koppelen van boekhoud- en betaal-API's
(Exact Online, Mollie, SnelStart en toekomstige partners).

**Verwerker:** Emeq B.V., Tokyostraat 17, 1175 RB Lijnden. KvK 84148691,
BTW NL863113114B01. Contact: support@emeq.nl.

## 1. Onze rol

De Hub koppelt de administratie van een eindgebruiker aan een partner-API in
opdracht van een aangesloten applicatie (de "Consumer"). Voor de gegevens die
via die koppeling stromen is de Consumer de **verwerkingsverantwoordelijke** en
treedt Emeq op als **verwerker** in de zin van de AVG. Emeq verwerkt die
gegevens uitsluitend volgens de instructies van de Consumer en legt de afspraken
vast in een verwerkersovereenkomst.

## 2. Welke gegevens verwerken wij

- **Koppelingsgegevens** — OAuth access- en refresh-tokens, clientkeys en
  subscription-keys waarmee de Hub namens de eindgebruiker met de partner-API
  praat. Deze worden **versleuteld opgeslagen** (encrypted at rest).
- **Doorgegeven partnergegevens (pass-through)** — de boekhoud- en
  betaalgegevens (facturen, relaties, betalingen) die de Hub tussen de Consumer
  en de partner-API doorzet. Deze worden **niet blijvend opgeslagen**; de Hub
  is een doorgeefluik.
- **Audit-metadata** — per API-call leggen wij metadata vast: methode, endpoint,
  statuscode, duur en tijdstip. **Geen payloads, geen headers, geen
  persoonsgegevens uit de inhoud.**
- **Webhook-metadata** — van inkomende partner-webhooks bewaren wij alleen
  provider, onderwerp, actie en uitkomst — eveneens metadata-only.
- **Accountgegevens** — een technische verwijzing (Consumer-id + external-id)
  om de juiste koppeling bij de juiste eindgebruiker te vinden.

## 3. Doeleinden en grondslag

- **Uitvoeren van de koppeling** die de Consumer en zijn eindgebruiker hebben
  aangevraagd — grondslag: uitvoering van de overeenkomst.
- **Beveiliging, fraudepreventie en incident-triage** — grondslag:
  gerechtvaardigd belang bij een veilig en betrouwbaar platform.
- **Voldoen aan wettelijke verplichtingen** waar die van toepassing zijn.

Wij gebruiken de gegevens niet voor profilering, advertenties of doorverkoop.

## 4. Beveiliging

- Tokens en secrets worden **versleuteld opgeslagen** (AES via de
  Laravel-encryptielaag).
- In logs verschijnen **alleen fingerprints** (sha256, eerste tekens), nooit
  rauwe tokens of secrets.
- Elke koppeling heeft een **eigen webhook-secret**; er is geen gedeelde secret.
- Al het verkeer loopt over **TLS** (versleuteld transport).
- **Strikte multi-tenant-scheiding**: elke koppeling hoort bij precies één
  account bij precies één Consumer; cross-tenant-toegang is technisch
  afgedwongen.
- Toegang tot de productieomgeving is beperkt tot geautoriseerd personeel.

## 5. Bewaartermijnen

- **Audit- en webhook-metadata** — standaard **90 dagen**, daarna automatisch
  verwijderd.
- **Koppelingstokens** — bewaard zolang de koppeling actief is; **verwijderd
  zodra de eindgebruiker of Consumer de koppeling ontkoppelt**.
- **Doorgegeven partnergegevens** — niet bewaard (pass-through).

## 6. Sub-verwerkers

Wij schakelen de volgende sub-verwerkers in:

| Sub-verwerker | Doel | Locatie |
|---|---|---|
| OVH | Hosting van de applicatie en database | EU |
| Cloudflare | Netwerk, CDN en beveiligde tunnel naar de server | EU-verwerking |
| Laravel Nightwatch | Applicatie-monitoring en foutopsporing | EU/VS afhankelijk van plan |

De partner-API's zelf (Exact Online, Mollie, SnelStart) zijn geen
sub-verwerkers van Emeq maar zelfstandige partijen waarmee de eindgebruiker een
eigen relatie heeft.

## 7. Doorgifte buiten de EER

De gegevens worden binnen de Europese Economische Ruimte verwerkt. Waar een
sub-verwerker gegevens buiten de EER zou verwerken, gebeurt dat onder passende
waarborgen (zoals de EU-modelcontractbepalingen).

## 8. Rechten van betrokkenen

Betrokkenen hebben recht op inzage, rectificatie, verwijdering, beperking,
bezwaar en dataportabiliteit. Omdat Emeq als verwerker optreedt, lopen deze
verzoeken in beginsel via de Consumer (de verwerkingsverantwoordelijke); Emeq
verleent daarbij medewerking.

## 9. Verwijderverzoek

Bij ontkoppeling van een koppeling worden de bijbehorende tokens direct
verwijderd; de resterende audit-metadata vervalt binnen 90 dagen. Voor een
expliciet verwijderverzoek kun je contact met ons opnemen (zie hieronder).

## 10. Cookies

De publieke pagina's en de API gebruiken uitsluitend **functionele cookies**
(sessie). Er worden geen tracking- of advertentiecookies geplaatst.

## 11. Klacht

Ben je het niet eens met hoe wij met gegevens omgaan, dan kun je een klacht
indienen bij de **Autoriteit Persoonsgegevens** (autoriteitpersoonsgegevens.nl).

## 12. Contact

Vragen over deze verklaring of over gegevensbescherming? Mail naar
**support@emeq.nl**.
MARKDOWN;
    }

    public static function termsStatement(): string
    {
        return <<<'MARKDOWN'
# Algemene voorwaarden — Emeq Hub

## Artikel 1 — Definities

- **Emeq**: Emeq B.V., de aanbieder van de Hub.
- **Hub / de Dienst**: het integratieplatform Emeq Hub dat koppelingen met
  boekhoud- en betaal-API's (Exact Online, Mollie, SnelStart en toekomstige
  partners) aanbiedt.
- **Consumer / Afnemer**: de (rechts)persoon die via een eigen applicatie op de
  Hub aansluit.
- **Eindgebruiker**: de klant van de Consumer wiens administratie via de Hub
  gekoppeld wordt.
- **Partner-API**: de externe boekhoud- of betaaldienst waarmee de Hub koppelt.

## Artikel 2 — Identiteit van Emeq

Emeq B.V., Tokyostraat 17, 1175 RB Lijnden. KvK 84148691, BTW NL863113114B01.
Contact: support@emeq.nl.

## Artikel 3 — Toepasselijkheid

Deze voorwaarden zijn van toepassing op elk gebruik van de Hub en op elke
overeenkomst tussen Emeq en de Afnemer. Afwijkingen gelden alleen als ze
schriftelijk zijn overeengekomen. Inkoop- of andere voorwaarden van de Afnemer
worden uitdrukkelijk van de hand gewezen.

## Artikel 4 — Totstandkoming van de overeenkomst

De overeenkomst komt tot stand op het moment dat de Afnemer toegang krijgt tot
de Hub (bijvoorbeeld door het aanmaken van een koppeling of het ontvangen van
een access-token) dan wel een aanbod van Emeq aanvaardt.

## Artikel 5 — Gebruik van de Hub

De Afnemer gebruikt de Hub uitsluitend voor het doel waarvoor die is bedoeld en
onthoudt zich van misbruik, van pogingen de beveiliging of multi-tenant-scheiding
te omzeilen, en van gebruik in strijd met wet- of regelgeving of met de
voorwaarden van de betrokken Partner-API's. De Afnemer is verantwoordelijk voor
het geheimhouden van zijn access-tokens en inloggegevens.

## Artikel 6 — Koppelingen met derden

De Hub koppelt met Partner-API's van derden. Emeq is niet verantwoordelijk voor
de beschikbaarheid, juistheid of wijzigingen van die Partner-API's. De
eindgebruiker heeft met elke partner een eigen rechtsverhouding.

## Artikel 7 — Prijzen en wijzigingen

Alle tarieven zijn exclusief btw, tenzij anders vermeld. Emeq mag tarieven
wijzigen; wijzigingen worden vooraf aangekondigd.

## Artikel 8 — Betaling

Betaling geschiedt volgens de tussen partijen overeengekomen wijze. Bij niet
tijdige betaling mag Emeq de toegang tot de Hub opschorten.

## Artikel 9 — Beschikbaarheid en onderhoud

Emeq streeft naar een hoge beschikbaarheid maar garandeert geen ononderbroken
werking. Onderhoud kan tot tijdelijke onderbreking leiden; waar mogelijk kondigt
Emeq gepland onderhoud aan.

## Artikel 10 — Aansprakelijkheid

Emeq is uitsluitend aansprakelijk voor directe schade die het gevolg is van een
toerekenbare tekortkoming, tot ten hoogste het bedrag dat voor de betreffende
dienst in de voorgaande twaalf maanden is betaald. Aansprakelijkheid voor
indirecte schade (waaronder gevolgschade, gederfde winst of dataverlies) is
uitgesloten, behoudens opzet of bewuste roekeloosheid.

## Artikel 11 — Overmacht

Bij overmacht worden de verplichtingen van Emeq opgeschort. Onder overmacht
vallen onder meer storingen bij hosting- of netwerkleveranciers en uitval of
wijziging van Partner-API's.

## Artikel 12 — Intellectueel eigendom

Alle rechten op de Hub, de software en de bijbehorende documentatie berusten bij
Emeq. De Afnemer krijgt een niet-exclusief, niet-overdraagbaar gebruiksrecht voor
de duur van de overeenkomst.

## Artikel 13 — Gegevensverwerking

Voor de verwerking van persoonsgegevens via de Hub gelden de
[privacyverklaring](/privacy) en, waar van toepassing, een verwerkersovereenkomst
tussen Emeq en de Afnemer.

## Artikel 14 — Geheimhouding

Partijen behandelen vertrouwelijke informatie die zij in het kader van de
overeenkomst ontvangen als vertrouwelijk en gebruiken die alleen voor de
uitvoering van de overeenkomst.

## Artikel 15 — Duur en beëindiging

De overeenkomst kan door beide partijen schriftelijk worden opgezegd met
inachtneming van een redelijke opzegtermijn. Emeq mag de overeenkomst met
onmiddellijke ingang beëindigen bij misbruik of een wezenlijke tekortkoming van
de Afnemer.

## Artikel 16 — Klachtenprocedure

Klachten dienen binnen twee maanden na het ontstaan gemotiveerd bij Emeq te
worden gemeld via support@emeq.nl. Emeq reageert binnen een redelijke termijn.

## Artikel 17 — Wijziging van de voorwaarden

Emeq mag deze voorwaarden wijzigen. Gewijzigde voorwaarden worden vooraf
bekendgemaakt en gelden vanaf het aangekondigde moment.

## Artikel 18 — Toepasselijk recht en geschillen

Op deze voorwaarden en op elke overeenkomst is **Nederlands recht** van
toepassing. Geschillen worden voorgelegd aan de bevoegde rechter in het
arrondissement waar Emeq is gevestigd.
MARKDOWN;
    }
}
