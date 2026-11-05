# martin-juul/dbal-duckdb

A Doctrine DBAL 4 driver, platform and schema manager for [DuckDB](https://duckdb.org) 2.0, built on the native [ext-duckdb](https://github.com/martin-juul/php-duckdb) extension. It lets DBAL, Doctrine ORM, Doctrine Migrations and Symfony's DoctrineBundle work against a DuckDB database file or an in-memory database. Where DuckDB lacks a feature that Doctrine expects, the package either emulates it or fails with an error that names the gap and the workaround.

## Requirements

| Package | Version |
| --- | --- |
| PHP | 8.4 or later |
| ext-duckdb | ^2.0, linked against DuckDB 2.0 |
| doctrine/dbal | ^4.5 |
| doctrine/orm (optional) | ^3.7 |
| doctrine/doctrine-bundle (optional) | ^3.3 |

## Installation

Install ext-duckdb first. See the [php-duckdb README](https://github.com/martin-juul/php-duckdb) for PIE and source builds. Then add the package:

```sh
composer require martin-juul/dbal-duckdb
```

## Quick start with DBAL

```php
use Doctrine\DBAL\DriverManager;
use MartinJuul\DBAL\DuckDB\Driver;

$connection = DriverManager::getConnection(['driverClass' => Driver::class, 'dbname' => 'var/app.duckdb']);

$connection->executeStatement('CREATE TABLE event (id INTEGER PRIMARY KEY, name VARCHAR, created_at TIMESTAMP)');
$connection->insert('event', ['id' => 1, 'name' => 'signup', 'created_at' => '2026-11-04 10:00:00']);

$counts = $connection->fetchAllKeyValue('SELECT name, count(*) FROM event GROUP BY name');
```

Leave out `dbname` for an in-memory database.

## Quick start with the ORM

ORM needs two extra steps. `DuckDBSetup::configureOrm()` registers the package's types and makes the default (`AUTO`) identifier strategy use sequences; without it, every entity with a default generated ID fails to persist, because DuckDB has no identity columns. `DuckDBSetup::registerListeners()` deletes the subclass rows of entities mapped with JOINED inheritance when they are removed.

```php
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Setup\DuckDBSetup;

$config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/src/Entity'], isDevMode: true);
$config->enableNativeLazyObjects(true);
DuckDBSetup::configureOrm($config);

$eventManager = new EventManager();
DuckDBSetup::registerListeners($eventManager);

$connection    = DriverManager::getConnection(['driverClass' => Driver::class, 'dbname' => 'var/app.duckdb'], $config);
$entityManager = new EntityManager($connection, $config, $eventManager);
```

Symfony applications register `DuckDBBundle` instead and need only a `duckdb://` database URL. See [docs/setup.md](docs/setup.md).

## What works

- DBAL queries, prepared statements, the QueryBuilder and transactions, with results converted to the shapes DBAL's types expect.
- Schema tooling: introspection, the comparator, `schema:update`, `schema:validate`, and `database:create`/`database:drop` for database files.
- Doctrine Migrations, including `all_or_nothing`. Schema changes that DuckDB's `ALTER TABLE` cannot express run as table rebuilds inside a transaction.
- Doctrine ORM entities with default generated IDs (from sequences), associations, nested transactions through `wrapInTransaction()` and `flush()`, and optimistic locking.
- Custom types for DuckDB's `LIST`, `STRUCT`, `MAP`, `HUGEINT` and `INTERVAL` columns, and a passthrough type for any other DuckDB type.
- Streaming of large results in constant memory, and access to the native `DuckDB\Connection` through `$connection->getNativeConnection()` for the appender, Arrow and async APIs.
- A Symfony bundle that configures DoctrineBundle for DuckDB.

## Differences from other databases

Most of these fail loudly with an error that explains the limit. [docs/limitations.md](docs/limitations.md) lists each one with the error and the workaround.

- No row locks: `forUpdate()` and ORM's pessimistic locks throw. Use optimistic locking.
- `lastInsertId()` throws. Use `INSERT … RETURNING` or sequences.
- Nested transactions are flattened. A rollback at an inner level makes the whole transaction roll back.
- Foreign keys that ask for `CASCADE`, `SET NULL` or `SET DEFAULT` are left out, because DuckDB cannot enforce them; the application enforces those relations. Other foreign keys are real, cannot form cycles between tables, and DuckDB checks them earlier than other databases.
- String and binary lengths are not enforced: `VARCHAR(255)` becomes `VARCHAR`.
- Some schema changes rebuild whole tables, which costs memory and time on large tables.
- Across processes, a database file has either one writer or many readers, never both. See [docs/deployment.md](docs/deployment.md).

## Documentation

- [Setup](docs/setup.md): connection parameters, URLs, plain-PHP setup, the Symfony bundle and Doctrine Migrations.
- [Limitations](docs/limitations.md): every unsupported or emulated feature, its error and its workaround.
- [Deployment](docs/deployment.md): process models that work with DuckDB's file locking.
- [Types](docs/types.md): how DuckDB values reach PHP, the DBAL type mappings and the custom types.

## License

MIT. See [LICENSE](LICENSE).
