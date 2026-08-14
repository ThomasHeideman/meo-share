---

# MeoDrive — Veilig Bestandsdelingsplatform

Een lichtgewicht, veilig bestandsdelingsplatform gebouwd in pure PHP, zonder afhankelijkheid van zware frameworks. Dit platform is specifiek ontwikkeld voor gebruik door MEO. Bestanden worden direct bij upload versleuteld opgeslagen, e-mailverificatie is verplicht voor activering, en elk bestand wordt na de eerste succesvolle download permanent van de server verwijderd.

Dit document beschrijft de werking, de achterliggende architectuur en de gemaakte technische keuzes.

---

## 1. Functionele Werking

Het platform beveiligt de overdracht van bestanden door middel van een strikte keten van verificatie en versleuteling:

1. **Upload & Encryptie:** De gebruiker selecteert maximaal 3 bestanden op de homepage. Het systeem voegt deze bestanden samen in een ZIP-archief en versleutelt dit direct met een unieke AES-256-sleutel. Het versleutelde bestand wordt opgeslagen buiten de publiek toegankelijke webmap.
2. **Identiteitsverificatie:** Voordat de downloadlink naar de ontvanger wordt verzonden, moet de uploader bewijzen toegang te hebben tot het opgegeven e-mailadres. Hiervoor genereert het systeem een eenmalige, 6-cijferige verificatiecode.
3. **Gecontroleerde Vrijgave:** Pas nadat de uploader de juiste code heeft ingevoerd, wordt de definitieve downloadlink gegenereerd en naar de ontvanger gemaild. De encryptiesleutel bevindt zich uitsluitend in deze link.
4. **Download & Vernietiging (Download-and-Destroy):** Zodra de ontvanger op de landingspagina de download start, wordt het bestand in het servergeheugen ontsleuteld en naar de browser gestreamd. Direct na de overdracht verwijdert PHP het bestand fysiek van de schijf en wordt de bijbehorende database-rij gewist.
5. **Geautomatiseerde Opschoning:** Indien een downloadlink niet binnen de gestelde termijn (bijvoorbeeld 24 of 48 uur) wordt geactiveerd, verwijdert een periodiek script (cronjob) het bestand en de databaserecord om serververvuiling tegen te gaan.

---

## 2. Architectuuroverzicht

De procesflow verloopt lineair via de volgende scripts:

```
                    ┌─────────────────┐
                    │   index.php     │  ← Keuzescherm: Klant of MEO-medewerker
                    └────────┬────────┘
                             │ Upload + e-mailadres(sen)
                             ▼
                    ┌─────────────────┐
                    │   upload.php    │  ← Voegt samen tot ZIP, versleutelt, verzendt verificatiecode
                    └────────┬────────┘
                             │ E-mail 1: 6-cijferige verificatiecode
                             ▼
                    ┌─────────────────┐
                    │   verify.php    │  ← Controleert code, activeert downloadlink
                    └────────┬────────┘
                             │ E-mail 2: Downloadlink inclusief encryptiesleutel
                             ▼
                    ┌─────────────────┐
                    │   receive.php   │  ← Landingspagina met bestandsinformatie
                    └────────┬────────┘
                             │ Actie: Klik op "Start Download"
                             ▼
                    ┌─────────────────┐
                    │  download.php   │  ← Ontsleutelt, streamt bestand en verwijdert data direct
                    └─────────────────┘

                    ┌─────────────────┐
                    │    cron.php     │  ← Server-side opschoning van verlopen uploads
                    └─────────────────┘  (Draait autonoom via de CLI/cron)

```

---

## 3. Technische Bouwstenen

### 3.1 Bestandsopslag Buiten de Webroot

Om directe toegang via een URL onmogelijk te maken, is de mappenstructuur strikt gescheiden in een publiek en een afgeschermd deel:

```
meo-share/
├── private/          ← NIET bereikbaar via de browser
│   ├── config.php
│   ├── credentials.php
│   ├── MailService.php
│   ├── cron.php
│   └── <versleutelde opslag van bestanden>
└── public_html/      ← WEL bereikbaar via de browser (de webroot)
    ├── index.php
    ├── upload.php
    ├── verify.php
    ├── receive.php
    └── download.php

```

De map `public_html/` fungeert als de enige toegangspoort voor de browser. Gevoelige logica, configuratiebestanden en de fysieke uploadopslag bevinden zich in `private/`. Mocht de serverconfiguratie onverhoopt de `private/`-map blootstellen, dan blokkeert een additioneel `.htaccess`-bestand met `Deny from all` elke directe HTTP-aanroep.

