<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fee System Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the fee system behavior and defaults
    |
    */
    'entity_date_column' => 'created_at',

    'cache' => [
        'enabled' => true,
        'ttl' => 3600,
        'prefix' => 'fee_rules:',
    ],

    'fee_types' => [
        'product' => ['markup'],
        'service' => ['commission', 'convenience'],
    ],
];
