# MeoDrive — Beheerdershandleiding

Deze handleiding is bedoeld voor wie MeoDrive technisch beheert: installatie, dagelijks beheer,
de cronjob, logging en het admin-overzicht. Voor de architectuur zie [README.md](../README.md),
voor een overzicht van beveiligingsmaatregelen zie [beveiliging.md](beveiliging.md), en voor de
eindgebruikersflow zie [gebruikershandleiding.md](gebruikershandleiding.md).

---

## 1. Installatie (kort)

Zie README.md §5 voor de volledige stappen. Kort samengevat:

1. Documentroot van de webserver wijst naar `public_html/` (niet naar de projectroot).
2. Database aanmaken via `schema.sql` (zie §2 hieronder).
3. `private/credentials.example.php` kopiëren naar `private/credentials.php` en invullen.
4. `composer install` uitvoeren in de projectroot.

---

## 2. Database-schema beheren

Het volledige, actuele schema staat in [`schema.sql`](../schema.sql) in de projectroot — dit is
de bron van waarheid, niet de chatgeschiedenis of losse notities. Nieuwe installatie:

```bash
mysql -u root -p < schema.sql
```

Bestaande database die nog niet alle kolommen heeft (bijv. na een update van MeoDrive)? Voer de
`ALTER TABLE`-commando's onderaan `schema.sql` los uit — deze zijn geschreven om zonder
dataverlies op een bestaande `uploads`-tabel toegepast te kunnen worden.

De twee tabellen:

| Tabel | Doel |
| --- | --- |
| `uploads` | Eén rij per upload: bestandsgegevens, verificatiestatus, vervaltermijn, lockout-status, cancel-token. |
| `rate_limits` | Generieke "max N pogingen per tijdvenster"-tellers, herbruikt voor uploadlimieten én de resend-cooldown. |

---

## 3. `private/credentials.php` beheren

Dit bestand staat in `.gitignore` en mag **nooit** worden gecommit. Het bevat:

- Database-credentials (`host`, `dbname`, `user`, `password`).
- `app_url`, `mail_from`, `mail_from_name` — gebruikt in mails en gegenereerde links.
- `admin_password_hash` — het bcrypt-hash van het admin-wachtwoord voor `admin.php`.
- `smtp` — SMTP-instellingen (Mailtrap tijdens ontwikkeling, een echte mailserver in productie).

### Admin-wachtwoord instellen of wijzigen

Genereer een nieuwe hash en plak die in `admin_password_hash`:

```bash
php -r "echo password_hash('jouw-nieuwe-wachtwoord', PASSWORD_DEFAULT);"
```

Er is bewust geen "wachtwoord vergeten"-flow — bij verlies pas je `credentials.php` handmatig
aan met een nieuwe hash.

---

## 4. Admin-overzicht (`admin.php`)

Ga naar `admin.php` en log in met het wachtwoord dat bij `admin_password_hash` hoort. Dit is de
enige pagina in MeoDrive die een PHP-sessie gebruikt (voor de login-status); de rest van het
platform is bewust stateless.

Het overzicht toont:

- **Actieve uploads** — nog niet verlopen, wachten op verificatie of download.
- **Verlopen uploads** — `expires_at` ligt in het verleden, maar nog niet opgeruimd (wachten op
  de volgende cronjob-run).
- **Geverifieerde uploads** — uploads waarvan de uploader de code al heeft bevestigd.
- **Totale opslag in `private/`** — som van de bestandsgroottes van alle versleutelde bestanden
  direct in `private/` (logs en mailbestanden tellen hier niet in mee).
- **Laatste 50 regels uit `activity.log`** — zie §6.

Uitloggen kan met de knop onderaan de pagina; dit maakt de sessie ongeldig.

Is `admin_password_hash` nog niet ingesteld (of staat er nog de placeholder-waarde in), dan
toont `admin.php` een duidelijke configuratiemelding in plaats van een sessie te starten.

---

## 5. De opschoon-cronjob (`private/cron.php`)

### Wat het doet

Zoekt alle uploads waarvan `expires_at` in het verleden ligt, verwijdert per upload het
versleutelde bestand van de schijf (`unlink()`) en de bijbehorende rij uit de database
(`DELETE`). Geeft een leesbare samenvatting terug (aantal opgeruimd, aantal fouten).

### Handmatig testen

Via de CLI:

```bash
php private/cron.php
```

Of via de browser (alleen zinvol op een lokale/afgeschermde omgeving, `private/` is normaal
gesproken niet publiek bereikbaar):

```
http://localhost/meo-share/private/cron.php
```

### In productie: een echte cronjob/taakplanner

**Linux/cron** (elke 15 minuten):

```cron
*/15 * * * * php /pad/naar/meo-share/private/cron.php >> /pad/naar/meo-share/private/logs/cron-output.log 2>&1
```

**Windows Taakplanner**: maak een basistaak die elke 15 minuten
`php.exe C:\xampp\htdocs\meo-share\private\cron.php` uitvoert.

### Als de cronjob faalt

