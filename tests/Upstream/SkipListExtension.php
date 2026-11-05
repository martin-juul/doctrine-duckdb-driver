<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Upstream;

use Composer\InstalledVersions;
use InvalidArgumentException;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Subscriber;
use PHPUnit\Event\Test\BeforeTestMethodCalled;
use PHPUnit\Event\Test\BeforeTestMethodCalledSubscriber;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedTestSuiteError;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

use function array_is_list;
use function array_key_exists;
use function assert;
use function class_exists;
use function count;
use function file_put_contents;
use function getenv;
use function implode;
use function is_array;
use function is_string;
use function json_encode;
use function method_exists;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function version_compare;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Skips the upstream Doctrine tests listed in skiplist.php, each with its
 * "[category] reason" message. Works with PHPUnit 10, 11 and 12.
 *
 * Parameters (phpunit.xml): `suite` selects the list (`dbal` or `orm`).
 * Entries that name a minimum DBAL version apply only when the installed
 * doctrine/dbal is that version or later, so a test that a newer DBAL adds can
 * be listed without breaking the run against an older one.
 * Environment: `DUCKDB_UPSTREAM_MODE=verify` runs listed tests instead of
 * skipping them, so the runner can report entries whose tests now pass, and
 * `DUCKDB_UPSTREAM_REPORT` names a JSON file that receives the outcome of every
 * listed test, and for entries that matched no test, why their target is not a
 * test. A malformed list makes bootstrapping fail, which leaves no report.
 *
 * The skip is raised from the event that follows the first before-test hook,
 * or from the prepared event for tests without hooks. Both are dispatched
 * inside PHPUnit's test try block, so the skip is recorded like any other. The
 * first hook is DBAL's connect() or the hook that orm/TestUtil.patch adds, so
 * setUp() does not run for a listed test.
 */
final class SkipListExtension implements Extension
{
    public const string MODE_SKIP   = 'skip';
    public const string MODE_VERIFY = 'verify';

    private const string KEY_PATTERN = '/^(?<class>[A-Za-z_][A-Za-z0-9_\\\\]*)::(?<method>[A-Za-z_][A-Za-z0-9_]*)(?:#(?<dataSet>.+))?$/';

    private const string VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

    /** @var array<string, string> skip message per entry key */
    private array $messages = [];

    /** @var array<string, array<string, int>> outcome counts per entry key */
    private array $outcomes = [];

    private string $mode = self::MODE_SKIP;

    private string|null $currentEntry = null;

    private bool $skipRaised = false;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $suite = $parameters->has('suite') ? $parameters->get('suite') : '';

        $this->messages = self::loadSkipList(__DIR__ . '/skiplist.php', $suite, self::installedDbalVersion());
        $this->mode     = self::resolveMode();

        foreach ($this->messages as $key => $message) {
            $this->outcomes[$key] = [];
        }

