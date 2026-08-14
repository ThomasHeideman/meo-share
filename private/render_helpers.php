<?php

declare(strict_types=1);

/**
 * Render een volledige HTML-statuspagina in de MeoDrive huisstijl.
 * Gebruikt door upload.php, verify.php, receive.php en download.php
 * voor alle informatieve pagina's en foutmeldingen (niet voor de
 * eigenlijke bestandsstream in download.php).
 */
function renderStatusPage(
    string $title,
    string $bodyHtml,
    int $httpCode = 200,
    array $extraScripts = []
): never {
    http_response_code($httpCode);

    echo '<!DOCTYPE html>';
    echo '<html lang="nl">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' — MeoDrive</title>';
    echo '<link rel="icon" type="image/svg+xml" href="assets/favicons/favicon.svg">';
    echo '<link rel="icon" type="image/png" sizes="96x96" href="assets/favicons/favicon-96x96.png">';
    echo '<link rel="shortcut icon" href="assets/favicons/favicon.ico">';
    echo '<link rel="apple-touch-icon" sizes="180x180" href="assets/favicons/apple-touch-icon.png">';
    echo '<link rel="manifest" href="assets/favicons/site.webmanifest">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@100..900&family=Ubuntu:wght@300;400;500;700&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="css/index.css">';
    echo '</head>';
    echo '<body>';
    echo '<div class="page">';
    echo '<div class="status-layout">';
    echo '<img class="logo-img" src="assets/meo-drive-logo.png" alt="MeoDrive">';
    echo $bodyHtml;
    echo '</div>';
    echo '</div>';

    foreach ($extraScripts as $script) {
        echo '<script src="' . htmlspecialchars($script, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }

    echo '</body>';
    echo '</html>';
    exit;
}

/**
 * Bouw een consistente rij lage-nadruk links onder een statusmelding.
 *
 * @param array<int, array{href: string, label: string}> $links
 */
function renderStatusLinks(array $links): string
{
    $html = '<div class="status-links">';

    foreach ($links as $link) {
        $html .= '<a href="' . htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8')
            . '</a>';
    }

    $html .= '</div>';

    return $html;
}
