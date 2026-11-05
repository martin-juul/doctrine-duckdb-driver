# Setup

This page covers connecting DBAL to DuckDB, configuring Doctrine ORM with or without Symfony, and running Doctrine Migrations.

## Connection parameters

Select the driver with DBAL's `driverClass` parameter, or with a `duckdb://` URL (see [URLs](#urls)).

| Parameter | Meaning |
| --- | --- |
| `driverClass` | `MartinJuul\DBAL\DuckDB\Driver` |
| `dbname` | Path of the database file. Preferred over `path`. |
| `path` | Path of the database file, used when `dbname` is not set. |
| `memory` | `true` opens an in-memory database. |
| `driverOptions` | DuckDB configuration options, passed to DuckDB unchanged. |

```php
use Doctrine\DBAL\DriverManager;
use MartinJuul\DBAL\DuckDB\Driver;

$connection = DriverManager::getConnection([
    'driverClass' => Driver::class,
    'dbname' => '/srv/data/app.duckdb',
    'driverOptions' => [
        'threads' => 4,
        'memory_limit' => '4GB',
    ],
]);
```

### `dbname` or `path`

Both name the database file, and `dbname` wins when both are set. Prefer `dbname`: DoctrineBundle appends its test-environment `dbname_suffix` to `dbname` only, so tests get their own file. The one drawback is that `doctrine:database:drop --if-exists` skips the drop for a `dbname` connection (see [limitations](limitations.md#introspection)).

Relative paths resolve against the process's working directory. DuckDB creates the file when it does not exist, but not its directory.

### In-memory databases

With `memory: true`, or with neither `dbname` nor `path`, the connection opens an in-memory database. Each DBAL connection gets its own in-memory database, which disappears when the connection closes.

### `driverOptions`

`driverOptions` takes DuckDB's [configuration options](https://duckdb.org/docs/stable/configuration/overview) by name, with string, integer, float or boolean values. Common ones:

| Option | Example | Effect |
| --- | --- | --- |
| `access_mode` | `'read_only'` | Opens the file read-only, so other processes can read it too. See [deployment](deployment.md). |
| `threads` | `4` | Worker threads DuckDB uses for a query. |
| `memory_limit` | `'4GB'` | Memory DuckDB may use before it spills to disk or fails. |

An unknown option name makes the connection fail with DuckDB's error. DBAL connects lazily, so the error appears with the first statement.

Every connection to one file in one process must use the same `driverOptions`; see [limitations](limitations.md#connections).

### URLs

The `duckdb` scheme maps to the driver. The path after the host lands in `dbname`.

| URL | Database |
| --- | --- |
| `duckdb://localhost/var/app.duckdb` | `var/app.duckdb`, relative to the working directory |
| `duckdb://localhost//srv/data/app.duckdb` | `/srv/data/app.duckdb` (absolute: note the double slash) |
| `duckdb://localhost` | in-memory |

DBAL's URL parser rejects `duckdb:///srv/data/app.duckdb`, so absolute paths need a host and a second slash.

Outside Symfony, parse URLs with the parser from `DuckDBSetup`:

```php
use Doctrine\DBAL\DriverManager;
use MartinJuul\DBAL\DuckDB\Setup\DuckDBSetup;

$params     = DuckDBSetup::createDsnParser()->parse('duckdb://localhost//srv/data/app.duckdb');
$connection = DriverManager::getConnection($params);
```

### The native connection

`$connection->getNativeConnection()` returns the underlying `DuckDB\Connection`, for ext-duckdb's appender, Arrow and async APIs. Statements run on it bypass DBAL, so DBAL's middleware and transaction tracking do not see them.

## Plain PHP

`MartinJuul\DBAL\DuckDB\Setup\DuckDBSetup` applies the package's configuration to Doctrine's configuration objects. Each change applies only to DuckDB, so a configuration shared with another database keeps working for it.

| Method | What it does |
| --- | --- |
| `registerTypes()` | Registers the custom DBAL types (`duckdb_list`, `duckdb_struct`, `duckdb_map`, `duckdb_hugeint`, `duckdb_interval`, `duckdb_native`). Types already registered under those names are left alone. |
| `createDsnParser()` | Returns a DBAL `DsnParser` that maps the `duckdb` scheme to the driver. |
| `configureOrm(Configuration $config)` | Calls `registerTypes()`, then adds an identity-generation preference that makes ORM's `AUTO` strategy use sequences on DuckDB. Preferences already set for other platforms are kept. |
| `registerListeners(EventManager $eventManager)` | Adds the package's ORM listener, which deletes the subclass-table rows of removed JOINED-inheritance entities on DuckDB connections. |
| `omitForeignKeys(Configuration $config)` | Appends a DBAL middleware that leaves foreign-key constraints out of generated DDL for DuckDB connections. |
| `logSchemaNotices(Configuration $config, LoggerInterface $logger)` | Appends a DBAL middleware that logs, as PSR-3 notices, schema changes that lose something DuckDB cannot carry over. See [Schema notices](#schema-notices). |

### Why ORM needs `configureOrm()`

ORM resolves the `AUTO` identifier strategy to `IDENTITY` on platforms it does not know. DuckDB has no identity columns, so without the preference every entity with a default `#[GeneratedValue]` fails when it is persisted: the flush throws a DBAL `DriverException` saying the last inserted ID is unknown. With `configureOrm()`, `AUTO` resolves to `SEQUENCE` and ORM creates a sequence named `<table>_<column>_seq` for each such entity.

### DBAL only

```php
use Doctrine\DBAL\DriverManager;
use MartinJuul\DBAL\DuckDB\Driver;
use MartinJuul\DBAL\DuckDB\Setup\DuckDBSetup;

DuckDBSetup::registerTypes();

$connection = DriverManager::getConnection(['driverClass' => Driver::class, 'dbname' => 'var/app.duckdb']);
```

Call `registerTypes()` before you refer to the custom types by name, for example in a schema definition or as a parameter type. The platform also registers them when it first maps introspected column types.

### ORM

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

ORM's `Configuration` extends DBAL's, so passing it to `DriverManager` also applies DBAL settings such as the middleware from `omitForeignKeys()`.

`registerListeners()` matters for entities mapped with JOINED inheritance. ORM deletes only the root-table row of a removed entity and relies on `ON DELETE CASCADE` foreign keys for the subclass tables, which DuckDB cannot enforce and the platform therefore leaves out (see [limitations](limitations.md#keys-with-a-referential-action-are-left-out)). The listener deletes those rows in the same flush. For an entity manager that already exists, call `DuckDBSetup::registerListeners($entityManager->getEventManager())`. The listener does nothing on connections to other databases.

### Omitting foreign keys

DuckDB checks foreign keys early, and some valid ORM flushes fail because of it (see [limitations](limitations.md#foreign-keys)). If your application hits this, you can leave foreign-key constraints out of the schema:

```php
DuckDBSetup::omitForeignKeys($config);

$connection = DriverManager::getConnection($params, $config);
```

Call it before creating the connection. Generated DDL then contains no foreign keys, schema comparison ignores them, and introspection reports none. ORM associations keep working; referential integrity becomes the application's job.

### Schema notices

Some schema changes succeed but lose something DuckDB cannot carry over. Today that is a column's collation, such as `COLLATE NOCASE`, when the column gets a type without collations (see [limitations](limitations.md#check-constraints-and-collations-in-a-rebuild)). The driver logs each such change at the PSR-3 `notice` level:

```text
DuckDB drops the collation NOCASE of column t.code: its new type INTEGER has no collation; only VARCHAR columns have one.
```

The context carries `table`, `column`, `collation` and `type`. Pass a PSR-3 logger to receive the notices; without one they are discarded:

```php
DuckDBSetup::logSchemaNotices($config, $logger);

$connection = DriverManager::getConnection($params, $config);
```

The notice is logged when the change is planned: when the schema manager compares schemas or alters a table, and when a table rebuild's SQL is generated. A migration generated with `doctrine:migrations:diff` therefore logs it during `diff`, not when the migration runs.

## Symfony

`MartinJuul\DBAL\DuckDB\Bridge\Symfony\DuckDBBundle` configures DoctrineBundle for DuckDB:

- it registers the custom types and the `duckdb` URL scheme;
- it adds the sequence preference to every entity manager, keeping preferences configured there;
- it registers the listener that deletes the subclass-table rows of removed JOINED-inheritance entities on every connection's event manager, so every entity manager uses it;
- it logs [schema notices](#schema-notices) of every DuckDB connection to the `logger` service, in the `doctrine` channel when MonologBundle is installed;
- it omits foreign keys on the connections you list under `duckdb.omit_foreign_keys`.

Connections to other databases are left unchanged. The bundle also works in applications that use DBAL without the ORM.

Register it after DoctrineBundle in `config/bundles.php`:

```php
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use MartinJuul\DBAL\DuckDB\Bridge\Symfony\DuckDBBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;

return [
    FrameworkBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    DoctrineMigrationsBundle::class => ['all' => true],
    DuckDBBundle::class => ['all' => true],
];
```

Point the connection at a `duckdb` URL in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
    orm:
        mappings:
            App:
                type: attribute
                dir: '%kernel.project_dir%/src/Entity'
                prefix: 'App\Entity'
                alias: App
```

and in `.env`:

```dotenv
DATABASE_URL="duckdb://localhost/%kernel.project_dir%/var/app.duckdb"
```

`%kernel.project_dir%` is an absolute path, so this URL has the double slash of the absolute form.

No `identity_generation_preferences`, type or driver configuration is needed. To pass DuckDB options, use DoctrineBundle's `options` key, which becomes `driverOptions`:

```yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        options:
            threads: 4
            memory_limit: '4GB'
```

To omit foreign keys, name the DBAL connections in `config/packages/duckdb.yaml`:

```yaml
duckdb:
    omit_foreign_keys: [default]
```

`doctrine:database:create` creates an empty database file, and `doctrine:database:drop --force` deletes it and its write-ahead log (`.wal`). Both refuse an in-memory database or a connection without a file, and drop refuses a file that is not a DuckDB database. `doctrine:database:drop --if-exists` skips the drop when the connection uses `dbname`; see [limitations](limitations.md#introspection).

## Doctrine Migrations

Migrations work as usual: `migrations:diff` generates DuckDB SQL, and `migrations:migrate` runs each migration in a transaction, because DuckDB's DDL is transactional. `all_or_nothing: true` also works and runs every migration in one transaction.

### Table rebuilds

When a change cannot be expressed with DuckDB's `ALTER TABLE` (for example renaming a column of a table that has an index or is referenced by a foreign key), the generated SQL rebuilds the table and every table that references it. The rebuild copies the rows to a staging table, recreates the table and copies them back, keeping indexes, comments, sequence defaults, `CHECK` constraints and collations. It runs inside the migration's transaction, so a failure leaves the tables and rows unchanged. [Limitations](limitations.md#schema-changes-and-table-rebuilds) covers the cost and the cases a rebuild refuses.

Generated rebuild SQL contains two marker statements, `DBAL_DUCKDB_REBUILD_BEGIN` and `DBAL_DUCKDB_REBUILD_END`, which the driver handles. Run the SQL through DBAL; the DuckDB CLI rejects the markers.

### Self-referencing tables

A self-referencing table, such as a category tree whose `parent_id` references its own `id`, is copied one tree level per statement, so the number of statements depends on the data. Generated SQL cannot express that: `migrations:diff` and `schema:update` fail with a DBAL `NotSupported` error naming the table and `MartinJuul\DBAL\DuckDB\Migrations\RebuildTables::queue()`.

Write that migration by hand with `RebuildTables::queue()`. Pass the connection, a closure that returns the target schema from the current one, and the migration's `addSql()`:

```php
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\Migrations\AbstractMigration;
use MartinJuul\DBAL\DuckDB\Migrations\RebuildTables;

final class Version20261104120000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        RebuildTables::queue(
            $this->connection,
            static fn (Schema $current): Schema => $current->edit()
                ->modifyTableByUnquotedName('category', static function (TableEditor $table): void {
                    $table->renameColumnByUnquotedName('name', 'title');
                })
                ->create(),
            $this->addSql(...),
        );
    }
}
```

The helper compares the database's current schema with the target, counts the tree's levels, and queues the statements through `addSql()`. It executes nothing itself, so `--dry-run` only prints them. The statements end with a check that fails when rows are left over because their parents are missing or form a cycle.

- Build the target with DBAL's `Schema::edit()` and `TableEditor` API as above. DBAL's deprecated `Table::renameColumn()` produces a wrong copy statement, and the migration fails.
- Do not also change the same tables through the `$schema` argument of `up()`: Migrations would generate SQL for those changes as well.
- Outside migrations, the schema manager applies the same change directly: pass the target schema to `$connection->createSchemaManager()->migrateSchema()`, or use `alterSchema()` or `alterTable()`.

### Owned sequences in `down()`

A DBAL column declared `autoincrement` gets its own sequence, named `<table>_<column>__autoinc`. The `down()` that Migrations generates drops the table but leaves that sequence behind. Add the drop after the table's:

```php
$this->addSql('DROP TABLE ticket');
$this->addSql('DROP SEQUENCE ticket_id__autoinc');
```
