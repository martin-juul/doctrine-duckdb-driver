# Limitations

DuckDB 2.0 lacks some features that Doctrine expects from a database. For each one, the package either emulates it or fails with an error that names the gap, at schema generation or when the call is made. This page lists every such case: what happens, the error you see, and what to do instead.

Exception classes without a namespace below are DBAL's, in `Doctrine\DBAL\Exception\` (`NotSupported` is `Doctrine\DBAL\Platforms\Exception\NotSupported`).

## Results and streaming

### Results are fully buffered

Every DBAL query reads its whole result into memory before the first row is returned. A query over millions of rows can therefore exhaust PHP's memory. Buffering is deliberate: DuckDB refuses new statements on a connection while a streamed result is still open, and Doctrine code often reads only part of a result, for example with `fetchOne()`.

Workaround: stream large reads with `MartinJuul\DBAL\DuckDB\Streaming\StreamingQuery::rows()`, which reads one chunk at a time:

```php
use MartinJuul\DBAL\DuckDB\Streaming\StreamingQuery;

foreach (StreamingQuery::rows($connection, 'SELECT * FROM event WHERE created_at >= ?', ['2026-01-01']) as $row) {
    // $row is ['id' => 1, 'name' => 'signup', 'created_at' => '2026-11-04 10:00:00']
}
```

- Each row is an associative array keyed by column name, with values in the same shapes as DBAL's results (see [types](types.md#values-returned-by-the-driver)).
- Parameters are a positional list for `?` placeholders or a map for DuckDB's `$name` placeholders. DBAL's `:name` syntax and DBAL type conversion do not apply.
- While the rows are open, the connection refuses every other statement with a `DriverException` whose code is ext-duckdb's `ErrorType::ResourceInUse`. The stream closes when the loop finishes, when the iterator is destroyed, or when you call `close()`. If you keep the iterator in a variable and break out of the loop early, call `$rows->close()`.
- The query runs on the native connection, so DBAL middleware (logging, the Symfony profiler) does not see it, and its errors are the driver's `DriverError`, not converted DBAL exceptions.
- The iterator can be read once.

## Transactions

### Nested transactions are flattened

DBAL nests transactions with savepoints. DuckDB has no savepoints, so the driver joins every nested level to the outermost transaction. The platform's `supportsSavepoints()` returns `true` so that DBAL and ORM nest at all; the driver intercepts the `SAVEPOINT`, `RELEASE SAVEPOINT` and `ROLLBACK TO SAVEPOINT` statements and never sends them to DuckDB. DBAL's logging middleware and the Symfony profiler still list those statements, although they never ran.

Nothing is committed until the outermost level commits. ORM's `flush()` and `wrapInTransaction()` inside an application transaction work as expected.

### A rollback at an inner level dooms the whole transaction

An inner level cannot undo its own writes, because there is no savepoint to return to. When an inner level rolls back:

1. the transaction becomes rollback-only. Every later statement, reads included, fails with `TransactionRolledBack` ("The transaction is rollback-only: an inner transaction level rolled back, …") until the outermost level ends;
2. the outermost `commit()` rolls back all work of every level and throws `TransactionRolledBack` ("… nothing was committed"). The connection is then idle and usable;
3. the outermost `rollBack()` succeeds.

DBAL's own `Connection::isRollbackOnly()` stays `false`: the state lives in the driver.

Workaround: do not catch an inner failure and carry on with the outer transaction. Let the exception reach the outermost level and retry the whole unit of work there.

### Any error aborts the transaction

DuckDB aborts a transaction when any statement in it fails, as PostgreSQL does. Later statements in the same transaction fail, and `commit()` throws `TransactionRolledBack`. The driver has then already ended the transaction: nothing was committed, DBAL reports no active transaction, and the connection accepts new statements.

A commit can also fail on its own, for example on a uniqueness conflict with a concurrent transaction or when DuckDB runs out of memory. The driver ends the transaction in the same way; a uniqueness conflict throws `UniqueConstraintViolationException`, anything else `TransactionRolledBack`.