        $facade->registerSubscribers(...$this->subscribers());
    }

    /**
     * Loads and validates the skip list of one suite, keeping the entries that
     * apply to the given DBAL version.
     *
     * @param string $dbalVersion normalized doctrine/dbal version, such as 4.5.0.0 or 4.6.9999999.9999999-dev
     *
     * @return array<string, string> "[category] reason" per entry key
     *
     * @throws InvalidArgumentException When the list is malformed or names a missing test.
     */
    public static function loadSkipList(string $path, string $suite, string $dbalVersion): array
    {
        $list = require $path;

        if (! is_array($list) || ! is_array($list['categories'] ?? null)) {
            throw new InvalidArgumentException(sprintf('%s must return an array with a "categories" map.', $path));
        }

        if (! is_array($list[$suite] ?? null)) {
            throw new InvalidArgumentException(sprintf('%s has no list for the suite "%s".', $path, $suite));
        }

        $labels = [];

        foreach ($list['categories'] as $category => $definition) {
            $pair = self::sentencePair($definition);

            if (! is_string($category) || $pair === null) {
                throw new InvalidArgumentException(sprintf(
                    'Skip-list category "%s" must map to [label, description sentence].',
                    $category,
                ));
            }

            $labels[$category] = $pair[0];
        }

        $messages = [];
        $errors   = [];

        foreach ($list[$suite] as $key => $entry) {
            $key   = (string) $key;
            $parts = self::entryParts($entry);
            $error = $parts === null
                ? sprintf('%s: entry must be [category, reason sentence] or [category, reason sentence, minimum DBAL version such as "4.5.1"]', $key)
                : self::validateEntry($key, $parts[0], $labels);

            if ($error !== null || $parts === null) {
                $errors[] = $error;

                continue;
            }

            [$category, $reason, $since] = $parts;

            if ($since !== null && version_compare($dbalVersion, $since, '<')) {
                continue;
            }

            $messages[$key] = sprintf('[%s] %s', $labels[$category], $reason);
        }

        if ($errors !== []) {
            throw new InvalidArgumentException("Invalid skip-list entries:\n  " . implode("\n  ", $errors));
        }

        return $messages;
    }

    /** @param array<string, string> $labels */
    private static function validateEntry(string $key, string $category, array $labels): string|null
    {
        if (preg_match(self::KEY_PATTERN, $key, $match) !== 1) {
            return sprintf('%s: key must be Class::method or Class::method#dataSet', $key);
        }

        if (! array_key_exists($category, $labels)) {
            return sprintf('%s: unknown category "%s"', $key, $category);
        }

        return null;
    }

    /**
     * Explains why an entry names no runnable test. Called only after the run:
     * autoloading a test class before PHPUnit loads its file makes PHPUnit
     * drop the whole class.
     */
    private static function targetProblem(string $key): string|null
    {
        if (preg_match(self::KEY_PATTERN, $key, $match) !== 1) {
            return 'the key is malformed';
        }

        if (! class_exists($match['class'])) {
            return 'the test class does not exist';
        }

        if ((new ReflectionClass($match['class']))->isAbstract()) {
            return 'the class is abstract; name the concrete test class';
        }

        if (! method_exists($match['class'], $match['method'])) {
            return 'the test method does not exist';
        }

        $method = new ReflectionMethod($match['class'], $match['method']);

        if (! $method->isPublic() || (! str_starts_with($method->getName(), 'test') && $method->getAttributes(Test::class) === [])) {
            return 'the method is not a test';
        }

        return null;
    }

    /**
     * Splits a skip-list entry into its category, reason and optional minimum
     * DBAL version, or returns null when the entry is malformed.
     *
     * @return array{string, string, string|null}|null
     */
    private static function entryParts(mixed $entry): array|null
    {
        if (! is_array($entry) || ! array_is_list($entry) || count($entry) < 2 || count($entry) > 3) {
            return null;
        }

        $pair = self::sentencePair([$entry[0], $entry[1]]);

        if ($pair === null) {
            return null;
        }

        $since = $entry[2] ?? null;

        if ($since !== null && (! is_string($since) || preg_match(self::VERSION_PATTERN, $since) !== 1)) {
            return null;
        }

        return [$pair[0], $pair[1], $since];
    }

    /**
     * Returns [name, sentence] when the value is a two-element list whose
     * second element reads as a sentence.
     *
     * @return array{string, string}|null
     */
    private static function sentencePair(mixed $value): array|null
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 2) {
            return null;
        }

        [$name, $sentence] = $value;

        if (! is_string($name) || $name === '' || ! is_string($sentence) || preg_match('/^\S.*[.!?]$/s', $sentence) !== 1) {
            return null;
        }

        return [$name, $sentence];
    }

    private static function installedDbalVersion(): string
    {
        $version = InstalledVersions::isInstalled('doctrine/dbal') ? InstalledVersions::getVersion('doctrine/dbal') : null;

        if ($version === null) {
            throw new InvalidArgumentException('The installed doctrine/dbal version is unknown, so version-bound skip-list entries cannot be applied.');
        }

        return $version;
    }

    private static function resolveMode(): string
    {
        $mode = getenv('DUCKDB_UPSTREAM_MODE');

        if ($mode === false || $mode === '' || $mode === self::MODE_SKIP) {
            return self::MODE_SKIP;
        }

        if ($mode === self::MODE_VERIFY) {
            return self::MODE_VERIFY;
        }

        throw new InvalidArgumentException(sprintf('DUCKDB_UPSTREAM_MODE must be "skip" or "verify", got "%s".', $mode));
    }

    public function testStarted(TestMethod $test): void
    {
        $this->currentEntry = null;
        $this->skipRaised   = false;

        $method = $test->className() . '::' . $test->methodName();
        $data   = $test->testData();

        if ($data->hasDataFromDataProvider()) {
            $variant = $method . '#' . $data->dataFromDataProvider()->dataSetName();

            if (isset($this->messages[$variant])) {
                $this->currentEntry = $variant;

                return;
            }
        }

        if (! isset($this->messages[$method])) {
            return;
        }

        $this->currentEntry = $method;
    }

    public function raiseSkip(): void
    {
        if ($this->currentEntry === null || $this->mode !== self::MODE_SKIP || $this->skipRaised) {
            return;
        }

        $this->skipRaised = true;

        // PHPUnit lets an error in tearDown() replace a skip raised through
        // markTestSkipped(), and a listed test whose setUp() never ran often
        // fails in tearDown(). Any other SkippedTest keeps the skip.
        $skip = new SkippedTestSuiteError($this->messages[$this->currentEntry]);

        // The event dispatcher rethrows exceptions raised in PHPUnit's sources
        // and turns those of third-party subscribers into warnings.
        (new ReflectionProperty($skip, 'file'))->setValue($skip, self::phpunitSourceFile());

        throw $skip;
    }

    private static function phpunitSourceFile(): string
    {
        $file = (new ReflectionClass(Assert::class))->getFileName();
        assert(is_string($file));

        return $file;
    }

    public function recordOutcome(string $outcome): void
    {
        if ($this->currentEntry === null) {
            return;
        }

        $this->outcomes[$this->currentEntry][$outcome] = ($this->outcomes[$this->currentEntry][$outcome] ?? 0) + 1;
    }

    public function testFinished(): void
    {
        $this->currentEntry = null;
    }

    public function writeReport(): void
    {
        $path = getenv('DUCKDB_UPSTREAM_REPORT');

        if ($path === false || $path === '') {
            return;
        }

        $problems = [];

        foreach ($this->outcomes as $key => $outcomes) {
            if ($outcomes !== []) {
                continue;
            }

            $problem = self::targetProblem($key);

            if ($problem === null) {
                continue;
            }

            $problems[$key] = $problem;
        }

        file_put_contents($path, json_encode(
            ['mode' => $this->mode, 'entries' => $this->outcomes, 'problems' => $problems],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return list<Subscriber> */
    private function subscribers(): array
    {
        $extension = $this;

        return [
            new class ($extension) implements PreparationStartedSubscriber {
                public function __construct(private readonly SkipListExtension $extension)
                {
                }

                public function notify(PreparationStarted $event): void
                {
                    $test = $event->test();

                    if (! $test instanceof TestMethod) {
                        return;
                    }

                    $this->extension->testStarted($test);
                }
            },
            new class ($extension) implements BeforeTestMethodCalledSubscriber {
                public function __construct(private readonly SkipListExtension $extension)
                {
                }

                public function notify(BeforeTestMethodCalled $event): void
                {
                    $this->extension->raiseSkip();
                }
            },
            new class ($extension) implements PreparedSubscriber {
                public function __construct(private readonly SkipListExtension $extension)
                {
                }

                public function notify(Prepared $event): void
                {
                    $this->extension->raiseSkip();
                }
            },
            new class ($extension) implements PassedSubscriber {
                public function __construct(private readonly SkipListExtension $extension)
                {
                }

                public function notify(Passed $event): void
                {
                    $this->extension->recordOutcome('passed');
                }
            },
            new class ($extension) implements FailedSubscriber {
                public function __construct(private readonly SkipListExtension $extension)
                {
                }

                public function notify(Failed $event): void
                {
                    $this->extension->recordOutcome('failed');
                }
            },
            new class ($extension) implements ErroredSubscriber {
                public function __construct(private readonly SkipListExtension $extension)
                {
                }

                public function notify(Errored $event): void
                {
                    $this->extension->recordOutcome('errored');
                }
            },
            new class ($extension) implements SkippedSubscriber {
                public function __construct(private readonly SkipListExtension $extension)
                {
                }

                public function notify(Skipped $event): void
                {
                    $this->extension->recordOutcome('skipped');
                }
            },
            new class ($extension) implements MarkedIncompleteSubscriber {
                public function __construct(private readonly SkipListExtension $extension)
                {
                }

                public function notify(MarkedIncomplete $event): void
                {
                    $this->extension->recordOutcome('incomplete');
                }
            },
            new class ($extension) implements FinishedSubscriber {
                public function __construct(private readonly SkipListExtension $extension)
                {
                }

                public function notify(Finished $event): void
                {
                    $this->extension->testFinished();
                }
            },
            new class ($extension) implements ExecutionFinishedSubscriber {
                public function __construct(private readonly SkipListExtension $extension)
                {
                }

                public function notify(ExecutionFinished $event): void
                {
                    $this->extension->writeReport();
                }
            },
        ];
    }
}
