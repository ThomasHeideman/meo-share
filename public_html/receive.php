<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/render_helpers.php';
require_once __DIR__ . '/../private/validation_helpers.php';

const PRIVATE_STORAGE_DIR = __DIR__ . '/../private/';
const CIPHER_METHOD = 'aes-256-cbc';

$uploadId = trim($_GET['id'] ?? '');
$encryptionKey = trim($_GET['key'] ?? '');

if ($uploadId === '') {
    renderStatusPage(
        'Fout',
        '<h1 class="status-title">Download niet beschikbaar</h1>'
        . '<p class="status-message is-error">Geen download-ID opgegeven.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        400
    );
}

if ($encryptionKey === '' || !preg_match('/^[0-9a-f]{32}$/i', $encryptionKey)) {
    renderStatusPage(
        'Fout',
        '<h1 class="status-title">Download niet beschikbaar</h1>'
        . '<p class="status-message is-error">Geen geldige encryptiesleutel opgegeven.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        400
    );
}

if (!isValidUuid($uploadId)) {
    renderStatusPage(
        'Fout',
        '<h1 class="status-title">Download niet beschikbaar</h1>'
        . '<p class="status-message is-error">Het opgegeven ID is ongeldig.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        400
    );
}

try {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        'SELECT file_name, file_path, encryption_iv, is_verified, expires_at
         FROM uploads
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $uploadId]);
    $upload = $stmt->fetch();
} catch (Throwable $e) {
    renderStatusPage(
        'Serverfout',
        '<h1 class="status-title">Er ging iets mis</h1>'
        . '<p class="status-message is-error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        500
    );
}

if ($upload === false) {
    renderStatusPage(
        'Niet gevonden',
        '<h1 class="status-title">Download niet beschikbaar</h1>'
        . '<p class="status-message is-error">Deze downloadlink bestaat niet of is al gebruikt.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        404
    );
}

if ((int) $upload['is_verified'] !== 1) {
    renderStatusPage(
        'Niet geverifieerd',
        '<h1 class="status-title">Download niet beschikbaar</h1>'
        . '<p class="status-message is-error">Dit bestand is nog niet geverifieerd. Gebruik eerst de verificatielink uit je e-mail.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        403
    );
}

if (isExpired($upload['expires_at'])) {
    renderStatusPage(
        'Verlopen',
        '<h1 class="status-title">Download niet beschikbaar</h1>'
        . '<p class="status-message is-error">Deze downloadlink is verlopen.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        410
    );
}

$storedFileName = basename($upload['file_path']);
$filePath = PRIVATE_STORAGE_DIR . $storedFileName;

if ($storedFileName === '' || !is_file($filePath)) {
    renderStatusPage(
        'Niet gevonden',
        '<h1 class="status-title">Download niet beschikbaar</h1>'
        . '<p class="status-message is-error">Het bestand is niet meer beschikbaar op de server.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        404
    );
}

$originalFileName = basename($upload['file_name']);
$fileSizeLabel = 'Onbekend';

$encryptionIv = $upload['encryption_iv'] ?? '';

if ($encryptionIv !== '' && preg_match('/^[0-9a-f]{32}$/i', $encryptionIv)) {
    $encryptedContent = file_get_contents($filePath);

    if ($encryptedContent !== false) {
        $iv = hex2bin($encryptionIv);

        if ($iv !== false) {
            $decryptedContent = openssl_decrypt(
                $encryptedContent,
                CIPHER_METHOD,
                $encryptionKey,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decryptedContent === false) {
                renderStatusPage(
                    'Ongeldige sleutel',
                    '<h1 class="status-title">Download niet beschikbaar</h1>'
                    . '<p class="status-message is-error">De encryptiesleutel in de link is onjuist.</p>'
                    . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
                    403
                );
            }

            $fileSizeLabel = formatFileSize(strlen($decryptedContent));
        }
    }
}

$downloadUrl = 'download.php?id=' . urlencode($uploadId) . '&key=' . urlencode($encryptionKey);

$content = '<div id="receiveCard" class="receive-card">';
$content .= '<h1 class="status-title">Beveiligd bestand ontvangen</h1>';
$content .= '<p class="status-text">Er staat een beveiligd bestand voor u klaar.</p>';
$content .= '<dl class="info-card">';
$content .= '<dt>Bestandsnaam</dt>';
$content .= '<dd>' . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . '</dd>';
$content .= '<dt>Grootte</dt>';
$content .= '<dd>' . htmlspecialchars($fileSizeLabel, ENT_QUOTES, 'UTF-8') . '</dd>';
$content .= '<dt>Geldig tot</dt>';
$content .= '<dd>' . htmlspecialchars($upload['expires_at'], ENT_QUOTES, 'UTF-8') . '</dd>';
$content .= '</dl>';
$content .= '<p class="status-text">Let op: het bestand kan <strong>slechts één keer</strong> worden gedownload. Zorg dat je op de juiste plek opslaat.</p>';
$content .= '<a id="downloadBtn" class="submit-btn" href="' . htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Start Download</a>';
$content .= '</div>';

renderStatusPage('Bestand ontvangen', $content, 200, ['js/receive.js']);
