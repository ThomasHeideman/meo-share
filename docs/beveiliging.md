# MeoDrive — Beveiligingsoverzicht

Dit document geeft een compleet overzicht van alle beveiligingsmaatregelen in MeoDrive, waarom ze
zijn toegepast, en welke bewuste beperkingen er nog zijn. Bedoeld voor wie het platform beoordeelt
of beheert, en als referentie voor het stageverslag.

---

## 1. Bestanden buiten de webroot

Alle versleutelde bestanden, de database-credentials en de mail- en activiteitenlogs staan in
`private/`, buiten `public_html/` (de enige map die de webserver publiek serveert). Zelfs bij een
verkeerd geconfigureerde webserver die per ongeluk toch in `private/` zou kijken, blokkeert een
`.htaccess`-bestand met `Require all denied` (Apache 2.4+) elke directe HTTP-aanroep naar die map.

## 2. Onvoorspelbare identifiers (UUID v4)

Elke upload heeft een UUID v4 als primaire sleutel in plaats van een oplopend nummer. Een
aanvaller kan dus niet simpelweg `id=1`, `id=2`, ... proberen om bij andermans bestanden te komen
(Insecure Direct Object Reference / IDOR). Hetzelfde principe geldt voor het `cancel_token`
(64 hexadecimale tekens, `random_bytes(32)`).

## 3. Zero-knowledge AES-256-CBC-encryptie

- Elk bestand krijgt een eigen, willekeurige sleutel (`bin2hex(random_bytes(16))`) en IV
  (`openssl_random_pseudo_bytes()`), en wordt versleuteld vóór het naar schijf geschreven wordt.
- De sleutel staat **nooit permanent** in de database: hij wordt tijdelijk in
  `uploads.encryption_key` gezet tussen upload en verificatie, en pas naar `NULL` gezet door
  `verify.php` zodra de downloadmail met de sleutel succesvol is verstuurd. De IV is niet geheim
  en blijft permanent in `uploads.encryption_iv` staan (dat is voor AES-CBC ook niet nodig — de
  veiligheid zit in de sleutel, niet in de IV).
- Gevolg: zelfs bij een volledige database- of serverdiefstal, ná verificatie, zijn de bestanden
  zonder de downloadlink uit de mailbox van de ontvanger onleesbaar.

## 4. E-mailverificatie van de uploader

Voordat een downloadlink actief wordt, moet de uploader een 6-cijferige, aselecte code
(`random_int(100000, 999999)`) invullen die naar zijn eigen e-mailadres is gestuurd. Dit
voorkomt dat iemand een bestand naar een willekeurige derde stuurt met een vervalst
afzenderadres, en zorgt dat we weten dat de uploader daadwerkelijk toegang heeft tot het
opgegeven adres.

## 5. Rate limiting

Generieke, database-gebaseerde rate limiter (`private/RateLimiter.php`, tabel `rate_limits`),
toegepast op twee plekken:

| Scope | Regel | Doel |
| --- | --- | --- |
| `upload_ip` | Max. 5 uploads per IP-adres per 15 minuten | Voorkomt dat één IP het platform overspoelt met uploads. |
| `resend_code` | Max. 1 "code opnieuw versturen" per upload per minuut | Voorkomt mail-spam via de resend-knop. |

De teller wordt per venster bijgehouden (`window_started_at` + `attempt_count`); na afloop van
het venster wordt automatisch een nieuw venster gestart.

## 6. Lockout op verificatiepogingen

Zonder limiet zou de 6-cijferige code (1 op de miljoen kansen per gok) op termijn "gegokt" kunnen
worden door voldoende pogingen te doen. `uploads.verification_attempts` telt mislukte pogingen op;
na **5 mislukte pogingen** wordt `uploads.locked_until` gezet op **15 minuten** in de toekomst en
worden verdere pogingen geweigerd totdat die tijd is verstreken. Bij een correcte code worden
teller en lockout weer gereset.

## 7. CSRF-bescherming (double-submit cookie)

Alle formulieren die een state-wijzigende actie uitvoeren (uploaden, verifiëren, code opnieuw
versturen, upload intrekken, admin-login/logout) zijn beveiligd met een CSRF-token via het
double-submit-cookiepatroon (`private/Csrf.php`):

1. Bij het tonen van een formulier zet de server een willekeurige token (40 hex-tekens,
   `random_bytes(20)`) zowel in een `HttpOnly`-cookie als in een verborgen formulierveld.
2. Bij het versturen vergelijkt de server de cookie-waarde met de formulierwaarde
   (tijdveilig via `hash_equals()`).
3. Een externe site kan de gebruiker weliswaar laten posten naar MeoDrive (de cookie wordt door
   de browser automatisch meegestuurd), maar kan de verborgen veldwaarde niet kennen — die staat
   alleen in HTML die uitsluitend via hetzelfde origin te lezen is.

Bewust géén PHP-sessies nodig: dit patroon past bij de verder volledig stateless architectuur van
het platform (met uitzondering van `admin.php`, zie §11).

## 8. Security headers (`public_html/.htaccess`)

| Header | Waarde | Effect |
| --- | --- | --- |
| `X-Content-Type-Options` | `nosniff` | Voorkomt dat de browser bestandstypen "raadt" en bijv. een upload als HTML interpreteert. |
| `X-Frame-Options` | `DENY` | Voorkomt dat de site in een `<iframe>` van een andere site wordt ingeladen (clickjacking). |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Beperkt hoeveel URL-informatie (die een upload-ID kan bevatten) naar externe sites lekt via de referrer. |
| `Permissions-Policy` | camera/microfoon/locatie uit | MeoDrive heeft geen van deze functies nodig; expliciet uitschakelen minimaliseert het aanvalsoppervlak. |
| `Content-Security-Policy` | zie `.htaccess` | Staat alleen eigen CSS/JS en Google Fonts toe; blokkeert inline scripts en het laden van content van onbekende bronnen. |

