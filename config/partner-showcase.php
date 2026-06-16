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
 *   capabilities  list<array{title,description}>
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
        'capabilities' => [
            [
                'title' => 'OAuth Connect-broker',
                'description' => 'OAuth2-koppeling per Account met reactieve refresh-token-rotatie '
                    .'(Exact weigert een refresh zolang het access_token nog geldig is) en encryption-at-rest.',
            ],
            [
                'title' => 'Division-aware pass-through',
                'description' => 'Elk Exact REST-endpoint via één route; de Hub injecteert de juiste '
                    .'division en token, logt elke call en mapt upstream-fouten naar een uniform formaat.',
            ],
            [
                'title' => 'Boekhoud-sync',
                'description' => 'POST één canonical FinancialDocument; de Hub mapt naar salesinvoice, '
                    .'purchaseentry of generaljournalentry. Per-Connection mapping (VATCode, GLAccount, '
                    .'relatie, dagboek) is instelbaar.',
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
