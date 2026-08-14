<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/render_helpers.php';
require_once __DIR__ . '/../private/validation_helpers.php';
require_once __DIR__ . '/../private/Csrf.php';
require_once __DIR__ . '/../private/Logger.php';

/**
 * De enige pagina in MeoDrive die PHP-sessies gebruikt. De rest van het
 * platform is bewust stateless (zie CSRF via double-submit-cookie), maar
 * voor een password-beveiligd beheerdersoverzicht is een sessie de
 * simpelste, meest voor de hand liggende aanpak — er is hier maar één
 * gedeeld wachtwoord, geen volwaardig gebruikersbeheer nodig.
 */
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

const PRIVATE_STORAGE_DIR = __DIR__ . '/../private/';
const ACTIVITY_LOG_PATH = __DIR__ . '/../private/logs/activity.log';
const LOG_LINES_TO_SHOW = 50;

global $dbConfig;

/**
 * Totale opslag (in bytes) van alle versleutelde uploadbestanden direct in
 * private/ (dus zonder submappen als logs/ of mails/ mee te rekenen).
 */
function calculateStorageUsageBytes(string $dir): int
{
    $total = 0;

    foreach (glob($dir . '/*') ?: [] as $entry) {
        if (is_file($entry)) {
            $total += (int) filesize($entry);
        }
    }

    return $total;
}

/**
 * Laatste N regels uit het activiteitenlog, nieuwste eerst.
 *
 * @return array<int, string>
 */
function readLastLogLines(string $path, int $maxLines): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    return array_reverse(array_slice($lines, -$maxLines));
}

$isPlaceholderHash = !isset($dbConfig['admin_password_hash'])
    || str_starts_with((string) $dbConfig['admin_password_hash'], 'JOUW_');

if ($isPlaceholderHash) {
    renderStatusPage(
        'Niet geconfigureerd',
        '<h1 class="status-title">Admin-overzicht niet geconfigureerd</h1>'
        . '<p class="status-message is-error">Er is nog geen <code>admin_password_hash</code> ingesteld in '
        . '<code>private/credentials.php</code>.</p>'
        . '<p class="status-text">Genereer een hash met:<br><code>php -r "echo password_hash(\'jouw-wachtwoord\', PASSWORD_DEFAULT);"</code></p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        500
    );
}

$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'login') {
    if (!csrfValidateRequest()) {
        $loginError = 'Ongeldige of verlopen beveiligingstoken. Probeer het opnieuw.';
    } else {
        $password = (string) ($_POST['password'] ?? '');

        if (password_verify($password, (string) $dbConfig['admin_password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            Logger::log('admin_login', ['ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'onbekend')]);
        } else {
            $loginError = 'Onjuist wachtwoord.';
            Logger::log('admin_login_mislukt', ['ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'onbekend')]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'logout' && csrfValidateRequest()) {
    unset($_SESSION['admin_logged_in']);
    session_regenerate_id(true);
}

$isLoggedIn = ($_SESSION['admin_logged_in'] ?? false) === true;

if (!$isLoggedIn) {
    $content = '<h1 class="status-title">Admin-overzicht</h1>';
    $content .= '<p class="status-text">Log in om het beheerdersoverzicht te bekijken.</p>';

    if ($loginError !== '') {
        $content .= '<p class="status-message is-error">' . htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $content .= '<form class="verify-form" method="post" action="admin.php">';
    $content .= csrfHiddenField();
    $content .= '<input type="hidden" name="form" value="login">';
    $content .= '<label class="sr-only" for="admin-password">Wachtwoord</label>';
    $content .= '<input class="code-input" type="password" name="password" id="admin-password" autocomplete="current-password" required>';
    $content .= '<button type="submit" class="submit-btn">Inloggen</button>';
    $content .= '</form>';
    $content .= renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]);

    renderStatusPage('Admin-login', $content);
}

try {
    $pdo = getDbConnection();

    $activeCount = (int) $pdo->query('SELECT COUNT(*) FROM uploads WHERE expires_at >= NOW()')->fetchColumn();
    $expiredCount = (int) $pdo->query('SELECT COUNT(*) FROM uploads WHERE expires_at < NOW()')->fetchColumn();
    $verifiedCount = (int) $pdo->query('SELECT COUNT(*) FROM uploads WHERE is_verified = 1')->fetchColumn();
} catch (Throwable $e) {
    renderStatusPage(
        'Serverfout',
        '<h1 class="status-title">Er ging iets mis</h1>'
        . '<p class="status-message is-error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
        . renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]),
        500
    );
}

$storageBytes = calculateStorageUsageBytes(rtrim(PRIVATE_STORAGE_DIR, '/'));
$logLines = readLastLogLines(ACTIVITY_LOG_PATH, LOG_LINES_TO_SHOW);

$content = '<h1 class="status-title">Admin-overzicht</h1>';

$content .= '<dl class="info-card">';
$content .= '<dt>Actieve uploads</dt><dd>' . $activeCount . '</dd>';
$content .= '<dt>Verlopen uploads (wachten op cronjob)</dt><dd>' . $expiredCount . '</dd>';
$content .= '<dt>Geverifieerde uploads</dt><dd>' . $verifiedCount . '</dd>';
$content .= '<dt>Totale opslag in private/</dt><dd>' . htmlspecialchars(formatFileSize($storageBytes), ENT_QUOTES, 'UTF-8') . '</dd>';
$content .= '</dl>';

$content .= '<h2 class="status-title admin-subtitle">Laatste activiteit</h2>';

if ($logLines === []) {
    $content .= '<p class="status-text">Nog geen activiteit gelogd.</p>';
} else {
    $content .= '<pre class="admin-log">';
    foreach ($logLines as $line) {
        $content .= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "\n";
    }
    $content .= '</pre>';
}

$content .= '<form method="post" action="admin.php" class="admin-logout-form">';
$content .= csrfHiddenField();
$content .= '<input type="hidden" name="form" value="logout">';
$content .= '<button type="submit" class="file-drop-link">Uitloggen</button>';
$content .= '</form>';

$content .= renderStatusLinks([['href' => 'index.php', 'label' => 'Terug naar home']]);

renderStatusPage('Admin-overzicht', $content);
