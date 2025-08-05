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

## License

MIT