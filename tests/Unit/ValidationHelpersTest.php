<?php

declare(strict_types=1);

namespace MeoDrive\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Rooktest voor de PHPUnit-scaffolding + eerste echte tests voor de
 * gedeelde validatiefuncties uit private/validation_helpers.php.
 */
final class ValidationHelpersTest extends TestCase
{
    public function testValidUuidV4IsAccepted(): void
    {
        self::assertTrue(isValidUuid('550e8400-e29b-41d4-a716-446655440000'));
    }

    public function testUppercaseUuidIsAlsoAccepted(): void
    {
        self::assertTrue(isValidUuid('550E8400-E29B-41D4-A716-446655440000'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidUuidProvider(): iterable
    {
        yield 'leeg' => [''];
        yield 'te kort' => ['550e8400-e29b-41d4-a716'];
        yield 'geen UUID v4 (versie-nibble is 1)' => ['550e8400-e29b-11d4-a716-446655440000'];
        yield 'ontbrekende koppeltekens' => ['550e8400e29b41d4a716446655440000'];
        yield 'niet-hexadecimale tekens' => ['zzze8400-e29b-41d4-a716-446655440000'];
    }

    /**
     * @dataProvider invalidUuidProvider
     */
    public function testInvalidUuidsAreRejected(string $candidate): void
    {
        self::assertFalse(isValidUuid($candidate));
    }

    public function testPastDateTimeIsExpired(): void
    {
        $oneHourAgo = (new \DateTimeImmutable('now'))->modify('-1 hour')->format('Y-m-d H:i:s');

        self::assertTrue(isExpired($oneHourAgo));
    }

    public function testFutureDateTimeIsNotExpired(): void
    {
        $inOneHour = (new \DateTimeImmutable('now'))->modify('+1 hour')->format('Y-m-d H:i:s');

        self::assertFalse(isExpired($inOneHour));
    }

    public function testFileSizeUnderOneKilobyteIsFormattedInBytes(): void
    {
        self::assertSame('500 B', formatFileSize(500));
    }

    public function testFileSizeInKilobytes(): void
    {
        self::assertSame('2,5 KB', formatFileSize(2560));
    }

    public function testFileSizeInMegabytes(): void
    {
        self::assertSame('1,0 MB', formatFileSize(1_048_576));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function dangerousFileNameProvider(): iterable
    {
        yield 'php-script' => ['malware.php'];
        yield 'hoofdletters extensie' => ['malware.PHP'];
        yield 'windows-executable' => ['setup.exe'];
        yield 'dubbele extensie blijft gevaarlijk op de laatste' => ['factuur.pdf.exe'];
        yield 'powershell-script' => ['run.ps1'];
        yield 'htaccess' => ['pwn.htaccess'];
    }

    /**
     * @dataProvider dangerousFileNameProvider
     */
    public function testDangerousExtensionsAreDetected(string $fileName): void
    {
        self::assertTrue(isDangerousFileExtension($fileName));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function safeFileNameProvider(): iterable
    {
        yield 'pdf' => ['factuur.pdf'];
        yield 'afbeelding' => ['foto.png'];
        yield 'zip-archief' => ['MeoDrive_20260720-120000.zip'];
        yield 'geen extensie' => ['README'];
        yield 'office-document' => ['Redirects-nalopen (1).xlsx'];
    }

    /**
     * @dataProvider safeFileNameProvider
     */
    public function testSafeExtensionsAreAllowed(string $fileName): void
    {
        self::assertFalse(isDangerousFileExtension($fileName));
    }
}
