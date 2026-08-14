<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/render_helpers.php';
require_once __DIR__ . '/../private/validation_helpers.php';
require_once __DIR__ . '/../private/Csrf.php';
require_once __DIR__ . '/../private/Logger.php';

const PRIVATE_STORAGE_DIR = __DIR__ . '/../private/';

/**
 * Alleen de uploader kent deze link (die kreeg hij direct na het uploaden en
 * op de verificatiepagina te zien). Zolang de upload nog niet is gedownload
 * of automatisch opgeruimd, kan hij hem hiermee zelf definitief intrekken.
 */
$uploadId = trim($_GET['id'] ?? '');
$cancelToken = trim($_GET['token'] ?? '');

if ($uploadId === '' || $cancelToken === '') {
    renderStatusPage(
        'Fout',
        '<h1 class="status-title">Annuleren mislukt</h1>'
        . '<p class="status-message is-error">Geen upload-ID of annuleringstoken opgegeven.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        400
    );
}

if (!isValidUuid($uploadId)) {
    renderStatusPage(
        'Fout',
        '<h1 class="status-title">Annuleren mislukt</h1>'
        . '<p class="status-message is-error">Het opgegeven ID is ongeldig.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        400
    );
}

if (!preg_match('/^[0-9a-f]{64}$/i', $cancelToken)) {
    renderStatusPage(
        'Fout',
        '<h1 class="status-title">Annuleren mislukt</h1>'
        . '<p class="status-message is-error">Ongeldige annuleringslink.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        400
    );
}

try {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        'SELECT id, file_name, file_path, cancel_token
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
        '<h1 class="status-title">Upload niet gevonden</h1>'
        . '<p class="status-message is-error">Deze upload bestaat niet meer — hij is mogelijk al gedownload, '
        . 'eerder ingetrokken, of automatisch opgeruimd omdat hij was verlopen.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        404
    );
}

if (!hash_equals($upload['cancel_token'], $cancelToken)) {
    renderStatusPage(
        'Ongeldig',
        '<h1 class="status-title">Annuleren mislukt</h1>'
        . '<p class="status-message is-error">Ongeldige annuleringslink.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        403
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidateRequest()) {
        renderStatusPage(
            'Fout',
            '<h1 class="status-title">Annuleren mislukt</h1>'
            . '<p class="status-message is-error">Ongeldige of verlopen beveiligingstoken. Herlaad de pagina en probeer het opnieuw.</p>'
            . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
            403
        );
    }

    try {
        $storedFileName = basename($upload['file_path']);
        $filePath = PRIVATE_STORAGE_DIR . $storedFileName;

        if ($storedFileName !== '' && is_file($filePath)) {
            unlink($filePath);
        }

        $delete = $pdo->prepare('DELETE FROM uploads WHERE id = :id');
        $delete->execute([':id' => $uploadId]);

        Logger::log('annulering', ['id' => $uploadId, 'file' => $upload['file_name']]);
    } catch (Throwable $e) {
        renderStatusPage(
            'Serverfout',
            '<h1 class="status-title">Annuleren mislukt</h1>'
            . '<p class="status-message is-error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
            . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
            500
        );
    }

    renderStatusPage(
        'Upload ingetrokken',
        '<h1 class="status-title">Upload ingetrokken</h1>'
        . '<p class="status-message is-success">Je upload is verwijderd. Het bestand is definitief weg en de link werkt niet meer.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']])
    );
}

$formAction = 'cancel.php?id=' . urlencode($uploadId) . '&token=' . urlencode($cancelToken);

$content = '<h1 class="status-title">Upload intrekken</h1>'
    . '<p class="status-text">Bestand: <strong>' . htmlspecialchars($upload['file_name'], ENT_QUOTES, 'UTF-8') . '</strong></p>'
    . '<p class="status-text">Weet je zeker dat je deze upload wilt intrekken? Het bestand wordt direct en '
    . '<strong>definitief verwijderd</strong> van de server, en de downloadlink werkt daarna niet meer — ook niet '
    . 'als de ontvanger nog niet heeft gedownload.</p>'
    . '<form method="post" action="' . htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') . '">'
    . csrfHiddenField()
    . '<button type="submit" class="submit-btn">Ja, upload intrekken</button>'
    . '</form>'
    . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]);

renderStatusPage('Upload intrekken', $content);
