# Official Apex Toolbox SDK for Laravel

[![Tests](https://img.shields.io/badge/tests-44%20passed-brightgreen)](https://github.com/apextoolbox/laravel-logger)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10.x%20%7C%2011.x%20%7C%2012.x-red)](https://laravel.com)

This is the official Laravel SDK for [Apex Toolbox](https://apextoolbox.com/).

## Installation

Install the package:

```bash
composer require apextoolbox/laravel-logger
```

Add your token to `.env`:

```env
APEX_TOOLBOX_TOKEN=your_token_here
```

Add to `config/logging.php`:

```php
'channels' => [
    'apextoolbox' => [
        'driver' => 'monolog',
        'handler' => \ApexToolbox\Logger\Handlers\ApexToolboxLogHandler::class,
        'level' => 'debug',
    ],
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'apextoolbox'],
    ],
],
```

Set log channel in `.env`:

```env
LOG_CHANNEL=stack
```

## Usage

All your existing logs are automatically sent to Apex Toolbox:

```php
Log::info('User created', ['user_id' => 123]);
Log::error('Payment failed', ['order_id' => 456]);
```

## Optional HTTP Request Tracking

Add middleware for HTTP request tracking:

```php
// app/Http/Kernel.php
protected $middlewareAliases = [
    'track.request' => \ApexToolbox\Logger\Middleware\LoggerMiddleware::class,
];
```

```php
// routes/api.php
Route::middleware('track.request')->group(function () {
    Route::apiResource('users', UserController::class);
});
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="ApexToolbox\Logger\LoggerServiceProvider"
```

### Path Filtering

Configure which routes to track:

```php
// config/logger.php
'path_filters' => [
    'include' => [
        'api/*',        // Track all API routes
        // '*',         // Uncomment to track ALL routes
    ],
    'exclude' => [
        'api/health',   // Skip health checks
        'api/ping',     // Skip ping endpoints
    ],
],
```

### Security Configuration

**⚠️ IMPORTANT SECURITY NOTICE**: This package automatically filters sensitive data from logs to protect your users' privacy. The default configuration excludes common sensitive fields from headers, request bodies, and responses.

```php
// config/logger.php
'headers' => [
    'exclude' => [
        'authorization', 'x-api-key', 'cookie',
        // ... more sensitive headers
    ],
],

'body' => [
    'exclude' => [
        'password', 'password_confirmation', 'token', 'secret',
        'access_token', 'refresh_token', 'api_key', 'private_key',
        // ... more sensitive fields that should be completely removed
    ],
    'mask' => [
        'ssn', 'social_security', 'phone', 'email', 'address',
        'postal_code', 'zip_code',
        // ... fields that should be masked with '*******'
    ],
],

'response' => [
    'exclude' => [
        'password', 'password_confirmation', 'token', 'secret',
        'access_token', 'refresh_token', 'api_key', 'private_key',
        // ... more sensitive fields that should be completely removed
    ],
    'mask' => [
        'ssn', 'social_security', 'phone', 'email', 'address',
        'postal_code', 'zip_code',
        // ... fields that should be masked with '*******'
    ],
],
```

### Data Filtering Options

You have two options for protecting sensitive data:

**1. Exclude (Complete Removal)**
- Fields listed in `exclude` arrays are completely removed from logs
- Use for highly sensitive data like passwords, tokens, API keys
- Data structure changes (field disappears entirely)

**2. Mask (Value Replacement)**  
- Fields listed in `mask` arrays are replaced with `'*******'`
- Use for PII that you want to track structurally but hide values
- Data structure preserved (field exists but value is masked)
- Works recursively in nested objects/arrays
- Case-insensitive matching (`SSN`, `ssn`, `Ssn` all match)

**Example:**
```php
// Input data
[
    'user' => [
        'name' => 'John Doe',
        'password' => 'secret123',      // Will be excluded (removed)
        'ssn' => '123-45-6789',        // Will be masked to '*******'
        'profile' => [
            'email' => 'john@test.com', // Will be masked to '*******'
            'token' => 'bearer-xyz'     // Will be excluded (removed)
        ]
    ]
]

// Logged data
[
    'user' => [
        'name' => 'John Doe',
        'ssn' => '*******',
        'profile' => [
            'email' => '*******'
        ]
    ]
]
```

### ⚠️ Security Disclaimer

**YOU ARE RESPONSIBLE** for configuring the sensitive data filters appropriately for your application. While this package provides sensible defaults to protect common sensitive fields, **you must review and customize the exclude lists** to ensure all sensitive data specific to your application is properly filtered.

**The package maintainers are NOT liable** for any sensitive data that may be logged if you:
- Modify or remove the default security filters
- Add custom sensitive fields without proper exclusion
- Disable the filtering mechanisms
- Misconfigure the security settings

Always review your logs to ensure no sensitive data is being transmitted before deploying to production.

## License

MIT