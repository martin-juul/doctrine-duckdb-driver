<?php

declare(strict_types=1);

/**
 * Holds a DuckDB database file open in its own process until standard input
 * closes. Arguments: the file path, then "read_write" or "read_only".
 * Prints "ready" once the file lock is held.
 */

use DuckDB\Database;

$path = $argv[1] ?? '';
$mode = $argv[2] ?? 'read_write';

$config = [];

if ($mode === 'read_only') {
    $config['access_mode'] = 'read_only';
}

$database   = new Database($path, $config);
$connection = $database->connect();

fwrite(STDOUT, "ready\n");
fflush(STDOUT);

stream_get_contents(STDIN);
