<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Provider;

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
        'generaljournalentry/GeneralJournalEntries' => 'Memoriaalboekingen',
        'webhooks/WebhookSubscriptions' => 'Webhook-abonnementen van de Hub',
    ];

    /**
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

    public static function note(Provider $provider): string
    {
        return match ($provider) {
            Provider::Mollie => 'Mollie geeft de goedgekeurde scopes mee in de token. De organisatie heeft deze bij het koppelen zelf geaccordeerd.',
            Provider::Exact => 'Exact geeft geen scopes mee in de token. Wat bereikbaar is volgt uit de App Center-registratie van de Hub en de rechten van de Exact-gebruiker die gekoppeld heeft. De Hub laat daarbovenop alleen onderstaande resources door.',
            Provider::Snelstart => 'Snelstart authenticeert met een clientkey en een subscriptionkey en kent geen scopes. Wat de koppeling mag, volgt uit het Snelstart-abonnement van de klant.',
        };
    }
}
