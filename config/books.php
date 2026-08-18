<?php

return [
    'company_id' => (int) env('BOOKS_COMPANY_ID', 1),

    'default_currency' => env('BOOKS_DEFAULT_CURRENCY', 'EUR'),

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
