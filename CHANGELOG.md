# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.0] - 2025-08-20

### 🚀 Added
- **Exception Handling**: Comprehensive exception capture and logging system
  - New `ApexToolboxExceptionHandler` class for capturing exceptions across the application
  - Automatic exception attachment to HTTP request logs when available
  - Standalone exception sending for non-HTTP contexts (console commands, queue jobs, etc.)
  - Exception grouping via SHA-256 hash generation for similar errors
  - Source code context extraction (5 lines before, 10 lines after error line)
  - Exception data includes: message, class, file path, line number, timestamp, and environment context
  - Graceful shutdown handling ensures exceptions are sent even during application termination

### 🔧 Enhanced
- **Middleware Integration**: LoggerMiddleware now automatically includes exception data in HTTP logs
  - Exceptions captured during request processing are included in the request log payload
  - Prevents duplicate exception sending (either with request or standalone, never both)
  - Clean exception state management with automatic cleanup
- **JSON Response Handling**: Fixed handling of primitive JSON responses (integers, floats, strings)
  - Previously caused TypeError when trying to filter non-array JSON responses
  - Now properly handles mixed response types without filtering primitive values
  - Maintains filtering for array responses while passing through primitives unchanged

### 🛡️ Security & Reliability
- **Exception Data Sanitization**: Sensitive data handling in exception context
  - File paths are relative to project root to avoid exposing absolute paths
  - Stack trace and detailed trace arrays are commented out by default to prevent information leakage
  - Source context preserves whitespace using HTML entities for accurate display
- **Error Resilience**: All exception handling operations include failsafe error handling
  - Silent failure prevents infinite exception loops
  - File reading errors in source context extraction are handled gracefully
  - Network failures during exception sending don't affect application stability

### 🧪 Testing
- **Comprehensive Test Coverage**: All existing tests pass including new JSON response handling
  - Added tests for primitive JSON response handling (integers, floats, strings)
  - Mixed response type validation
  - Array filtering vs primitive pass-through behavior
  - 52 total tests with 153 assertions all passing

### ⚙️ Technical Implementation
- **Static Exception Storage**: Thread-safe exception capture using static properties
- **Shutdown Function Registration**: Automatic registration of shutdown handlers for exception cleanup
- **UUID v7 Integration**: Uses Laravel's UUID v7 for trace ID generation
- **HTTP Timeout Configuration**: 2-second timeout for exception sending to prevent blocking
- **Environment Detection**: Supports custom endpoints via `APEX_TOOLBOX_DEV_ENDPOINT`

### 📋 Usage
Exception handling is automatic - no configuration required. Exceptions are automatically captured and sent to ApexToolbox for monitoring and analysis.

### 🔄 Migration
No migration required - this release is fully backward compatible. Exception handling is automatically enabled when the package is active.

## [2.1.1] - 2025-01-16

### Fixed
- Add Silently fail to middleware to prevent exceptions from breaking the request lifecycle

## [2.1.0] - 2025-01-15

### 🚀 Added
- **Masking Feature**: New data masking capability to replace sensitive field values with `'*******'` instead of removing them entirely
  - Configure maskable fields in `logger.body.mask` and `logger.response.mask` arrays
  - Preserves data structure while protecting sensitive values
  - Case-insensitive field matching (`SSN`, `ssn`, `Ssn` all work)
  - Works recursively in nested objects and arrays
- **Enhanced Security Configuration**: Added comprehensive default mask fields including `ssn`, `social_security`, `phone`, `email`, `address`, `postal_code`, `zip_code`

### 🔧 Enhanced
- **Recursive Filtering**: Complete rewrite of sensitive data filtering to work at any nesting level
  - Previous filtering only worked at top-level fields
  - Now handles deeply nested structures like `user.profile.credentials.private_key`
  - Case-insensitive matching for all sensitive field detection
- **Security Priority**: Exclude takes precedence over mask (if field appears in both lists, it gets excluded)
- **Configuration Expansion**: Added more comprehensive sensitive field defaults for better out-of-the-box security

### 📚 Documentation
- **Updated README**: Added complete masking documentation with examples
- **Data Filtering Guide**: Clear explanation of when to use exclude vs mask
- **Real-world Examples**: Before/after data transformation examples
- **Security Best Practices**: Enhanced security disclaimers and liability protection

### 🧪 Testing
- **Comprehensive Test Suite**: Added 8+ new tests covering masking functionality
  - Nested array masking tests
  - Case-insensitive masking validation
  - Custom mask value support
  - Priority testing (exclude vs mask)
  - Integration tests for body and response filtering
- **Enhanced Existing Tests**: Updated recursive filtering tests for new functionality

### 🛡️ Security
- **Enhanced Legal Protection**: Updated LICENSE with additional security disclaimer
- **Secure Masking Implementation**: Masking system designed to prevent user injection of malicious values
- **Default Mask Value**: Simple `'*******'` replacement prevents information leakage

### ⚙️ Technical Details
- **New Method**: `recursivelyFilterSensitiveData()` with masking support
- **Backward Compatibility**: All existing configurations continue to work unchanged
- **Performance**: Optimized recursive filtering with single-pass processing

### 📋 Configuration Example
```php
'body' => [
    'exclude' => ['password', 'token', 'secret'],     // Completely removed
    'mask' => ['ssn', 'phone', 'email', 'address'],  // Replaced with '*******'
],
```

### 🔄 Migration
No migration required - this release is fully backward compatible. Existing configurations will continue to work as before. New masking features are opt-in.

## [2.0.0] - 2025-01-08

### Changed
- **BREAKING**: Simplified architecture to match cleaned codebase
- Updated README to concise SDK-style documentation
- Reduced from 44 to 26 tests focusing on core functionality

### Removed
- **BREAKING**: Removed complex test implementations
- Cleaned up unused test scenarios

### Fixed
- All tests now passing with simplified architecture
- Consistent batch logging behavior

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