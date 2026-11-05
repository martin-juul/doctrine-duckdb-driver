<?php

declare(strict_types=1);

/*
 * Runs Doctrine's own DBAL or ORM test suite against this package.
 *
 *   php tests/Upstream/run.php dbal [--dbal=4.5.0|4.6.x] [options] [-- phpunit args]
 *   php tests/Upstream/run.php orm  [--dbal=4.5.0|4.6.x] [options] [-- phpunit args]
 *
 * Options:
 *   --dbal=REF     DBAL tag or branch. The dbal suite is cloned at REF; the orm
 *                  suite installs DBAL REF (a branch as REF-dev). Default 4.5.0.
 *   --offline      Reuse the existing checkout and installed dependencies.
 *   --no-verify    Skip the second pass that runs the skip-listed tests.
 *
 * Doctrine excludes its tests from Composer archives, so the suite is cloned at
 * the pinned tag into .upstream/. That directory must stay outside any "tests"
 * directory: doctrine/deprecations treats every caller whose path contains
 * /tests/ as test code, which would break DBAL's deprecation tests. Its dev
 * dependencies are
 * installed there from a generated composer.duckdb.json that adds this package
 * as a path repository, the ORM checkout gets orm/TestUtil.patch, and the
 * suite's phpunit.xml is copied in as phpunit.duckdb.xml.
 *
 * Pass 1 runs the suite with skiplist.php applied by SkipListExtension. It
 * fails when PHPUnit fails, which means some test outside the skip list failed,
 * or when a skip-list entry matched no test. Pass 2 runs only the listed tests,
 * and the tests they depend on, without skipping and reports entries whose
 * tests now pass. Entries of an order-dependent category are expected to pass
 * outside the full suite and are not reported as stale.
 */

const ORM_TAG              = '3.7.6';
const DEFAULT_DBAL         = '4.5.0';
const NETWORK_ATTEMPTS     = 3;
const VERIFY_FILTER_LENGTH = 16000;

/**
 * Skip-list categories whose tests fail only in some test orders, so passing
 * in the verification pass, which runs a subset, does not make an entry stale.
 */
const ORDER_DEPENDENT_CATEGORIES = ['row-order', 'leftover-sequences'];

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];

main($arguments);

/** @param list<string> $argv */
function main(array $argv): void
{
    $options = parseArguments($argv);
    $root    = dirname(__DIR__, 2);
    $suite   = $options['suite'];
    $dbal    = $options['dbal'];

    if ($suite === 'dbal') {
        $repository = 'https://github.com/doctrine/dbal.git';
        $ref        = $dbal;
        $directory  = sprintf('%s/.upstream/dbal-%s', $root, $dbal);
    } else {
        $repository = 'https://github.com/doctrine/orm.git';
        $ref        = ORM_TAG;
        $directory  = sprintf('%s/.upstream/orm-%s-dbal-%s', $root, ORM_TAG, $dbal);
    }

    checkOut($repository, $ref, $directory, $options['offline']);

    if ($suite === 'orm') {
        applyPatch($directory, __DIR__ . '/orm/TestUtil.patch', $ref);
    }

    installDependencies($directory, $root, $suite, $ref, $dbal, $options['offline']);
    copyFile(sprintf('%s/%s/phpunit.xml', __DIR__, $suite), $directory . '/phpunit.duckdb.xml');

    $categories = listedEntries($suite);
    $entries    = array_keys($categories);

    $failed = runSkipPass($directory, $entries, $options['phpunit']);

    if (! $options['verify']) {
        exit($failed ? 1 : 0);
    }

    runVerifyPass($directory, $categories, $options['phpunit']);

    exit($failed ? 1 : 0);
}

/**
 * @param list<string> $argv
 *
 * @return array{suite: string, dbal: string, offline: bool, verify: bool, phpunit: list<string>}
 */