Bij een fatale fout, of als een of meer bestanden/rijen niet opgeruimd konden worden, verstuurt
het script automatisch een alertmail naar het vaste MEO-adres (`CRON_ALERT_EMAIL` in
`private/cron.php`) via `MailService::sendCronFailureAlert()`. Een mislukte alertmail zelf mag
de cronrun nooit laten crashen — die fout wordt alleen naar de output/console gelogd.
Elke run logt bovendien `cron_opgeruimd` of `cron_mislukt` in `activity.log` (zie §6).

---

## 6. Logging / audit-trail

Alle belangrijke events worden weggeschreven naar `private/logs/activity.log` (buiten de
webroot, net als de versleutelde bestanden). Dit bestand staat in `.gitignore` en groeit met de
tijd — plan voor productie eventueel een eenvoudige logrotatie (bijv. maandelijks hernoemen).

Elke regel heeft het formaat:

```
[2026-01-15 14:32:07] upload               id=... ip=... rol=client
```

### Gelogde events

| Event | Wanneer |
| --- | --- |
| `upload` | Een upload is succesvol opgeslagen. |
| `upload_csrf_geweigerd` | Een uploadpoging is geweigerd wegens een ongeldig CSRF-token. |
| `upload_rate_limited` | Een IP-adres heeft de uploadlimiet overschreden. |
| `verify_geslaagd` | Een verificatiecode is correct ingevoerd. |
| `verify_mislukt` | Een onjuiste verificatiecode is ingevoerd. |
| `verify_vergrendeld` | Een verificatiepoging is geweigerd wegens een actieve lockout. |
| `verify_code_opnieuw_verzonden` | De "code opnieuw versturen"-knop is gebruikt. |
| `download` | Een bestand is gedownload (en dus meteen daarna vernietigd). |
| `annulering` | Een uploader heeft zijn eigen upload ingetrokken. |
| `cron_opgeruimd` | De cronjob is zonder fouten voltooid. |
| `cron_mislukt` | De cronjob is met een fout gestopt. |
| `admin_login` / `admin_login_mislukt` | Een (mislukte) inlogpoging op `admin.php`. |

Een mislukte logschrijving (bijv. schrijfrechten-probleem) mag nooit de eigenlijke actie laten
mislukken — dat wordt afgevangen en weggeschreven naar de reguliere PHP-errorlog in plaats van
de gebruiker een foutmelding te tonen.

---

## 7. Rate limits en lockouts handmatig resetten

Zit een IP-adres of upload onterecht vast (bijv. tijdens het testen), dan kun je de teller
resetten door de bijbehorende rij te verwijderen:

```sql
-- Uploadlimiet voor een IP-adres resetten
DELETE FROM rate_limits WHERE scope = 'upload_ip' AND identifier = '123.123.123.123';

-- Resend-cooldown voor een specifieke upload resetten
DELETE FROM rate_limits WHERE scope = 'resend_code' AND identifier = 'de-upload-uuid';

-- Verificatie-lockout van een upload direct opheffen
UPDATE uploads SET verification_attempts = 0, locked_until = NULL WHERE id = 'de-upload-uuid';
```

---

## 8. Testen draaien

MeoDrive heeft een PHPUnit-testsuite met unit- en integratietests (zie
[`tests/`](../tests)). PHPUnit staat als los `.phar`-bestand in `tools/phpunit.phar` (zie
`.gitignore` — dit bestand hoeft niet gecommit te worden, download het zelf van
[phpunit.de](https://phpunit.de) indien nodig).

```bash
# Alle tests (unit + integratie)
php tools/phpunit.phar

# Alleen de snelle, pure unit-tests (geen database/mailserver nodig)
php tools/phpunit.phar --testsuite unit

# Alleen de end-to-end integratietest
php tools/phpunit.phar --testsuite integration
```

### Wat de integratietest doet

`tests/Integration/CoreFlowTest.php` simuleert de volledige kernflow (upload → encryptie →
verificatie → download-and-destroy) via echte HTTP-requests tegen een tijdelijke PHP
ingebouwde webserver, met een losse testdatabase (`meo_share_test`, aangemaakt en opgeruimd
door de test zelf). Omdat deze test een echte SMTP-server aanspreekt (Mailtrap), kan een stap
worden **overgeslagen** (`markTestSkipped`) als het mailversturen mislukt door een niet-
bereikbare of tijdelijk beperkte mailserver — dat zegt iets over de lokale mailconfiguratie, niet
over een fout in de kernlogica. Een `SKIPPED`-melding op deze test is dus geen reden tot zorg;
een `FAILED`-melding wel.

---

## 9. Back-ups

Voor een volledige back-up zijn twee dingen nodig:

1. De database (tabel `uploads` bevat de metadata en de niet-geheime encryptie-IV's).
2. De map `private/` (bevat de versleutelde bestanden zelf, `credentials.php` en `activity.log`).

Let op: zonder de encryptiesleutel (die alleen in de oorspronkelijke downloadmail staat, nooit in
de database) zijn de versleutelde bestanden in een back-up niet te ontsleutelen. Dit is een
bewuste eigenschap van het zero-knowledge-ontwerp, niet een bug — zie
[beveiliging.md](beveiliging.md) §3.
