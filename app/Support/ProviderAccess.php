<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Provider;

/**
 * Leesbare uitleg bij wat een koppeling bij de partner mag. Twee bronnen, want
 * de providers regelen toegang verschillend:
 *
 *  - Mollie geeft de goedgekeurde scopes mee in de token; die staan per
 *    Connection in `scopes`.
 *  - Exact geeft géén scopes mee. Wat bereikbaar is volgt uit de App-Center-
 *    registratie en de rechten van de gebruiker die koppelde; de Hub beperkt
 *    zichzelf daarbovenop tot de whitelist in `config/hub-providers.php`.
 *  - Snelstart authenticeert met clientkey + subscriptionkey en kent geen
 *    scopes.
 *
 * De resource-lijst komt uit dezelfde config die de pass-through afdwingt, zodat
 * het scherm niet uit de pas kan lopen met wat er werkelijk doorgelaten wordt.
 */
final class ProviderAccess
{
    /** @var array<string, string> */
    private const SCOPE_DESCRIPTIONS = [
        'payments.read' => 'Betalingen inzien',
        'payments.write' => 'Betalingen aanmaken, wijzigen en terugbetalen',
        'customers.read' => 'Klanten inzien',
        'customers.write' => 'Klanten aanmaken en wijzigen',
        'subscriptions.read' => 'Abonnementen inzien',
        'subscriptions.write' => 'Abonnementen aanmaken, wijzigen en stoppen',
        'mandates.read' => 'Incassomachtigingen inzien',
        'organizations.read' => 'Organisatiegegevens inzien',
        'onboarding.read' => 'Onboarding-status van de organisatie inzien',
    ];

    /** @var array<string, string> */
    private const RESOURCE_DESCRIPTIONS = [
        'crm/Accounts' => 'Relaties — klanten en leveranciers',
        'financial/GLAccounts' => 'Grootboekrekeningen',
        'financial/Journals' => 'Dagboeken',
        'financial/CostCenters' => 'Kostenplaatsen',
        'financial/CostUnits' => 'Kostendragers',
        'vat/VATCodes' => 'Btw-codes',
        'salesentry/SalesEntries' => 'Verkoopboekingen',
        'purchaseentry/PurchaseEntries' => 'Inkoopboekingen',
        'documents/Documents' => 'Documenten — de container van een bijlage',
        'documents/DocumentAttachments' => 'Bestanden die aan een document hangen',
        'financialtransaction/BankEntries' => 'Bankboekingen',
        'financialtransaction/CashEntries' => 'Kasboekingen',
        'webhooks/WebhookSubscriptions' => 'Webhook-abonnementen van de Hub',
    ];

    /**
     * Verleende scopes met hun uitleg, in de volgorde waarin de partner ze
     * teruggaf. Een onbekende scope verdwijnt niet: die krijgt een lege uitleg,
     * anders zou het scherm toegang verbergen die wél verleend is.
     *
     * @param  list<string>|null  $scopes
     * @return array<string, string>
     */
    public static function describeScopes(?array $scopes): array
    {
        $described = [];

        foreach ($scopes ?? [] as $scope) {
            $described[$scope] = self::SCOPE_DESCRIPTIONS[$scope] ?? '';
        }

        return $described;
    }

    /**
     * De partner-resources die de pass-through voor deze provider doorlaat, met
     * uitleg. Leeg wanneer er geen whitelist is (dan geldt geen beperking vanuit
     * de Hub) of wanneer de provider geen pass-through kent.
     *
     * @return array<string, string>
     */
    public static function describeResources(Provider $provider): array
    {
        /** @var list<string> $paths */
        $paths = config("hub-providers.{$provider->value}.allowed_paths", []);

        $described = [];

        foreach ($paths as $path) {
            $described[$path] = self::RESOURCE_DESCRIPTIONS[$path] ?? '';
        }

        return $described;
    }

    /**
     * Uitleg over hoe deze partner toegang regelt — zonder die zin leest een
     * lege scope-lijst als "geen toegang", wat bij Exact en Snelstart onjuist is.
     */
    public static function note(Provider $provider): string
    {
        return match ($provider) {
            Provider::Mollie => 'Mollie geeft de goedgekeurde scopes mee in de token. De organisatie heeft deze bij het koppelen zelf geaccordeerd.',
            Provider::Exact => 'Exact geeft geen scopes mee in de token. Wat bereikbaar is volgt uit de App Center-registratie van de Hub en de rechten van de Exact-gebruiker die gekoppeld heeft. De Hub laat daarbovenop alleen onderstaande resources door.',
            Provider::Snelstart => 'Snelstart authenticeert met een clientkey en een subscriptionkey en kent geen scopes. Wat de koppeling mag, volgt uit het Snelstart-abonnement van de klant.',
        };
    }
}
