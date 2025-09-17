<?php

return [
    'crm' => [
        'base_url'   => env('CRM_BASE_URL', ''),
        'api_key'    => env('CRM_API_KEY', ''),
        'api_secret' => env('CRM_API_SECRET', ''),
        'headers'    => [
            'Accept'          => 'application/json',
            'api-key'         => env('CRM_API_KEY', ''),
            'secret-key'      => env('CRM_API_SECRET', ''),
            'accept-language' => 'en',
        ],
        'customers_endpoint' => '/get-customers',
        'pagination' => [
            'enabled'        => true,
            'page_param'     => 'page',
            'per_page_param' => 'per_page',
            'per_page'       => 100,
        ],
        // set true if you want wallet to be overwritten when API provides it
        'reset_wallet_on_import' => false,
    ],
];
