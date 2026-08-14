<?php

declare(strict_types=1);

/**
 * Simpel bestandsgebaseerd audit-log naar private/logs/activity.log (buiten
 * de webroot, net als de versleutelde bestanden zelf). Bewust géén
 * databasetabel: een los, append-only tekstbestand is voor dit platform
 * eenvoudiger te lezen (bijv. tijdens het schrijven van een stageverslag)
 * en blijft ook werken als de database ooit even niet bereikbaar is.
 *
 * Een mislukte logschrijving mag de eigenlijke actie (upload, verificatie,
 * download, ...) nooit laten mislukken — fouten hier worden daarom altijd
 * afgevangen en weggeschreven naar de reguliere PHP-errorlog in plaats van
 * doorgegooid.
 */
final class Logger
{
    private static ?string $logPathOverride = null;

    public static function log(string $event, array $context = []): void
    {
        $line = sprintf(
            '[%s] %-20s %s',
            (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            $event,
            self::formatContext($context)
        );

        try {
            $path = self::logPath();
            self::ensureLogDirectoryExists($path);
            file_put_contents($path, rtrim($line) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            error_log('Logger kon niet schrijven naar activity.log: ' . $e->getMessage());
        }
    }

    /**
     * Alleen voor gebruik in tests: schrijf tijdelijk naar een ander
     * bestand, zodat tests niet het echte activity.log vervuilen. Geef
     * null door om terug te schakelen naar het standaardpad.
     */
    public static function useLogPathForTesting(?string $path): void
    {
        self::$logPathOverride = $path;
    }

    private static function logPath(): string
    {
        return self::$logPathOverride ?? __DIR__ . '/logs/activity.log';
    }

    private static function ensureLogDirectoryExists(string $path): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Kon logmap niet aanmaken: {$dir}");
        }
    }

    private static function formatContext(array $context): string
    {
        if ($context === []) {
            return '';
        }

        $pairs = [];
        foreach ($context as $key => $value) {
            $pairs[] = $key . '=' . self::stringifyValue($value);
        }

        return implode(' ', $pairs);
    }

    private static function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return (string) $value;
    }
}
