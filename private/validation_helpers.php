<?php

declare(strict_types=1);

/**
 * Gedeelde, pure validatiefuncties zonder database- of netwerktoegang.
 * Voorheen stonden isValidUuid()/isExpired() los gedupliceerd in
 * download.php, verify.php en receive.php — nu op één plek, zodat ze ook
 * los (buiten een HTTP-request) getest kunnen worden, zie tests/Unit/.
 */

/**
 * Controleer of een string een geldig UUID v4-formaat heeft.
 */
function isValidUuid(string $id): bool
{
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $id
    );
}

/**
 * Controleer of een DATETIME-string (bijv. expires_at, locked_until) in het
 * verleden ligt ten opzichte van nu.
 */
function isExpired(string $dateTime): bool
{
    $expiry = new DateTimeImmutable($dateTime);
    $now = new DateTimeImmutable('now');

    return $now >= $expiry;
}

/**
 * Maak een aantal bytes leesbaar voor de gebruiker (bijv. "2,3 MB").
 */
function formatFileSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1_048_576) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }

    return number_format($bytes / 1_048_576, 1, ',', '.') . ' MB';
}

/**
 * Extensies die nooit toegestaan zijn bij upload, omdat ze door een
 * besturingssysteem, browser of webserver rechtstreeks uitgevoerd kunnen
 * worden. Bewust een blacklist in plaats van een whitelist: MeoDrive is een
 * algemeen bestandsdelingsplatform en moet gewone documenten, afbeeldingen,
 * archieven, etc. blijven toestaan zonder een onderhoudslast van "alle
 * toegestane extensies ooit" te worden.
 *
 * @var array<int, string>
 */
const BLOCKED_FILE_EXTENSIONS = [
    // Server-side scripts (zouden op een verkeerd geconfigureerde host
    // per ongeluk uitgevoerd kunnen worden i.p.v. gedownload)
    'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht',
    // Windows-uitvoerbare bestanden en installers
    'exe', 'com', 'bat', 'cmd', 'msi', 'msp', 'scr', 'pif', 'gadget',
    // Scripts die bij een dubbelklik direct worden uitgevoerd
    'js', 'jse', 'jar', 'vb', 'vbs', 'vbe', 'ws', 'wsf', 'wsh',
    'ps1', 'ps1xml', 'psc1', 'psm1', 'sh', 'bash', 'cgi',
    // Serverconfiguratie die (bij een verkeerde .htaccess-instelling)
    // gedrag van de webserver zelf zou kunnen beïnvloeden
    'htaccess', 'htpasswd',
];

/**
 * Controleer of een bestandsnaam een verboden (potentieel gevaarlijke)
 * extensie heeft. Vergelijking is case-insensitive, want "FOTO.EXE" is
 * net zo gevaarlijk als "foto.exe".
 */
function isDangerousFileExtension(string $fileName): bool
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    return $extension !== '' && in_array($extension, BLOCKED_FILE_EXTENSIONS, true);
}
