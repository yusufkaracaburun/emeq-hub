<?php

/*
 * Compact NL-grootboekschema (RGS-licht, custom) voor emeq's eigen boeken, EUR.
 * `category` wordt afgeleid uit `type` (AccountType::getCategory) — niet dubbel
 * opslaan. `bank => true` koppelt er een BankAccount aan (de cash-kant). BTW-
 * rekeningen splitsen te vorderen (input) en af te dragen (output) per tarief.
 * Codes volgen de gangbare NL-decimale indeling (0/1/4/7-8/9-reeksen).
 */
return [
    'accounts' => [
        // Eigen vermogen (0-reeks)
        ['code' => '0500', 'name' => 'Eigen vermogen', 'type' => 'equity', 'subtype' => 'Eigen vermogen'],

        // Liquide middelen + vorderingen (1-reeks)
        ['code' => '1000', 'name' => 'Kas', 'type' => 'current_asset', 'subtype' => 'Liquide middelen'],
        ['code' => '1100', 'name' => 'Bank', 'type' => 'current_asset', 'subtype' => 'Liquide middelen', 'bank' => true],
        ['code' => '1300', 'name' => 'Debiteuren', 'type' => 'current_asset', 'subtype' => 'Vorderingen'],
        ['code' => '1530', 'name' => 'Te vorderen BTW', 'type' => 'current_asset', 'subtype' => 'BTW'],

        // Kortlopende schulden + BTW af te dragen (1-reeks, liability)
        ['code' => '1600', 'name' => 'Crediteuren', 'type' => 'current_liability', 'subtype' => 'Schulden'],
        ['code' => '1620', 'name' => 'Af te dragen BTW hoog (21%)', 'type' => 'current_liability', 'subtype' => 'BTW'],
        ['code' => '1621', 'name' => 'Af te dragen BTW laag (9%)', 'type' => 'current_liability', 'subtype' => 'BTW'],

        // Kosten (4-reeks)
        ['code' => '4000', 'name' => 'Inkoopwaarde van de omzet', 'type' => 'operating_expense', 'subtype' => 'Inkoop'],
        ['code' => '4400', 'name' => 'Algemene kosten', 'type' => 'operating_expense', 'subtype' => 'Kosten'],
        ['code' => '4500', 'name' => 'Autokosten', 'type' => 'operating_expense', 'subtype' => 'Kosten'],

        // Omzet (8-reeks)
        ['code' => '8000', 'name' => 'Omzet hoog (21%)', 'type' => 'operating_revenue', 'subtype' => 'Omzet'],
        ['code' => '8010', 'name' => 'Omzet laag (9%)', 'type' => 'operating_revenue', 'subtype' => 'Omzet'],
        ['code' => '8020', 'name' => 'Omzet 0% / verlegd', 'type' => 'operating_revenue', 'subtype' => 'Omzet'],
    ],
];
