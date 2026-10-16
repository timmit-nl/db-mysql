# timmit/db-mysql

[![CI](https://github.com/timmit-nl/db-mysql/actions/workflows/ci.yml/badge.svg)](https://github.com/timmit-nl/db-mysql/actions/workflows/ci.yml)

A simple database layer in PHP for MySQL and MariaDB, built on `mysqli`.

It gives you static helpers for running queries, a small builder for `WHERE`
conditions, read/write/fallback connections, transactions, schema reconciliation
(`dbDelta`) and CSV export, with support for MariaDB Galera clusters.

## Requirements

- PHP 8.0 or newer (the test suite runs on PHP 8.2)
- The `mysqli` extension
- MySQL or MariaDB

## Installation

```bash
composer require timmit/db-mysql
```

## Quick start

```php
use TiMMiT\MySQL\DB;

// Connect. `host`, `username`, `password`, `database` and `port` are used.
new DB([
    'host'     => '127.0.0.1',
    'username' => 'app',
    'password' => 'secret',
    'database' => 'app',
    'port'     => 3306,
]);

// Select rows, optionally keyed by a column.
$users = DB::selectQuery('SELECT id, name FROM users WHERE active = 1', 'id');

// Insert, update and delete.
$id = DB::insertQuery("INSERT INTO users (name) VALUES ('" . DB::clean4DB($name) . "')");
DB::updateQuery("UPDATE users SET active = 0 WHERE id = " . (int)$id);
DB::deleteQuery('DELETE FROM users WHERE id = ' . (int)$id);
```

Always escape values that end up in a query: use `DB::clean4DB()` for strings
and cast numbers. Note that `clean4DB()` also escapes `%` and `_` (meant for
`LIKE` conditions); `DB::cleanFromDB()` reverses the escaping when reading
values back.

The connection can also be set up in two steps with `DB::loadConfig()` and
`DB::connect()`. `DB::disconnect()`, `DB::reconnect()` and `DB::ping()` manage
the connection afterwards.

## Read, write and fallback nodes

`new DB($primary, $write, $fallback)` takes three connection settings arrays:

| Argument    | Purpose                                                                 |
| ----------- | ----------------------------------------------------------------------- |
| `$primary`  | The main connection, used for reads (and writes if no write node is set) |
| `$write`    | Optional separate node for writes                                        |
| `$fallback` | Optional node to connect to when connecting to the primary fails         |

Most query methods take a `$writeNode` argument. `selectQuery()` reads from the
primary node by default, while `insertQuery()`, `updateQuery()`, `deleteQuery()`
and `executeQuery()` use the write node.

## Building WHERE conditions

`Where\Collection` and `Where\Param` build escaped conditions, so you do not
have to concatenate them by hand:

```php
use TiMMiT\MySQL\DB;
use TiMMiT\MySQL\Where\Collection;

$where = new Collection('AND');
$where->addParam('status')->is('active');
$where->addParam('age')->greaterOrEqual(18);
$where->addParam('country')->inArray(['NL', 'BE']);

$rows = DB::selectQuery('SELECT * FROM users WHERE ' . DB::getWhereFromCollection($where));
```

Collections can be nested with `addCollection('OR')`. An empty collection
renders as an empty string by default; use `setIfEmptyThenShowAll(true)` or
`setIfEmptyThenShowNothing(true)` to make it render as `(1=1)` or `(1=0)`.

Available conditions on `Param`: `is`, `isNot`, `greater`, `greaterOrEqual`,
`lower`, `lowerOrEqual`, `inArray`, `notInArray`, `inQuery`, `notInQuery`,
`regexMatch` and `betweenRange`.

## Transactions

```php
DB::startTransaction();

try {
    DB::executeQuery('UPDATE accounts SET balance = balance - 10 WHERE id = 1');
    DB::executeQuery('UPDATE accounts SET balance = balance + 10 WHERE id = 2');
    DB::endTransaction(true);   // commit
} catch (\Throwable $e) {
    DB::endTransaction(false);  // rollback
    throw $e;
}
```

`commitTransaction()` and `rollbackTransaction()` are available as well.

## Other features

- **Result types**: `selectQuery()` returns strings, `selectQueryToCorrectType()`
  casts values to their column types.
- **Schema helpers**: `checkTableExist()`, `checkIfColumnExistInTable()`,
  `getColumnsOfTable()` and `getCollationOfTableColumn()`.
- **`dbDelta()`**: reconciles your `CREATE TABLE` statements with the actual
  database and returns the changes it made (or would make with `$execute = false`).
- **`export2Csv()`**: exports a table, optionally filtered with a `Collection`.
- **`makeId()`**: creates random or seed-based identifiers. See
  [makeId vs. UUID](docs/makeId-vs-uuid.md) for a comparison.
- **Galera**: `checkIfWsrep()` and `setWsrepSyncWait()`.
- **Statistics**: `getStats()` returns how often each method was called.

Errors are thrown as `TiMMiT\MySQL\DBException`.

## Development

```bash
composer install
```

| Task                      | Command                                                          |
| ------------------------- | ---------------------------------------------------------------- |
| Static analysis (level 9) | `vendor/bin/phpstan analyse`                                     |
| Unit tests                | `vendor/bin/phpunit -c ./`                                       |
| Code style and docblocks  | `phpcs --standard=.phpcs-docblock.xml --extensions=php ./src`    |

The integration tests need a database and are skipped when `DB_TEST_HOST` is not
set. To run them, set `DB_TEST_HOST`, `DB_TEST_USER`, `DB_TEST_PASSWORD`,
`DB_TEST_DATABASE` and `DB_TEST_PORT`. The CI uses a MariaDB 11.4 service with
user, password and database `db_test`.

Pull requests run the same checks in GitHub Actions (`.github/workflows/ci.yml`):
YAML lint, code style, PHPStan, PHPUnit, secret detection and a dependency audit.
Every run has an overview with the results of all checks.

## License

MIT. Created by [Tim Schoondergang](http://www.TiMMiT.nl).
