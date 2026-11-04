<?php

declare(strict_types=1);

namespace MartinJuul\DBAL\DuckDB\Tests\Functional;

use PHPUnit\Framework\TestCase;

use function DuckDB\version;
use function extension_loaded;
use function phpversion;

final class ExtensionLoadedTest extends TestCase
{
    public function testTheReleasedExtensionAndEngineAreLoaded(): void
    {
        self::assertTrue(extension_loaded('duckdb'));
        self::assertSame('2.0.0', phpversion('duckdb'));
        self::assertSame('v2.0.0', version());
    }
}
