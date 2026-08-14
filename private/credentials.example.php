<?php

declare(strict_types=1);

/**
 * Kopieer dit bestand naar credentials.php en vul je eigen gegevens in.
 * credentials.php staat in .gitignore en mag nooit gecommit worden.
 */
return [
    // Database-connectie
    'host'     => 'JOUW_DB_HOST',
    'dbname'   => 'JOUW_DB_NAAM',
    'user'     => 'JOUW_DB_GEBRUIKERSNAAM',
    'password' => 'JOUW_DB_WACHTWOORD',

    // Basis-URL van public_html (zonder trailing slash)
    'app_url'        => 'JOUW_APP_URL',
    'mail_from'      => 'JOUW_AFZENDER_EMAIL',
    'mail_from_name' => 'JOUW_AFZENDER_NAAM',

    // Wachtwoord-hash voor public_html/admin.php. Genereer met:
    // php -r "echo password_hash('jouw-wachtwoord', PASSWORD_DEFAULT);"
    'admin_password_hash' => 'JOUW_ADMIN_WACHTWOORD_HASH',

    // SMTP-instellingen (bijv. Mailtrap voor testen, of een echte mailserver voor productie)
    'smtp' => [
        'host'        => 'JOUW_SMTP_HOST',
        'port'        => 'JOUW_SMTP_PORT',
        'username'    => 'JOUW_SMTP_USERNAME',
        'password'    => 'JOUW_SMTP_PASSWORD',
        'encryption'  => 'JOUW_SMTP_ENCRYPTION',
    ],
];