## 9. Bestandstype-blacklist

`private/validation_helpers.php` blokkeert een vaste lijst potentieel gevaarlijke extensies
(server-side scripts als `.php`/`.phtml`, Windows-uitvoerbare bestanden als `.exe`/`.bat`/`.msi`,
scripts als `.js`/`.vbs`/`.ps1`, en serverconfiguratie als `.htaccess`). Bewust een **blacklist**
in plaats van een whitelist: MeoDrive is een algemeen bestandsdelingsplatform en moet gewone
documenten, afbeeldingen, archieven etc. blijven toestaan. De vergelijking is
case-insensitive (`FOTO.EXE` is net zo gevaarlijk als `foto.exe`).

## 10. Upload intrekken (`cancel_token`)

Elke upload krijgt een eigen, willekeurige `cancel_token` (64 hex-tekens). Alleen wie de link met
dit token heeft (die de uploader direct na het uploaden te zien krijgt) kan de upload intrekken.
Vergelijking gebeurt tijdveilig via `hash_equals()` om timing-aanvallen te voorkomen.

## 11. Download-and-destroy

Een downloadlink werkt **precies één keer**. Direct na het streamen van de ontsleutelde bytes
naar de browser verwijdert `download.php` het bestand van de schijf (`unlink()`) én de
bijbehorende rij uit de database (`DELETE`) — in die volgorde, zodat een falende verwijdering
nooit een "geldige" databaserij achterlaat bij een reeds verwijderd bestand.

## 12. Automatische opschoning (cronjob)

`private/cron.php` verwijdert periodiek alle uploads waarvan `expires_at` (standaard 24 uur na
upload) is verstreken, zowel het bestand als de databaserij. Dit beperkt de tijd dat een
niet-opgehaald bestand op de server blijft staan, en zorgt dat verlopen links niet oneindig in de
database blijven hangen.

## 13. Admin-authenticatie

`admin.php` is het enige onderdeel met een gebruikersaccount, bewust beperkt tot één gedeeld
wachtwoord (geen volwaardig gebruikersbeheer, passend bij de schaal van dit project):

- Het wachtwoord wordt nooit in platte tekst opgeslagen, alleen als bcrypt-hash
  (`password_hash()`/`password_verify()`) in `private/credentials.php`.
- De sessie gebruikt `HttpOnly`, `SameSite=Lax` en (indien https actief is) `Secure` cookies, en
  wordt na login/logout ge-regenereerd (`session_regenerate_id(true)`) om session fixation te
  voorkomen.
- Zowel geslaagde als mislukte loginpogingen worden gelogd (`admin_login` /
  `admin_login_mislukt`), inclusief IP-adres.

## 14. Audit-logging

`private/Logger.php` schrijft alle beveiligingsrelevante events (uploads, verificaties,
downloads, annuleringen, cronruns, admin-logins) naar `private/logs/activity.log`, buiten de
webroot. Dit maakt achteraf onderzoek naar misbruik of fouten mogelijk. Zie
[beheerdershandleiding.md](beheerdershandleiding.md) §6 voor de volledige lijst met events.

## 15. Database-toegang

Alle database-interactie verloopt via PDO met **uitsluitend prepared statements** en
named parameters — nooit stringconcatenatie in SQL — en `PDO::ERRMODE_EXCEPTION`, zodat
databasefouten nooit stilzwijgend verdwijnen maar altijd afgehandeld (of gelogd) worden.

## 16. Scheiding van geheimen

`private/credentials.php` (database-wachtwoord, SMTP-gegevens, admin-wachtwoord-hash) staat in
`.gitignore` en wordt nooit gecommit. `private/credentials.example.php` is het publieke sjabloon
met uitsluitend neutrale placeholder-waarden (`JOUW_...`), zodat het risicoloos in Git kan staan.

---

## Bekende beperkingen (bewuste keuzes, geen oversights)

Voor transparantie: dit zijn grenzen van de huidige implementatie, passend bij de schaal en het
doel van dit project (een interne tool voor MEO, geen publieke, high-traffic dienst).

- **Geen malware-scanning van geüploade bestanden.** De bestandstype-blacklist voorkomt directe
  uitvoerbaarheid, maar scant de inhoud niet op virussen. Voor een productieomgeving met
  onbekende, externe uploaders zou een virusscanner (bijv. ClamAV) een waardevolle toevoeging
  zijn.
- **Geen 2FA voor het admin-account.** Bij één gedeeld wachtwoord voor een klein, intern team is
  dit een bewuste afweging tussen eenvoud en extra beveiliging.
- **Rate limiter is niet 100% race-condition-vrij.** `RateLimiter::tooManyAttempts()` gebruikt
  select-dan-schrijven in plaats van een atomaire database-operatie. Bij de schaal van dit
  platform (geen publieke high-traffic dienst) is het theoretische risico — twee gelijktijdige
  requests van hetzelfde IP binnen dezelfde milliseconde omzeilen de limiet net één keer —
  acceptabel bevonden.
- **Geen wachtwoord-hersteloptie voor het admin-account.** Bij verlies van het wachtwoord past een
  beheerder de hash in `private/credentials.php` handmatig aan (zie
  [beheerdershandleiding.md](beheerdershandleiding.md) §3).
