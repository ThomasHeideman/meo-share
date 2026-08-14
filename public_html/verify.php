<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/MailService.php';
require_once __DIR__ . '/../private/render_helpers.php';
require_once __DIR__ . '/../private/validation_helpers.php';
require_once __DIR__ . '/../private/rate_limit_helpers.php';
require_once __DIR__ . '/../private/RateLimiter.php';
require_once __DIR__ . '/../private/Csrf.php';
require_once __DIR__ . '/../private/Logger.php';

const MAX_VERIFICATION_ATTEMPTS = 5;
const VERIFICATION_LOCKOUT_SECONDS = 900; // 15 minuten
const RESEND_CODE_COOLDOWN_SECONDS = 60;

$uploadId = trim($_GET['id'] ?? '');

if ($uploadId === '') {
    renderStatusPage(
        'Fout',
        '<h1 class="status-title">Verificatie mislukt</h1>'
        . '<p class="status-message is-error">Geen upload-ID opgegeven. Gebruik de link uit je e-mail.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        400
    );
}

if (!isValidUuid($uploadId)) {
    renderStatusPage(
        'Fout',
        '<h1 class="status-title">Verificatie mislukt</h1>'
        . '<p class="status-message is-error">Het opgegeven ID is ongeldig.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        400
    );
}

try {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        'SELECT id, email, verification_code, verification_attempts, locked_until,
                is_verified, expires_at, email_receiver, encryption_key, file_name, cancel_token
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
        . '<p class="status-message is-error">Deze downloadlink bestaat niet of is verwijderd.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        404
    );
}

if ((int) $upload['is_verified'] === 1) {
    renderStatusPage(
        'Al geverifieerd',
        '<h1 class="status-title">Al geverifieerd</h1>'
        . '<p class="status-text">Deze upload is al eerder geverifieerd. De downloadlink is verzonden naar de ontvanger.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']])
    );
}

if (isExpired($upload['expires_at'])) {
    renderStatusPage(
        'Verlopen',
        '<h1 class="status-title">Link verlopen</h1>'
        . '<p class="status-message is-error">Deze downloadlink is verlopen op '
        . htmlspecialchars($upload['expires_at'], ENT_QUOTES, 'UTF-8')
        . '.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        410
    );
}

// Limiet op verificatiepogingen: zonder dit zou de 6-cijferige code
// (1-op-1.000.000) op termijn gewoon te gokken zijn. Na te veel mislukte
// pogingen wordt de upload tijdelijk vergrendeld, los van het IP-adres van
// de gebruiker (zodat overstappen op een ander netwerk niet helpt).
if (isLockedOut($upload['locked_until'] ?? null)) {
    $minutesLeft = minutesUntil($upload['locked_until']);
    renderStatusPage(
        'Tijdelijk vergrendeld',
        '<h1 class="status-title">Te veel mislukte pogingen</h1>'
        . '<p class="status-message is-error">Deze upload is tijdelijk vergrendeld na te veel mislukte '
        . 'verificatiepogingen. Probeer het over ongeveer ' . $minutesLeft . ' minuut/minuten opnieuw.</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        429
    );
}