Workaround: catch the exception outside the transaction and retry the whole transaction if appropriate.

### Write-write conflicts

When two transactions on the same database update the same row, DuckDB rejects the second write when the statement runs. It surfaces as `DeadlockException`, which is a `RetryableException`. Retry the transaction.

### Raw transaction statements are rejected

`BEGIN`, `START TRANSACTION`, `COMMIT`, `END`, `ROLLBACK` and `ABORT` sent as SQL fail with a `DriverException`: "The DuckDB driver does not run "COMMIT". Control transactions with the connection's beginTransaction(), commit() and rollBack() methods." A raw `COMMIT` on an aborted DuckDB transaction reports success while rolling back, and raw statements would also bypass the nesting emulation.

Workaround: use `beginTransaction()`, `commit()`, `rollBack()` or `transactional()`.

### Only snapshot isolation

DuckDB runs every transaction under snapshot isolation, which DBAL calls `REPEATABLE READ`. `setTransactionIsolation()` with any other level throws `NotSupported`; with `REPEATABLE_READ` it does nothing.

## Locking

### No row locks

DuckDB has no row locks. `QueryBuilder::forUpdate()` and ORM's `LockMode::PESSIMISTIC_READ` and `LockMode::PESSIMISTIC_WRITE` (in `find()`, `lock()`, `refresh()` or a DQL query's lock mode) throw `NotSupported` before any SQL runs: "DuckDB does not support row locks, so FOR UPDATE cannot be honored. Use optimistic locking with a version column instead." For ORM, the message names the lock mode instead of `FOR UPDATE`.

Workaround: use optimistic locking. Add a `#[Version]` column to the entity and pass `LockMode::OPTIMISTIC` where you need the check; ORM then fails a flush that would overwrite a concurrent change. Write-write conflicts between open transactions are also detected (see above).

## Identifiers and sequences

### `lastInsertId()` throws

DuckDB has no identity columns and no `lastval()`, and DBAL 4's `lastInsertId()` takes no sequence name. It throws a `DriverException`: "DuckDB has no identity columns and no lastval(), so the last inserted ID is unknown. Read generated IDs with INSERT … RETURNING, or use a sequence."

Workaround: read generated IDs with `RETURNING`:

```php
$id = $connection->fetchOne("INSERT INTO ticket (title) VALUES ('first') RETURNING id");
```

or fetch the next value of a sequence before inserting:

```php
$id = $connection->fetchOne($connection->getDatabasePlatform()->getSequenceNextValSQL('ticket_seq'));
```

