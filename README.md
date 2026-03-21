# Apex Toolbox for Laravel

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10.x%20%7C%2011.x%20%7C%2012.x-red)](https://laravel.com)

Automatic error tracking, logging, and performance monitoring for Laravel applications. Part of [ApexToolbox](https://apextoolbox.com/).

## Installation

```bash
composer require apextoolbox/laravel-logger
```

Add to `.env`:

```env
APEXTOOLBOX_ENABLED=true
APEXTOOLBOX_TOKEN=your_token_here
```

Add the log channel to `config/logging.php`:

```php
'channels' => [
    // ... other channels

    'apextoolbox' => [
        'driver' => 'monolog',
        'handler' => \ApexToolbox\Logger\Handlers\ApexToolboxLogHandler::class,
        'level' => 'debug',
    ],
],
```

Update `.env` to include the channel in your log stack:

```env
LOG_STACK=daily,apextoolbox
```

Add the middleware for HTTP request tracking (optional):

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\ApexToolbox\Logger\Middleware\LoggerMiddleware::class);
})

// Or app/Http/Kernel.php (Laravel 10)
protected $middleware = [
    \ApexToolbox\Logger\Middleware\LoggerMiddleware::class,
];
```

Done! The SDK automatically captures exceptions, logs, and database queries.

## Configuration

Publish the config file for customization:

```bash
php artisan vendor:publish --tag=logger-config
```

This will create `config/apextoolbox.php` with the full configuration (all filtering options show their **default values** — you only need to override the sections you want to customize):

```php
return [
    'enabled' => env('APEXTOOLBOX_ENABLED', true),
    'token' => env('APEXTOOLBOX_TOKEN', ''),

    // Paths to include/exclude from logging (supports wildcards)
    'path_filters' => [
        'include' => ['*'],
        'exclude' => ['_debugbar/*', 'telescope/*', 'horizon/*', 'api/health', 'api/ping'],
    ],

    // Headers filtering
    // 'exclude' removes headers entirely, 'mask' replaces values with '*******'
    'headers' => [
        'exclude' => [
            'authorization', 'x-api-key', 'cookie', 'x-auth-token',
            'x-access-token', 'x-refresh-token', 'bearer', 'x-secret',
            'x-private-key', 'authentication',
        ],
        'mask' => [
            'ssn', 'social_security', 'phone', 'email',
            'address', 'postal_code', 'zip_code',
        ],
    ],

    // Request body filtering
    // 'exclude' removes fields entirely, 'mask' replaces values with '*******'
    'body' => [
        'exclude' => [
            'password', 'password_confirmation', 'token', 'access_token',
            'refresh_token', 'api_key', 'secret', 'private_key', 'auth',
            'authorization', 'social_security', 'credit_card', 'card_number',
            'cvv', 'pin', 'otp',
        ],
        'mask' => [
            'ssn', 'social_security', 'phone', 'email',
            'address', 'postal_code', 'zip_code',
        ],
    ],

    // Response body filtering
    // 'exclude' removes fields entirely, 'mask' replaces values with '*******'
    'response' => [
        'exclude' => [
            'password', 'password_confirmation', 'token', 'access_token',
            'refresh_token', 'api_key', 'secret', 'private_key', 'auth',
            'authorization', 'social_security', 'credit_card', 'card_number',
            'cvv', 'pin', 'otp',
        ],
        'mask' => [
            'ssn', 'social_security', 'phone', 'email',
            'address', 'postal_code', 'zip_code',
        ],
    ],
];
```

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APEXTOOLBOX_TOKEN` | Your project token | Required |
| `APEXTOOLBOX_ENABLED` | Enable/disable tracking | `true` |

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x

## License

MIT
