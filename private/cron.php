<?php

declare(strict_types=1);

/**
 * Opschoon-cronjob: verwijdert verlopen uploads (bestand + databaserij).
 *
 * Aanroepen via CLI:
 *   php C:\xampp\htdocs\meo-share\private\cron.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/MailService.php';
require_once __DIR__ . '/Logger.php';

const PRIVATE_STORAGE_DIR = __DIR__ . '/';
const CRON_ALERT_EMAIL = 'thomas@wijzijnmeo.nl';

/**
 * Schrijf een regel naar de console of browser.
 */
function cronLog(string $message): void
{
    echo $message . PHP_EOL;
}

/**
 * Stuur een alertmail als de cronjob niet (volledig) is gelukt. Deze functie
 * mag zelf nooit een fatale fout veroorzaken — een mislukte mail mag de
 * cron-uitvoering niet alsnog laten crashen, dus fouten worden hier alleen
 * gelogd in plaats van doorgegooid.
 */
function notifyCronFailure(string $details): void
{
    try {
        (new MailService())->sendCronFailureAlert(CRON_ALERT_EMAIL, $details);
        cronLog('Alertmail verstuurd naar ' . CRON_ALERT_EMAIL . '.');
    } catch (Throwable $e) {
        cronLog('Kon geen alertmail versturen: ' . $e->getMessage());
    }
}

try {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        'SELECT id, file_path, file_name, expires_at
         FROM uploads
         WHERE expires_at < NOW()'
    );
    $stmt->execute();
    $expiredUploads = $stmt->fetchAll();

    if ($expiredUploads === []) {
        cronLog('MeoDrive cron: geen verlopen uploads gevonden.');
        exit(0);
    }

    $cleanedCount = 0;
    $errorCount   = 0;

    foreach ($expiredUploads as $upload) {
        $uploadId       = $upload['id'];
        $storedFileName = basename($upload['file_path']);
        $filePath       = PRIVATE_STORAGE_DIR . $storedFileName;

        if ($storedFileName !== '' && is_file($filePath)) {
            if (!unlink($filePath)) {
                cronLog("FOUT: kon bestand niet verwijderen voor upload {$uploadId} ({$storedFileName}).");
                $errorCount++;
                continue;
            }

            cronLog("Bestand verwijderd: {$storedFileName}");
        }

        $delete = $pdo->prepare('DELETE FROM uploads WHERE id = :id');
        $delete->execute([':id' => $uploadId]);

        $cleanedCount++;
        cronLog(
            "Database opgeruimd: {$uploadId} "
            . '(' . ($upload['file_name'] ?? 'onbekend') . ', verlopen op ' . $upload['expires_at'] . ')'
        );
    }

    cronLog('');
    cronLog("MeoDrive cron voltooid: {$cleanedCount} verlopen upload(s) succesvol opgeruimd.");

    Logger::log('cron_opgeruimd', ['cleaned' => $cleanedCount, 'errors' => $errorCount]);

    if ($errorCount > 0) {
        $message = "Waarschuwing: {$errorCount} upload(s) konden niet volledig worden opgeruimd.";
        cronLog($message);
        notifyCronFailure(
            "{$errorCount} van de {$cleanedCount} verlopen upload(s) konden niet volledig "
            . 'worden opgeruimd (bestand en/of databaserij). Zie de serverlogs voor details per upload.'
        );
        exit(1);
    }

    exit(0);
} catch (Throwable $e) {
    cronLog('MeoDrive cron mislukt: ' . $e->getMessage());
    Logger::log('cron_mislukt', ['error' => $e->getMessage()]);
    notifyCronFailure('De cronjob is volledig gestopt met een fout: ' . $e->getMessage());
    exit(1);
}
