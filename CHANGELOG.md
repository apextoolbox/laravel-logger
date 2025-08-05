# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2025-01-08

### Added
- **Universal Logging Support**: New `ApexToolboxLogHandler` Monolog handler that captures logs from HTTP requests, console commands, and queue jobs
- **Context Detection**: Automatically detects and tags logs with `type` field (`http`, `console`, or `queue`)
- **Source Class Extraction**: Automatically identifies the originating class/service and includes it as `source_class` in the payload
- **Batch Processing**: Logs are batched and sent in single HTTP requests for improved performance (~90% reduction in HTTP calls)
- **Smart Buffer Management**: Automatic buffer flushing after HTTP requests, console commands, and queue jobs complete
- **Graceful Shutdown Handling**: Ensures logs are sent even on unexpected exits
- **Laravel Native Integration**: Standard Monolog channel configuration using Laravel's stack driver
- **Non-Blocking HTTP Requests**: Uses 2-second timeout to prevent application blocking
- **Backward Compatibility**: Existing HTTP middleware continues to work unchanged

### Changed
- Updated README to be more concise and SDK-focused
- Logs payload format changed to batched format with `logs` array
- Enhanced test coverage with comprehensive batch processing tests (44 total tests)
- Event-driven buffer flushing for optimal performance

### Technical Details
- New `ApexToolboxLogHandler` class extending Monolog's `AbstractProcessingHandler` with static buffer
- New `ContextDetector` service for runtime context identification
- New `SourceClassExtractor` service for automatic class name detection
- Uses Laravel's `RequestHandled`, `CommandFinished`, and `JobAttempted` events for buffer flushing
- Simple setup: just add to `config/logging.php` and set `LOG_CHANNEL=stack`

### Migration Guide
No breaking changes. Existing users can:
1. Continue using the current setup (HTTP middleware only)
2. Optionally upgrade to universal logging by adding the Monolog handler to their logging configuration

## [1.0.1] - 2024-XX-XX

### Fixed
- Various bug fixes and improvements

## [1.0.0] - 2024-XX-XX

### Added
- Initial release with HTTP request/response logging middleware
- Configurable path filtering and security features
- Laravel package auto-discovery support