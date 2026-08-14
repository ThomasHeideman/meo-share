<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/MailService.php';
require_once __DIR__ . '/../private/render_helpers.php';
require_once __DIR__ . '/../private/validation_helpers.php';
require_once __DIR__ . '/../private/RateLimiter.php';
require_once __DIR__ . '/../private/Csrf.php';
require_once __DIR__ . '/../private/Logger.php';

/**
 * Genereer een cryptografisch veilige UUID v4.
 */
function generateUuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
    $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($bytes), 4)
    );
}

/**
 * Toon een foutmelding en stop het script.
 */
function abort(string $message, int $httpCode = 400): never
{
    $body = '<h1 class="status-title">Upload mislukt</h1>'
        . '<p class="status-message is-error">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . renderStatusLinks([
            ['href' => 'index.php', 'label' => 'Terug naar home'],
        ]);

    renderStatusPage('Upload mislukt', $body, $httpCode);
}

const MEO_RECIPIENT_EMAIL = 'thomas@wijzijnmeo.nl';
const PRIVATE_STORAGE_DIR = __DIR__ . '/../private/';
const CIPHER_METHOD = 'aes-256-cbc';
const MAX_FILES_PER_UPLOAD = 3;
const MAX_UPLOADS_PER_WINDOW = 5;
const UPLOAD_RATE_LIMIT_WINDOW_SECONDS = 900; // 15 minuten

/**
 * Normaliseer de PHP $_FILES-array (die bij <input name="file[]" multiple>
 * per eigenschap een array van waarden bevat) naar een lijst van losse
 * bestandsentries: [['name' => ..., 'tmp_name' => ..., 'error' => ..., 'size' => ...], ...].
 *
 * @param array<string, mixed> $filesField
 * @return array<int, array{name: string, tmp_name: string, error: int, size: int}>
 */
function normalizeUploadedFiles(array $filesField): array
{
    if (!isset($filesField['name'])) {
        return [];
    }

    if (!is_array($filesField['name'])) {
        return [[
            'name'     => (string) $filesField['name'],
            'tmp_name' => (string) $filesField['tmp_name'],
            'error'    => (int) $filesField['error'],
            'size'     => (int) $filesField['size'],
        ]];
    }

    $files = [];
    foreach (array_keys($filesField['name']) as $index) {
        // Een leeg extra bestandsslot (geen bestand gekozen) heeft error === UPLOAD_ERR_NO_FILE
        // en een lege naam; die slaan we hier al over.
        if ($filesField['error'][$index] === UPLOAD_ERR_NO_FILE && $filesField['name'][$index] === '') {
            continue;
        }

        $files[] = [
            'name'     => (string) $filesField['name'][$index],
            'tmp_name' => (string) $filesField['tmp_name'][$index],
            'error'    => (int) $filesField['error'][$index],
            'size'     => (int) $filesField['size'][$index],
        ];
    }

    return $files;
}

/**
 * Zet meerdere geüploade bestanden samen in één ZIP-archief en geeft het pad
 * naar dat tijdelijke archief terug. Zo kan de rest van de flow (encryptie,
 * opslag, download-and-destroy) ongewijzigd blijven werken met "1 bestand".
 *
 * @param array<int, array{name: string, tmp_name: string, error: int, size: int}> $files
 */
function zipUploadedFiles(array $files): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'meo_zip_');
    if ($zipPath === false) {
        throw new RuntimeException('Kon geen tijdelijk ZIP-bestand aanmaken.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        unlink($zipPath);
        throw new RuntimeException('Kon het ZIP-archief niet openen voor schrijven.');
    }

    $usedNames = [];
    foreach ($files as $file) {
        $entryName = basename($file['name']);
        $originalEntryName = $entryName;
        $suffix = 1;

        // Voorkom overschrijven binnen het archief als twee bestanden dezelfde naam hebben.
        while (isset($usedNames[$entryName])) {
            $extension = pathinfo($originalEntryName, PATHINFO_EXTENSION);
            $base = pathinfo($originalEntryName, PATHINFO_FILENAME);
            $entryName = $extension !== '' ? "{$base} ({$suffix}).{$extension}" : "{$base} ({$suffix})";
            $suffix++;
        }

        $usedNames[$entryName] = true;

        if (!$zip->addFile($file['tmp_name'], $entryName)) {
            $zip->close();
            unlink($zipPath);
            throw new RuntimeException('Kon "' . $entryName . '" niet aan het ZIP-archief toevoegen.');
        }
    }

    if (!$zip->close()) {
        unlink($zipPath);
        throw new RuntimeException('Kon het ZIP-archief niet correct afronden.');
    }

    return $zipPath;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abort('Alleen POST-requests zijn toegestaan.', 405);
}