function parseArguments(array $argv): array
{
    $options = ['suite' => '', 'dbal' => DEFAULT_DBAL, 'offline' => false, 'verify' => true, 'phpunit' => []];
    $args    = array_slice($argv, 1);

    while ($args !== []) {
        $arg = array_shift($args);

        if ($arg === '--') {
            $options['phpunit'] = $args;

            break;
        }

        if (str_starts_with($arg, '--dbal=')) {
            $options['dbal'] = substr($arg, 7);
        } elseif ($arg === '--offline') {
            $options['offline'] = true;
        } elseif ($arg === '--no-verify') {
            $options['verify'] = false;
        } elseif ($options['suite'] === '' && in_array($arg, ['dbal', 'orm'], true)) {
            $options['suite'] = $arg;
        } else {
            fail(sprintf('Unknown argument "%s". Usage: run.php dbal|orm [--dbal=REF] [--offline] [--no-verify] [-- phpunit args]', $arg));
        }
    }

    if ($options['suite'] === '') {
        fail('Name the suite to run: dbal or orm.');
    }

    if (preg_match('/^\d+\.\d+(\.\d+|\.x)$/', $options['dbal']) !== 1) {
        fail(sprintf('--dbal must be a tag such as 4.5.0 or a branch such as 4.6.x, got "%s".', $options['dbal']));
    }

    return $options;
}

function checkOut(string $repository, string $ref, string $directory, bool $offline): void
{
    $isBranch = str_ends_with($ref, '.x');
    $refspec  = ($isBranch ? 'refs/heads/' : 'refs/tags/') . $ref;

    if (! is_dir($directory . '/.git')) {
        if ($offline) {
            fail(sprintf('%s does not exist; run once without --offline.', $directory));
        }

        @mkdir(dirname($directory), 0777, true);

        retry(
            sprintf('clone %s at %s', $repository, $ref),
            ['git', 'clone', '--quiet', '--depth', '1', '--branch', $ref, $repository, $directory],
            static fn () => removeDirectory($directory),
        );

        return;
    }

    if (! $offline && ($isBranch || ! headIsTag($directory, $ref))) {
        // A branch is fetched into its remote-tracking ref: git refuses to
        // fetch into the local branch that the clone checked out.
        $localRef = $isBranch ? 'refs/remotes/origin/' . $ref : $refspec;

        retry(
            sprintf('fetch %s %s', $repository, $ref),
            ['git', '-C', $directory, 'fetch', '--quiet', '--depth', '1', 'origin', '+' . $refspec . ':' . $localRef],
        );

        command(['git', '-C', $directory, 'checkout', '--quiet', '--force', '--detach', $localRef]);
    }

    // Drops an earlier run's patch; untracked files such as vendor/ stay.
    command(['git', '-C', $directory, 'reset', '--quiet', '--hard']);
}

function headIsTag(string $directory, string $tag): bool
{
    $head   = capture(['git', '-C', $directory, 'rev-parse', 'HEAD']);
    $tagged = capture(['git', '-C', $directory, 'rev-parse', '--verify', '--quiet', 'refs/tags/' . $tag . '^{commit}']);

    return $tagged !== null && $head === $tagged;
}

function applyPatch(string $directory, string $patch, string $ref): void
{
    if (runCommand(['git', '-C', $directory, 'apply', '--check', $patch]) !== 0) {
        fail(sprintf('%s no longer applies to doctrine/orm %s. Update the patch for the pinned tag.', $patch, $ref));
    }

    command(['git', '-C', $directory, 'apply', $patch]);
}

