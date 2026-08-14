<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/Csrf.php';

// Output buffering: csrfHiddenField() (verderop in de HTML) zet een cookie
// via setcookie(), wat alleen werkt vóórdat er HTTP-headers zijn verstuurd.
// Zonder buffer zou dat, afhankelijk van php.ini's output_buffering-
// instelling, soms wel en soms niet lukken.
ob_start();

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MeoDrive — Bestand delen</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicons/favicon.svg">
    <link rel="icon" type="image/png" sizes="96x96" href="assets/favicons/favicon-96x96.png">
    <link rel="shortcut icon" href="assets/favicons/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicons/apple-touch-icon.png">
    <link rel="manifest" href="assets/favicons/site.webmanifest">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@100..900&family=Ubuntu:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/index.css">
</head>
<body>
    <div class="page">
        <!-- State 1: kies wie je bent -->
        <section class="state is-active" id="state-choice" data-state="choice">
            <div class="choice-stack">
                <div class="choice-layout">
                    <div class="choice-logo">
                        <img class="logo-img" src="assets/meo-drive-logo.png" alt="MeoDrive">
                    </div>

                    <div class="role-cards">
                        <button type="button" class="role-card" data-role="client">Ik ben een klant</button>
                        <button type="button" class="role-card" data-role="meo">Ik ben een medewerker</button>
                    </div>
                </div>

                <p class="choice-tagline">
                    <svg class="choice-tagline-icon" width="17" height="17" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a4 4 0 00-4 4v2H5a1 1 0 00-1 1v6a1 1 0 001 1h10a1 1 0 001-1V9a1 1 0 00-1-1h-1V6a4 4 0 00-4-4zm2 6H8V6a2 2 0 114 0v2z"></path></svg>
                    Versleuteld verzonden, eenmalig gedownload, automatisch vernietigd — bestanden delen zonder zorgen.
                </p>
            </div>
        </section>

        <!-- State 2a: klant stuurt naar MEO -->
        <section class="state" id="state-client" data-state="client">
            <div class="form-layout">
                <button type="button" class="back-link" data-back>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"></path></svg>
                    Terug
                </button>

                <img class="logo-img" src="assets/meo-drive-logo.png" alt="MeoDrive">

                <form
                    class="upload-form"
                    id="form-client"
                    action="upload.php"
                    method="post"
                    enctype="multipart/form-data"
                >
                    <input type="hidden" name="user_role" value="client">
                    <?= csrfHiddenField() ?>

                    <div class="field">
                        <label for="client-sender-email">Je email-adres</label>
                        <input
                            type="email"
                            name="sender_email"
                            id="client-sender-email"
                            required
                            autocomplete="email"
                        >
                    </div>

                    <div class="field">
                        <label class="sr-only" for="client-file">Bestand(en) uploaden</label>
                        <div class="file-drop" data-file-drop data-max-files="3" tabindex="0" role="button" aria-describedby="client-file-hint">
                            <input type="file" name="file[]" id="client-file" multiple required>
                            <div class="file-drop-text" id="client-file-hint">
                                <div data-file-drop-label>Sleep je bestand(en) om te delen</div>
                                <span data-file-drop-of>of </span><button type="button" class="file-drop-link" data-file-trigger data-default-text="zoek" data-selected-text="nog een bestand kiezen">zoek</button><span data-file-drop-sep hidden> · </span><button type="button" class="file-drop-link" data-file-clear hidden>selectie wissen</button>
                            </div>
                        </div>
                        <p class="file-drop-text--small" id="client-file-hint-2">maximaal 3 bestanden per keer</p>
                        <p class="file-drop-warning" data-file-drop-warning hidden></p>
                    </div>

                    <div class="upload-progress" data-upload-progress hidden>
                        <div class="upload-progress-track"><div class="upload-progress-bar" data-upload-progress-bar></div></div>
                        <span class="upload-progress-label" data-upload-progress-label>0%</span>
                    </div>

                    <button type="submit" class="submit-btn">Versturen</button>
                </form>
            </div>
        </section>

        <!-- State 2b: MEO-medewerker stuurt naar klant -->
        <section class="state" id="state-meo" data-state="meo">
            <div class="form-layout">
                <button type="button" class="back-link" data-back>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"></path></svg>
                    Terug
                </button>

                <img class="logo-img" src="assets/meo-drive-logo.png" alt="MeoDrive">

                <form
                    class="upload-form"
                    id="form-meo"
                    action="upload.php"
                    method="post"
                    enctype="multipart/form-data"
                >
                    <input type="hidden" name="user_role" value="meo">
                    <?= csrfHiddenField() ?>

                    <div class="field">
                        <label for="meo-sender-email">Je MEO email-adres</label>
                        <input
                            type="email"
                            name="sender_email"
                            id="meo-sender-email"
                            required
                            autocomplete="email"
                        >
                    </div>

                    <div class="field">
                        <label for="meo-recipient-email">Email-adres van de klant (ontvanger)</label>
                        <input
                            type="email"
                            name="recipient_email"
                            id="meo-recipient-email"
                            required
                            autocomplete="email"
                        >
                    </div>

                    <div class="field">
                        <label class="sr-only" for="meo-file">Bestand(en) uploaden</label>
                        <div class="file-drop" data-file-drop data-max-files="3" tabindex="0" role="button" aria-describedby="meo-file-hint">
                            <input type="file" name="file[]" id="meo-file" multiple required>
                            <div class="file-drop-text" id="meo-file-hint">
                                <div data-file-drop-label>Sleep je bestand(en) om te delen</div>
                                <span data-file-drop-of>of </span><button type="button" class="file-drop-link" data-file-trigger data-default-text="zoek" data-selected-text="nog een bestand kiezen">zoek</button><span data-file-drop-sep hidden> · </span><button type="button" class="file-drop-link" data-file-clear hidden>selectie wissen</button>
                            </div>
                            
                        </div>
                        <p class="file-drop-text--small" id="meo-file-hint-2">maximaal 3 bestanden per keer</p>
                        <p class="file-drop-warning" data-file-drop-warning hidden></p>
                    </div>

                    <div class="upload-progress" data-upload-progress hidden>
                        <div class="upload-progress-track"><div class="upload-progress-bar" data-upload-progress-bar></div></div>
                        <span class="upload-progress-label" data-upload-progress-label>0%</span>
                    </div>

                    <button type="submit" class="submit-btn">Versturen</button>
                </form>
            </div>
        </section>
    </div>

    <script src="js/index.js" defer></script>
</body>
</html>