ORM needs the sequence preference that `DuckDBSetup::configureOrm()` or the Symfony bundle adds; without it, every persist of an entity with a default generated ID fails with this error. See [setup](setup.md#why-orm-needs-configureorm).

An entity mapped with `#[GeneratedValue(strategy: 'IDENTITY')]` always fails this way. Use `AUTO` or `SEQUENCE`.

### Autoincrement columns get an owned sequence

A DBAL column declared `autoincrement` gets a sequence named `<table>_<column>__autoinc` and a `nextval()` default, so plain INSERTs receive IDs. Introspection reports the column as `autoincrement` and does not list the sequence separately.

- DuckDB cannot rename sequences. Renaming the table keeps the sequence's old name; it still belongs to the table.
- Dropping the table through a schema diff or the schema manager's `dropTable()` drops the sequence too.
- A plain `DROP TABLE`, including the one in the `down()` that Doctrine Migrations generates, leaves the sequence behind. Add `DROP SEQUENCE <table>_<column>__autoinc` after it.

ORM entities with a default generated ID use their own sequence, `<table>_<column>_seq`, once the setup is applied.

### Sequences cannot be altered

DuckDB has no `ALTER SEQUENCE`. A schema change that alters a sequence, such as a new `allocationSize` in an ORM mapping, fails with `NotSupported`: "Operation "…getAlterSequenceSQL" is not supported by platform."

Workaround: write the migration by hand. Drop the sequence and create it with the new settings and a start value above the highest ID in use. A sequence that a column default draws from cannot be dropped while the table exists.

DuckDB rewrites a sequence's `START WITH` as values are drawn, so introspection reads a sequence's initial value from its `MINVALUE`, which the platform sets to the same value when it creates the sequence. Sequences created outside Doctrine without a matching `MINVALUE` may show a changed initial value in schema comparisons.

## Foreign keys

### Keys with a referential action are left out

DuckDB 2.0 enforces only `NO ACTION` and `RESTRICT`. A foreign key that requests `CASCADE`, `SET NULL` or `SET DEFAULT` for `ON DELETE` or `ON UPDATE` is left out of generated DDL; every other foreign key is created. The index on the key's columns is still created. Schema comparison ignores the left-out keys, so `doctrine:schema:validate` and `migrations:diff` report no change for them, and they cause no rebuilds and take no part in [cycles](#cycles-between-tables-lose-one-key).

The relations behind these keys are application-enforced: DuckDB neither checks them nor applies the action. Deleting a parent row leaves its child rows in place, still holding the parent's key.

ORM declares such keys in three places:

- **`onDelete` on a join column**, such as `#[JoinColumn(onDelete: 'CASCADE')]`. Removing the parent entity leaves its children. Remove them in the application, for example with `cascade: ['remove']` on the inverse side; the left-out key is not checked, so children and parent can go in one flush.
- **JOINED inheritance.** ORM declares `ON DELETE CASCADE` from every subclass table to the root table, and on removal deletes only the root row. The package's `MartinJuul\DBAL\DuckDB\ORM\JoinedSubclassRowsListener` deletes the entity's subclass-table rows in the same flush transaction. The Symfony bundle registers it; without Symfony, register it with `DuckDBSetup::registerListeners()` (see [setup](setup.md#orm)). Without the listener, removed entities leave rows in their subclass tables. DQL `DELETE` queries on a JOINED class already delete from every table.
- **Many-to-many associations with default join columns.** ORM declares `ON DELETE CASCADE` on both join columns, and when an owning-side entity is removed it does not delete the entity's join-table rows, because it relies on the cascade. Those rows stay behind. Clear the collection and flush before removing the entity, or declare the join columns with `#[JoinTable]` without `onDelete`, which makes ORM delete the rows itself; such keys are created and checked.

### Cycles between tables lose one key

DuckDB creates a foreign key only together with its table, and only when the referenced table already exists, so it cannot create tables whose foreign keys reference each other in a cycle. Generated DDL leaves out one foreign key per cycle and creates the others, with the tables in dependency order. The index on the left-out key's columns is still created. A table that references itself forms no cycle, and keys [left out for their referential action](#keys-with-a-referential-action-are-left-out) take no part.

The left-out key is chosen from the whole schema, the same way every time:

1. the key that lies on the most cycles goes first, so a key that overlapping cycles share breaks them all;
2. then a key whose columns all accept `NULL`;
3. then the key whose table name, then column names, sort first.

For example, with ORM entities `User` and `Post` where the nullable `User::$lastPost` references a post and the required `Post::$author` references a user, the key on `user.last_post_id` is left out and the key on `post.author_id` is created and checked.

The relation behind a left-out key is application-enforced: DuckDB accepts any value in its columns, including the ID of a row that does not exist. Schema comparison ignores the left-out keys, so `doctrine:schema:validate`, `doctrine:schema:update` and `migrations:diff` report no change for them, and they cause no rebuilds. Adding a key or table that changes which key a cycle loses rebuilds the affected tables.

Updating the columns of a left-out key on a row that other rows reference fails with DuckDB's [early check](#duckdb-checks-foreign-keys-early), because ORM indexes its join columns. In the example, ORM saves a new user with `lastPost` set by inserting the user with `NULL` and updating `last_post_id` after inserting the post, which fails once the post references the user.

Only schema comparison sees the whole schema. Comparing a single table of a cycle with the comparator's `compareTables()` proposes adding the left-out key, and applying that diff fails when the rebuild drops the table, because the cycle's other table references it. ORM's schema tool and validator, Doctrine Migrations and DBAL's schema manager compare whole schemas.

The same rule means a foreign key cannot be added to or dropped from an existing table. Schema diffs handle this by rebuilding the table (see [table rebuilds](#schema-changes-and-table-rebuilds)).

### Referenced columns need a unique constraint

DuckDB requires a `PRIMARY KEY` or `UNIQUE` constraint on the referenced columns, in the same column order as the foreign key. The platform declares one when it creates the referenced table. If the referenced table was created earlier with only a unique index, a schema comparison rebuilds it when a new foreign key references it. Without a comparison, as with the schema manager's `createTable()` for a new referencing table or `alterTable()` with a table diff, DuckDB rejects the key with "Failed to create foreign key: …".

When the referenced table is created together with the referencing one, as ORM's schema tool and `Schema::toSql()` do, a composite foreign key whose columns are listed in another order than the referenced key is created with its column pairs in the referenced key's order, and schema comparison treats both orders as equal. A table created on its own with such a key, or rebuilt without its referenced table, still fails, because the platform cannot see the referenced key's order.

DuckDB 2.0 does not support foreign keys that reference a table in another schema.

### DuckDB checks foreign keys early

DuckDB checks a foreign key when each statement runs, against the state before the statement, rather than at the end of the statement or transaction. Valid sequences of statements then fail:

- within one transaction, deleting a child row and then its parent fails, because DuckDB does not yet see the child as deleted;
- an `UPDATE` that sets any indexed column of a referenced row fails, even under autocommit and even when the value does not change (`SET id = id`). DuckDB runs such an update as a delete and an insert. This covers primary-key and unique columns and any column with an index, such as an ORM join column.

ORM runs into the first case when one `flush()` removes a child and its parent, for example through `cascade: ['remove']`, and into the second when an entity that other rows reference has a changed unique column or association.

The driver raises `MartinJuul\DBAL\DuckDB\Exception\EarlyForeignKeyCheckViolation`, a subclass of `ForeignKeyConstraintViolationException`. DuckDB reports a real violation and an early-check rejection with the same message, so the exception cannot tell them apart. Its message says the row may still be referenced, or DuckDB's early check may have rejected a valid sequence of statements, and points to "Over-eager constraint checking in foreign keys" in DuckDB's documentation on indexes. Check for a real reference before you assume the early check.

Workarounds:

- delete children and parents in separate transactions (for ORM, separate flushes outside a surrounding transaction);
- do not update indexed columns of rows that other rows reference; give such tables a surrogate key that never changes;
- omit foreign-key constraints for the connection (below).

### Omitting foreign keys

`DuckDBSetup::omitForeignKeys()` or the bundle's `duckdb.omit_foreign_keys` option leaves foreign-key constraints out of generated DDL for a connection. Schema comparison then ignores foreign keys and introspection reports none. This removes the early-check failures, the keys left out of cycles and the rebuilds that foreign-key changes cause. Referential integrity becomes the application's job. See [setup](setup.md#omitting-foreign-keys).

## Schema changes and table rebuilds

### Changes that `ALTER TABLE` cannot express rebuild the table

DuckDB 2.0's `ALTER TABLE` can add a nullable column or one with a default, change or drop a default, and change comments on any table. Other changes, such as setting `NOT NULL`, changing a type or dropping a column, work in place only on tables with no index that no foreign key references. Renames, removing `NOT NULL`, autoincrement and `CHECK` changes, and every foreign-key change always need a rebuild.

For those changes the generated SQL rebuilds the table, and every table that references it: it copies each table into a staging table with explicit column lists, drops the originals, creates the new tables with their owned sequences, copies the rows back column by column, and recreates indexes and comments. IDs continue where they left off.

- **Memory:** a rebuild holds roughly two copies of the rebuilt tables in memory until it commits. With a low `memory_limit` it fails at commit with the data intact. Raise `memory_limit` in `driverOptions` before rebuilding large tables.
- **Atomicity:** the rebuild runs in the migration's transaction or in the caller's. Without one (for example `doctrine:schema:update --force`), the driver opens a transaction for it. A failure at any point leaves the original tables and rows in place.
- **Time:** every row of every rebuilt table is copied twice.

### A unique index over duplicate data fails at commit

When a rebuild adds a unique index to a table whose rows contain duplicates, DuckDB reports the violation only at `COMMIT`. Under Doctrine Migrations you then see `NoActiveTransaction` ("There is no active transaction.") instead of the uniqueness error: the driver has already ended the failed transaction, and Migrations' failure handling then reports that no transaction is active, hiding the original error. Nothing is committed.

Workaround: when a migration that adds a unique index fails with `NoActiveTransaction`, look for duplicate values in the indexed columns. Remove the duplicates before adding the index.

### Self-referencing tables need the migration helper

A self-referencing table is copied one tree level per statement, so the rebuild depends on the data. `migrations:diff`, `schema:update` and any other caller of `getAlterSchemaSQL()` fail with `NotSupported`: "Rebuilding the self-referencing table … cannot be expressed as generated SQL. Apply the change through the schema manager (alterSchema(), migrateSchema() or alterTable()), or from a migration with MartinJuul\DBAL\DuckDB\Migrations\RebuildTables::queue()."

Workaround: [write the migration with `RebuildTables::queue()`](setup.md#self-referencing-tables), or apply the change with the schema manager.

### Schema asset filters

The rebuild must see every table that references a rebuilt table. If a schema asset filter hides a referencing table, the rebuild fails when it drops the referenced table, and the transaction rolls back.

Workaround: widen the filter for the migration, or apply the change by hand.

### `getAlterTableSQL()` without a schema comparison

`AbstractPlatform::getAlterTableSQL()` receives a single `TableDiff` and knows nothing about other tables. It rebuilds only that table, so the rebuild fails at the drop when another table references it. Schema diffs from the schema manager's comparator (which Migrations, `schema:update` and `alterSchema()` use) include referencing tables.

### Tables with generated columns cannot be rebuilt

DuckDB reports a generated column like a plain column with a default, so a rebuild would turn it into one. A rebuild of such a table fails with `NotSupported`: "Cannot rebuild table …: its column … is a generated column, …".

Workaround: change these tables with hand-written SQL.

### What a rebuild does not keep

- **Named `ENUM` types:** a column of a named `ENUM` type (`CREATE TYPE mood AS ENUM (…)`) comes back as an anonymous `ENUM` with the same values.
- **Indexes on expressions, when the SQL comes from `getAlterTableSQL()`:** introspection skips indexes on expressions, such as `CREATE INDEX … ON t (lower(email))`, because DBAL cannot represent them. The rebuild reads them from DuckDB's catalog instead, which needs the connection. Schema diffs from the schema manager's comparator, the schema manager's `alterTable()`, `alterSchema()` and `renameTable()`, and `RebuildTables::queue()` keep them. `AbstractPlatform::getAlterTableSQL()` has no connection, so a rebuild it generates drops them. Recreate them in the same migration after the rebuild.

### Partial and expression indexes

DuckDB cannot create partial indexes. An index with a `WHERE` clause (the `where` option) fails schema generation with `NotSupported`. DBAL cannot declare expression indexes; create them with SQL.

A rebuild recreates an expression index under its name, on the rebuilt or renamed table, except in these cases:

- **The change renames or drops a column the index uses:** the rebuild fails and rolls back with "Cannot rebuild table …: its expression index … uses column …, which this change renames or drops. …". The check runs when the rebuild runs, not when the SQL is generated. Workaround: in the same migration, drop the index before the change and create it again afterwards.
- **The SQL comes from `getAlterTableSQL()`:** see [What a rebuild does not keep](#what-a-rebuild-does-not-keep).
- **The table has no other index:** a change that DuckDB can apply in place to an unindexed table, such as dropping a column or renaming the table, is applied in place, and DuckDB refuses it with "Dependency Error: Cannot alter entry … because there are entries that depend on it". Workaround: drop the expression index before the change and create it again afterwards.

## Introspection

- **The `main` schema:** DuckDB's default schema is `main`, as PostgreSQL's is `public`. Introspection lists it, and names tables in it without a qualifier.
- **Unique constraints on referenced columns:** a `UniqueConstraint` declared in a DBAL schema on columns that a foreign key references shows up as a change in every comparison, because DBAL's comparator compares unique indexes. Declare a unique index instead. ORM maps unique columns to unique indexes and is not affected.
- **Expression defaults:** on a non-integer column, a default that is an SQL expression, rather than a literal or one of DBAL's current date and time defaults, shows up as a change in every comparison. Use a literal default or set the value in the application.
- **Keyword table names:** the deprecated `listTableNames()` returns table names that are SQL keywords quoted (`"order"`). `introspectTableNames()` and `introspectSchema()` return them as plain names.
- **`doctrine:database:drop --if-exists`:** DoctrineBundle runs the command on a connection without the file, which cannot see the file, so with a `dbname` connection the command assumes the database does not exist and skips the drop. Plain `doctrine:database:drop --force` works. With a `path` connection both forms work.
- **Database lifecycle:** `createDatabase()` creates an empty database file, and `dropDatabase()` deletes the file and its `.wal`. Both refuse an empty or `:memory:` name with a DBAL `InvalidArgumentException`, and `dropDatabase()` refuses to delete a file that is not a DuckDB database.

## Types and strings

### Lengths are not enforced

DuckDB neither enforces nor reports string and binary lengths. The platform declares `string` columns as `VARCHAR` and `binary` columns as `BLOB`, whatever their `length`, and introspection reports no length. A value longer than the mapped length is stored in full.

Workaround: validate lengths in the application, or add a `CHECK (length(col) <= n)` constraint with SQL.

### Invalid UTF-8 is rejected

A string parameter that is not valid UTF-8 fails before the statement runs, with a `DriverException` whose code is ext-duckdb's `ErrorType::Conversion`: "Parameter 1 is not valid UTF-8. Bind binary data with ParameterType::BINARY or ParameterType::LARGE_OBJECT." DuckDB's `VARCHAR` holds only UTF-8, and ext-duckdb 2.0.0 would otherwise bind the bytes without checking them.

Workaround: bind binary data with `ParameterType::BINARY` or `ParameterType::LARGE_OBJECT`, or use DBAL's `binary` or `blob` types, which do that.

## Connections

### One writer or many readers per file

Across processes, DuckDB lets one process open a file for writing, or any number of processes open it read-only, never both. Opening a file another process holds fails with a `ConnectionException` that explains the rule. DBAL connects lazily, so the error appears with the first statement. [Deployment](deployment.md) describes process models that fit this rule.

### Same configuration for every connection to a file

Connections in one process share one DuckDB instance per database file. Opening a file that is already open in the same process with different `driverOptions`, for example read-only next to read-write, fails with a `ConnectionException`: "Connections in one process share a single DuckDB instance per database file, so every connection to this file must pass the same driverOptions as the connections already open on it."

Workaround: give every connection to one file the same `driverOptions`. The instance lives while any connection or result on the file is open.

### Session time zone

Each connection sets DuckDB's session `TimeZone` to PHP's default time zone when it connects. A process that changes PHP's default time zone afterwards must reconnect. See [deployment](deployment.md#time-zone).
