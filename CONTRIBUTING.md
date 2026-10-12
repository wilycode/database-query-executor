# Contributing

Thank you for your interest in WilyCode Database Query Executor.

## Before opening a pull request

1. Create an issue first for significant API or behavior changes.
2. Keep this library driver-agnostic. Driver binding belongs in the PDO and MySQLi adapter packages.
3. Preserve the public `QueryExecutorInterface` contract unless a change is explicitly agreed upon.
4. Add PHPUnit tests for changes to query delegation and transaction behavior.
5. Update the English README and CHANGELOG when behavior changes.
6. Run `composer validate --strict` and `composer test` on PHP 8.4 or newer.

Keep the code simple and focused on query execution. Do not introduce logging, profiling, HTTP, framework, or environment dependencies in this package.

## Reporting issues

Use the [GitHub issue tracker](https://github.com/wilycode/database-query-executor/issues). Please include a minimal reproducer and versions of any related WilyCode packages.
