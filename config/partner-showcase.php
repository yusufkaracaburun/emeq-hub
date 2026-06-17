<?php

declare(strict_types=1);

/*
 * Publieke /partners-showcase: marketing-copy per provider, gescheiden van de
 * credential-metadata in config/hub-providers.php. Een provider verschijnt op
 * de showcase als hij in BEIDE configs staat (bestaat én heeft copy).
 *
 * Capabilities/connect-stappen/cURL moeten exact kloppen met de echte
 * Hub-endpoints (.ai/rules/global.md: geen verzonnen partner-features). Géén
 * tenant-data hier — puur statische productbeschrijving.
 *
 * Vorm per provider:
 *   label         human-readable provider-naam
 *   tagline       één regel onder de titel
 *   summary       korte alinea
 *   category      'Boekhouden' | 'Betalingen' | …
 *   how_it_works  ?list<string>  uitleg-alinea's over het request-pad (optioneel)
 *   capabilities  list<array{title,description}>
 *   endpoints     ?list<array{method,path,target,description}>  Hub→partner endpoint-kaart (optioneel)
 *   connect_steps list<string>
 *   example_curl  ?string  (null = geen snippet)
 *   docs_url      ?string
 */
return [
    'exact' => [
        'label' => 'Exact Online',
        'tagline' => 'Boekhouden — NL/BE',
        'category' => 'Boekhouden',
        'summary' => 'Koppel je app aan Exact Online-administraties zonder zelf OAuth2, '
            .'token-refresh en division-routing te bouwen. De Hub regelt de koppeling, '
            .'multi-tenant token-opslag en audit-logging.',
        'how_it_works' => [
            'Je app authentiseert met een Bearer-token (Personal Access Token) en geeft per call mee '
                .'om welke eindgebruiker het gaat via de header X-Account-Id. De Hub leidt daaruit de juiste '
                .'Connection af (Consumer → Account → actieve Exact-koppeling) — nooit via een losse connection-id.',
            'Voor die Connection injecteert de Hub de juiste division (administratie) en het OAuth-token, '
                .'en stuurt je verzoek door naar de Exact REST-API. Tokens worden reactief ververst: Exact '
                .'weigert een refresh zolang het access_token nog geldig is, dus de Hub ververst pas bij verloop.',
            'Elke call — named resource én generieke pass-through — wordt vastgelegd in een audit-log '
                .'(methode, geraakte Exact-endpoint, status, duur, response-grootte). Upstream-fouten worden '
                .'naar een uniform Hub-formaat gemapt, zodat je app niet elk Exact-specifiek foutgeval hoeft te kennen.',
        ],
        'use_cases' => [
            [
                'title' => 'Koppelen in één keer, daarna automatisch',
                'value' => 'Je klant geeft de koppeling eenmalig toestemming. Daarna wisselen je app en '
                    .'Exact Online automatisch gegevens uit — niemand hoeft nog in twee systemen bij te werken '
                    .'of gegevens over te typen.',
            ],
            [
                'title' => 'Altijd de juiste Exact-gegevens in je app',
                'value' => 'Grootboekrekeningen, btw-codes, dagboeken en relaties komen rechtstreeks uit de '
                    .'administratie van je klant. Keuzelijsten in je app blijven kloppen, zonder dubbel beheer.',
            ],
            [
                'title' => 'Boekhoudgegevens opvragen wanneer je ze nodig hebt',
                'value' => 'Je app kan gegevens uit de administratie tonen — bijvoorbeeld relaties of facturen — '
                    .'zonder dat je klant Exact Online apart hoeft open te zetten.',
            ],
            [
                'title' => 'Wijzigingen stromen terug naar Exact',
                'value' => 'Een nieuwe relatie of aanpassing vanuit je app belandt direct in de juiste '
                    .'Exact-administratie. De boekhouding blijft actueel zonder extra handwerk.',
            ],
        ],
        'capabilities' => [
            [
                'title' => 'Veilige koppeling per klant',
                'description' => 'Elke klant koppelt zijn eigen Exact-administratie. De toegang wordt '
                    .'versleuteld bewaard en vernieuwt automatisch — je klant hoeft niet telkens opnieuw '
                    .'in te loggen.',
            ],
            [
                'title' => 'Lezen én bijwerken',
                'description' => 'Gegevens uit Exact — grootboek, btw-codes, relaties en dagboeken — zijn '
                    .'zichtbaar in je app én bij te werken vanuit je app, altijd op de juiste administratie '
                    .'van je klant.',
            ],
        ],
        'endpoints' => [
            [
                'method' => 'POST',
                'path' => '/v1/oauth/exact/init',
                'target' => 'start OAuth-flow',
                'description' => 'Maakt een pending Connection en geeft de Exact authorize-URL terug (ability exact:write).',
            ],
            [
                'method' => 'GET',
                'path' => '/v1/oauth/exact/callback',
                'target' => 'OAuth-callback',
                'description' => 'Exact stuurt de eindgebruiker hierheen terug; tokens + division landen encrypted in de Connection.',
            ],
            [
                'method' => 'GET',
                'path' => '/v1/exact/gl-accounts',
                'target' => 'financial/GLAccounts',
                'description' => 'Grootboekrekeningen (read-only). OData-query ($select/$filter/$top) wordt doorgegeven.',
            ],
            [
                'method' => 'GET',
                'path' => '/v1/exact/vat-codes',
                'target' => 'vat/VATCodes',
                'description' => 'BTW-codes (read-only) — de referentie voor de tarief→VATCode-mapping.',
            ],
            [
                'method' => 'GET',
                'path' => '/v1/exact/relations',
                'target' => 'crm/Accounts',
                'description' => 'Relaties / debiteuren-crediteuren (read-only) — de referentie voor de relatie→GUID-mapping.',
            ],
            [
                'method' => 'GET',
                'path' => '/v1/exact/journals',
                'target' => 'financial/Journals',
                'description' => 'Dagboeken (read-only) — de referentie voor de doc-type→dagboek-mapping.',
            ],
            [
                'method' => 'ANY',
                'path' => '/v1/exact/{path}',
                'target' => 'elk Exact OData-endpoint',
                'description' => 'Generieke pass-through: bereik elk overig Exact-endpoint via dezelfde auth + audit.',
            ],
            [
                'method' => 'POST',
                'path' => '/v1/accounting/documents',
                'target' => 'salesinvoice / purchaseentry / generaljournalentry',
                'description' => 'Provider-agnostische boekhoud-sync: POST één canonical FinancialDocument; de Hub mapt naar het juiste Exact-endpoint.',
            ],
        ],
        'connect_steps' => [
            'POST /v1/oauth/exact/init (ability exact:write) — de Hub geeft een authorize-URL terug.',
            'Stuur de eindgebruiker naar Exact; de callback landt op /v1/oauth/exact/callback.',
            'Access- en refresh-token landen encrypted in de Connection; de division wordt automatisch vastgelegd.',
        ],
        'example_curl' => <<<'CURL'
            curl -X POST {APP_URL}/v1/oauth/exact/init \
              -H "Authorization: Bearer {PAT}" \
              -H "Content-Type: application/json" \
              -d '{"account_external_id":"school1"}'
            CURL,
        'docs_url' => null,
    ],

    'mollie' => [
        'label' => 'Mollie',
        'tagline' => 'Betalingen — NL/EU',
        'category' => 'Betalingen',
        'summary' => 'Handel Mollie-betalingen, mandaten en subscriptions af via één Connect-koppeling. '
            .'De Hub regelt de OAuth-flow, multi-tenant token-opslag, webhook-fanout en audit-logging.',
        'capabilities' => [
            [
                'title' => 'OAuth Connect-broker',
                'description' => 'OAuth-koppeling per Account met automatische token-refresh, encryption-at-rest '
                    .'en per-Connection webhook-secret.',
            ],
            [
                'title' => 'Payments & resources',
                'description' => 'Payments, Customers, Mandates, Refunds, Payment-links en Payment-methods via '
                    .'de /v1/mollie/*-endpoints.',
            ],
            [
                'title' => 'Subscriptions',
                'description' => 'Customer-subscriptions via de Hub plus Account-level subscriptions voor het '
                    .'factureren van je eigen eindgebruikers.',
            ],
            [
                'title' => 'Webhook-fanout',
                'description' => 'Inkomende Mollie-webhooks worden geverifieerd en doorgezet naar je '
                    .'consumer-callback-URL, met audit per call.',
            ],
        ],
        'connect_steps' => [
            'POST /v1/oauth/mollie/init (ability mollie:write) — de Hub geeft een authorize-URL terug.',
            'Stuur de eindgebruiker naar Mollie; na goedkeuring landt het access_token encrypted in de Connection.',
            'Maak Payments/Customers/Subscriptions via de /v1/mollie/*-endpoints.',
        ],
        'example_curl' => <<<'CURL'
            curl -X POST {APP_URL}/v1/mollie/payments \
              -H "Authorization: Bearer {PAT}" \
              -H "Content-Type: application/json" \
              -d '{"account_external_id":"school1","amount":{"currency":"EUR","value":"10.00"},"description":"Order #12"}'
            CURL,
        'docs_url' => null,
    ],

    'snelstart' => [
        'label' => 'SnelStart',
        'tagline' => 'Boekhouden — NL',
        'category' => 'Boekhouden',
        'summary' => 'Lees en werk SnelStart-administraties bij zonder zelf de B2B-API te implementeren. '
            .'De Hub regelt credential-opslag (encrypted at rest) en audit-logging.',
        'capabilities' => [
            [
                'title' => 'Key-based koppeling',
                'description' => 'Koppel met clientkey + subscription-key via /v1/connections; de Hub '
                    .'encrypt de credentials at rest en bewaart alleen de fingerprint voor debugging.',
            ],
            [
                'title' => 'Pass-through',
                'description' => 'Elk SnelStart B2B-endpoint via één route (ANY /v1/snelstart/{path}); de Hub '
                    .'voegt auth toe, logt elke call en mapt upstream-fouten uniform.',
            ],
        ],
        'connect_steps' => [
            'Vraag bij SnelStart de credentials op (client key, subscription key, subscription ID).',
            'POST /v1/connections met provider=snelstart en de drie velden.',
            'De Hub encrypt de credentials at rest; daarna routeer je calls via /v1/snelstart/{path}.',
        ],
        'example_curl' => <<<'CURL'
            curl -X POST {APP_URL}/v1/connections \
              -H "Authorization: Bearer {PAT}" \
              -H "Content-Type: application/json" \
              -d '{"account_external_id":"school1","provider":"snelstart","client_key":"…","subscription_key":"…","subscription_id":"…"}'
            CURL,
        'docs_url' => null,
    ],
];