$errorMessage = '';
$infoMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfValidateRequest()) {
    $errorMessage = 'Ongeldige of verlopen beveiligingstoken. Herlaad deze pagina en probeer het opnieuw.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_code') {
    // "Code opnieuw versturen": met een cooldown, zodat dit knopje niet kan
    // worden misbruikt om de mailserver te bestoken met verzendverzoeken.
    $rateLimiter = new RateLimiter($pdo);

    if ($rateLimiter->tooManyAttempts('resend_code', $uploadId, 1, RESEND_CODE_COOLDOWN_SECONDS)) {
        $errorMessage = 'Je kunt de code maximaal eens per minuut opnieuw laten versturen. Probeer het zo weer.';
    } else {
        try {
            $mailService = new MailService();
            $mailService->sendVerificationCode(
                $upload['email'],
                $upload['verification_code'],
                $uploadId,
                $upload['file_name']
            );
            $infoMessage = 'De verificatiecode is opnieuw verzonden naar ' . htmlspecialchars($upload['email'], ENT_QUOTES, 'UTF-8') . '.';
            Logger::log('verify_code_opnieuw_verzonden', ['id' => $uploadId]);
        } catch (Throwable $e) {
            $errorMessage = 'Het opnieuw versturen van de code is mislukt: ' . $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCode = trim($_POST['verification_code'] ?? '');

    if (!preg_match('/^\d{6}$/', $submittedCode)) {
        $errorMessage = 'Voer een geldige 6-cijferige code in.';
    } elseif (!hash_equals($upload['verification_code'], $submittedCode)) {
        $newAttempts = (int) $upload['verification_attempts'] + 1;

        if ($newAttempts >= MAX_VERIFICATION_ATTEMPTS) {
            $lockedUntil = nextLockedUntil(VERIFICATION_LOCKOUT_SECONDS);
            $lock = $pdo->prepare(
                'UPDATE uploads
                 SET verification_attempts = :attempts, locked_until = :locked_until
                 WHERE id = :id'
            );
            $lock->execute([':attempts' => $newAttempts, ':locked_until' => $lockedUntil, ':id' => $uploadId]);

            Logger::log('verify_vergrendeld', ['id' => $uploadId, 'attempts' => $newAttempts]);

            renderStatusPage(
                'Tijdelijk vergrendeld',
                '<h1 class="status-title">Te veel mislukte pogingen</h1>'
                . '<p class="status-message is-error">Je hebt te vaak een onjuiste code ingevoerd. Deze upload is nu '
                . '15 minuten vergrendeld.</p>'
                . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
                429
            );
        }

        $attemptsLeft = MAX_VERIFICATION_ATTEMPTS - $newAttempts;
        $updateAttempts = $pdo->prepare(
            'UPDATE uploads SET verification_attempts = :attempts WHERE id = :id'
        );
        $updateAttempts->execute([':attempts' => $newAttempts, ':id' => $uploadId]);

        $errorMessage = 'De ingevulde code is onjuist. Probeer het opnieuw. '
            . '(Nog ' . $attemptsLeft . ' poging(en) voor deze upload wordt vergrendeld.)';

        Logger::log('verify_mislukt', ['id' => $uploadId, 'attempts' => $newAttempts]);
    } elseif (isExpired($upload['expires_at'])) {
        renderStatusPage(
            'Verlopen',
            '<h1 class="status-title">Link verlopen</h1>'
            . '<p class="status-message is-error">De verificatiecode is niet meer geldig; de link is verlopen.</p>'
            . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
            410
        );
    } else {
        try {
            $update = $pdo->prepare(
                'UPDATE uploads
                 SET is_verified = 1, verification_attempts = 0, locked_until = NULL
                 WHERE id = :id AND is_verified = 0'
            );
            $update->execute([':id' => $uploadId]);

            $encryptionKey = $upload['encryption_key'] ?? '';
            $emailReceiver = $upload['email_receiver'] ?? '';
            $fileName      = $upload['file_name'] ?? '';

            if ($emailReceiver === '' || !filter_var($emailReceiver, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Geen geldig ontvanger-e-mailadres gevonden.');
            }

            if ($encryptionKey === '' || !preg_match('/^[0-9a-f]{32}$/i', $encryptionKey)) {
                throw new RuntimeException('Encryptiesleutel ontbreekt. De downloadlink kan niet worden verzonden.');
            }

            $mailService = new MailService();
            $mailService->sendDownloadLink(
                $emailReceiver,
                $uploadId,
                $encryptionKey,
                $fileName
            );

            $clearKey = $pdo->prepare(
                'UPDATE uploads SET encryption_key = NULL WHERE id = :id'
            );
            $clearKey->execute([':id' => $uploadId]);

            Logger::log('verify_geslaagd', ['id' => $uploadId]);

            renderStatusPage(
                'Verificatie geslaagd',
                '<h1 class="status-title">Verificatie geslaagd!</h1>'
                . '<p class="status-message is-success">De downloadlink is succesvol verzonden naar de ontvanger.</p>'
                . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']])
            );
        } catch (Throwable $e) {
            renderStatusPage(
                'Verzenden mislukt',
                '<h1 class="status-title">Verificatie geslaagd, maar e-mail mislukt</h1>'
                . '<p class="status-message is-error">De verificatie is opgeslagen, maar het verzenden van de downloadlink is mislukt: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                . '</p>'
                . '<p class="status-text">Controleer uw Mailtrap SMTP-instellingen in <code>private/credentials.php</code>.</p>'
                . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
                500
            );
        }
    }
}

$formAction = 'verify.php?id=' . urlencode($uploadId);

$formContent = '<h1 class="status-title">Verificatie</h1>';
$formContent .= '<p class="status-text">Voer de 6-cijferige code in die je per e-mail hebt ontvangen.</p>';

if ($errorMessage !== '') {
    $formContent .= '<p class="status-message is-error">' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</p>';
}

if ($infoMessage !== '') {
    $formContent .= '<p class="status-message is-success">' . htmlspecialchars($infoMessage, ENT_QUOTES, 'UTF-8') . '</p>';
}

$formContent .= '<form class="verify-form" action="' . htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') . '" method="post">';
$formContent .= csrfHiddenField();
$formContent .= '<label class="sr-only" for="verification_code">Verificatiecode</label>';
$formContent .= '<input class="code-input" type="text" name="verification_code" id="verification_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>';
$formContent .= '<button type="submit" class="submit-btn">Verifiëren</button>';
$formContent .= '</form>';

$formContent .= '<form class="resend-form" action="' . htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') . '" method="post">';
$formContent .= csrfHiddenField();
$formContent .= '<input type="hidden" name="action" value="resend_code">';
$formContent .= '<button type="submit" class="file-drop-link">Code opnieuw versturen</button>';
$formContent .= '</form>';

$cancelUrl = 'cancel.php?id=' . urlencode($uploadId) . '&token=' . urlencode($upload['cancel_token']);
$formContent .= '<p class="status-text">Van gedachten veranderd? '
    . '<a href="' . htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8') . '">Annuleer deze upload</a>.</p>';

$formContent .= renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]);

renderStatusPage('Verificatie', $formContent);
