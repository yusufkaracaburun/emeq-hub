<?php

return [
    /*
     * Single-company boekhouding (emeq zelf). Alle Books-models hangen aan deze
     * ene company (D1 in .docs/decisions/books-module.md). Het
     * company_id-kolom + FK's blijven behouden voor een eventuele multi-entity-
     * toekomst, maar worden niet via session/auth geresolved — gewoon deze vaste id.
     */
    'company_id' => (int) env('BOOKS_COMPANY_ID', 1),

    /*
     * EUR-only in v1. Bedragen worden als integer-centen opgeslagen; geen
     * valuta-conversie zolang alles in deze munt staat.
     */
    'default_currency' => env('BOOKS_DEFAULT_CURRENCY', 'EUR'),

    /*
     * Afzender-gegevens voor verkoopfacturen (PDF-kop). Single-company D1: de
     * BooksCompany draagt alleen een naam, dus de wettelijke factuurvelden
     * (KvK, BTW, IBAN) leven hier env-overridebaar. Lege velden vallen weg in de
     * PDF — geen lege regels.
     */
    'issuer' => [
        'name' => env('BOOKS_ISSUER_NAME', 'Emeq'),
        'address_line_1' => env('BOOKS_ISSUER_ADDRESS_1'),
        'address_line_2' => env('BOOKS_ISSUER_ADDRESS_2'),
        'postal_code' => env('BOOKS_ISSUER_POSTAL_CODE'),
        'city' => env('BOOKS_ISSUER_CITY'),
        'country' => env('BOOKS_ISSUER_COUNTRY', 'Nederland'),
        'email' => env('BOOKS_ISSUER_EMAIL', 'info@emeq.nl'),
        'phone' => env('BOOKS_ISSUER_PHONE'),
        'website' => env('BOOKS_ISSUER_WEBSITE'),
        'coc_number' => env('BOOKS_ISSUER_COC_NUMBER'),
        'vat_number' => env('BOOKS_ISSUER_VAT_NUMBER'),
        'iban' => env('BOOKS_ISSUER_IBAN'),
    ],
];
