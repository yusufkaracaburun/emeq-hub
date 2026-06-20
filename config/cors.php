<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Consumer-SPA's praten direct vanuit de browser met de Hub-API. Auth is
    | altijd een Bearer-PAT (`Authorization`-header), geen cookies — daarom
    | `supports_credentials` op false.
    |
    | Origins: elke https-origin mag de /v1-API aanroepen, plus de expliciete
    | dev-hosts uit CORS_ALLOWED_ORIGINS (csv, default localhost:3000 over http).
    | Bewuste keuze: voor een token-API is CORS niet de security-grens — de PAT
    | is dat. Een vreemde origin zonder geldige PAT kan niets, dus per-consumer
    | origin-scoping (emeq.nl, planny.nl, …) levert geen winst op en zou bij elke
    | nieuwe consumer config-onderhoud vragen. Wel https afdwingen (geen
    | plain-http behalve de expliciete dev-hosts).
    |
    */

    'paths' => ['v1/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')),
    ))),

    'allowed_origins_patterns' => ['#^https://#i'],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Account-Id'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
