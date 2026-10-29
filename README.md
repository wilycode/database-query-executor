# WilyCode Database Query Executor

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://github.com/wilycode/database-query-executor/actions/workflows/tests.yml/badge.svg)](https://github.com/wilycode/database-query-executor/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/wilycode/database-query-executor)](https://packagist.org/packages/wilycode/database-query-executor)

**A small, driver-independent execution layer for WilyCode database queries.** Compile a query, bind parameters for your database driver, and execute it with one consistent interface. PHP 8.4+.

> **WilyCode PHP Ecosystem**
>
> Part of **[WilyCode](https://github.com/wilycode/wilycode)** — a collection of independent, reusable PHP components. Each package has its own repository, releases, and Composer installation.
>
> **[Explore the WilyCode ecosystem →](https://github.com/wilycode/wilycode)**

## Platform detection

QueryExecutor 0.2 accepts an optional third constructor argument, `?DialectInterface $dialect = null`. An explicit choice wins. Otherwise it requires `PlatformProviderInterface` on the driver and maps mysql, pgsql or sqlite to the corresponding built-in dialect. Unknown platforms (including sqlsrv/oci for now) and drivers without that capability throw before binding/execution. Pass an explicit dialect for a custom driver. The standalone Query default is never used to guess a connection's platform.

```php
$executor = new QueryExecutor($database, $binder); // automatic for updated PDO/MySQLi drivers
$executor = new QueryExecutor($database, $binder, new \WilyCode\Database\Query\Dialect\PostgreSqlDialect());
```

Requires database >=0.1.2 and database-query >=0.2. Update drivers to versions implementing the platform provider before switching an existing two-argument executor. Raw and compiled SQL remain unchanged; getLastInsertId() and transactions remain driver operations. Release database and query first, then driver adapters, then executor. These minimum versions are proposed releases and are not published by this change.

## Table of contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start (PDO)](#quick-start-pdo)
- [How query execution works](#how-query-execution-works)
- [Dependency injection with QueryExecutorInterface](#dependency-injection-with-queryexecutorinterface)
- [Reading data](#reading-data)
    - [Fetch one row](#fetch-one-row)
    - [Fetch multiple rows](#fetch-multiple-rows)
    - [Fetch a single column](#fetch-a-single-column)
- [Writing data](#writing-data)
    - [Insert](#insert)
    - [Update and delete](#update-and-delete)
    - [Last inserted ID](#last-inserted-id)
- [Transactions](#transactions)
    - [Starting a transaction](#starting-a-transaction)
    - [Joining an existing transaction](#joining-an-existing-transaction)
- [Raw SQL](#raw-sql)
- [MySQLi integration](#mysqli-integration)
- [API reference](#api-reference)
- [Errors and edge cases](#errors-and-edge-cases)
    - [Handling database errors](#handling-database-errors)
- [Architecture and optional extensions](#architecture-and-optional-extensions)
- [Related packages](#related-packages)
- [Development](#development)
- [Contributing and license](#contributing-and-license)

## Overview

`QueryExecutor` is the bridge between three independent parts of the WilyCode database ecosystem:

```text
QueryInterface (database-query)
            |
            | compile()
            v
       CompiledQuery
            |
            | BinderInterface::bind()
            v
         BoundQuery
            |
            | DatabaseInterface::fetchAll(), execute(), etc.
            v
       Database driver
```

It does **not** open database connections, construct SQL, perform logging, cache queries, or map rows into model objects. Those responsibilities belong to other libraries. The executor requires a **concrete binder** (for example `PdoBinder` or `MysqliBinder`) alongside a matching database driver.

## Requirements

- PHP **8.4 or newer**.
- [`wilycode/database:^0.1`](https://github.com/wilycode/database) — database driver contract.
- [`wilycode/database-query:^0.1`](https://github.com/wilycode/database-query) — `QueryInterface`, compiled queries, and binder contract.
- A concrete driver and binder, installed **separately** for the database you use.

This package does not require PDO, MySQLi, a particular SQL dialect, or a framework at runtime.

## Installation

```bash
composer require wilycode/database-query-executor
```

For a PDO-based application, install its adapter too:

```bash
composer require wilycode/database-pdo
```

For MySQLi, use `wilycode/database-mysqli` instead. Only install the adapter you need.

## Quick start (PDO)

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use WilyCode\Database\DriverPdo\PdoBinder;
use WilyCode\Database\DriverPdo\PdoDatabase;
use WilyCode\Database\Query\Query;
use WilyCode\Database\QueryExecutor\QueryExecutor;

$database = PdoDatabase::connect(
    'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    'app_user',
    'app_password',
);

$executor = new QueryExecutor($database, new PdoBinder());

$users = $executor->fetchAll(
    Query::select('users', 'u')
        ->columns(['id' => 'id', 'email' => 'email'])
        ->where('u.active', true)
        ->orderBy('u.id', 'DESC')
        ->limit(10),
);
```

This example assumes a running MySQL server and an existing `app.users` table with at least `id`, `email`, and `active` columns. The connection details are illustrative; **do not hardcode production credentials**. Read database credentials from environment variables or your application's secret manager instead.

**Compiled SQL** (before the PDO binder and driver execute it):

```sql
SELECT u.id AS `id`, u.email AS `email`
FROM users AS u
WHERE u.active = :param_1
ORDER BY u.id DESC
    LIMIT 10
```

The query builder produces the parameter `[':param_1' => true]`. The boolean is passed separately to the binder and driver; it is **not interpolated into the SQL string**. Formatting and supported SQL syntax depend on the database dialect.

No manual `compile()` or `bind()` call is needed: the executor handles both steps. It returns whatever the underlying database driver's read method returns, such as an array of associative rows for `fetchAll()`.

## How query execution works

All read and write methods follow the same sequence:

1. `QueryInterface::compile()` produces a `CompiledQuery` containing SQL, parameters, and optional parameter types.
2. `BinderInterface::bind()` converts that compiled query into the format required by the selected driver (`BoundQuery`).
3. The executor calls the corresponding `DatabaseInterface` method with the bound SQL, parameters, and types.
4. The driver's result (or exception) is passed back to the caller.

For example, the canonical query may use named placeholders while a MySQLi binder transforms them into positional `?` placeholders. The executor itself is unaware of this difference.

The executor **does not execute SQL while calling `compile()`**; actual execution happens in the database driver.

## Dependency injection with QueryExecutorInterface

Application services and repositories can depend on `QueryExecutorInterface` rather than the concrete `QueryExecutor`. This keeps database access decoupled from the executor implementation and makes optional decorators (such as profiling or caching) easier to introduce later.

```php
use WilyCode\Database\Query\Query;
use WilyCode\Database\QueryExecutor\QueryExecutorInterface;

final class UserRepository
{
    public function __construct(
        private QueryExecutorInterface $executor,
    ) {}

    public function findActive(): array
    {
        return $this->executor->fetchAll(
            Query::select('users', 'u')
                ->where('u.active', true),
        );
    }
}

// $executor is the QueryExecutor instance configured in Quick start.
$repository = new UserRepository($executor);
$activeUsers = $repository->findActive();
```

The `QueryExecutor` class implements `QueryExecutorInterface`, so it can be injected directly. Decorators may also implement this interface without requiring changes to `UserRepository`.

## Reading data

### Fetch one row

Use `fetch()` to obtain one associative row:

```php
$user = $executor->fetch(
    Query::select('users', 'u')
        ->column('u.id', 'id')
        ->column('u.email', 'email')
        ->where('u.id', 15),
);

// Example: ['id' => 15, 'email' => 'ada@example.test']
```

If no row is found, the database contract returns an **empty array** (`[]`).

### Fetch multiple rows

```php
$users = $executor->fetchAll(
    Query::select('users', 'u')
        ->where('u.active', true)
        ->orderBy('u.id', 'DESC')
        ->limit(20),
);

// Example: [['id' => 15, ...], ['id' => 14, ...]]
```

An empty result set returns `[]`.

### Fetch a single column

Use `fetchColumn()` for scalar values and aggregate results:

```php
$email = $executor->fetchColumn(
    Query::select('users', 'u')->column('u.email')->where('u.id', 15),
);

// Pick another zero-based result column when needed:
$value = $executor->fetchColumn(
    Query::raw('SELECT id, email FROM users WHERE id = :id', ['id' => 15]),
    columnIndex: 1,
);
```

The default column index is `0`; negative indices throw `InvalidArgumentException`. The database contract returns `null` when there is no row.

## Writing data

### Insert

```php
$affected = $executor->execute(
    Query::insert('users')->values([
        'email' => 'ada@example.test',
        'active' => true,
    ]),
);

// $affected: number of affected rows (driver-dependent).
```

### Update and delete

```php
$updated = $executor->execute(
    Query::update('users')
        ->set(['active' => false])
        ->where('id', 15),
);

$deleted = $executor->execute(
    Query::delete('users')->where('id', 15),
);
```

**Important:** neither the query builder nor the executor automatically adds a `WHERE` clause to an `UPDATE` or `DELETE`. Ensure the intended filter is present before executing the statement.

### Last inserted ID

After inserting data, ask the driver for the last inserted ID through the executor:

```php
$executor->execute(
    Query::insert('users')->values(['email' => 'new@example.test']),
);

$id = $executor->getLastInsertId(); // string, e.g. '42'
```

ID generation and semantics depend on your database driver. `getLastInsertId()` does **not** compile a query or invoke the binder.

## Transactions

### Starting a transaction

The callback receives the **same `QueryExecutor` instance**, and the driver's transaction mechanism controls commit and rollback:

```php
$result = $executor->transaction(function (QueryExecutor $tx): string {
    $tx->execute(Query::insert('users')->values(['email' => 'first@example.test']));
    $tx->execute(Query::insert('users')->values(['email' => 'second@example.test']));

    return 'saved';
});

// 'saved' after the driver's transaction completes successfully.
```

A thrown exception is passed to the database driver, whose transaction implementation is responsible for rollback; the original exception is propagated according to the driver's behavior.

Check the transaction state with `$executor->inTransaction()`.

### Joining an existing transaction

If the driver is already in a transaction, the executor **joins it** instead of starting a nested transaction:

```php
$executor->transaction(function (QueryExecutor $tx): void {
    $tx->execute(Query::update('users')->set(['active' => true])->where('id', 15));

    // This reuses the current driver transaction.
    $tx->transaction(function (QueryExecutor $inner): void {
        $inner->execute(Query::update('users')->set(['active' => true])->where('id', 16));
    });
});
```

**No nested transaction or savepoint is created.** If a callback joins an already active transaction, it does not commit, roll back, or otherwise manage that outer transaction. The transaction owner is responsible for its lifecycle. In particular, catching an exception inside an outer transaction does **not** automatically roll back its changes.

## Raw SQL

Use `Query::raw()` to pass trusted SQL with bound **values**; the executor accepts `QueryInterface`, not a plain SQL string:

```php
$rows = $executor->fetchAll(
    Query::raw(
        'SELECT id, email FROM users WHERE active = :active AND id > :minId',
        ['active' => true, 'minId' => 10],
    ),
);
```

Never interpolate untrusted input into table names, column names, ORDER BY fragments, or raw SQL. Parameter placeholders protect **values**, not SQL identifiers.

## MySQLi integration

MySQLi uses a different driver and binder but the **same executor**:

```php
use WilyCode\Database\DriverMysqli\MysqliBinder;
use WilyCode\Database\DriverMysqli\MysqliDatabase;
use WilyCode\Database\Query\Query;
use WilyCode\Database\QueryExecutor\QueryExecutor;

$database = MysqliDatabase::connect(
    host: '127.0.0.1',
    username: 'app_user',
    password: 'app_password',
    database: 'app',
);

$executor = new QueryExecutor($database, new MysqliBinder());

$rows = $executor->fetchAll(
    Query::select('users', 'u')->where('u.active', true),
);
```

The `MysqliBinder` converts canonical named placeholders to positional bindings for MySQLi. Always use the binder that matches your driver.

## API reference

`QueryExecutor` is a `final readonly` class implementing `QueryExecutorInterface`.

| Method | Arguments | Return type | Purpose |
| --- | --- | --- | --- |
| `__construct()` | `DatabaseInterface $database`, `BinderInterface $binder` | — | Inject a driver and binder. |
| `execute()` | `QueryInterface $query` | `int` | Execute a write/DDL query; return affected rows. |
| `fetch()` | `QueryInterface $query` | `array` | Fetch one associative row, or `[]`. |
| `fetchAll()` | `QueryInterface $query` | `array` | Fetch a list of associative rows, or `[]`. |
| `fetchColumn()` | `QueryInterface $query`, `int $columnIndex = 0` | `mixed` | Read one result column; `null` if no row. |
| `getLastInsertId()` | — | `string` | Return the driver's last inserted ID. |
| `transaction()` | `callable $callback` | `mixed` | Run or join a transaction; callback receives the executor. |
| `inTransaction()` | — | `bool` | Ask the driver whether a transaction is active. |

All query methods accept a `QueryInterface` instance, which is implemented by WilyCode's select/insert/update/delete/raw query statement classes. The executor does **not** expose a `query(string $sql)` or `prepare()` API.

## Errors and edge cases

| Case | Behavior |
| --- | --- |
| Negative `fetchColumn()` index | Throws `InvalidArgumentException` **before** compiling the query. |
| No matching row for `fetch()` | Returns `[]` according to the driver contract. |
| No matching row for `fetchColumn()` | Returns `null` according to the driver contract. |
| Compile or bind failure | Exception propagates to the caller. |
| Driver query or connection failure | Driver's database exceptions propagate to the caller. |
| Callback throws inside a new transaction | Driver controls rollback and propagates an error. |
| Callback joins an active transaction | No nested transaction; the outer transaction owner manages commit/rollback. |

This library does not swallow or reformat errors. Catch driver-level exceptions where your application can meaningfully handle them.

### Handling database errors

Database driver failures use the shared database exception hierarchy. Catch `DatabaseException` when your application can recover, report the failure, or return a safe response:

```php
use WilyCode\Database\Driver\Exception\DatabaseException;
use WilyCode\Database\Query\Query;

try {
    $users = $executor->fetchAll(Query::select('users'));
} catch (DatabaseException $exception) {
    // Log a safe, non-sensitive failure marker.
    error_log('Database query failed: ' . $exception::class);

    // Do not expose raw SQL, parameters, or connection details to end users.
    throw $exception;
}
```

Only **driver-level** exceptions are covered by this catch. Compilation, parameter binding, validation, and application errors can throw other exception types and are propagated unchanged. In production, ensure logs are access-controlled and do not reveal sensitive SQL or credentials.

## Architecture and optional extensions

The executor deliberately does **not** include query profiling, logging, caching, model hydration, HTTP responses, or environment configuration.

Optional packages may decorate `QueryExecutorInterface` without modifying this library, for example:

- [`wilycode/database-query-executor-profiler`](https://github.com/wilycode/database-query-executor-profiler) — optional profiling decorator.
- [`wilycode/database-query-executor-cache`](https://github.com/wilycode/database-query-executor-cache) — optional caching integration.

These are separate packages; their existence is **not required** to use the base executor.

## Related packages

| Package | Purpose |
| --- | --- |
| [`wilycode/database-query`](https://github.com/wilycode/database-query) | Build and compile driver-independent SQL queries. |
| [`wilycode/database`](https://github.com/wilycode/database) | Database driver interface and shared exceptions. |
| [`wilycode/database-pdo`](https://github.com/wilycode/database-pdo) | PDO driver and canonical query binder. |
| [`wilycode/database-mysqli`](https://github.com/wilycode/database-mysqli) | MySQLi driver and canonical query binder. |
| [`wilycode/database-model`](https://github.com/wilycode/database-model) | Model metadata and hydration layer. |

## Development

```bash
composer install
composer validate --strict
composer test
```

GitHub Actions runs PHPUnit on PHP 8.4 for pushes and pull requests. Tests are kept in the source repository but excluded from distribution archives.

## Contributing and license

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) and open issues in the [GitHub issue tracker](https://github.com/wilycode/database-query-executor/issues).

MIT © 2026 Simon Piter. See [LICENSE](LICENSE).
