# Apex Toolbox for Laravel

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10.x%20%7C%2011.x%20%7C%2012.x-red)](https://laravel.com)

Automatic error tracking, logging, and performance monitoring for Laravel applications.

## Installation

```bash
composer require apextoolbox/laravel-logger
```

Add to `.env`:

```env
APEX_TOOLBOX_ENABLED=true
APEX_TOOLBOX_TOKEN=your_token_here
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

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APEX_TOOLBOX_TOKEN` | Your project token | Required |
| `APEX_TOOLBOX_ENABLED` | Enable/disable tracking | `true` |

### Path Filtering

```php
// config/logger.php
'path_filters' => [
    'include' => ['*'],
    'exclude' => ['_debugbar/*', 'telescope/*', 'api/health'],
],
```

### Sensitive Data

Sensitive fields like `password`, `token`, `authorization` are automatically excluded from logs.

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x

## License

MIT