// Wanneer de POST-body groter is dan php.ini's post_max_size, ruimt PHP
// $_POST en $_FILES zelf helemaal leeg zonder een bruikbare uploadfout te
// zetten. Zonder deze check zou de gebruiker verderop alleen de verwarrende
// melding "Er is geen bestand gekozen" krijgen, terwijl er wél iets is
// geselecteerd — het paste alleen niet in de toegestane limiet.
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 0 && $_POST === [] && $_FILES === []) {
    $postMaxSize = ini_get('post_max_size') ?: 'de serverlimiet';
    abort(
        'De geselecteerde bestanden zijn samen te groot om te uploaden (limiet: '
        . htmlspecialchars($postMaxSize, ENT_QUOTES, 'UTF-8') . '). '
        . 'Probeer kleinere bestanden of upload ze in meerdere keren.',
        413
    );
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'onbekend');

if (!csrfValidateRequest()) {
    Logger::log('upload_csrf_geweigerd', ['ip' => $clientIp]);
    abort(
        'Ongeldige of verlopen beveiligingstoken. Ga terug naar de homepage en probeer het opnieuw.',
        403
    );
}

// Rate limiting per IP-adres: voorkomt dat iemand deze pagina misbruikt om
// massaal bestanden te blijven uploaden (spam, opslag vollopen). De check
// staat vóór alle overige validatie, zodat een geblokkeerd IP-adres niet
// eens de moeite van de rest van de verwerking waard is.
try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    abort('Databaseverbinding mislukt: ' . $e->getMessage(), 500);
}

$rateLimiter = new RateLimiter($pdo);

if ($rateLimiter->tooManyAttempts('upload_ip', $clientIp, MAX_UPLOADS_PER_WINDOW, UPLOAD_RATE_LIMIT_WINDOW_SECONDS)) {
    Logger::log('upload_rate_limited', ['ip' => $clientIp]);
    abort(
        'Je hebt de afgelopen 15 minuten al te veel bestanden geüpload vanaf dit netwerk '
        . '(max. ' . MAX_UPLOADS_PER_WINDOW . '). Wacht even en probeer het daarna opnieuw.',
        429
    );
}

$userRole = $_POST['user_role'] ?? '';

if (!in_array($userRole, ['client', 'meo'], true)) {
    abort('Ongeldige rol. Kies opnieuw wie je bent.');
}

$senderEmail = trim($_POST['sender_email'] ?? '');

if ($senderEmail === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
    abort('Voer een geldig e-mailadres in.');
}

if ($userRole === 'meo') {
    $emailReceiver = trim($_POST['recipient_email'] ?? '');

    if ($emailReceiver === '' || !filter_var($emailReceiver, FILTER_VALIDATE_EMAIL)) {
        abort('Voer een geldig e-mailadres in voor de ontvanger.');
    }
} else {
    $emailReceiver = MEO_RECIPIENT_EMAIL;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    abort('Geen bestand ontvangen.');
}

$uploadedFiles = normalizeUploadedFiles($_FILES['file']);

if (count($uploadedFiles) === 0) {
    abort('Er is geen bestand gekozen.');
}

if (count($uploadedFiles) > MAX_FILES_PER_UPLOAD) {
    abort('Je kunt maximaal ' . MAX_FILES_PER_UPLOAD . ' bestanden per keer uploaden.');
}

