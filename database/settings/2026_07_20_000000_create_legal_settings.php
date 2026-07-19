<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('legal.privacy_statement', $this->defaultPrivacyStatement());
        $this->migrator->add('legal.privacy_updated_at', '2026-07-20');
    }

    private function defaultPrivacyStatement(): string
    {
        return <<<'MARKDOWN'
# Privacyverklaring — Emeq Hub

Deze verklaring beschrijft hoe **Emeq Hub** ("de Hub") persoonsgegevens en
bedrijfsgegevens verwerkt bij het koppelen van boekhoud- en betaal-API's
(Exact Online, Mollie, SnelStart en toekomstige partners).

> **Bedrijfsgegevens — nog aanvullen:** Emeq, rechtsvorm en KvK-nummer, vestigingsadres.

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
| E-mailprovider *(nog aanvullen)* | Transactionele e-mail | nog aanvullen |

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
**info@emeq.nl**.
MARKDOWN;
    }
};
