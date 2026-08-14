# MeoDrive — Gebruikershandleiding

Deze handleiding beschrijft hoe je MeoDrive gebruikt als **klant**, als **MEO-medewerker** of als
**ontvanger** van een bestand. Voor de technische architectuur zie het hoofdbestand
[README.md](../README.md); voor beheer en beveiliging zie
[beheerdershandleiding.md](beheerdershandleiding.md) en [beveiliging.md](beveiliging.md).

---

## 1. Wie gebruikt MeoDrive, en waarvoor?

MeoDrive kent twee upload-rollen, gekozen op de homepage (`index.php`):

| Rol | Wie ben je? | Wie ontvangt het bestand? |
| --- | --- | --- |
| **Klant** | Iemand die iets naar MEO wil versturen. | MEO (het vaste MEO-e-mailadres). |
| **MEO-medewerker** | Een medewerker die iets naar een klant wil versturen. | Het e-mailadres dat je zelf invoert. |

In beide gevallen ben je de **uploader** en de andere partij is de **ontvanger** — ook als
"ontvanger" in het eerste geval MEO zelf is.

---

## 2. Een bestand versturen (uploader)

### 2.1 Rol kiezen en formulier invullen

1. Open de homepage en kies "Ik ben een klant" of "Ik ben een medewerker".
2. Vul je eigen e-mailadres in (en bij de medewerkersrol ook het e-mailadres van de klant).
3. Selecteer één of meerdere bestanden, via de knop of door ze in het vak te slepen (drag & drop).

### 2.2 Meerdere bestanden in één keer

- Je kunt **maximaal 3 bestanden** per keer uploaden. Bij een poging om meer te selecteren
  krijg je direct een duidelijke foutmelding — er wordt nooit stilzwijgend een deel van je
  selectie genegeerd.
- Nieuwe selecties/drops worden **toegevoegd** aan wat je al had geselecteerd, niet vervangen.
  Zodra het maximum van 3 is bereikt, verdwijnen de knoppen om nog meer toe te voegen.
- Bij meer dan 1 bestand zie je de namen van alle geselecteerde bestanden in een lijstje (lange
  namen worden ingekort zodat de lijst leesbaar blijft).
- Upload je meer dan 1 bestand, dan worden ze op de achtergrond automatisch samengevoegd tot
  één archief (`MeoDrive_[datum-tijd].zip`). Verder verandert er niets aan de flow: dat ene
  archief wordt net als een los bestand versleuteld, verstuurd en na één download vernietigd.

### 2.3 Versturen en voortgang

Bij het versturen zie je een voortgangsbalk die het percentage van de upload toont — handig bij
grotere bestanden. Is je bestand groter dan de serverlimiet, dan krijg je een duidelijke
Nederlandse foutmelding in plaats van een cryptische browserfout.

Bepaalde bestandstypen (zoals `.exe`, `.php`, `.bat`, `.js`, `.vbs`, ...) worden geweigerd,
omdat die op een ontvangende computer rechtstreeks uitgevoerd zouden kunnen worden. Gewone
documenten, afbeeldingen, video's, archieven, etc. blijven altijd toegestaan.

### 2.4 E-mailverificatie

Na een succesvolle upload ontvang je (de uploader) een e-mail met een **6-cijferige
verificatiecode**. Dit bewijst dat het opgegeven e-mailadres echt van jou is, vóórdat de
downloadlink naar de ontvanger wordt verstuurd.

1. Vul de code in op de verificatiepagina.
2. Is de code onjuist, dan mag je het opnieuw proberen — na **5 mislukte pogingen** wordt de
   upload voor **15 minuten vergrendeld** als bescherming tegen gokken.
3. Geen mail ontvangen? Klik op **"Code opnieuw versturen"**. Om misbruik te voorkomen kun je
   dit maximaal eens per minuut doen.
4. Na de juiste code krijg je de melding "Verificatie geslaagd" — de downloadlink is dan al
   automatisch naar de ontvanger gemaild. Jij krijgt de link zelf niet te zien: die staat alleen
   in de mail van de ontvanger.

### 2.5 Van gedachten veranderd? Upload intrekken

Zolang de ontvanger nog niet heeft gedownload, kun je je eigen upload altijd intrekken via de
link **"Annuleer deze upload"** (te vinden op de succespagina na het uploaden en op de
verificatiepagina). Na een korte bevestiging wordt het bestand direct en definitief van de
server verwijderd — de downloadlink werkt daarna niet meer, ook niet als de ontvanger de mail
nog niet had geopend.

### 2.6 Bevestiging bij download

Zodra de ontvanger het bestand daadwerkelijk downloadt, ontvang jij als uploader automatisch een
bevestigingsmail. Zo weet je zeker dat het bestand is aangekomen, zonder dat je zelf iets hoeft
te controleren.

---

## 3. Een bestand ontvangen (ontvanger)

1. Je ontvangt een e-mail met een link naar een beveiligde downloadpagina.
2. Open de link. Je komt op een landingspagina (`receive.php`) die de bestandsnaam en
   -grootte toont, zonder dat er al iets gedownload wordt.
3. Klik op **"Start Download"**. De browser start de download en toont daarna een bevestiging.

### Belangrijk om te weten

- De link werkt **precies één keer**. Zodra het bestand is gedownload, wordt het **direct en
  onherroepelijk van de server verwijderd** — er is geen manier om het opnieuw te downloaden,
  ook niet door dezelfde link nog eens te openen.
- De link verloopt automatisch **24 uur** na de upload, ook als er nooit is gedownload. Een
  verlopen link geeft een duidelijke foutmelding in plaats van een download.
- Bewaar het bestand dus meteen ergens veilig na het downloaden — een tweede kans is er niet.

---

## 4. Veelgestelde vragen

**Ik krijg geen verificatiemail. Wat nu?**
Controleer je spamfolder. Gebruik daarna de knop "Code opnieuw versturen" op de
verificatiepagina (maximaal eens per minuut).

**Ik heb de verkeerde code te vaak ingevuld.**
Na 5 mislukte pogingen wordt de upload 15 minuten vergrendeld. Wacht deze tijd af en probeer het
daarna opnieuw met de juiste code uit je mail.

**Kan ik een upload nog stoppen nadat ik hem heb verstuurd?**
Ja, zolang de ontvanger nog niet heeft gedownload — zie [2.5](#25-van-gedachten-veranderd-upload-intrekken).

**De ontvanger zegt dat de link niet meer werkt.**
Dat kan drie oorzaken hebben: het bestand is al één keer gedownload (en dus vernietigd), de
24-uurstermijn is verstreken, of jij (de uploader) hebt de upload zelf ingetrokken.

**Waarom mag ik geen `.exe`- of `.php`-bestand uploaden?**
Dat soort bestandstypen kan op een ontvangende computer direct worden uitgevoerd, wat een
veiligheidsrisico voor de ontvanger vormt. Zet zulke bestanden eerst in een `.zip`-archief als je
ze toch moet versturen.
