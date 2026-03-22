# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-03-22

- Fresh release with clean git history
- Exception tracking with source code context and stack trace
- HTTP request/response tracking via middleware
- Application log capture via Monolog handler
- Outgoing HTTP request tracking via Guzzle middleware
- Sensitive data filtering (exclude and mask) for headers, body, and response
- Path filtering with include/exclude glob patterns
- UUID v7 trace IDs
- Async payload delivery
- Octane-safe static state management
- Source code context preserved with HTML entity encoding
- Env prefix: `APEXTOOLBOX_ENABLED`, `APEXTOOLBOX_TOKEN`
