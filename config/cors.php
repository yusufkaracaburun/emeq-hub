<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Consumer-SPA's praten direct vanuit de browser met de Hub-API (Bearer-PAT,
    | geen cookies). Daarom alleen de `/v1/*`-API openstellen en
    | `supports_credentials` op false. Origins per omgeving toevoegen aan de
    | allowlist hieronder (dev + later staging/prod).
    |
    */

    'paths' => ['v1/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [
        'http://localhost:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Account-Id'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
