# MeoDrive — Voortgang & volgende stappen

_Geschreven op 15 juli 2026 om een nieuwe, schone chat vlot te kunnen starten zonder
contextverlies. Lees dit samen met `.cursor/rules/project-standards.mdc` (die regel wordt
automatisch aan elke chat meegegeven)._

## Wat staat er al (werkend, lokaal getest)

### Backend

- Database-tabel `uploads` (UUID-primary key) in de database `meo_share`.
- Volledige upload-flow: `index.php` → `upload.php` → `verify.php` → `receive.php` →
  `download.php`.
- AES-256-CBC-encryptie; sleutel via de URL, IV (niet geheim) in de database.
- Download-and-destroy: bestand + databaserij worden na precies 1 download definitief
  verwijderd.
- Opschoon-cronjob `private/cron.php` voor verlopen (24 uur) uploads.
- E-mailverzending via PHPMailer + Mailtrap SMTP (`private/MailService.php`), met een
  neutraal credentials-sjabloon (`private/credentials.example.php`).
- **Multi-file upload (max 3 bestanden)**: bij meer dan 1 bestand worden ze automatisch
  gezipt tot `MeoDrive_[datum-tijd].zip` en daarna als 1 bestand door de hele
  encryptie/opslag/download-flow verwerkt.

### Frontend

- Eén-pagina-flow (`index.php`) met rolkeuze (klant/MEO) en state-toggling via JS.
- Custom "blauwe cirkel"-knopstijl (`.submit-btn`) met uitstrekkend hover-effect, Google
  Fonts Outfit + Ubuntu.
- Consistente tekstkleur (`--meo-text: #0e2132`), behalve witte tekst op de navy
  `role-cards`.
- Gedeelde `renderStatusPage()`-helper zodat alle statuspagina's dezelfde huisstijl delen.
- E-mails (`MailService.php`) visueel afgestemd op de website (kleuren, fonts, knopvorm).
- Multi-file dropzone (`js/index.js`):
  - Bestanden worden bij nieuwe selecties/drops **opgeteld**, niet vervangen.
  - Harde limiet van 3 bestanden, met een duidelijke foutmelding bij overschrijding (geen
    stille weigering meer).
  - Bestandsnamen zijn direct zichtbaar in de dropzone (niet alleen "N bestanden
    geselecteerd").
  - "Selectie wissen"-knop; "nog een bestand kiezen" verdwijnt zodra het maximum bereikt is.
  - Debug-hulpmiddel: `window.meoDriveFileDrops` + `console.log` bij elke wijziging van de
    selectie.
- Merknaam overal hernoemd van "MEO Share" naar **"MeoDrive"** (pagina's, e-mails,
  README, comments).

## Waar we nu precies gebleven zijn

De laatste sessie stond in het teken van de **multi-file upload-UX**, met een aantal
iteraties:

1. Eerste versie kapte een te grote selectie automatisch (en stil) af tot 3 bestanden.
2. Aangepast naar: een te grote selectie wordt nu **volledig geweigerd** (er wordt niets
   voor-geselecteerd) + een duidelijke foutmelding.
3. Bug gevonden en gefixt: de waarschuwingstekst werd gezocht binnen het verkeerde
   DOM-scope (`dropZone.querySelector(...)` in plaats van via de gezamenlijke
   `.field`-container), waardoor de melding soms niet verscheen. Dit is opgelost.
4. UX-verbetering: bestandsnamen worden nu direct getoond in de dropzone in plaats van
   enkel een aantal ("2 bestanden geselecteerd").

**Laatste concrete actie:** dit `instructions.md`-bestand en de bijbehorende
`.cursor/rules/project-standards.mdc` aanmaken, zodat een nieuwe chat direct met de juiste
context kan beginnen.

## Directe volgende stappen

1. **End-to-end testen** van de volledige multi-file-flow: 2–3 bestanden uploaden → zip →
   verificatiemail → verify → downloadmail → receive → download-and-destroy. Dit is nog
   niet expliciet opnieuw getest sinds de laatste JS/backend-wijzigingen.
2. **Debug-logging opschonen**: de `console.log(...)`-aanroepen in `js/index.js` (voor het
   inspecteren van `selectedFiles`) staan nog aan. Beslissen of dit blijft staan (handig om
   verder te debuggen) of eruit gaat vóór "productie"-gebruik.
3. **Live-gereedheid** (nog af te stemmen met MEO):
   - Echte SMTP-gegevens (in plaats van de Mailtrap-sandbox).
   - Hostingomgeving met PHP 8.1+ (7.4 kan met kleine aanpassingen, maar wordt afgeraden).
   - Een schrijfbare `private/`-map buiten de webroot op de uiteindelijke server.
   - Een echte cronjob-configuratie (bijv. via cPanel) die `private/cron.php` aanroept.
4. **Beveiligingsreview**: er is nog geen formele security-review uitgevoerd op de huidige
   codebase — nuttig om te doen (en te documenteren voor het stageverslag) vóór livegang.
5. **Schema vastleggen**: de `CREATE TABLE uploads`-query staat nergens als bestand in de
   repo (alleen destijds gedeeld in de chat, en sindsdien uitgebreid met de kolommen
   `email_receiver`, `encryption_iv` en `encryption_key`). Overweeg een `schema.sql` toe te
   voegen zodat de database-structuur reproduceerbaar is zonder de oude chatgeschiedenis.

## Snelstart voor de volgende chat

Vertel simpelweg welke van de bovenstaande stappen je als eerste wil aanpakken — de nieuwe
chat heeft via `.cursor/rules/project-standards.mdc` automatisch de architectuur, stack en
codestijl tot haar beschikking.
