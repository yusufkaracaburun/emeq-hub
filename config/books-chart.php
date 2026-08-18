<?php

return [
    'accounts' => [
        ['code' => '0500', 'name' => 'Eigen vermogen', 'type' => 'equity', 'subtype' => 'Eigen vermogen'],

        ['code' => '1000', 'name' => 'Kas', 'type' => 'current_asset', 'subtype' => 'Liquide middelen'],
        ['code' => '1100', 'name' => 'Bank', 'type' => 'current_asset', 'subtype' => 'Liquide middelen', 'bank' => true],
        ['code' => '1300', 'name' => 'Debiteuren', 'type' => 'current_asset', 'subtype' => 'Vorderingen'],
        ['code' => '1530', 'name' => 'Te vorderen BTW', 'type' => 'current_asset', 'subtype' => 'BTW'],

        ['code' => '1600', 'name' => 'Crediteuren', 'type' => 'current_liability', 'subtype' => 'Schulden'],
        ['code' => '1620', 'name' => 'Af te dragen BTW hoog (21%)', 'type' => 'current_liability', 'subtype' => 'BTW'],
        ['code' => '1621', 'name' => 'Af te dragen BTW laag (9%)', 'type' => 'current_liability', 'subtype' => 'BTW'],

        ['code' => '4000', 'name' => 'Inkoopwaarde van de omzet', 'type' => 'operating_expense', 'subtype' => 'Inkoop'],
        ['code' => '4400', 'name' => 'Algemene kosten', 'type' => 'operating_expense', 'subtype' => 'Kosten'],
        ['code' => '4500', 'name' => 'Autokosten', 'type' => 'operating_expense', 'subtype' => 'Kosten'],

        ['code' => '8000', 'name' => 'Omzet hoog (21%)', 'type' => 'operating_revenue', 'subtype' => 'Omzet'],
        ['code' => '8010', 'name' => 'Omzet laag (9%)', 'type' => 'operating_revenue', 'subtype' => 'Omzet'],
        ['code' => '8020', 'name' => 'Omzet 0% / verlegd', 'type' => 'operating_revenue', 'subtype' => 'Omzet'],
    ],
];