$uploadErrorMessages = [
    UPLOAD_ERR_INI_SIZE   => 'Het bestand is te groot (serverlimiet).',
    UPLOAD_ERR_FORM_SIZE  => 'Het bestand is te groot (formulierlimiet).',
    UPLOAD_ERR_PARTIAL    => 'Het bestand is slechts gedeeltelijk geüpload.',
    UPLOAD_ERR_NO_FILE    => 'Er is geen bestand gekozen.',
    UPLOAD_ERR_NO_TMP_DIR => 'Tijdelijke uploadmap ontbreekt op de server.',
    UPLOAD_ERR_CANT_WRITE => 'Kon het bestand niet naar schijf schrijven.',
    UPLOAD_ERR_EXTENSION  => 'Upload geblokkeerd door een PHP-extensie.',
];

foreach ($uploadedFiles as $file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = $uploadErrorMessages[$file['error']] ?? 'Onbekende uploadfout (code ' . $file['error'] . ').';
        abort($message);
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        abort('Ongeldige upload: bestand komt niet van een HTTP-upload.');
    }

    if (isDangerousFileExtension($file['name'])) {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        abort(
            'Bestandstype ".' . htmlspecialchars($extension, ENT_QUOTES, 'UTF-8') . '" is niet '
            . 'toegestaan om veiligheidsredenen (uitvoerbare bestanden en scripts worden geblokkeerd).'
        );
    }
}

if (!is_dir(PRIVATE_STORAGE_DIR)) {
    abort('Opslagmap private/ bestaat niet. Maak deze map aan buiten de webroot.', 500);
}

if (!is_writable(PRIVATE_STORAGE_DIR)) {
    abort('Opslagmap private/ is niet beschrijfbaar.', 500);
}

$zipPath = null;

try {
    $uploadId = generateUuidV4();
    $verificationCode = (string) random_int(100000, 999999);
    // Los van de encryptiesleutel: een eigen, willekeurige token waarmee de
    // uploader (en alleen de uploader, want alleen hij kent deze link) zijn
    // eigen upload kan intrekken vóórdat de ontvanger downloadt.
    $cancelToken = bin2hex(random_bytes(32));
    $expiresAt = (new DateTimeImmutable('now'))->modify('+24 hours')->format('Y-m-d H:i:s');

    // Bij precies 1 bestand behandelen we het zoals voorheen. Bij meerdere
    // bestanden zippen we ze eerst tot één archief, zodat de rest van de flow
    // (encryptie, opslag, download-and-destroy) altijd met "1 bestand" werkt.
    if (count($uploadedFiles) === 1) {
        $originalFileName = basename($uploadedFiles[0]['name']);
        $plaintext = file_get_contents($uploadedFiles[0]['tmp_name']);
        if ($plaintext === false) {
            throw new RuntimeException('Kon het geüploade bestand niet lezen.');
        }
    } else {
        $zipPath = zipUploadedFiles($uploadedFiles);
        $originalFileName = 'MeoDrive_' . (new DateTimeImmutable('now'))->format('Ymd-His') . '.zip';

        $plaintext = file_get_contents($zipPath);
        unlink($zipPath);
        $zipPath = null;

        if ($plaintext === false) {
            throw new RuntimeException('Kon het samengestelde ZIP-archief niet lezen.');
        }
    }

    $extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
    $safeExtension = $extension !== ''
        ? '.' . preg_replace('/[^a-zA-Z0-9]/', '', $extension)
        : '';

    $storedFileName = bin2hex(random_bytes(16)) . $safeExtension;
    $destinationPath = PRIVATE_STORAGE_DIR . $storedFileName;

    $encryptionKey = bin2hex(random_bytes(16));

    $ivLength = openssl_cipher_iv_length(CIPHER_METHOD);
    if ($ivLength === false) {
        throw new RuntimeException('Kon de IV-lengte niet bepalen voor ' . CIPHER_METHOD . '.');
    }

    $iv = openssl_random_pseudo_bytes($ivLength);
    if ($iv === false) {
        throw new RuntimeException('Kon geen Initialization Vector genereren.');
    }

    $encrypted = openssl_encrypt($plaintext, CIPHER_METHOD, $encryptionKey, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        throw new RuntimeException('Kon het bestand niet versleutelen.');
    }

    if (file_put_contents($destinationPath, $encrypted) === false) {
        throw new RuntimeException('Kon het versleutelde bestand niet opslaan.');
    }

    $encryptionIv = bin2hex($iv);

    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        'INSERT INTO uploads (
            id,
            file_name,
            file_path,
            encryption_iv,
            encryption_key,
            cancel_token,
            email,
            email_receiver,
            verification_code,
            expires_at
        ) VALUES (
            :id,
            :file_name,
            :file_path,
            :encryption_iv,
            :encryption_key,
            :cancel_token,
            :email,
            :email_receiver,
            :verification_code,
            :expires_at
        )'
    );

    $stmt->execute([
        ':id'                 => $uploadId,
        ':file_name'          => $originalFileName,
        ':file_path'          => $storedFileName,
        ':encryption_iv'      => $encryptionIv,
        ':encryption_key'     => $encryptionKey,
        ':cancel_token'       => $cancelToken,
        ':email'              => $senderEmail,
        ':email_receiver'     => $emailReceiver,
        ':verification_code'  => $verificationCode,
        ':expires_at'         => $expiresAt,
    ]);
} catch (Throwable $e) {
    if ($zipPath !== null && is_file($zipPath)) {
        unlink($zipPath);
    }

    if (isset($destinationPath) && is_file($destinationPath)) {
        unlink($destinationPath);
    }

    $body = '<h1 class="status-title">Er ging iets mis</h1>'
        . '<p class="status-message is-error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
        . renderStatusLinks([
            ['href' => 'index.php', 'label' => 'Terug naar home'],
        ]);

    renderStatusPage('Serverfout', $body, 500);
}

