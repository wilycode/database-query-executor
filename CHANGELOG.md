# Changelog

## Unreleased

- Add SQL dialect support and/or the platform capability needed by the dialect-aware executor; see README for migration and release ordering.


All notable changes to WilyCode Database Query Executor will be documented here.

## [Unreleased]

### Changed

- Updated the shared database contract dependency to `wilycode/database`.
- Updated installation examples and package references to the renamed database packages.

## [0.1.0] - 2026-10-13

### Added

- Initial public release of `QueryExecutor` and `QueryExecutorInterface`.
- Delegation of compiled and bound queries to a database driver.
- Write and read methods, last-inserted ID retrieval, and transaction participation.
- PHPUnit coverage and automated testing on PHP 8.4.
- Documentation for PDO and MySQLi integration, transaction semantics, and error handling.

[0.1.0]: https://github.com/wilycode/database-query-executor/releases/tag/v0.1.0
