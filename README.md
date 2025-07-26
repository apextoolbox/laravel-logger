# Laravel Logger

[![Tests](https://img.shields.io/badge/tests-31%20passed-brightgreen)](https://github.com/apextoolbox/laravel-logger)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](https://github.com/apextoolbox/laravel-logger)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10.x%20%7C%2011.x%20%7C%2012.x-red)](https://laravel.com)

Laravel middleware package for logging HTTP requests and responses, sending data to ApexToolbox analytics platform.

## Features

- Lightweight middleware for request/response logging
- Configurable path filtering (include/exclude patterns)
- Security-focused: filters sensitive headers and request fields
- Configurable payload size limits
- Silent failure - won't break your application
- Easy configuration management

## Requirements

- PHP 8.1+
- Laravel 10.x or 11.x

## Installation

Install via Composer:

```bash
composer require apextoolbox/laravel-logger
```

The service provider will be automatically registered via Laravel's package auto-discovery.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=logger-config
```

This creates `config/logger.php` in your application.

### Environment Variables

Add these to your `.env` file:

```env
APEX_TOOLBOX_ENABLED=true
APEX_TOOLBOX_TOKEN=your_apextoolbox_token
```

## Usage

### Middleware Registration

#### Option 1: Global API Middleware

Add to your API routes in `app/Http/Kernel.php`:

```php
protected $middlewareGroups = [
    'api' => [
        // ... other middleware
        \ApexToolbox\Logger\Middleware\LoggerMiddleware::class,
    ],
];
```

#### Option 2: Middleware Alias (Recommended for specific routes)

Create an alias in `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    // ... other aliases
    'track.request' => \ApexToolbox\Logger\Middleware\LoggerMiddleware::class,
];
```

Then use the short alias in your routes:

```php
// Single route
Route::get('/api/users', [UserController::class, 'index'])
    ->middleware('track.request');

// Route group
Route::middleware('track.request')->group(function () {
    Route::apiResource('users', UserController::class);
    Route::post('/orders', [OrderController::class, 'store']);
});

// Multiple middleware
Route::middleware(['auth', 'track.request'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});
```

### Configuration Options

#### Path Filtering

```php
'path_filters' => [
    'include' => [
        'api/*',           // Track all API routes
        'webhook/*',       // Track webhooks
    ],
    'exclude' => [
        'api/health',      // Skip health checks
        'api/ping',        // Skip ping endpoints
    ],
],
```

#### Header Security

```php
'headers' => [
    'include_sensitive' => false,  // Exclude sensitive headers by default
    'exclude' => [
        'authorization',
        'x-api-key',
        'cookie',
    ],
],
```

#### Request Body Filtering

```php
'body' => [
    'max_size' => 10240,          // 10KB limit
    'exclude_fields' => [
        'password',
        'password_confirmation',
        'token',
        'secret',
    ],
],
```

## Data Collected

The middleware tracks:

- HTTP method and URL
- Request headers (filtered)
- Request body (filtered and size-limited)
- Response status code
- Response content (size-limited)
- Timestamp

## Security

- Sensitive headers are excluded by default
- Password fields are automatically filtered
- Request/response bodies are size-limited
- Failed requests are silently ignored
- No data is logged locally

## Troubleshooting

### No Data Being Sent

1. Verify `APEX_TOOLBOX_TOKEN` is set
2. Check `APEX_TOOLBOX_ENABLED=true`
3. Ensure requests match path filters
4. Confirm middleware is registered

### Performance Impact

- Uses 1-second HTTP timeout
- Failures are silently ignored
- Consider path filtering for high-traffic endpoints

## Testing

The package includes comprehensive tests covering all functionality:

```bash
# Run tests
composer test

# Run tests with coverage (requires Xdebug)
composer test-coverage
```

### Test Coverage

- **31 tests** covering all public and private methods
- **100% code coverage** across all classes:
  - `LogBuffer` - Static log entry management
  - `LoggerServiceProvider` - Laravel service registration and log listening
  - `LoggerMiddleware` - HTTP request/response tracking and filtering
- Tests include edge cases, error handling, and configuration scenarios

## License

MIT License. See LICENSE file for details.

## Support

For support, please contact ApexToolbox or create an issue in the package repository.