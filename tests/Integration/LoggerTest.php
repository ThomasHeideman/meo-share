<?php

declare(strict_types=1);

namespace MeoDrive\Tests\Integration;

use Logger;
use PHPUnit\Framework\TestCase;

/**
 * Integratietest omdat Logger echt naar schijf schrijft (via een tijdelijk
 * testpad, zodat het echte private/logs/activity.log niet wordt vervuild).
 */
final class LoggerTest extends TestCase
{
    private string $testLogPath;

    protected function setUp(): void
    {
        $this->testLogPath = sys_get_temp_dir() . '/meodrive_test_' . bin2hex(random_bytes(6)) . '/activity.log';
        Logger::useLogPathForTesting($this->testLogPath);
    }

    protected function tearDown(): void
    {
        Logger::useLogPathForTesting(null);

        if (is_file($this->testLogPath)) {
            unlink($this->testLogPath);
        }
        $dir = dirname($this->testLogPath);
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    public function testLogCreatesDirectoryAndFile(): void
    {
        Logger::log('test_event');

        self::assertFileExists($this->testLogPath);
    }

    public function testLogLineContainsEventNameAndTimestamp(): void
    {
        Logger::log('upload', ['id' => 'abc-123']);

        $contents = file_get_contents($this->testLogPath);

        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] upload\s+id=abc-123/',
            trim($contents)
        );
    }

    public function testMultipleLogCallsAppendRatherThanOverwrite(): void
    {
        Logger::log('eerste_event');
        Logger::log('tweede_event');

        $lines = array_filter(explode(PHP_EOL, (string) file_get_contents($this->testLogPath)));

        self::assertCount(2, $lines);
        self::assertStringContainsString('eerste_event', $lines[0]);
        self::assertStringContainsString('tweede_event', $lines[1]);
    }

    public function testBooleanAndNullContextValuesAreStringified(): void
    {
        Logger::log('context_test', ['success' => true, 'failed' => false, 'extra' => null]);

        $contents = (string) file_get_contents($this->testLogPath);

        self::assertStringContainsString('success=true', $contents);
        self::assertStringContainsString('failed=false', $contents);
        self::assertStringContainsString('extra=null', $contents);
    }
}
