# Deployment

DuckDB is an embedded database: it runs inside the PHP process and locks the database file. How your application's processes open the file decides which deployment shapes work.

## The locking rule

Across processes, DuckDB lets a database file be open either by one process for writing or by any number of processes read-only, never both:

- a file one process holds read-write cannot be opened by any other process, read-only included;
- a file held read-only by one or more processes can be opened read-only by more, but not read-write.

A connection that breaks the rule fails with a DBAL `ConnectionException` that carries DuckDB's lock message and explains the rule. DBAL connects lazily, so the error appears with the connection's first statement.

A connection opens the file read-only when its `driverOptions` contain `'access_mode' => 'read_only'`:

```php
$reader = DriverManager::getConnection([
    'driverClass' => Driver::class,
    'dbname' => '/srv/data/app.duckdb',
    'driverOptions' => ['access_mode' => 'read_only'],
]);
```

Within one process there is no such limit: any number of connections can read and write the same file. They share one DuckDB instance, so they must all use the same `driverOptions` (see [below](#one-configuration-per-file-in-a-process)).

A typical PHP-FPM setup, where many short-lived worker processes each open the database for writing, breaks the rule. Choose one of the shapes below instead.

## Single long-running process

Run the application as one long-lived process that holds the read-write connection: FrankenPHP in worker mode, RoadRunner, Swoole or a CLI daemon. The process can serve concurrent requests over several connections to the same file, because they share one DuckDB instance.

Configure a single worker process for the database, or route every database access through one process. With several worker processes, only the first one to connect gets the file.

## In-memory or per-request databases

An in-memory database (`memory: true`, or a `duckdb://localhost` URL) belongs to its DBAL connection: each connection gets its own, nothing is shared between connections or processes, and the data disappears when the connection closes. This suits:

- analytics over files, where each request runs `read_parquet()`, `read_csv()` or similar against data on disk or object storage;
- tests, where each test gets a fresh database;
- per-request scratch work.

A per-request file works the same way: give each request or job its own database file, so no two processes open the same one.

## Read-mostly applications

When many processes read and writes are rare, keep the readers on read-only connections and update the data by swapping files.

### Swap in a new file

1. A writer process builds the new database in a separate file, for example `app.duckdb.next`, never in the file readers have open.
2. The writer closes every connection to the new file. Closing checkpoints the write-ahead log into the database file; a file published with its changes still in a `.wal` is incomplete.
3. The writer renames the new file over the path readers open (`rename('app.duckdb.next', 'app.duckdb')`). A rename on the same file system is atomic, so a reader opens either the old file or the new one.

Readers keep reading the old file until they reconnect: on Linux, macOS and other POSIX systems, the replaced file's data stays readable until its last reader closes it. Where renaming over an open file is not possible, stop the readers instead (next pattern).

### Stop readers during writes

Alternatively, stop the readers, run the writes with a read-write connection, close it, and start the readers again. This fits batch jobs that run at fixed times.

### Stale readers

A reader sees a swapped file only after it has closed every connection to the old one. ext-duckdb keeps one DuckDB instance per file and process for as long as any connection or result on it is alive, and a new connection reuses that instance, so it still reads the old file.

Short-lived processes, such as PHP-FPM workers, reconnect on every request and pick up the new file on their own. Long-running processes, such as Messenger consumers and RoadRunner or FrankenPHP workers, must close their connections to switch over: call `$connection->close()` (DBAL reconnects on the next statement) and release any results or streaming iterators still open, for example after every message, after a fixed number of requests, or when they notice that the file changed.

## One configuration per file in a process

Every connection to one file within one process must pass the same `driverOptions`, because the connections share a single DuckDB instance. A process that opens a file read-write and then opens it again read-only, or with different `threads` or `memory_limit`, fails with a `ConnectionException` that says so. This applies to the connections an application defines and also to tooling that runs in the same process, such as a second DBAL connection for reporting.

## Time zone

Each connection sets DuckDB's session `TimeZone` to PHP's default time zone (`date_default_timezone_get()`) when it connects. DuckDB uses it for `TIMESTAMPTZ` arithmetic and casts, and the driver renders `TIMESTAMPTZ` values in PHP's default zone. A long-running worker that changes PHP's default time zone after connecting, for example per tenant or per request, must close and reopen its connections so the two agree.
