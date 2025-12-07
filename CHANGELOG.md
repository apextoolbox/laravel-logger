# Changelog

All notable changes to this project will be documented in this file.

## [0.1.2]

### Added
- Span evidence for N+1 query detection showing query sequence context
- Parent queries (up to 2) before N+1 pattern
- Following queries (up to 2) after N+1 pattern

### Changed
- SQL queries are now normalized (values replaced with ?) for security
- Only one query per N+1 pattern is sent with span evidence attached
- Removed bindings from query data

### Fixed
- Fixed SQL normalization to preserve PostgreSQL quoted identifiers

## [0.1.1]

### Fixed
- Fixed tests and updated version

## [0.1.0]

### Added
- Initial release
- N+1 query detection
- Query logging with caller location
- Integration with ApexToolbox telemetry API
