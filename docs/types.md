# Types

This page shows the PHP values DuckDB columns come back as, how DBAL's built-in types map to DuckDB, and the package's custom types for DuckDB's nested and wide types.

## Values returned by the driver

DBAL results and the [streaming helper](#streaming-rows) return these values. Date and time values are strings in the formats DBAL's date and time types parse, so `convertToPHPValue()` and ORM hydration work unchanged.

| DuckDB type | PHP value |
| --- | --- |
| `BOOLEAN` | `bool` |
| `TINYINT`, `SMALLINT`, `INTEGER`, `BIGINT`, `UTINYINT`, `USMALLINT`, `UINTEGER` | `int` |
| `UBIGINT`, `HUGEINT`, `UHUGEINT` | `int` when the value fits in a PHP integer, otherwise an exact decimal `string` |
| `FLOAT`, `DOUBLE` | `float` |
| `DECIMAL` | exact decimal `string`, always |
| `BIGNUM` | exact decimal `string` |
| `VARCHAR`, `ENUM` | `string` (for `ENUM`, the label) |
| `JSON` | `string` with the JSON text |
| `BLOB`, `GEOMETRY` | binary `string` |
| `BIT` | `string` of `0` and `1` |
| `UUID` | `string` in canonical form, `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` |
| `DATE` | `string`, `Y-m-d` |
| `TIMESTAMP`, `TIMESTAMP_S`, `TIMESTAMP_MS`, `TIMESTAMP_NS` | `string`, `Y-m-d H:i:s`, with `.u` microseconds when they are not zero. The stored wall-clock time, without time-zone conversion; nanoseconds are truncated to microseconds. |
| `TIMESTAMPTZ` | `string`, `Y-m-d H:i:sO`, in PHP's default time zone; sub-second digits are dropped |
| `TIME` | `string`, `H:i:s`; sub-second digits are dropped |
| `TIMETZ` | `string` as DuckDB renders it |
| `INTERVAL` | `string` in DBAL's `dateinterval` format, such as `+P00Y01M02DT03H04M05S`; sub-second digits are dropped. An interval whose parts have mixed signs, such as `1 month -1 day`, has no such form and comes back as a `DuckDB\Interval` object. |
| infinite `DATE` or `TIMESTAMP` | the `string` `infinity` or `-infinity` |
| `LIST`, `ARRAY` | `list` of decoded elements |
| `STRUCT` | associative `array` keyed by field name |
| `MAP` | `array` keyed by the map's keys; for keys that are not scalars, a `list` of `['key' => …, 'value' => …]` pairs |
| `UNION` | the value of the member that is set |
| `VARIANT` | `string` with DuckDB's JSON rendering |
| `NULL` | `null` |

## DBAL's built-in types

| DBAL type | DuckDB column type | Notes |
| --- | --- | --- |
| `smallint`, `integer`, `bigint` | `SMALLINT`, `INTEGER`, `BIGINT` | |
| `boolean` | `BOOLEAN` | |
| `decimal` | `NUMERIC(p, s)` | DuckDB's name for it is `DECIMAL`. Precision up to 38. Values are strings. |
| `float` | `DOUBLE` | |
| `smallfloat` | `FLOAT` | |
| `string`, `ascii_string`, `text` | `VARCHAR` | No length: DuckDB does not enforce or report one. See [limitations](limitations.md#lengths-are-not-enforced). |
| `binary`, `blob` | `BLOB` | No length. Bound as binary, so the bytes need not be UTF-8. |
| `guid` | `UUID` | |
| `json` | `JSON` | |
| `date_mutable`, `date_immutable` | `DATE` | |
| `datetime_mutable`, `datetime_immutable` | `TIMESTAMP` | |
| `datetimetz_mutable`, `datetimetz_immutable` | `TIMESTAMPTZ` | |
| `time_mutable`, `time_immutable` | `TIME` | Whole seconds. |

DBAL's `dateinterval` type stores a string in a `VARCHAR` column. For a native `INTERVAL` column, use [`duckdb_interval`](#duckdb_interval).

Introspection maps DuckDB's column types back to the types above, maps `HUGEINT`, `INTERVAL`, `LIST`, `STRUCT` and `MAP` to the package's custom types, and maps every other type, such as `TINYINT`, the unsigned integers, `TIMESTAMP_NS`, `ENUM`, `UNION` and fixed-size arrays (`INTEGER[3]`), to [`duckdb_native`](#duckdb_native), which keeps the DuckDB declaration. Comparing a schema with its own introspection therefore never proposes a type change.

## Custom types

The package registers six DBAL types in `MartinJuul\DBAL\DuckDB\Types`. `DuckDBSetup::registerTypes()`, `DuckDBSetup::configureOrm()` and the Symfony bundle register them (see [setup](setup.md)).

| Type name | Class | DuckDB type | PHP value | Column options |
| --- | --- | --- | --- | --- |
| `duckdb_list` | `ListType` | `<element>[]` | `list` | `element_type` |
| `duckdb_struct` | `StructType` | `STRUCT(…)` | associative `array` | `fields` |
| `duckdb_map` | `MapType` | `MAP(<key>, <value>)` | `array` keyed by map key | `key_type`, `value_type` |
| `duckdb_hugeint` | `HugeIntType` | `HUGEINT` | `string` | none |
| `duckdb_interval` | `IntervalType` | `INTERVAL` | `DateInterval` | none |
| `duckdb_native` | `NativeType` | any | as the driver returns it | `duckdb_type` |

### Inner types

The options hold DuckDB type strings, written exactly as DuckDB reports them: `INTEGER`, not `int`; `VARCHAR`, not `string`. Inner types may nest, for example `STRUCT(a INTEGER)[]` as a list's element type. If they are written differently, introspection reports a different type string and the comparator proposes a change on every run.

A column without its option fails at DDL generation with an `InvalidArgumentException`, such as: `Column "readings" of type duckdb_list needs a DuckDB type string in the "element_type" platform option.`

### Declaring custom-type columns with the ORM

ORM passes column `options` that it does not know to the platform, which is where the types read them:

```php
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Measurement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    /** @param list<int> $readings */
    public function __construct(
        #[ORM\Column(type: 'duckdb_list', options: ['element_type' => 'INTEGER'])]
        public array $readings,
        #[ORM\Column(type: 'duckdb_struct', nullable: true, options: ['fields' => ['station' => 'VARCHAR', 'elevation' => 'INTEGER']])]
        public array|null $origin,
        #[ORM\Column(type: 'duckdb_map', options: ['key_type' => 'VARCHAR', 'value_type' => 'INTEGER'])]
        public array $counts,
        #[ORM\Column(type: 'duckdb_hugeint')]
        public string $total,
        #[ORM\Column(type: 'duckdb_interval')]
        public \DateInterval $duration,
    ) {
    }
}
```

The `fields` option lists the struct's fields in declaration order.

### Declaring custom-type columns with DBAL

DBAL 4.5's `ColumnEditor` has no setter for platform options, so plain DBAL has no non-deprecated way to set these options. Either create the table with SQL, which introspection then reports with the right types and options:

```php
$connection->executeStatement('CREATE TABLE measurement (id INTEGER PRIMARY KEY, readings INTEGER[], counts MAP(VARCHAR, INTEGER))');
```

or pass the options under `platformOptions` through DBAL's deprecated `Table::addColumn()`:

```php
$table->addColumn('readings', 'duckdb_list', ['platformOptions' => ['element_type' => 'INTEGER']]);
```

### Writing values

Write values as plain PHP values: a list for `duckdb_list`, an array keyed by field name for `duckdb_struct`, an array keyed by map key for `duckdb_map`. The type infers each inner type from the PHP values and DuckDB casts the result to the column's type, so an `int` written to a `DOUBLE[]` column is stored as a `DOUBLE`. An array with keys 0 to n-1 written to a `duckdb_map` column is still written as a map.

```php
$connection->insert('measurement', ['id' => 1, 'readings' => [3, 5, 8], 'counts' => ['a' => 1, 'b' => 2]], [
    'readings' => 'duckdb_list',
    'counts' => 'duckdb_map',
]);
```

A value of the wrong shape, such as an associative array for `duckdb_list`, fails with DBAL's `InvalidType` conversion error.

### `duckdb_hugeint`

A 128-bit signed integer. Values are read as PHP strings, since most exceed PHP's integer range. Write an `int` or a numeric string.

### `duckdb_interval`

Reads and writes PHP `DateInterval` objects. Writes keep years and months, days, and the time including microseconds. Reads have whole seconds; an interval whose parts have mixed signs, such as `1 month -1 day`, becomes a `DateInterval` with negative parts.

### `duckdb_native`

An opaque passthrough for DuckDB types without a DBAL mapping. Introspection assigns it to such columns and keeps the DuckDB type string in the `duckdb_type` option, which the platform writes back unchanged into DDL. Values pass through as the driver returns them, without conversion. You can also declare a column with it:

```php
#[ORM\Column(type: 'duckdb_native', options: ['duckdb_type' => 'UTINYINT'])]
public int $level;
```

## Streaming rows

`MartinJuul\DBAL\DuckDB\Streaming\StreamingQuery::rows()` yields one associative array per row, keyed by column name, with the values in the [table above](#values-returned-by-the-driver). If two columns share a name, the later one wins; alias them apart. DBAL types are not applied, so convert values yourself where needed, for example with `Type::getType('datetime_immutable')->convertToPHPValue($row['created_at'], $platform)`. See [limitations](limitations.md#results-are-fully-buffered) for how to use the stream.
