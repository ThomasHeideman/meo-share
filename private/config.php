<?php

declare(strict_types=1);

/**
 * Database-connectie via PDO.
 * Inloggegevens staan in private/credentials.php (buiten de webroot).
 */

$credentialsFile = __DIR__ . '/credentials.php';

if (!is_readable($credentialsFile)) {
    throw new RuntimeException(
        'Databaseconfiguratie ontbreekt. Kopieer credentials.example.php naar credentials.php.'
    );
}

/** @var array{host: string, dbname: string, user: string, password: string} $dbConfig */
$dbConfig = require $credentialsFile;

// Alleen gebruikt door de PHPUnit-integratietests (tests/Integration/), die
// tegen een losse testdatabase draaien in plaats van de echte
// ontwikkeldatabase. Buiten een testrun is deze omgevingsvariabele niet
// gezet en verandert er niets aan het normale gedrag.
$testDbNameOverride = getenv('MEODRIVE_DB_NAME');
if ($testDbNameOverride !== false && $testDbNameOverride !== '') {
    $dbConfig['dbname'] = $testDbNameOverride;
}

function getDbConnection(): PDO
{
    global $dbConfig;

    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $dbConfig['host'],
        $dbConfig['dbname']
    );

    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
