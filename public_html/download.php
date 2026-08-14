<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/MailService.php';
require_once __DIR__ . '/../private/render_helpers.php';
require_once __DIR__ . '/../private/validation_helpers.php';
require_once __DIR__ . '/../private/Logger.php';

const PRIVATE_STORAGE_DIR = __DIR__ . '/../private/';
const CIPHER_METHOD = 'aes-256-cbc';

/**
 * Toon een HTML-foutpagina in de MeoDrive huisstijl (alleen bij fouten
 * — nooit meer aanroepen ná het versturen van de download-headers).
 */
function abortDownload(string $message, int $httpCode = 400): never
{
    $body = '<h1 class="status-title">Download mislukt</h1>'
        . '<p class="status-message is-error">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . renderStatusLinks([
            ['href' => 'index.php', 'label' => 'Terug naar home'],
        ]);

    renderStatusPage('Download mislukt', $body, $httpCode);
}

$uploadId = trim($_GET['id'] ?? '');
$encryptionKey = trim($_GET['key'] ?? '');

if ($uploadId === '') {
    abortDownload('Geen download-ID opgegeven.', 400);
}

if ($encryptionKey === '' || !preg_match('/^[0-9a-f]{32}$/i', $encryptionKey)) {
    abortDownload('Geen geldige encryptiesleutel opgegeven. De sleutel hoort in de downloadlink.', 400);
}

if (!isValidUuid($uploadId)) {
    abortDownload('Het opgegeven ID is ongeldig.', 400);
}

try {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        'SELECT file_name, file_path, encryption_iv, is_verified, expires_at, email
         FROM uploads
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $uploadId]);
    $upload = $stmt->fetch();
} catch (Throwable $e) {
    abortDownload('Databasefout: ' . $e->getMessage(), 500);
}

if ($upload === false) {
    abortDownload('Deze downloadlink bestaat niet of is al gebruikt.', 404);
}

if ((int) $upload['is_verified'] !== 1) {
    abortDownload('Dit bestand is nog niet geverifieerd. Gebruik eerst de verificatielink.', 403);
}

if (isExpired($upload['expires_at'])) {
    abortDownload('Deze downloadlink is verlopen.', 410);
}

$storedFileName = basename($upload['file_path']);
$filePath = PRIVATE_STORAGE_DIR . $storedFileName;

if ($storedFileName === '' || !is_file($filePath)) {
    abortDownload('Het bestand is niet meer beschikbaar op de server.', 404);
}

$encryptionIv = $upload['encryption_iv'] ?? '';

if ($encryptionIv === '' || !preg_match('/^[0-9a-f]{32}$/i', $encryptionIv)) {
    abortDownload('Ontsleutelingsgegevens ontbreken voor deze upload.', 500);
}

$encryptedContent = file_get_contents($filePath);
if ($encryptedContent === false) {
    abortDownload('Kon het versleutelde bestand niet lezen.', 500);
}

$iv = hex2bin($encryptionIv);
if ($iv === false) {
    abortDownload('Het opgeslagen IV is ongeldig.', 500);
}

$decryptedContent = openssl_decrypt(
    $encryptedContent,
    CIPHER_METHOD,
    $encryptionKey,
    OPENSSL_RAW_DATA,
    $iv
);

if ($decryptedContent === false) {
    abortDownload('Ontsleutelen mislukt. De sleutel is onjuist of het bestand is beschadigd.', 403);
}

$originalFileName = basename($upload['file_name']);
$fileSize = strlen($decryptedContent);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $originalFileName) . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $decryptedContent;

// Na de download-stream zijn de headers al verstuurd — geen HTML meer tonen.
@unlink($filePath);

Logger::log('download', ['id' => $uploadId, 'file' => $originalFileName]);

try {
    $delete = $pdo->prepare('DELETE FROM uploads WHERE id = :id');
    $delete->execute([':id' => $uploadId]);
} catch (Throwable $e) {
    error_log('Download destroy mislukt voor upload ' . $uploadId . ': ' . $e->getMessage());
}

// De uploader laten weten dat het bestand daadwerkelijk is opgehaald (en dus
// definitief weg is). Een mislukte mail mag de al voltooide download niet
// alsnog laten falen — headers en bytes zijn al verstuurd — dus we loggen
// een fout hier alleen weg in plaats van hem te tonen.
try {
    $uploaderEmail = $upload['email'] ?? '';
    if ($uploaderEmail !== '' && filter_var($uploaderEmail, FILTER_VALIDATE_EMAIL)) {
        (new MailService())->sendDownloadConfirmation($uploaderEmail, $originalFileName);
    }
} catch (Throwable $e) {
    error_log('Downloadbevestigingsmail mislukt voor upload ' . $uploadId . ': ' . $e->getMessage());
}

exit;