try {
    $mailService = new MailService();
    $mailService->sendVerificationCode(
        $senderEmail,
        $verificationCode,
        $uploadId,
        $originalFileName
    );
} catch (Throwable $e) {
    $body = '<h1 class="status-title">Upload opgeslagen, e-mail mislukt</h1>'
        . '<p class="status-message is-error">Het bestand is opgeslagen, maar de verificatie-e-mail kon niet worden aangemaakt: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p class="status-text">Controleer uw Mailtrap SMTP-instellingen in <code>private/credentials.php</code>.</p>'
        . renderStatusLinks([
            ['href' => 'verify.php?id=' . urlencode($uploadId), 'label' => 'Toch naar verificatie'],
            ['href' => 'index.php', 'label' => 'Terug naar home'],
        ]);

    renderStatusPage('E-mail mislukt', $body, 500);
}

Logger::log('upload', [
    'id'         => $uploadId,
    'role'       => $userRole,
    'ip'         => $clientIp,
    'file_count' => count($uploadedFiles),
]);

$verifyUrl = 'verify.php?id=' . urlencode($uploadId);
$cancelUrl = 'cancel.php?id=' . urlencode($uploadId) . '&token=' . urlencode($cancelToken);

$body = '<h1 class="status-title">Upload geslaagd</h1>'
    . '<p class="status-message is-success">Uw bestand is veilig opgeslagen.</p>'
    . '<p class="status-text">We hebben een 6-cijferige verificatiecode gestuurd naar '
    . '<strong>' . htmlspecialchars($senderEmail, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
    . '<p class="status-text">Controleer uw e-mail en voer de code in op de verificatiepagina.</p>'
    . '<a class="submit-btn" href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '">Ga naar verificatie</a>'
    . '<p class="status-text">Van gedachten veranderd? '
    . '<a href="' . htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8') . '">Annuleer deze upload</a>.</p>'
    . renderStatusLinks([
        ['href' => 'index.php', 'label' => 'Nog een bestand uploaden'],
    ]);

renderStatusPage('Upload geslaagd', $body);
