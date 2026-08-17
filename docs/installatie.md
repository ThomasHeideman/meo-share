# MeoDrive — Installatiehandleiding

Deze handleiding bestaat uit twee delen:

- **[Deel A](#deel-a--lokale-ontwikkelomgeving-xampp)** — MeoDrive lokaal draaien op XAMPP, om te
  ontwikkelen en te testen. Mail gaat hier naar een Mailtrap-sandbox en bereikt dus nooit echte
  ontvangers.
- **[Deel B](#deel-b--live-zetten-op-een-server)** — MeoDrive live zetten op een echte server, met
  HTTPS, een echte mailserver, productie-PHP-instellingen en een draaiende cronjob.

Werk je toe naar een live-omgeving? Doe dan eerst deel A, zodat je de flow één keer volledig hebt
zien werken, en loop daarna deel B door. Deel B verwijst terug naar deel A waar de stappen
identiek zijn.

Voor de architectuur en de gemaakte keuzes zie [`../README.md`](../README.md), voor dagelijks
beheer [`beheerdershandleiding.md`](beheerdershandleiding.md), en voor de beveiligingsmaatregelen
[`beveiliging.md`](beveiliging.md).

> **Let op:** deze export bevat géén `private/credentials.php`. Dat bestand houdt de database- en
> mailgegevens vast en is bewust weggelaten, zodat er geen wachtwoorden of SMTP-accounts van
> iemand anders worden meegestuurd. Je maakt het zelf aan (stap 5 voor lokaal, stap 16 voor live).
> Ook `vendor/` en `tools/phpunit.phar` zitten er niet in; die haal je met Composer respectievelijk
> een losse download op.

---
---

# Deel A — Lokale ontwikkelomgeving (XAMPP)

## 1. Benodigdheden

| Nodig | Versie / toelichting |
| --- | --- |
| PHP | 8.1 of hoger, met de extensies `pdo_mysql`, `openssl` en `zip` (alle drie standaard aan in XAMPP) |
| MySQL of MariaDB | Meegeleverd met XAMPP |
| Apache | Meegeleverd met XAMPP; `mod_headers` en `mod_rewrite` bij voorkeur actief |
| Composer | Voor het installeren van PHPMailer — [getcomposer.org](https://getcomposer.org) |
| Een SMTP-account | Bijvoorbeeld een eigen gratis [Mailtrap](https://mailtrap.io)-sandbox voor testen |

Controleer je PHP-versie en extensies met:

```bash
php -v
php -m
```

---

## 2. Bestanden plaatsen

Pak het zip-archief uit in de map waar je het project wilt hebben. Bij een standaard
XAMPP-installatie op Windows is dat bijvoorbeeld:

```
C:\xampp\htdocs\meo-share\
```

Na het uitpakken zie je twee belangrijke mappen:

```
meo-share/
├── private/      ← mag NOOIT via de browser bereikbaar zijn
└── public_html/  ← dit wordt de webroot
```

---

## 3. Webserver instellen (documentroot naar `public_html/`)

De hele beveiliging leunt erop dat `private/` buiten de webroot valt. Laat de documentroot
daarom naar `public_html/` wijzen — niet naar de projectmap.

Open `C:\xampp\apache\conf\extra\httpd-vhosts.conf` en voeg toe:

```apache
<VirtualHost *:80>
    ServerName meodrive.local
    DocumentRoot "C:/xampp/htdocs/meo-share/public_html"

    <Directory "C:/xampp/htdocs/meo-share/public_html">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Voeg daarna in je hosts-bestand (`C:\Windows\System32\drivers\etc\hosts`) deze regel toe:

```
127.0.0.1 meodrive.local
```

Herstart Apache via het XAMPP Control Panel.

**Alternatief zonder virtual host:** je kunt de map ook direct benaderen via
`http://localhost/meo-share/public_html/`. Dat werkt voor een snelle test, maar dan staat
`private/` technisch binnen de webroot. Het meegeleverde `private/.htaccess` blokkeert
directe aanvragen daarnaartoe, maar een eigen virtual host blijft veiliger.

---

## 4. Database aanmaken

Het volledige schema staat in [`../schema.sql`](../schema.sql). Dat bestand maakt zelf de
database `meo_share` aan, plus de twee tabellen `uploads` en `rate_limits`.

Via de command line:

```bash
mysql -u root -p < schema.sql
```

Of via phpMyAdmin (`http://localhost/phpmyadmin`): tabblad **Importeren** → kies
`schema.sql` → **Uitvoeren**.

---

## 5. `private/credentials.php` aanmaken

Kopieer het meegeleverde sjabloon en vul je eigen gegevens in:

```bash
# Windows (PowerShell)
Copy-Item private\credentials.example.php private\credentials.php

# macOS / Linux
cp private/credentials.example.php private/credentials.php
```

Open daarna `private/credentials.php` en vervang elke placeholder. Een voorbeeld van een
werkende lokale configuratie:

```php
return [
    // Database — bij een standaard XAMPP-installatie is de gebruiker 'root' zonder wachtwoord
    'host'     => 'localhost',
    'dbname'   => 'meo_share',
    'user'     => 'root',
    'password' => '',

    // Basis-URL van public_html, zonder afsluitende slash.
    // Deze URL wordt in de e-mails gebruikt om de verificatie- en downloadlinks te bouwen.
    'app_url'        => 'http://meodrive.local',
    'mail_from'      => 'noreply@meodrive.local',
    'mail_from_name' => 'MeoDrive',

    // Hash van het wachtwoord voor het admin-overzicht (zie stap 6).
    'admin_password_hash' => '$2y$10$...',

    // SMTP — vul hier je EIGEN gegevens in (bijv. je eigen Mailtrap-sandbox)
    'smtp' => [
        'host'       => 'sandbox.smtp.mailtrap.io',
        'port'       => '2525',
        'username'   => 'JOUW_EIGEN_MAILTRAP_USERNAME',
        'password'   => 'JOUW_EIGEN_MAILTRAP_PASSWORD',
        'encryption' => 'tls',
    ],
];
```

Belangrijke aandachtspunten:

- `app_url` moet exact overeenkomen met de URL waarop je de site opent. Staat hier een
  verkeerde waarde, dan wijzen de links in de e-mails naar een adres dat niet bestaat.
- MeoDrive weigert te mailen zolang de SMTP-velden nog een placeholder bevatten; je krijgt
  dan een duidelijke Nederlandse foutmelding in plaats van een stille mislukking.
- Commit dit bestand nooit: het staat om die reden in `.gitignore`.

### Eigen SMTP-gegevens ophalen bij Mailtrap

1. Maak een gratis account op [mailtrap.io](https://mailtrap.io).
2. Ga naar **Email Testing → Inboxes** en open je inbox.
3. Kies bij **Integrations** de optie **SMTP** en neem `host`, `port`, `username` en
   `password` over in `credentials.php`.

Mailtrap Sandbox vangt alle uitgaande mail af in die inbox, zodat er tijdens het testen
nooit mail naar echte ontvangers gaat. Voor een live-omgeving is dit juist níet wat je wilt —
zie stap 17.

---

## 6. Admin-wachtwoord instellen

Het admin-overzicht (`public_html/admin.php`) werkt met één wachtwoord-hash in
`credentials.php`. Genereer die hash:

```bash
php -r "echo password_hash('jouw-wachtwoord', PASSWORD_DEFAULT);"
```

Plak de volledige uitvoer (begint met `$2y$`) in het veld `admin_password_hash`.

---

## 7. Dependencies installeren

PHPMailer wordt via Composer geïnstalleerd. Voer dit uit in de projectmap (waar
`composer.json` staat):

```bash
composer install
```

Dit maakt de map `vendor/` aan. Zonder deze stap weigert MeoDrive te mailen met de melding
"PHPMailer niet gevonden".

Heb je geen Composer? Dan kun je de `src`-bestanden van PHPMailer ook handmatig in
`private/lib/PHPMailer/src/` plaatsen; `private/mailer_autoload.php` vindt ze daar
automatisch.

---

## 8. Werking controleren

Open de site (bijvoorbeeld `http://meodrive.local`) en loop de volledige flow één keer door:

1. Kies een rol op de homepage en upload een testbestand met je eigen e-mailadres als
   uploader en ontvanger.
2. Controleer je Mailtrap-inbox: daar staat een mail met een 6-cijferige verificatiecode.
3. Vul die code in op de verificatiepagina.
4. In Mailtrap verschijnt nu een tweede mail met de downloadlink.
5. Open die link en start de download. Het bestand moet daarna verdwenen zijn uit
   `private/` en de bijbehorende databaserij uit de tabel `uploads` — dat is de
   download-and-destroy-werking.
6. Open `admin.php` en log in met het wachtwoord uit stap 6.

Test tot slot de opschoonjob:

```bash
php private/cron.php
```

---

## 9. Opschoning automatiseren (lokaal, optioneel)

`private/cron.php` verwijdert verlopen uploads (bestand én databaserij). Lokaal is handmatig
aanroepen meestal genoeg; op een live server is automatiseren verplicht (stap 19).

Wil je het lokaal toch automatisch draaien, gebruik dan Windows Taakplanner met
`C:\xampp\php\php.exe` als programma en `C:\xampp\htdocs\meo-share\private\cron.php` als
argument.

---

## 10. Tests draaien (optioneel)

De testsuite staat in `tests/`. PHPUnit zelf zit niet in de export; download
`phpunit.phar` van [phpunit.de](https://phpunit.de) en plaats het in `tools/`:

```bash
php tools/phpunit.phar --testsuite unit          # snel, geen database of mailserver nodig
php tools/phpunit.phar --testsuite integration   # volledige flow, vereist database + SMTP
```

De integratietest maakt zijn eigen testdatabase (`meo_share_test`) aan en ruimt die daarna
weer op. Een overgeslagen (`SKIPPED`) mailstap betekent alleen dat er geen SMTP-server
bereikbaar was, niet dat de kernlogica stuk is.

---
---

# Deel B — Live zetten op een server

Deel A levert een werkende ontwikkelomgeving op. Die configuratie is bewust **niet** geschikt
om zo live te zetten: mail komt in een sandbox terecht, er is geen HTTPS, de database draait op
`root` zonder wachtwoord en foutmeldingen zijn zichtbaar voor bezoekers.

## 11. Wat er live per definitie anders moet

| Onderdeel | Lokaal (deel A) | Live (deel B) | Waarom |
| --- | --- | --- | --- |
| Protocol | `http://` | `https://`, met redirect en HSTS | De encryptiesleutel staat als parameter in de downloadlink; over http gaat die leesbaar over de lijn. |
| Mail | Mailtrap-sandbox | Echte SMTP met SPF/DKIM | In de sandbox ontvangt de gebruiker níets, dus de flow loopt vast bij de verificatiecode. |
| Databasegebruiker | `root`, geen wachtwoord | Eigen gebruiker met minimale rechten | Beperkt de schade als PHP-code ooit misbruikt wordt. |
| Foutmeldingen | zichtbaar in de browser | naar een logbestand, niet naar de bezoeker | Stacktraces verraden paden, versies en queries. |
| Cronjob | handmatig | automatisch, elk uur | Zonder cron blijven verlopen bestanden voor onbepaalde tijd staan. |
| Alert-e-mail cronjob | standaardadres in de code | eigen beheeradres | `CRON_ALERT_EMAIL` in `private/cron.php` staat hardgecodeerd op `thomas@wijzijnmeo.nl`. |
| Rechten op `private/` | ruim (dev-machine) | strikt (`750`/`640`, eigenaar = webserver) | `private/` bevat de versleutelde bestanden, de credentials en het auditlog. |

---

## 12. Serververeisten

| Nodig | Toelichting |
| --- | --- |
| PHP 8.1+ (8.2 of 8.3 aanbevolen) | Met `pdo_mysql`, `openssl` en `zip`. Controleer met `php -m`. |
| MySQL 5.7+ / MariaDB 10.3+ | Voor de tabellen `uploads` en `rate_limits`. |
| Apache 2.4 met `mod_headers` **of** nginx | Bij nginx moet je de `.htaccess`-regels handmatig overzetten, zie stap 14. |
| Vrij instelbare documentroot | De documentroot moet naar `public_html/` kunnen wijzen. |
| Een geldig TLS-certificaat | Bijvoorbeeld gratis via Let's Encrypt. |
| SMTP-gegevens van een echte mailserver | Van je hostingpartij of een dienst als Postmark, Brevo of Microsoft 365. |
| SSH-toegang (sterk aanbevolen) | Voor `composer install`, de cronjob en het zetten van rechten. |

Werk je op shared hosting zonder SSH, dan is MeoDrive nog steeds te installeren, maar moet je
`vendor/` lokaal opbouwen en meesturen (stap 13) en de cronjob via het controlepaneel
instellen (stap 19).

---

## 13. Bestanden naar de server zetten

Zet **de hele projectmap** op de server, niet alleen `public_html/`. `private/` hoort erbij, maar
mag niet publiek bereikbaar zijn (stap 14).

Met Git (aanbevolen als je SSH hebt):

```bash
cd /var/www
git clone https://github.com/ThomasHeideman/meo-share.git meodrive
cd meodrive
composer install --no-dev --optimize-autoloader
```

`--no-dev` slaat de ontwikkelafhankelijkheden over en `--optimize-autoloader` maakt een snellere
klassenmap — beide zijn wat je op een productieserver wil.

Met SFTP (bijvoorbeeld via FileZilla), als je geen SSH hebt:

1. Draai lokaal `composer install --no-dev --optimize-autoloader`.
2. Upload alles **behalve** deze bestanden en mappen:

| Niet uploaden | Reden |
| --- | --- |
| `private/credentials.php` | Live krijgt eigen gegevens; nooit de dev-versie kopiëren. |
| `private/logs/` en `private/mails/` | Lokale logs en testmails met echte e-mailadressen. |
| `.git/`, `tests/`, `tools/`, `phpunit.xml` | Niet nodig om te draaien; kleiner aanvalsoppervlak. |
| Losstaande uploadbestanden in `private/` | Restanten van lokale tests. |

---

## 14. Documentroot en afscherming van `private/`

Dit is de belangrijkste stap van deel B: als `private/` publiek bereikbaar is, kan iemand de
versleutelde bestanden, `credentials.php` en het auditlog opvragen.

### Apache met eigen virtual host

```apache
<VirtualHost *:443>
    ServerName meodrive.example.nl
    DocumentRoot /var/www/meodrive/public_html

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/meodrive.example.nl/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/meodrive.example.nl/privkey.pem

    <Directory /var/www/meodrive/public_html>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    # private/ ligt buiten de documentroot, maar voor de zekerheid ook hier dicht
    <Directory /var/www/meodrive/private>
        Require all denied
    </Directory>

    ErrorLog  /var/log/apache2/meodrive-error.log
    CustomLog /var/log/apache2/meodrive-access.log combined
</VirtualHost>

# Alle http-verkeer doorsturen naar https
<VirtualHost *:80>
    ServerName meodrive.example.nl
    Redirect permanent / https://meodrive.example.nl/
</VirtualHost>
```

`AllowOverride All` is nodig, anders negeert Apache het meegeleverde
`public_html/.htaccess` met de security headers.

### Shared hosting met een vaste `public_html`

Veel hostingpakketten hebben een vaste documentroot (`public_html`, `httpdocs` of `www`) die je
niet kunt verplaatsen. Zet de mappen dan zo neer dat `private/` één niveau **boven** de
documentroot staat:

```
/home/gebruiker/
├── private/          ← buiten de documentroot
└── public_html/      ← de vaste documentroot
```

De code verwijst intern relatief (`__DIR__ . '/../private/'`), dus deze indeling werkt zonder
aanpassingen — zolang `private/` en `public_html/` broer en zus blijven.

### nginx

nginx leest **geen** `.htaccess`-bestanden. Zowel de afscherming van `private/` als de security
headers moet je daar in de serverconfiguratie zetten:

```nginx
server {
    listen 443 ssl http2;
    server_name meodrive.example.nl;
    root /var/www/meodrive/public_html;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/meodrive.example.nl/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/meodrive.example.nl/privkey.pem;

    client_max_body_size 64M;  # moet minstens gelijk zijn aan post_max_size in php.ini

    # Dezelfde headers als public_html/.htaccess, dat nginx niet leest
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
    add_header Content-Security-Policy "default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param HTTPS on;  # zodat PHP de Secure-vlag op cookies zet
    }

    location / {
        try_files $uri $uri/ =404;
    }
}

server {
    listen 80;
    server_name meodrive.example.nl;
    return 301 https://$host$request_uri;
}
```

---

## 15. HTTPS verplicht stellen

MeoDrive zet de AES-sleutel bewust niet in de database, maar in de downloadlink. Dat maakt HTTPS
geen luxe: zonder TLS staat die sleutel leesbaar in het netwerkverkeer en is de hele
zero-knowledge-opzet zinloos.

Certificaat aanvragen met Let's Encrypt op Ubuntu met Apache:

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d meodrive.example.nl
```

Controleer daarna twee dingen:

1. `http://` stuurt door naar `https://` (stap 14).
2. De `Strict-Transport-Security`-header staat aan. Voor Apache voeg je die toe aan
   `public_html/.htaccess` of aan de virtual host:

   ```apache
   Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
   ```

**Let op bij een reverse proxy of load balancer.** MeoDrive zet de `Secure`-vlag op de
sessie- en CSRF-cookies op basis van `$_SERVER['HTTPS']`. Termineert een proxy of CDN de TLS en
gaat het verkeer daarachter over http verder, dan is die variabele leeg en verdwijnt de
`Secure`-vlag. Zet hem dan expliciet, op basis van de header van de proxy:

```apache
# Apache, in de virtual host
SetEnvIf X-Forwarded-Proto "https" HTTPS=on
```

Bij nginx doet de regel `fastcgi_param HTTPS on;` uit stap 14 hetzelfde.

---

## 16. Database en `credentials.php` voor productie

Maak een eigen databasegebruiker aan in plaats van `root` te gebruiken. MeoDrive heeft alleen
lees- en schrijfrechten op de eigen tabellen nodig:

```sql
CREATE DATABASE meo_share CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'meodrive'@'localhost' IDENTIFIED BY 'een-lang-willekeurig-wachtwoord';
GRANT SELECT, INSERT, UPDATE, DELETE ON meo_share.* TO 'meodrive'@'localhost';
FLUSH PRIVILEGES;
```

Importeer daarna het schema (`schema.sql` bevat zelf al een `CREATE DATABASE IF NOT EXISTS`):

```bash
mysql -u root -p meo_share < schema.sql
```

Maak vervolgens `private/credentials.php` aan (zie stap 5 voor de werkwijze) met
productiewaarden:

```php
return [
    'host'     => 'localhost',
    'dbname'   => 'meo_share',
    'user'     => 'meodrive',
    'password' => 'een-lang-willekeurig-wachtwoord',

    // https, exact het domein waarop de site draait, zonder afsluitende slash
    'app_url'        => 'https://meodrive.example.nl',
    'mail_from'      => 'noreply@example.nl',
    'mail_from_name' => 'MeoDrive',

    // Genereer een NIEUWE hash voor live; hergebruik niet die van je dev-omgeving
    'admin_password_hash' => '$2y$10$...',

    'smtp' => [
        'host'       => 'smtp.example.nl',
        'port'       => '587',
        'username'   => 'noreply@example.nl',
        'password'   => 'het-smtp-wachtwoord',
        'encryption' => 'tls',
    ],
];
```

Staat `app_url` verkeerd, dan komen er wél mails aan, maar wijzen de verificatie- en
downloadlinks naar een domein dat niet bestaat.

---

## 17. Echte mailserver in plaats van Mailtrap

Mailtrap Sandbox houdt álle mail vast; live betekent dat de gebruiker zijn verificatiecode nooit
ontvangt en de flow stilvalt. Vul daarom de SMTP-gegevens van een echte mailserver in.

De mails zijn transactioneel en bevatten links, dus ze belanden snel in de spammap als het
afzenderdomein niet klopt. Regel daarom:

- **Een `mail_from` op je eigen domein** — geen gmail.com-adres via een vreemde SMTP-server.
- **SPF** — een DNS-TXT-record dat de verzendende server toestaat, bijvoorbeeld
  `v=spf1 include:_spf.example.nl ~all`.
- **DKIM** — ondertekening aanzetten bij je mailprovider en het opgegeven DNS-record plaatsen.
- **DMARC** — begin met `v=DMARC1; p=none; rua=mailto:postmaster@example.nl` en verscherp later.
- **Poort 587 met `tls`** (STARTTLS) of poort 465 met `ssl`. MeoDrive controleert het certificaat
  van de mailserver, dus een zelfgebakken certificaat zonder geldige keten wordt geweigerd.

Test na het instellen met een echte upload naar een adres bij een externe provider (bijvoorbeeld
Gmail) en controleer of de mail in de inbox belandt en niet in spam.

---

## 18. PHP-instellingen voor productie

Pas dit aan in de `php.ini` van de server (of via het hostingpaneel) en herstart daarna PHP-FPM
of Apache:

```ini
; Foutmeldingen loggen, niet tonen: een stacktrace verraadt paden en queries
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php/meodrive-error.log
expose_php = Off

; Uploadlimieten — bepalen samen de maximale grootte van 3 bestanden in één keer
upload_max_filesize = 64M
post_max_size = 65M
max_file_uploads = 20
memory_limit = 256M
max_execution_time = 300

; Tijdzone expliciet zetten, zie stap 19
date.timezone = Europe/Amsterdam
```

`post_max_size` moet iets groter zijn dan `upload_max_filesize`, omdat de POST ook de
formuliervelden bevat. Bij nginx moet `client_max_body_size` minstens even groot zijn, anders
kapt nginx de upload af vóórdat PHP hem ziet. MeoDrive vangt een te grote POST netjes af met een
Nederlandse foutmelding, maar de limiet zelf moet je hier zetten.

---

## 19. Tijdzone en de cronjob

### Tijdzone-consistentie

MeoDrive berekent `expires_at` in PHP (`+24 hours`), terwijl de cronjob en het admin-overzicht
vergelijken met het MySQL-`NOW()`. Staan PHP en MySQL in een verschillende tijdzone, dan lijken
links te vroeg te verlopen of blijven ze te lang geldig. Controleer daarom:

```bash
php -r "echo date_default_timezone_get(), PHP_EOL;"
mysql -u root -p -e "SELECT NOW(), @@global.time_zone, @@session.time_zone;"
```

Wijken ze af, zet dan `date.timezone` in `php.ini` (stap 18) en de MySQL-tijdzone in
`my.cnf` (`default-time-zone = 'Europe/Amsterdam'`) op dezelfde waarde.

### `CRON_ALERT_EMAIL` aanpassen

`private/cron.php` mailt een waarschuwing als het opruimen mislukt, naar een hardgecodeerd
adres:

```php
const CRON_ALERT_EMAIL = 'thomas@wijzijnmeo.nl';
```

Zet dit vóór de livegang op het adres van de beheerder die de meldingen moet ontvangen.

### De cronjob inplannen

Zonder deze stap blijven verlopen uploads voor onbepaalde tijd op de server staan — precies wat
het ontwerp juist wil voorkomen. Elk uur is een prima interval:

```bash
crontab -e
```

```
0 * * * * /usr/bin/php /var/www/meodrive/private/cron.php >> /var/log/php/meodrive-cron.log 2>&1
```

Op shared hosting doe je dit via het controlepaneel (DirectAdmin, cPanel, Plesk): kies het
PHP-binary en geef als argument het volledige pad naar `private/cron.php`.

Controleer na een uur of er regels in het logbestand verschijnen, en of het auditlog
`private/logs/activity.log` een `cron_opgeruimd`-regel bevat.

---

## 20. Rechten en eigendom

`private/` moet schrijfbaar zijn voor de gebruiker waaronder PHP draait (vaak `www-data`), maar
voor niemand anders leesbaar. Op Ubuntu met Apache of PHP-FPM:

```bash
cd /var/www/meodrive
sudo chown -R www-data:www-data .
sudo find . -type d -exec chmod 750 {} \;
sudo find . -type f -exec chmod 640 {} \;
sudo chmod 750 private private/logs
```

`private/logs/` hoeft niet vooraf te bestaan: MeoDrive maakt de map zelf aan bij de eerste
logregel, mits de bovenliggende map schrijfbaar is.

Gebruik nooit `chmod 777`. Kan PHP niet schrijven in `private/`, dan mislukken uploads en blijft
het bestand na een download staan; los dat op met de juiste eigenaar, niet met ruimere rechten.

---

## 21. Controlelijst na de livegang

Loop dit lijstje één keer helemaal af voordat je het adres deelt met gebruikers:

| Controle | Verwacht resultaat |
| --- | --- |
| `https://meodrive.example.nl` openen | Homepage met rolkeuze, groen slotje in de adresbalk |
| `http://meodrive.example.nl` openen | Automatische doorverwijzing naar https |
| `https://meodrive.example.nl/../private/credentials.php` en `/private/` proberen | 403 of 404, nooit de inhoud van het bestand |
| Volledige flow met twee echte adressen | Verificatiemail komt aan, code werkt, downloadmail komt aan, bestand downloadt |
| Tweede keer dezelfde downloadlink openen | Foutmelding dat het bestand niet meer bestaat (download-and-destroy) |
| `private/` na die download bekijken | Het versleutelde bestand is verdwenen |
| `admin.php` openen | Loginscherm; na inloggen de tellers en de laatste logregels |
| Verkeerd admin-wachtwoord invoeren | Foutmelding, en een `admin_login_mislukt`-regel in `activity.log` |
| Bewust een fout uitlokken (bijv. `receive.php?id=onzin`) | Nette Nederlandse foutpagina, géén stacktrace of pad |
| Response-headers bekijken (browser-devtools) | `Content-Security-Policy`, `X-Frame-Options`, `Strict-Transport-Security` aanwezig |
| `php private/cron.php` handmatig draaien | Meldt hoeveel uploads zijn opgeruimd, zonder fouten |
| PHP-errorlog bekijken | Geen waarschuwingen of fatale fouten van de testronde |

---

## 22. Onderhoud

- **Back-ups.** Zet zowel de database als `private/` in de back-up. Bedenk daarbij dat
  versleutelde bestanden in een back-up niet te ontsleutelen zijn zonder de sleutel uit de
  downloadmail; dat is bewust zo. Zie [beheerdershandleiding.md](beheerdershandleiding.md) §9.
- **Logrotatie.** `private/logs/activity.log` groeit onbeperkt. Voeg een `logrotate`-regel toe
  (bijvoorbeeld maandelijks roteren, twaalf bestanden bewaren) zodat het bestand hanteerbaar
  blijft.
- **Updates.** Draai periodiek `composer outdated` en werk PHPMailer bij met
  `composer update phpmailer/phpmailer`. Houd ook de PHP-versie van de server actueel.
- **Nieuwe versie uitrollen.** Bij een update van de code: `git pull`, daarna
  `composer install --no-dev --optimize-autoloader`, en controleer of `schema.sql` onderaan
  nieuwe `ALTER TABLE`-regels bevat die je nog moet uitvoeren.

---

## 23. Aanbevolen extra's voor een publieke omgeving

Deze punten staan als bekende beperkingen in [beveiliging.md](beveiliging.md) en wegen zwaarder
zodra het platform buiten een besloten kring wordt gebruikt:

- **Virusscanner op uploads.** De extensie-blacklist voorkomt uitvoerbaarheid op de server, maar
  scant de inhoud niet. Overweeg ClamAV op de map `private/`.
- **`admin.php` extra afschermen.** Er is één gedeeld wachtwoord en geen 2FA. Zet er op
  serverniveau een IP-whitelist of HTTP Basic Auth voor:

  ```apache
  <Files "admin.php">
      Require ip 203.0.113.0/24
  </Files>
  ```

- **fail2ban of een WAF.** De ingebouwde rate limiting geldt per IP en per upload, maar houdt
  geen gedistribueerde pogingen tegen.
- **Bewaartermijn heroverwegen.** De 24 uur uit `upload.php` (`+24 hours`) is een bewuste keuze;
  pas die aan als het gebruik van de organisatie erom vraagt.

---

## 24. Veelvoorkomende problemen

| Melding of symptoom | Oorzaak en oplossing |
| --- | --- |
| "Databaseconfiguratie ontbreekt" | `private/credentials.php` bestaat nog niet — zie stap 5 of 16. |
| "SMTP-username bevat nog een placeholder" | De SMTP-velden staan nog op de sjabloonwaarden. |
| "PHPMailer niet gevonden" | `composer install` is nog niet gedraaid, of `vendor/` is niet meegekomen in de upload. |
| Mail komt niet aan | Controleer host, poort en `encryption` (`tls`/`ssl`) en of de firewall uitgaand verkeer op die poort toestaat. |
| Mail belandt in de spammap | SPF, DKIM en DMARC ontbreken, of `mail_from` staat niet op je eigen domein — zie stap 17. |
| Verificatie- of downloadlink werkt niet | `app_url` wijkt af van de URL waarop de site draait (let ook op `http` versus `https`). |
| "De geselecteerde bestanden zijn samen te groot" | Verhoog `upload_max_filesize` en `post_max_size`, en bij nginx ook `client_max_body_size`. |
| Bestanden verdwijnen niet na download | PHP heeft geen schrijfrechten op `private/` — zie stap 20. |
| Links verlopen te vroeg of te laat | Tijdzoneverschil tussen PHP en MySQL — zie stap 19. |
| Security headers ontbreken | Bij Apache: `mod_headers` uit of `AllowOverride None`. Bij nginx: headers staan niet in de serverconfiguratie (stap 14). |
| `private/` is via de browser op te vragen | Documentroot staat op de projectmap in plaats van op `public_html/` — zie stap 14. |
| Uitloggen bij het admin-overzicht werkt niet zoals verwacht | Cookies zonder `Secure`-vlag achter een reverse proxy — zie stap 15. |
| Blanco pagina in plaats van een foutmelding | Bekijk het PHP-errorlog uit stap 18; zet `display_errors` alleen tijdelijk aan, nooit structureel op een live server. |
