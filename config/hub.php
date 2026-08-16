<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data-retentie
    |--------------------------------------------------------------------------
    |
    | Bewaartermijn (dagen) voor de append-only audit-tabellen. Het dagelijkse
    | `model:prune` verwijdert rijen ouder dan dit venster via de MassPrunable-
    | modellen PassThroughCall (created_at) en InboundWebhookEvent (received_at).
    | 0 = pruning uit (onbegrensd bewaren). Het definitieve bewaartermijn-beleid
    | is issue #41; deze sleutels zijn de code-haak waar dat beleid een getal zet.
    |
    */

    'retention' => [
        'pass_through_days' => (int) env('RETENTION_PASS_THROUGH_DAYS', 90),
        'webhook_days' => (int) env('RETENTION_WEBHOOK_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotentie
    |--------------------------------------------------------------------------
    |
    | `lease_seconds` is hoe lang een lopend request zijn Idempotency-Key claimt.
    | Verloopt de lease, dan mag een volgend request de claim overnemen omdat het
    | vorige kennelijk gestorven is.
    |
    | INVARIANT: de lease MOET langer zijn dan de langst mogelijke request-duur.
    | Te lang kost niets: de `Retry-After` op een 409 wordt apart afgetopt (zie
    | `IdempotencyKey::RETRY_AFTER_CEILING_SECONDS`), dus de lease bepaalt alleen
    | wanneer we een request dood verklaren — niet hoe lang een consumer wacht.
    | Te kort is wél gevaarlijk: een traag-maar-levend request verliest zijn claim
    | en de retry boekt dubbel. Bij twijfel dus ruimer.
    |
    | De rekensom achter 900: `exact.http.timeout` is 30s per call. Eén boeking is
    | in het slechtste geval token-refresh (10s) + relatie-lookup + aanmaken +
    | rolwijziging + de boeking zelf (4×30s), plus 2 calls per bijlage (60s). Met
    | het maximum van 10 bijlagen uit StoreDocumentRequest komt dat op ~730s.
    |
    | `retention_hours` is hoe lang een afgeronde respons herhaald kan worden. Na
    | dat venster ruimt `model:prune` de rij op; `provider_entity_links` blijft
    | daarna de herboeking tegenhouden.
    |
    */

    'idempotency' => [
        'lease_seconds' => (int) env('IDEMPOTENCY_LEASE_SECONDS', 900),
        'retention_hours' => (int) env('IDEMPOTENCY_RETENTION_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting — `/v1/*`
    |--------------------------------------------------------------------------
    |
    | Schrijvende en lezende requests hebben een apart budget (zie
    | `AppServiceProvider::boot()`, `RateLimiter::for('api', ...)`), zodat een
    | consumer die een backlog aan documenten boekt (serieel, één POST per
    | document) niet vastloopt op verkeer dat alleen leest, en andersom.
    |
    | `writes_per_minute` is ruim boven een realistische boek-cadans: een
    | Exact-boeking kost ~1-4s aan upstream-calls (token-refresh + relatie-
    | lookup + aanmaken + boeken), dus 120/min laat een consumer een backlog
    | wegwerken zonder throttle terwijl een misconfigureerde retry-loop nog
    | steeds binnen een minuut afgevangen wordt.
    |
    | `reads_per_minute` ligt hoger omdat lezen (documenten/grootboek/btw
    | ophalen, polling) vaker en goedkoper is dan boeken.
    |
    */

    'rate_limits' => [
        'writes_per_minute' => (int) env('RATE_LIMIT_WRITES_PER_MINUTE', 120),
        'reads_per_minute' => (int) env('RATE_LIMIT_READS_PER_MINUTE', 300),
    ],

];