function installDependencies(string $directory, string $root, string $suite, string $ref, string $dbal, bool $offline): void
{
    $composer = json_decode((string) file_get_contents($directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($composer));

    $isBranch = static fn (string $version): bool => str_ends_with($version, '.x');

    $composer['repositories'] = [
        [
            'type'    => 'path',
            'url'     => relativePath($directory, $root),
            'options' => [
                'symlink'  => true,
                'versions' => ['martin-juul/dbal-duckdb' => '1.0.x-dev'],
            ],
        ],
    ];

    $requireDev = is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [];

    $requireDev['martin-juul/dbal-duckdb'] = '1.0.x-dev';

    if ($suite === 'orm') {
        $requireDev['doctrine/dbal'] = $isBranch($dbal) ? $dbal . '-dev' : $dbal;
    }

    $composer['require-dev'] = $requireDev;

    $autoloadDev = is_array($composer['autoload-dev'] ?? null) ? $composer['autoload-dev'] : [];
    $psr4        = is_array($autoloadDev['psr-4'] ?? null) ? $autoloadDev['psr-4'] : [];

    $psr4['MartinJuul\\DBAL\\DuckDB\\Tests\\Upstream\\'] = relativePath($directory, __DIR__);

    $autoloadDev['psr-4']     = $psr4;
    $composer['autoload-dev'] = $autoloadDev;

    $composer['minimum-stability'] = 'dev';
    $composer['prefer-stable']     = true;

    $json = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

    // The installed set is current when neither the generated file nor this
    // package's own requirements changed since the last install.
    $stamp     = hash('sha256', $json . file_get_contents($root . '/composer.json'));
    $stampFile = $directory . '/vendor/.duckdb-install-stamp';

    if (is_file($stampFile) && file_get_contents($stampFile) === $stamp) {
        return;
    }

    if ($offline) {
        fail(sprintf('Dependencies in %s are missing or outdated; run once without --offline.', $directory));
    }

    file_put_contents($directory . '/composer.duckdb.json', $json);

    // Composer keeps an installed path package whose version did not change,
    // even when the repository path moved, so the symlink is recreated.
    removeDirectory($directory . '/vendor/martin-juul/dbal-duckdb');

    $environment = [
        'COMPOSER'              => 'composer.duckdb.json',
        'COMPOSER_ROOT_VERSION' => $isBranch($ref) ? $ref . '-dev' : $ref,
    ];

    retry(
        'install the dev dependencies of ' . basename($directory),
        ['composer', 'update', '--no-interaction', '--no-progress', '--prefer-dist', '--working-dir=' . $directory],
        null,
        $environment,
    );

    file_put_contents($stampFile, $stamp);
}

/** @return array<string, string> Category per skip-list key of the suite; SkipListExtension validates them. */
function listedEntries(string $suite): array
{
    $list = require __DIR__ . '/skiplist.php';

    if (! is_array($list) || ! is_array($list[$suite] ?? null)) {
        fail(sprintf('tests/Upstream/skiplist.php has no list for the suite "%s".', $suite));
    }

    $categories = [];

    foreach ($list[$suite] as $key => $entry) {
        $categories[(string) $key] = is_array($entry) && is_string($entry[0] ?? null) ? $entry[0] : '';
    }

    return $categories;
}

/**
 * @param list<string> $entries
 * @param list<string> $phpunitArguments
 */
function runSkipPass(string $directory, array $entries, array $phpunitArguments): bool
{
    section('Pass 1: upstream suite with the skip list applied');

    $report = $directory . '/.duckdb-skip-report.json';
    @unlink($report);

    $exitCode = runPhpUnit($directory, 'skip', $report, $phpunitArguments, null);
    $failed   = $exitCode !== 0;

    if ($failed) {
        annotate('error', sprintf('PHPUnit exited with %d: a test outside the skip list failed, or PHPUnit reported a warning.', $exitCode));
    }

    $outcomes = readReport($report);
    $problems = readReport($report, 'problems');

    if ($outcomes === null || $problems === null) {
        annotate('error', 'SkipListExtension wrote no report: the skip list is invalid or the extension did not bootstrap. See the PHPUnit warning above.');

        return true;
    }

    if ($phpunitArguments !== []) {
        return $failed;
    }

    foreach ($entries as $key) {
        if (($outcomes[$key] ?? []) !== []) {
            continue;
        }

        annotate('error', sprintf(
            'Skip-list entry %s matched no test that ran (%s). Remove it or fix the name.',
            $key,
            is_string($problems[$key] ?? null) ? $problems[$key] : 'the test is excluded by a group or was not loaded',
        ));
        $failed = true;
    }

    return $failed;
}

/**
 * @param array<string, string> $categories       category per skip-list entry
 * @param list<string>          $phpunitArguments
 */
function runVerifyPass(string $directory, array $categories, array $phpunitArguments): void
{
    if ($categories === []) {
        return;
    }

    section('Pass 2: skip-listed tests run without skipping');

    $entries  = array_keys($categories);
    $outcomes = [];
    $logs     = [];

    foreach (verifyFilters($directory, $entries) as $index => $filter) {
        $report = sprintf('%s/.duckdb-verify-report-%d.json', $directory, $index);
        $log    = sprintf('%s/.duckdb-verify-%d.log', $directory, $index);
        @unlink($report);

        $logs[] = $log;

        runPhpUnit($directory, 'verify', $report, [...$phpunitArguments, '--filter', $filter], $log);

        $chunk = readReport($report);

        if ($chunk === null) {
            annotate('warning', sprintf('The verification pass wrote no report; see %s.', $log));

            return;
        }

        // Every run reports every entry, so an entry keeps the outcomes of
        // the run whose filter selected it.
        foreach ($chunk as $key => $counts) {
            if (! is_array($counts) || $counts === []) {
                continue;
            }

            $outcomes[$key] = $counts;
        }
    }

    $stale          = [];
    $notRun         = [];
    $orderDependent = [];

    foreach ($categories as $key => $category) {
        $counts = $outcomes[$key] ?? [];

        if ($counts === []) {
            $notRun[] = $key;

            continue;
        }

        if (array_keys($counts) !== ['passed']) {
            continue;
        }

        if (in_array($category, ORDER_DEPENDENT_CATEGORIES, true)) {
            $orderDependent[] = $key;

            continue;
        }

        $stale[] = $key;
    }

    // A test that fails partway through a transaction can leave the shared
    // connection unusable for the tests after it, so an entry that did not run
    // or passed among the others is run again on its own before it is reported.
    $confirmedStale  = [];
    $confirmedNotRun = [];

    foreach ([...$notRun, ...$stale] as $index => $key) {
        $report = sprintf('%s/.duckdb-verify-alone-report-%d.json', $directory, $index);
        $log    = sprintf('%s/.duckdb-verify-alone-%d.log', $directory, $index);
        @unlink($report);

        runPhpUnit($directory, 'verify', $report, [...$phpunitArguments, '--filter', verifyFilters($directory, [$key])[0]], $log);

        $counts = readReport($report)[$key] ?? [];

        if (! is_array($counts) || $counts === []) {
            $confirmedNotRun[] = $key;
            $logs[]            = $log;

            continue;
        }

        if (array_keys($counts) === ['passed']) {
            $confirmedStale[] = $key;
            $logs[]           = $log;

            continue;
        }

        fwrite(STDOUT, sprintf("Skip-list entry %s fails when run on its own; its verification-pass result came from test order, so it stays listed.\n", $key));
    }

    $stale  = $confirmedStale;
    $notRun = $confirmedNotRun;

    foreach ($notRun as $key) {
        annotate('warning', sprintf('Skip-list entry %s did not run in the verification pass (PHPUnit output: %s).', $key, implode(', ', $logs)));
    }

    foreach ($stale as $key) {
        annotate('warning', sprintf('Skip-list entry %s now passes; remove it from tests/Upstream/skiplist.php.', $key));
    }

    foreach ($orderDependent as $key) {
        fwrite(STDOUT, sprintf("Skip-list entry %s passed outside the full suite, which its category expects; it stays listed.\n", $key));
    }

    if ($stale !== [] || $notRun !== []) {
        return;
    }

    fwrite(STDOUT, sprintf("No skip-list entry is stale (PHPUnit output: %s).\n", implode(', ', $logs)));
}

/**
 * PHPUnit --filter patterns that together select the listed test methods.
 * PCRE refuses patterns whose compiled form exceeds 64 KiB, and PHPUnit then
 * silently runs no test, so methods are grouped by class and split into
 * patterns of at most VERIFY_FILTER_LENGTH characters.
 *
 * @param list<string> $entries
 *
 * @return list<string>
 */
function verifyFilters(string $directory, array $entries): array
{
    $methodsByClass = [];

    foreach ($entries as $key) {
        [$class, $method] = explode('::', (string) preg_replace('/#.*$/s', '', $key), 2);

        $pending = [$method];

        // PHPUnit's --filter does not pull in the tests a selected test
        // depends on, and skips a test whose dependencies did not run.
        while ($pending !== []) {
            $next = array_shift($pending);

            if (isset($methodsByClass[$class][$next])) {
                continue;
            }

            $methodsByClass[$class][$next] = true;

            array_push($pending, ...testDependencies($directory, $class, $next));
        }
    }

    $filters      = [];
    $alternatives = [];
    $length       = 0;

    foreach ($methodsByClass as $class => $methods) {
        $alternative = preg_quote($class . '::', '/') . '(?:' . implode('|', array_map(
            static fn (string $method): string => preg_quote($method, '/'),
            array_keys($methods),
        )) . ')';

        if ($alternatives !== [] && $length + strlen($alternative) > VERIFY_FILTER_LENGTH) {
            $filters[]    = verifyFilter($alternatives);
            $alternatives = [];
            $length       = 0;
        }

        $alternatives[] = $alternative;
        $length        += strlen($alternative) + 1;
    }

    if ($alternatives !== []) {
        $filters[] = verifyFilter($alternatives);
    }

    return $filters;
}

/**
 * The same-class tests that a test method names in #[Depends('method')]
 * attributes, read from the test's source file.
 *
 * @return list<string>
 */
function testDependencies(string $directory, string $class, string $method): array
{
    $file = testClassFile($directory, $class);

    if ($file === null) {
        return [];
    }

    $source = (string) file_get_contents($file);

    // The attribute block directly above the method declaration.
    $pattern = '/((?:^[ \t]*#\[[^\n]*\]\s*\n)*)^[ \t]*(?:final\s+)?public\s+function\s+' . preg_quote($method, '/') . '\s*\(/m';

    if (preg_match($pattern, $source, $match) !== 1) {
        return [];
    }

    preg_match_all('/#\[Depends\(\s*[\'"](\w+)[\'"]\s*\)\]/', $match[1], $dependencies);

    return $dependencies[1];
}

/** The source file of a test class, found through the checkout's PSR-4 map. */
function testClassFile(string $directory, string $class): string|null
{
    /** @var array<string, mixed> $maps */
    static $maps = [];

    $maps[$directory] ??= require $directory . '/vendor/composer/autoload_psr4.php';
    $map                = $maps[$directory];

    if (! is_array($map)) {
        return null;
    }

    foreach ($map as $prefix => $paths) {
        if (! is_string($prefix) || ! is_array($paths) || ! str_starts_with($class, $prefix)) {
            continue;
        }

        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $file = $path . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                return $file;
            }
        }
    }

    return null;
}

