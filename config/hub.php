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

];
