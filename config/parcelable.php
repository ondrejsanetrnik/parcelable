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

    'GLS_PRICE_LIST' => [
        0 => [
            5  => 66.00,
            10 => 69.00,
            20 => 73.00,
            99 => 79.00,
        ],
        1 => [
            5  => 99.00,
            10 => 148.00,
            20 => 204.00,
            99 => 274.00,
        ],
        2 => [
            5  => 116.00,
            10 => 185.00,
            20 => 255.00,
            99 => 341.00,
        ],
        3 => [
            5  => 129.00,
            10 => 234.00,
            20 => 341.00,
            99 => 458.00,
        ],
        4 => [
            5  => 142.00,
            10 => 290.00,
            20 => 428.00,
            99 => 650.00,
        ],
        5 => [
            5  => 205.00,
            10 => 381.00,
            20 => 552.00,
            99 => 756.00,
        ],
        6 => [
            5  => 218.00,
            10 => 447.00,
            20 => 657.00,
            99 => 884.00,
        ],
        7 => [
            5  => 311.00,
            10 => 572.00,
            20 => 813.00,
            99 => 1001.00,
        ],
        8 => [
            5  => 311.00,
            10 => 572.00,
            20 => 813.00,
            99 => 1001.00,
        ],
    ],

    'GLS_DIESEL_SURCHARGE'        => 0.09,
    'GLS_TOLL_SURCHARGE_DOMESTIC' => 0.94,
    'GLS_TOLL_SURCHARGE_FOREIGN'  => 1.47,
    'GLS_COD_COSTS_DOMESTIC'      => 20,
    'GLS_COD_COSTS_FOREIGN'       => 24,
    'GLS_WEIGHT_MULTIPLIER'       => 1.6,
    'GLS_COD_CARD_PAYMENT'        => 0.002,


    'CUT_OFF_TIMES' => [
        'GENERAL'     => '12:00',
        'GLS'         => '12:00',
        'Zásilkovna'  => '12:00',
        'Balíkovna'   => '12:00',
        'Allegro One' => '12:00',
        ''            => '15:00',
    ],
];