/** @param non-empty-list<string> $alternatives */
function verifyFilter(array $alternatives): string
{
    return '/^(?:' . implode('|', $alternatives) . ')(?: with data set .*)?$/';
}

/**
 * Reads one section of a SkipListExtension report: `entries` (outcome counts
 * per entry) or `problems` (why an unmatched entry names no test).
 *
 * @return array<string, mixed>|null
 */
function readReport(string $path, string $section = 'entries'): array|null
{
    if (! is_file($path)) {
        return null;
    }

    $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($report) || ! is_array($report[$section] ?? null)) {
        return null;
    }

    /** @var array<string, mixed> $values */
    $values = $report[$section];

    return $values;
}

/** @param list<string> $arguments */
function runPhpUnit(string $directory, string $mode, string $report, array $arguments, string|null $log): int
{
    $command = [PHP_BINARY, 'vendor/bin/phpunit', '-c', 'phpunit.duckdb.xml', ...$arguments];

    $environment = [
        'DUCKDB_UPSTREAM_MODE'   => $mode,
        'DUCKDB_UPSTREAM_REPORT' => $report,
    ];

    $output = $log === null ? STDOUT : ['file', $log, 'w'];

    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $log === null ? STDERR : $output], $pipes, $directory, [...getenv(), ...$environment]);

    if ($process === false) {
        fail('Cannot start PHPUnit.');
    }

    return proc_close($process);
}

