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
];
