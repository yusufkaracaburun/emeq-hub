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
 *   logo          ?string  pad naar officieel SVG-logo (bv. '/img/partners/exact.svg').
 *                 null = de UI toont een merk-veilige monogram-tegel tot het echte
 *                 logo in public/img/partners/ staat.
 *   brand         ?string  hex-accent uit de huisstijl (bv. '#E2001A'). null = amber-fallback.
 *   live          ?bool    true = productie-klaar; alleen deze partners staan in de
 *                 "Nu live met"-strip op de homepage. Ontbreekt = false.
 *   capabilities  list<array{title,description}>
 *   endpoints     ?list<array{method,path,target,description}>  Hub→partner endpoint-kaart (optioneel)
 *   connect_steps list<string>
 *   example_curl  ?string  (null = geen snippet)
 *   docs_url      ?string  officiële API-/developer-documentatie van de partner
 *   meta_description ?string  ~155 tekens voor de zoekresultaat-snippet.
 *                 Ontbreekt = summary afgekapt, wat midden in een woord kan
 *                 eindigen. Voor live providers dus invullen.
 *   website_url   ?string  officiële partner-website
 *   support_url   ?string  officieel support-portaal van de partner
 */
return [
    'exact' => [
        'label' => 'Exact Online',
        'tagline' => 'Boekhouden — NL/BE',
        'category' => 'Boekhouden',
        'logo' => '/img/partners/exact.svg',
        'brand' => '#e1141d',
        'live' => true,
        'summary' => 'Verwerk verkoop- en inkoopboekingen, relaties en grootboekgegevens '
            .'betrouwbaar en realtime.',
        'headline' => 'Exact Online koppelen via één API',
        'intro' => 'Synchroniseer verkoop- en inkoopboekingen, relaties en grootboekgegevens '
            .'via één API. Emeq Hub beheert OAuth, tokens en audit; jouw team bouwt door.',
        'features' => [
            ['icon' => 'file-text', 'tag' => 'SalesEntry', 'title' => 'Verkoopfacturen', 'description' => 'Boek verkoopfacturen direct als SalesEntry in Exact.'],
            ['icon' => 'receipt', 'tag' => 'PurchaseEntry', 'title' => 'Inkoopfacturen', 'description' => 'Inkoopboekingen als PurchaseEntry, inclusief bijlagen.'],
            ['icon' => 'users', 'tag' => 'Accounts', 'title' => 'Relaties', 'description' => 'Debiteuren en crediteuren automatisch resolve-or-learn.'],
            ['icon' => 'book-open', 'tag' => 'GLAccounts', 'title' => 'Grootboek & kostenplaats', 'description' => 'GL-accounts, kostenplaatsen en kostendragers.'],
            ['icon' => 'percent', 'tag' => 'VATCodes', 'title' => 'Verlegde btw', 'description' => 'VAT-codes voor verlegde btw worden automatisch gemapt.'],
            ['icon' => 'webhook', 'tag' => 'Webhooks', 'title' => 'Webhooks & audit', 'description' => 'Realtime events en een volledige, immutable audit-trail.'],
        ],
        'steps' => [
            ['title' => 'Koppel via OAuth', 'description' => 'Eén klik — de gebruiker autoriseert Exact Online.'],
            ['title' => 'Emeq Hub host de tokens', 'description' => 'Token-lifecycle, refresh en webhooks regelen wij.'],
            ['title' => 'Eén REST-API', 'description' => 'POST /v1/accounting/documents. Klaar.'],
        ],
        'connect_pitch' => 'Maak je financiële integratie schaalbaar. Wij regelen de infrastructuur; '
            .'jij levert de workflow.',
        'meta_description' => 'Koppel je app aan Exact Online zonder zelf OAuth2, token-refresh en '
            .'division-routing te bouwen. De Hub regelt koppeling, tokenopslag en audit-logging.',
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
                'description' => 'Start de OAuth-flow en geeft de authorize-URL terug',
            ],
            [
                'method' => 'GET',
                'path' => '/v1/oauth/exact/callback',
                'target' => 'OAuth-callback',
                'description' => 'Exact stuurt de eindgebruiker hierheen terug',
            ],
            [
                'method' => 'GET',
                'path' => '/v1/exact/gl-accounts',
                'target' => 'financial/GLAccounts',
                'description' => 'Grootboekrekeningen',
            ],
            [
                'method' => 'GET',
                'path' => '/v1/exact/vat-codes',
                'target' => 'vat/VATCodes',
                'description' => 'Btw-codes',
            ],
            [
                'method' => 'GET',
                'path' => '/v1/exact/relations',
                'target' => 'crm/Accounts',
                'description' => 'Relaties, debiteuren en crediteuren',
            ],
            [
                'method' => 'GET',
                'path' => '/v1/exact/journals',
                'target' => 'financial/Journals',
                'description' => 'Dagboeken',
            ],
            [
                'method' => 'ANY',
                'path' => '/v1/exact/{path}',
                'target' => 'elk Exact OData-endpoint',
                'description' => 'Elk overig Exact-endpoint via dezelfde auth en audit',
            ],
            [
                'method' => 'POST',
                'path' => '/v1/accounting/documents',
                'target' => 'salesentry / purchaseentry / generaljournalentry',
                'description' => 'Provider-onafhankelijke boekhoud-sync',
            ],
        ],
        'integration' => [
            [
                'title' => 'Vraag een Hub-koppeling aan',
                'description' => 'Je krijgt van Emeq Hub een Personal Access Token met de ability exact:write. '
                    .'Daarmee praat je app met de Hub — je bouwt zelf geen Exact-app, geen OAuth en geen token-opslag.',
            ],
            [
                'title' => 'Laat je klant zijn administratie koppelen',
                'description' => 'Start de koppeling per eindgebruiker via /v1/oauth/exact/init en stuur de klant naar Exact. '
                    .'De Hub bewaart de tokens en de gekozen administratie versleuteld. In je eigen systeem leg je alleen '
                    .'je account-id vast — dat geef je bij elke call mee als X-Account-Id.',
            ],
            [
                'title' => 'Vul de boekhoud-mapping in',
                'description' => 'Leg per koppeling één keer vast hoe jouw gegevens op die administratie landen: relatie → '
                    .'Exact-relatie, btw-tarief → btw-code, categorie → grootboekrekening, documentsoort → dagboek. '
                    .'De keuzelijsten komen rechtstreeks uit de administratie van je klant. Ontbreekt een mapping, dan '
                    .'weigert de Hub de boeking met een duidelijke melding in plaats van fout te boeken.',
            ],
            [
                'title' => 'Vertaal je documenten naar het Hub-formaat',
                'description' => 'Je stuurt geen Exact-velden. Map je eigen factuur of boeking naar één gestandaardiseerd '
                    .'document — documentsoort, relatie, regels (omschrijving, bedrag, btw-tarief, categorie), datum — en '
                    .'POST dat naar /v1/accounting/documents met een Idempotency-Key-header (UUID per document) — een '
                    .'herhaalde verzending met dezelfde key boekt niet dubbel. De Hub buigt het document naar het juiste '
                    .'Exact-endpoint; Exact-veldnamen hoef je niet te kennen.',
            ],
            [
                'title' => 'Stuur brondocumenten, geen betalingen',
                'description' => 'Verkoopfacturen, inkoopfacturen en losse inkomsten of uitgaven stuur je door. De betaling of '
                    .'afhandeling van een al-doorgestuurd document stuur je niet — die verwerkt Exact via de bankkoppeling. '
                    .'Zo komt omzet of kosten nooit dubbel in de boekhouding.',
            ],
        ],
        'connect_steps' => [
            'POST /v1/oauth/exact/init (ability exact:write) — de Hub geeft een authorize-URL terug.',
            'Stuur de eindgebruiker naar Exact; de callback landt op /v1/oauth/exact/callback.',
            'Access- en refresh-token landen encrypted in de Connection; de division wordt automatisch vastgelegd.',
        ],
        'example_curl' => <<<'CURL'
            curl https://hub.emeq.nl/v1/exact/gl-accounts \
              -H "Authorization: Bearer $EMEQ_PAT" \
              -H "X-Account-Id: klant-2841" \
              -H "Accept: application/json"
            CURL,
        // De REST-API-resourcelijst, niet de algemene kennisbank — dit veld is de
        // developer-doc-link; support_url dekt de kennisbank al.
        'docs_url' => 'https://start.exactonline.nl/docs/HlpRestAPIResources.aspx',
        'website_url' => 'https://www.exact.com/nl',
        'support_url' => 'https://support.exactonline.com',
    ],

    'mollie' => [
        'label' => 'Mollie',
        'tagline' => 'Betalingen — NL/EU',
        'category' => 'Betalingen',
        'logo' => '/img/partners/mollie.svg',
        'brand' => '#000000',
        'summary' => 'Handel Mollie-betalingen, mandaten en subscriptions af via één Connect-koppeling. '
            .'De Hub regelt de OAuth-flow, multi-tenant token-opslag, webhook-fanout en audit-logging.',
        'meta_description' => 'Handel Mollie-betalingen, mandaten en subscriptions af via één '
            .'Connect-koppeling. De Hub regelt OAuth, tokenopslag, webhook-fanout en audit-logging.',
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
        'docs_url' => 'https://docs.mollie.com',
        'website_url' => 'https://www.mollie.com/nl',
        // help.mollie.com blokkeert geautomatiseerde requests (403) — niet als
        // publieke link opnemen zolang we 'm niet kunnen verifiëren.
        'support_url' => null,
    ],

    // SnelStart bewust niet in de showcase: de integratie is niet gebouwd en er
    // is geen partnercontract — de publieke surface toont alleen Exact en Mollie.
];
