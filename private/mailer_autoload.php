<?php

declare(strict_types=1);

/**
 * Laad PHPMailer via Composer (aanbevolen) of handmatige installatie.
 *
 * Composer:  composer install   (in de projectmap)
 * Handmatig: plaats de PHPMailer src-bestanden in private/lib/PHPMailer/src/
 */
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$manualBase     = __DIR__ . '/lib/PHPMailer/src';

if (is_readable($vendorAutoload)) {
    require_once $vendorAutoload;
    return;
}

$manualFiles = [
    $manualBase . '/Exception.php',
    $manualBase . '/PHPMailer.php',
    $manualBase . '/SMTP.php',
];

if (is_readable($manualFiles[1])) {
    foreach ($manualFiles as $file) {
        require_once $file;
    }
    return;
}

// Geen PHPMailer gevonden — MailService valt terug op de lokale file-mock.
