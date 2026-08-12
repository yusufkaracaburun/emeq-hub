# Exact App Center — scopes versus code

Momentopname 2026-08-11, tegen de scope-selectie van de live koppeling. Bijgewerkt
2026-08-12: `organization/administration` toegevoegd nadat de webhook-registratie er
live op stuk liep — zie punt 3.

Methode: elk endpoint dat de Hub kan aanroepen komt uit de SDK
(`vendor/emeq/exact-api/src/Http/Request/**`) plus de pass-through-whitelist in
`config/hub-providers.php`. Die lijst is naast de toegekende scopes gelegd.

## Toegekend

**Beheren** — crm/accounts · sales/invoices · purchase/invoices · logistics/items ·
financial/accounting · organization/documents

**Lezen** — financial/currencies · financial/costcenters · financial/generalledgers ·
financial/cashflow

**Aangevraagd, nog niet actief** — organization/administration (Beheren), ingediend
2026-08-12. Wacht op goedkeuring door Exact.

Al het overige staat op *Ongebruikt*.

## Endpoint → scope

| Endpoint | Gebruikt voor | Scope | Status |
|---|---|---|---|
| `crm/Accounts` | relaties lezen, aanmaken, rol promoveren | crm/accounts (Beheren) | ✅ |
| `salesentry/SalesEntries` | verkoop boeken + teruglezen | sales/invoices (Beheren) | ✅ |
| `purchaseentry/PurchaseEntries` | inkoop boeken + teruglezen | purchase/invoices (Beheren) | ✅ |
| `documents/Documents` | bijlage-container | organization/documents (Beheren) | ✅ |
| `documents/DocumentAttachments` | bijlage-upload | organization/documents (Beheren) | ✅ |
| `financial/GLAccounts` | grootboek spiegelen | financial/generalledgers (Lezen) | ✅ alleen-lezen, klopt |
| `financial/CostCenters` | kostenplaatsen spiegelen | financial/costcenters (Lezen) | ✅ alleen-lezen, klopt |
| `financial/Journals` | dagboeken spiegelen | financial/accounting (Beheren) | ✅ |
| `vat/VATCodes` | btw-codes spiegelen | financial/accounting (Beheren) | ✅ |
| `webhooks/WebhookSubscriptions` · `WebhookTopics` | abonnementen beheren | organization/administration (Beheren) | ⚠️ aangevraagd 2026-08-12, wacht op goedkeuring |
| `generaljournalentry/GeneralJournalEntries` | — | financial/accounting (Beheren) | ⚠️ SDK heeft de request, de Hub roept 'm nergens aan |
| `financial/CostUnits` | kostendragers spiegelen | **niet als aparte scope zichtbaar** | ⚠️ verifiëren |

## Drie dingen om op te volgen

### 1. `financial/CostUnits` heeft geen zichtbare scope

Kostenplaatsen staan er wel (`financial/costcenters`), kostendragers niet. Vermoedelijk
vallen die onder `financial/accounting` (toegekend, Beheren), maar dat is een aanname en
die horen we in dit project niet te maken.

Empirisch te beslissen: de Hub roept `GetCostUnits` al aan in productie tegen division
4471372. Zou de scope ontbreken, dan gaf Exact daar een 403. Controleer
`pass_through_calls` op `financial/CostUnits` met status 403, en de logs van
`ExactReferenceData` — die faalt fail-soft naar `[]`, dus een ontbrekende scope zou zich
tonen als een permanent lege kostendrager-lijst en niet als een foutmelding.

### 2. We notificeren over bankmutaties die niemand kan ophalen

`config/services.php` abonneert op de webhook-topics `BankEntries` en `CashEntries`.
Die notificaties gaan door naar de consumer. Maar:

- er is geen SDK-request die bank- of kasmutaties leest;
- `financial/BankEntries` staat niet in de pass-through-whitelist;
- er is geen canoniek lees-endpoint ervoor.

Een Exact-notificatie draagt alleen `Content.Key` en `ExactOnlineEndpoint` — de inhoud
moet je zelf ophalen. Een consumer krijgt dus een seintje "er is een bankmutatie
gewijzigd" en heeft vervolgens geen enkele weg om te zien wélke. De scope
`financial/cashflow` (Lezen) is toegekend, dus het mág wel.

Twee uitwegen, allebei legitiem:
- de topics uitzetten tot er een lees-pad is; of
- `financial/BankEntries` aan de whitelist toevoegen en een canonieke
  `BankTransaction`-resource bouwen (past in het lees-pad van fase 6).

### 3. Webhooks vielen niet onder "app-niveau"

Deze audit noteerde `webhooks/WebhookSubscriptions` als app-niveau: geen aparte scope
nodig. Dat is op 2026-08-12 weerlegd door productie.
`RegisterExactWebhookSubscriptionsJob` faalde op division 4471372 met:

```
403 — Forbidden - Application Scope Violated.
Cannot read 'organization.administration' scope.
```

`Application Scope Violated` is een uitspraak over de app-registratie, niet over het
token. De koppeling zelf is gezond: pass-through en boeken lopen door op hun eigen
scopes; alleen de inkomende change-notificaties liggen stil.

`organization/administration` staat sinds 2026-08-12 op **Beheren** in het App Center.
Beheren en niet Lezen, omdat `ExactWebhookSubscriptionManager` drie kanten op werkt:
`ListWebhookSubscriptions` (GET), `CreateWebhookSubscription` (POST) en
`DeleteWebhookSubscription` (DELETE). Lezen dekt alleen de eerste.

Een retry ná het opslaan (18:21:30) gaf dezelfde 403 — de scope-wijziging wacht op
goedkeuring door Exact. Zodra die er is: de gefaalde job opnieuw aanbieden
(`php artisan queue:retry <uuid>`) en `ListWebhookSubscriptions` narekenen. Blijft het
dan 403, dan is de scope tokengebonden en moet de Connection opnieuw geautoriseerd
worden; dat weten we pas na die eerste retry, dus niet vooraf inplannen.

Elke retry is een Exact-call en telt mee in de error-budget-kill-switch
(`config/hub-providers.php`: 6 fouten per rollend uur). Niet in een poll-loop hangen.

### Ongebruikte toekenningen

`logistics/items` (Beheren) en `financial/currencies` (Lezen) worden nergens
aangeroepen. Geen bezwaar, maar bij een Data & Security-review is "we vragen wat we
gebruiken" een sterker verhaal dan "we vragen vast wat extra". Intrekken kan, tenzij ze
op de roadmap staan.

`financial/cashflow` (Lezen) lijkt ongebruikt maar hangt vermoedelijk aan de
BankEntries/CashEntries-abonnementen — niet intrekken zonder punt 2 op te lossen.

## Zie ook

- `docs/unified-api-architecture.md` — het lees-pad waar een `BankTransaction` in past
- `config/hub-providers.php` — de pass-through-whitelist
- `config/services.php` — de webhook-topics