### 3.2 Database-ontwerp (Tabel: `uploads`)

Elke uploadsessie wordt geïdentificeerd aan de hand van een cryptografisch gegenereerde UUID v4 in plaats van een opeenvolgend ID (auto-increment). Dit voorkomt dat kwaadwillenden andere uploads kunnen achterhalen door simpelweg numerieke ID's te raden (Insecure Direct Object Reference).


| Kolom               | Type         | Beschrijving                                                                   |
| ------------------- | ------------ | ------------------------------------------------------------------------------ |
| `id`                | VARCHAR(36)  | De unieke UUID v4 van de uploadsessie.                                         |
| `file_name`         | VARCHAR(255) | De oorspronkelijke bestandsnaam (of de naam van het gegenereerde ZIP-archief). |
| `file_path`         | VARCHAR(255) | De willekeurig gegenereerde bestandsnaam op de harde schijf in `private/`.     |
| `encryption_iv`     | BLOB / TEXT  | De Initialisatievector benodigd voor de AES-decryptie (niet-geheim).           |
| `email`             | VARCHAR(255) | E-mailadres van de uploader.                                                   |
| `email_receiver`    | VARCHAR(255) | E-mailadres van de beoogde ontvanger.                                          |
| `verification_code` | VARCHAR(6)   | De 6-cijferige numerieke code voor e-mailverificatie.                          |
| `is_verified`       | TINYINT(1)   | Statusindicator (0 = in afwachting, 1 = geverifieerd en actief).               |
| `expires_at`        | DATETIME     | Het exacte tijdstip waarop de downloadlink onherroepelijk verloopt.            |


### 3.3 Zero-Knowledge AES-256-encryptie

Het platform implementeert een *zero-knowledge* architectuur. Dit garandeert dat de server de opgeslagen data nooit zelfstandig kan inzien of ontsleutelen zonder expliciete aanlevering van de sleutel door de cliënt.

- **Sleutelgeneratie:** Bij elke upload genereert PHP een willekeurige, cryptografisch veilige sleutel via `bin2hex(random_bytes(16))`.
- **Algoritme:** Het geüploade bestand (of het samengevoegde ZIP-archief) wordt direct versleuteld middels AES-256-CBC. Pas na deze versleuteling wordt het bestand weggeschreven naar de schijf in de `private/`-map.
- **Strikte Sleutelscheiding:** De encryptiesleutel wordt **nooit** opgeslagen in de database of op de server. In plaats daarvan wordt de sleutel exclusief meegegeven als GET-parameter in de downloadlink (`receive.php?id=UUID&key=SLEUTEL`) die naar de ontvanger wordt gemaild.

Bij een eventuele algehele databasediefstal of serverinbraak blijft de data ontoegankelijk. Zonder de specifieke downloadlink uit de mailbox van de ontvanger is de versleutelde data op schijf onbruikbaar.

### 3.4 E-mailverificatie via Mailtrap

Om misbruik en anonieme spam via het platform te voorkomen, is een tweestaps-verificatieproces ingericht:

1. Na het uploaden verzendt het platform via SMTP een 6-cijferige token naar de uploader.
2. Pas wanneer deze token succesvol is gevalideerd op `verify.php`, wordt de status in de database omgezet naar geverifieerd.
3. Direct na validatie genereert het systeem de definitieve downloadmail naar de ontvanger.