/**
 * Runs a network operation up to NETWORK_ATTEMPTS times. Git aborts stalled
 * transfers through its low-speed limit; Composer has its own timeouts.
 *
 * @param list<string>          $command
 * @param array<string, string> $environment
 */
function retry(string $description, array $command, Closure|null $cleanUp = null, array $environment = []): void
{
    $environment += ['GIT_HTTP_LOW_SPEED_LIMIT' => '1000', 'GIT_HTTP_LOW_SPEED_TIME' => '60'];

    for ($attempt = 1; $attempt <= NETWORK_ATTEMPTS; $attempt++) {
        if (runCommand($command, $environment) === 0) {
            return;
        }

        if ($cleanUp !== null) {
            $cleanUp();
        }

        if ($attempt >= NETWORK_ATTEMPTS) {
            continue;
        }

        fwrite(STDERR, sprintf("Could not %s (attempt %d of %d); retrying in %d s.\n", $description, $attempt, NETWORK_ATTEMPTS, 5 * $attempt));
        sleep(5 * $attempt);
    }

    fail(sprintf('Could not %s after %d attempts.', $description, NETWORK_ATTEMPTS));
}

/** @param list<string> $command */
function command(array $command): void
{
    if (runCommand($command) === 0) {
        return;
    }

    fail('Command failed: ' . implode(' ', $command));
}

