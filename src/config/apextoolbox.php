<?php

/**
 * ApexToolbox for Laravel.
 *
 * Documentation: https://apextoolbox.com/docs
 * Website: https://apextoolbox.com
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Enable/Disable
    |--------------------------------------------------------------------------
    | Enable or disable the logger. When disabled, no data will be collected
    | or sent to the ApexToolbox API.
    |--------------------------------------------------------------------------
    */
    'enabled' => env('APEXTOOLBOX_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Token
    |--------------------------------------------------------------------------
    | Your project token from ApexToolbox. Required for authentication.
    | Get your token at https://apextoolbox.com
    |--------------------------------------------------------------------------
    */
    'token' => env('APEXTOOLBOX_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Path Filters
    |--------------------------------------------------------------------------
    | You can specify which paths to include or exclude from logging.
    | The 'include' array defines the paths that will be logged,
    | while the 'exclude' array defines paths that will be ignored.
    | You can use wildcards (*) to match multiple routes.
    | For example, 'api/*' will match all routes under the 'api' prefix
    | and 'api/health' will match the specific health check route.
    |--------------------------------------------------------------------------
    */
    'path_filters' => [
        'include' => [
            '*',
        ],
        'exclude' => [
            '_debugbar/*',
            'telescope/*',
            'horizon/*',
            'api/health',
            'api/ping',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Headers Configuration
    |--------------------------------------------------------------------------
    | You can configure which headers to include or exclude from logging.
    | The 'include_sensitive' option allows you to include sensitive headers
    | like 'Authorization' or 'Cookie'. By default, it is set to false.
    | The 'exclude' array defines headers that will not be logged.
    | Common headers like 'Authorization and 'Cookie' are excluded by default to
    | protect sensitive information.
    | You can modify this array to include or exclude additional headers as needed.
    |--------------------------------------------------------------------------
    */
    'headers' => [
        'exclude' => [
            'authorization',
            'x-api-key',
            'cookie',
            'x-auth-token',
            'x-access-token',
            'x-refresh-token',
            'bearer',
            'x-secret',
            'x-private-key',
            'authentication',
        ],
        'mask' => [
            'ssn',
            'social_security', 
            'phone',
            'email',
            'address',
            'postal_code',
            'zip_code',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Body Configuration
    |--------------------------------------------------------------------------
    | The 'exclude' array defines fields that will be excluded from the request body.
    | Common fields like 'password', 'password_confirmation', 'token', and 'secret'
    | are excluded by default to protect sensitive information.
    | You can modify this array to include or exclude additional fields as needed.
    |--------------------------------------------------------------------------
    */
    'body' => [
        'exclude' => [
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'secret',
            'private_key',
            'auth',
            'authorization',
            'social_security',
            'credit_card',
            'card_number',
            'cvv',
            'pin',
            'otp',
        ],
        'mask' => [
            'ssn',
            'social_security', 
            'phone',
            'email',
            'address',
            'postal_code',
            'zip_code',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Configuration
    |--------------------------------------------------------------------------
    | The 'exclude' array defines fields that will be excluded from the response body.
    | Common fields like 'token', 'password', and 'password_confirmation'
    | are excluded by default to protect sensitive information from being logged.
    | You can modify this array to include or exclude additional fields as needed.
    | This helps ensure that sensitive data is not accidentally logged in responses.
    |--------------------------------------------------------------------------
    */
    'response' => [
        'exclude' => [
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'secret',
            'private_key',
            'auth',
            'authorization',
            'social_security',
            'credit_card',
            'card_number',
            'cvv',
            'pin',
            'otp',
        ],
        'mask' => [
            'ssn',
            'social_security', 
            'phone',
            'email',
            'address',
            'postal_code',
            'zip_code',
        ],
    ]
];