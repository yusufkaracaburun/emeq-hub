<?php

return [
    /*
     * Single-company boekhouding (emeq zelf). Alle Books-models hangen aan deze
     * ene company (D1 in .docs/decisions/erpsaas-books-module.md). Het
     * company_id-kolom + FK's blijven behouden voor een eventuele multi-entity-
     * toekomst, maar worden niet via session/auth geresolved — gewoon deze vaste id.
     */
    'company_id' => (int) env('BOOKS_COMPANY_ID', 1),

    /*
     * EUR-only in v1. Bedragen worden als integer-centen opgeslagen; geen
     * valuta-conversie zolang alles in deze munt staat.
     */
    'default_currency' => env('BOOKS_DEFAULT_CURRENCY', 'EUR'),
];