/**
 * @param list<string>          $command
 * @param array<string, string> $environment
 */
function runCommand(array $command, array $environment = []): int
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, [...getenv(), ...$environment]);

    if ($process === false) {
        fail('Cannot start ' . $command[0]);
    }

    return proc_close($process);
}

/** @param list<string> $command */
function capture(array $command): string|null
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    if ($process === false) {
        return null;
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    if (proc_close($process) !== 0 || $output === false) {
        return null;
    }

    return trim($output);
}

function relativePath(string $from, string $to): string
{
    $fromParts = explode('/', trim($from, '/'));
    $toParts   = explode('/', trim($to, '/'));

    while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
        array_shift($fromParts);
        array_shift($toParts);
    }

    $path = implode('/', [...array_fill(0, count($fromParts), '..'), ...$toParts]);

    return $path === '' ? '.' : $path;
}

function copyFile(string $source, string $target): void
{
    if (copy($source, $target)) {
        return;
    }

    fail(sprintf('Cannot copy %s to %s.', $source, $target));
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    runCommand(['rm', '-rf', $directory]);
}

function section(string $title): void
{
    fwrite(STDOUT, "\n== " . $title . " ==\n\n");
}

function annotate(string $level, string $message): void
{
    if (getenv('GITHUB_ACTIONS') === 'true') {
        fwrite(STDOUT, sprintf("::%s::%s\n", $level, $message));

        return;
    }

    fwrite(STDOUT, sprintf("%s: %s\n", strtoupper($level), $message));
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");

    exit(1);
}