Voor de ontwikkel- en testfase is [Mailtrap Sandbox](https://mailtrap.io) geïntegreerd. Dit vangt al het uitgaande SMTP-verkeer af in een afgeschermde testomgeving, waardoor er tijdens het testen geen e-mails naar daadwerkelijke externe adressen worden verzonden.

### 3.5 Download-and-destroy Mechanisme

Zodra de ontvanger de download start via `download.php`, voert het systeem de volgende stappen sequentieel uit:

1. De geldigheid, verificatiestatus en verloopperiode van het UUID worden gecontroleerd.
2. Het versleutelde bestand wordt ingelezen en in-memory ontsleuteld met de meegegeven `key` uit de URL.
3. De ontsleutelde datastroom wordt rechtstreeks naar de browser gepusht (file streaming).
4. **Directe purging:** Onmiddellijk na het voltooien van de stream verwijdert PHP het fysieke bestand van de harde schijf via `unlink()` en wordt de bijbehorende rij definitief uit de database gewist (`DELETE`).

Dit zorgt ervoor dat een downloadlink strikt eenmalig bruikbaar is. Elke opeenvolgende poging resulteert in een 404-foutmelding.

### 3.6 Geautomatiseerde Cronjob Opschoning

Om serververvuiling door ongebruikte of verlopen uploads tegen te gaan, draait het script `private/cron.php` autonoom op de achtergrond. Het script voert periodiek de volgende database- en bestandshandelingen uit:

- Het selecteert alle records waarvan de timestamp in `expires_at` kleiner is dan de huidige systeemtijd.
- Het verwijdert de bijbehorende versleutelde bestanden permanent van de harde schijf.
- Het schoont de verlopen rijen op uit de database.

Dit script is niet bereikbaar via het web en kan uitsluitend worden aangeroepen via de Command Line Interface (CLI) van de server of een systeem-cronjob.

---

## 4. Technische Stack


| Component         | Technologie           | Onderbouwing                                                                                                 |
| ----------------- | --------------------- | ------------------------------------------------------------------------------------------------------------ |
| **Omgeving**      | PHP 8.2+ (Vanilla)    | Optimaal voor een performante, onderhoudbare applicatie zonder framework-overhead.                           |
| **Database**      | MySQL / MariaDB       | Betrouwbare relationele opslag met volledige ondersteuning voor transacties en indexering.                   |
| **Encryptie**     | AES-256-CBC (OpenSSL) | Industriele standaard voor symmetrische encryptie; biedt een hoge verwerkingssnelheid en sterke beveiliging. |
| **E-mail**        | PHPMailer             | Robuuste library voor het afhandelen van geformatteerde SMTP-verzendingen en authenticatie.                  |
| **Identificatie** | UUID v4               | Voorkomt databasetelling en zorgt voor onvoorspelbare, unieke URL-parameters.                                |


---

## 5. Lokale Installatie en Configuratierichtlijnen

1. Richt de virtual host van de webserver (bijvoorbeeld binnen XAMPP) zo in dat de document root exclusief naar de map `public_html/` verwijst.
2. Importeer de database-architectuur in MySQL middels het meegeleverde SQL-schema (tabel `uploads`).
3. Kopieer het bestand `private/credentials.example.php` naar `private/credentials.php` en configureer hierin de lokale database-credentials en de Mailtrap SMTP-instellingen.
4. Voer `composer install` uit in de hoofdmap van het project om PHPMailer en de benodigde dependencies te initialiseren.
5. Benader de applicatie lokaal via de geconfigureerde URL (bijvoorbeeld `http://localhost/`).
6. De cronjob kan handmatig worden getest en getriggerd via de terminal met het commando:

```bash
php private/cron.php

```

---

## 6. Toegepaste Beveiligingsprincipes

- **Least Privilege:** Bestanden en gevoelige PHP-logica zijn strikt gescheiden van de webroot en onbereikbaar voor directe HTTP-verzoeken.
- **Defense in Depth:** De beveiliging leunt niet op één enkele laag; zelfs bij een volledige database-compromis zorgt de scheiding van de encryptiesleutel ervoor dat de bestanden onleesbaar blijven.
- **Onvoorspelbare Identifiers:** Door het consequent toepassen van UUID v4 is het handmatig manipuleren van parameters in de URL om andermans data in te zien uitgesloten.
- **Ephemerality (Vergankelijkheid):** Data is slechts tijdelijk aanwezig. Zowel de download-and-destroy logica als de automatische cronjob-purging minimaliseren de bewaartermijn van gevoelige gegevens op de server.

Daarnaast bevat het platform onder meer rate limiting en lockouts op verificatiepogingen, CSRF-bescherming, security headers, een bestandstype-filter, mogelijkheid om een eigen upload in te trekken, een audit-log en een beveiligd admin-overzicht. Zie [`docs/beveiliging.md`](docs/beveiliging.md) voor het volledige overzicht.

---

## 7. Aanvullende documentatie

Naast dit architectuuroverzicht is er uitgebreide documentatie beschikbaar in de map [`docs/`](docs/):

| Document | Voor wie | Inhoud |
| --- | --- | --- |
| [`docs/gebruikershandleiding.md`](docs/gebruikershandleiding.md) | Klanten, MEO-medewerkers, ontvangers | Hoe je een bestand uploadt, verifieert, intrekt of ontvangt. |
| [`docs/beheerdershandleiding.md`](docs/beheerdershandleiding.md) | Beheerders | Installatie, credentials-beheer, het admin-overzicht, de cronjob, logging en tests draaien. |
| [`docs/beveiliging.md`](docs/beveiliging.md) | Beheerders, beoordelaars | Volledig overzicht van alle beveiligingsmaatregelen, inclusief bekende beperkingen. |

