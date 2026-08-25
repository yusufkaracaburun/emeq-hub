# Verkorte handleiding "Starten met Mijn Kadaster KLIC"

Transcriptie van de instructie die het Kadaster meestuurde bij de registratiebevestiging voor
klantnummer 2583859 (Emeq BV). Bewaard naast `2026_Instructiebrief_eHerkenning.pdf` omdat de
inhoud het aansluitscenario raakt — zie `.docs/decisions/kadaster-klic-provider.md`, beslissing 0.

Bron: Kadaster, gericht aan de grondroerder. Alleen de tekst is overgenomen; er is niets
geïnterpreteerd of aangevuld.

## Eerste beheerder — inloggen

- Log in op Mijn Kadaster met de inlogcode en het wachtwoord die per post en per e-mail zijn
  verstuurd.
- **De eerste beheerder moet eHerkenning op minimaal niveau EH3 hebben.**

## Diensten selecteren bij de eerste inlog

- Ga naar **Beheer applicaties** en vink alle applicaties aan die met KLIC beginnen. Daar worden
  ook per applicatie de gebruikers beheerd.
- Op het **Dashboard** kunnen maximaal vier applicaties als favoriet worden vastgezet.
- Wachtwoord wijzigen gaat via **Profielinstellingen**, rechtsboven onder de eigen naam.

## Extra beheerders en gebruikers aanmaken

Aanbeveling van het Kadaster: geef één of twee gebruikers beheerrechten voor het geval de
beheerder afwezig is.

- Ga naar **Gebruikers** → onderaan **Gebruiker toevoegen**.
- Vul een unieke gebruikersnaam en een wachtwoord in, plus naam, telefoonnummer en e-mailadres.
- Vink de benodigde KLIC-diensten aan en klik op **Gebruiker aanmaken**.
- *"Als uw collega nu inlogt met zijn eigen gegevens komen deze ook op de KLIC-meldingen te
  staan."*
- *"LET OP: een beheerder heeft ook eHerkenning (op niveau EH3) nodig."*

## Kosten

*"Gebruikt u meerdere diensten in Mijn Kadaster dan kost dat extra."* Er staat geen bedrag bij.
Deze zin staat in de aangeleverde tekst verminkt tussen de kopjes "Meer informatie?" en
"Vragen?"; de strekking komt overeen met de LET OP-regel die het Kadaster elders voert.

## Tips bij een KLIC-melding

Het Kadaster verwijst naar zijn eigen tips in Mijn Kadaster, onder meer over het gebruik van een
extra e-mailadres en over het intekenen van het graafgebied en het opvragen van
huisaansluitingen.

## Contact

Kadaster KLIC, maandag t/m vrijdag 08:00-17:00, telefoon 0800-0080, e-mail klic@kadaster.nl.

## Wat dit betekent voor de Hub

1. **Diensten activeren is een klantstap die wij niet kunnen zetten.** Zonder de aangevinkte
   KLIC-applicaties onder *Beheer applicaties* heeft de grondroerder geen KLIC-dienst en faalt de
   koppeling op iets buiten de Hub. Hoort als voorwaarde in `docs/consumer-onboarding.md` en
   verdient een herkenbare fout op de connect-landing.
2. **De melding draagt de identiteit van de inloggende gebruiker.** Onder het interactieve
   scenario staat dus de persoon op de melding die de koppeling autoriseert, niet de organisatie.
3. **EH3 is een beheerderseis, geen gebruikerseis.** Operationele gebruikers van een klant kunnen
   koppelen met een gewoon Mijn Kadaster-account.
