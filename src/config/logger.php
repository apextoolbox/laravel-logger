<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Logger Configuration
    |--------------------------------------------------------------------------
    | This configuration file allows you to enable or disable the logger,
    */
    'enabled' => env('APEX_TOOLBOX_ENABLED', true),

    /*
    | ---------------------------------------------------------------------------
    | Logger Token
    | ---------------------------------------------------------------------------
    | This token is used to authenticate requests to the logger service.
    | You can set it in your .env file as APEX_TOOLBOX_TOKEN.
    | If not set, the logger will not send any data.
    | Make sure to keep this token secure and do not expose it in public repositories.
    |--------------------------------------------------------------------------
    */
    'token' => env('APEX_TOOLBOX_TOKEN', ''),

    /*
    | ---------------------------------------------------------------------------
    | Path Filters
    | ---------------------------------------------------------------------------
    | You can specify which paths to include or exclude from logging.
    | The 'include' array defines the paths that will be logged,
    | while the 'exclude' array defines paths that will be ignored.
    | You can use wildcards (*) to match multiple routes.
    | For example, 'api/*' will match all routes under the 'api' prefix
    | and 'api/health' will match the specific health check route.
    | If you want to track all routes, you can uncomment the '*' line.
    |--------------------------------------------------------------------------
    */
    'path_filters' => [
        'include' => [
            'api/*',        // Log API routes
            // '*',         // Uncomment to log ALL routes
        ],
        'exclude' => [
            'api/health',   // Skip health checks
            'api/ping',     // Skip ping endpoints
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