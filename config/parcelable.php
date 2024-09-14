<?php

return [
    'PACKETA_API_PASSWORD'  => env('PACKETA_API_PASSWORD', null),

    'GLS_USERNAME'          => env('GLS_USERNAME', null),
    'GLS_PASSWORD'          => env('GLS_PASSWORD', null),
    'GLS_PHONE'             => env('PHONE', null),
    'GLS_HOUSE_NUMBER'      => env('HOUSE_NUMBER', null),
    'GLS_STREET'            => env('STREET', null),
    'GLS_SENDER_INFO'       => env('SENDER_INFO', null),

    'GLS_CLIENTS'           => [
        'eshop.cz' => [
            'id'            => '123456',
            'email'         => 'info@eshop.cz',
            'contactName'   => 'info@eshop.cz',
            'name'          => 'Eshop name',
        ],
    ],
];
