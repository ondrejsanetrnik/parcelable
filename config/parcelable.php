<?php

return [
    'PACKETA_API_PASSWORD' => env('PACKETA_API_PASSWORD'),
    'PACKETA_LABEL_FORMAT' => 'A6 on A6',

    'GLS_USERNAME'     => env('GLS_USERNAME'),
    'GLS_PASSWORD'     => env('GLS_PASSWORD'),
    'GLS_PHONE'        => env('PHONE'),
    'GLS_HOUSE_NUMBER' => env('HOUSE_NUMBER'),
    'GLS_STREET'       => env('STREET'),
    'GLS_SENDER_INFO'  => env('SENDER_INFO'),

    'GLS_CLIENTS' => [
        'eshop.cz' => [
            'id'          => '123456',
            'email'       => 'info@eshop.cz',
            'contactName' => 'info@eshop.cz',
            'name'        => 'Eshop name',
        ],
    ],

    'BALIKOVNA_BASE_URL'        => 'https://b2b-test.postaonline.cz:444/restservices/ZSKService/v1/',
    'BALIKOVNA_API_TOKEN'       => env('BALIKOVNA_API_TOKEN'),
    'BALIKOVNA_SECRET_KEY'      => env('BALIKOVNA_SECRET_KEY'),
    'BALIKOVNA_FORM_ID'         => 103,
    'BALIKOVNA_CUSTOMER_ID'     => env('BALIKOVNA_CUSTOMER_ID'),
    'BALIKOVNA_LOCATION_NUMBER' => env('BALIKOVNA_LOCATION_NUMBER', 4),
    'BALIKOVNA_POST_CODE'       => env('BALIKOVNA_POST_CODE'),
    'BALIKOVNA_ID_CCK'          => env('BALIKOVNA_ID_CCK'),
];
