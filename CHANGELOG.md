# Changelog

All notable changes to this project will be documented in this file.

## [0.3.1] - 2026-03-19

- Renamed env prefix from `APEX_TOOLBOX_` to `APEXTOOLBOX_` to match brand
- Expanded README with full configuration reference (headers, body, response filtering with exclude/mask)
- Added link to [apextoolbox.com](https://apextoolbox.com/)
- Removed dev artifacts from package

## [0.3.0]

- Fixed config key, endpoint, source_class, outgoing request tracking, sensitive data defaults

## [0.2.0]

- Simplified to requests + logs only, fixed outgoing request capture, bypassed Telescope for telemetry

## [0.1.4]

- Fixed tests and updated version

## [0.1.3]

- Added global `logException()` helper function
- Renamed `ApexToolboxExceptionHandler::capture()` to `logException()`

## [0.1.2]

- Added span evidence for N+1 query detection
- Normalized SQL queries for security
- Fixed SQL normalization to preserve PostgreSQL quoted identifiers

## [0.1.1]

- Fixed tests and updated version

## [0.1.0]

- Initial release
- N+1 query detection
- Query logging with caller location
- Integration with ApexToolbox telemetry API
