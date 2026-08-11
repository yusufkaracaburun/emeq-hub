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
    | Te lang kost hoogstens uitstel — een retry krijgt 409 met Retry-After. Te
    | kort is gevaarlijk: een traag-maar-levend request verliest zijn claim en de
    | retry boekt dubbel. Bij twijfel dus ruimer.
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

];
