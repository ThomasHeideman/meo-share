document.addEventListener('DOMContentLoaded', function () {
    const states = document.querySelectorAll('.state');

    function showState(stateName) {
        states.forEach(function (state) {
            state.classList.toggle('is-active', state.dataset.state === stateName);
        });
    }

    // Stap 1 -> stap 2: rol kiezen bepaalt welke vervolgstate wordt getoond.
    document.querySelectorAll('.role-card').forEach(function (card) {
        card.addEventListener('click', function () {
            showState(card.dataset.role);
        });
    });

    // Stap 2 -> stap 1: terug naar het keuzescherm.
    document.querySelectorAll('[data-back]').forEach(function (button) {
        button.addEventListener('click', function () {
            showState('choice');
        });
    });

    // Dropzone: klik op "zoek" (of de zone zelf) opent de bestandskiezer,
    // en slepen van bestanden vult hetzelfde verborgen input-element.
    // Er mogen maximaal data-max-files bestanden in totaal worden geselecteerd;
    // upload.php zipt deze bij meer dan 1 bestand automatisch tot één archief.
    document.querySelectorAll('[data-file-drop]').forEach(function (dropZone) {
        const input = dropZone.querySelector('input[type="file"]');
        const label = dropZone.querySelector('[data-file-drop-label]');
        const ofText = dropZone.querySelector('[data-file-drop-of]');
        const trigger = dropZone.querySelector('[data-file-trigger]');
        const sep = dropZone.querySelector('[data-file-drop-sep]');
        const clearBtn = dropZone.querySelector('[data-file-clear]');
        // De waarschuwing staat buiten .file-drop (als sibling, voor de layout),
        // dus die zoeken we op vanuit de gezamenlijke .field-container en niet
        // vanuit dropZone zelf (anders wordt hij nooit gevonden).
        const fieldContainer = dropZone.closest('.field') || dropZone.parentElement;
        const warning = fieldContainer ? fieldContainer.querySelector('[data-file-drop-warning]') : null;
        const defaultLabel = label.textContent;
        const maxFiles = parseInt(dropZone.dataset.maxFiles, 10) || 3;

        // De "echte" selectie houden we hier zelf bij, want de native
        // <input type="file"> vervangt bij elke nieuwe kiesactie zijn hele
        // FileList. Door dat handmatig samen te voegen kan de gebruiker in
        // meerdere stappen bestanden toevoegen in plaats van steeds te
        // vervangen wat er al gekozen was.
        let selectedFiles = [];

        // selectedFiles is een lokale variabele in deze forEach-closure en
        // is dus NIET zichtbaar in de browserconsole. Voor debugging zetten
        // we de actuele selectie ook op window.meoDriveFileDrops[<input-id>],
        // zodat je in de console kunt typen: console.log(window.meoDriveFileDrops)
        const debugId = input.id || ('file-drop-' + Math.random().toString(36).slice(2));
        window.meoDriveFileDrops = window.meoDriveFileDrops || {};

        function fileKey(file) {
            return [file.name, file.size, file.lastModified].join('::');
        }

        function escapeHtml(value) {
            return value.replace(/[&<>"']/g, function (char) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                }[char];
            });
        }

        // Lange bestandsnamen inkorten met "..." in het midden, zodat zowel
        // het begin (herkenbaarheid) als het einde (incl. extensie) leesbaar
        // blijft. Bijv. "Redirects-nalopen-prostaatkankerstichting-13-07-2026_v2 (1).xlsx"
        // wordt "Redirects-nalopen-pro...26_v2 (1).xlsx".
        function truncateFileName(name, maxLength) {
            maxLength = maxLength || 38;
            const ELLIPSIS = '...';

            if (name.length <= maxLength) {
                return name;
            }

            const keepLength = maxLength - ELLIPSIS.length;
            const frontLength = Math.ceil(keepLength * 0.6);
            const backLength = keepLength - frontLength;

            return name.slice(0, frontLength) + ELLIPSIS + name.slice(name.length - backLength);
        }

        function isAtMax() {
            return selectedFiles.length >= maxFiles;
        }

        function maxReachedMessage() {
            return 'Je hebt het maximum van ' + maxFiles + ' bestanden al bereikt. '
                + 'Klik op "selectie wissen" om een andere keuze te maken.';
        }

        function setInputFiles(files) {
            const dataTransfer = new DataTransfer();
            files.forEach(function (file) {
                dataTransfer.items.add(file);
            });
            input.files = dataTransfer.files;
        }

        function showWarning(message) {
            if (!warning) {
                return;
            }
            warning.textContent = message;
            warning.hidden = !message;
        }

        function render() {
            // Selectie ook buiten deze closure beschikbaar maken voor debugging
            // (zie window.meoDriveFileDrops hierboven), plus een console.log
            // zodat je de selectie live kunt volgen zonder iets te moeten typen.
            window.meoDriveFileDrops[debugId] = selectedFiles;
            dropZone.selectedFiles = selectedFiles;
            console.log(
                '[file-drop:' + debugId + '] geselecteerde bestanden:',
                selectedFiles.map(function (file) {
                    return file.name + ' (' + file.size + ' bytes)';
                })
            );

            const hasFiles = selectedFiles.length > 0;
            const atMax = isAtMax();
            dropZone.classList.toggle('has-file', hasFiles);
            dropZone.classList.toggle('is-max', atMax);

            if (!hasFiles) {
                label.textContent = defaultLabel;
                label.removeAttribute('title');
            } else {
                // Toon de daadwerkelijke bestandsnaam/-namen in plaats van enkel
                // een aantal, zodat direct duidelijk is wélke bestanden klaarstaan.
                // De volledige (niet-ingekorte) namen blijven beschikbaar via de
                // title-tooltip.
                const names = selectedFiles.map(function (file) { return file.name; });
                label.title = names.join(', ');

                if (selectedFiles.length === 1) {
                    label.textContent = truncateFileName(names[0]);
                } else {
                    const items = names.map(function (name, index) {
                        const isLast = index === names.length - 1;
                        return '<li>' + escapeHtml(truncateFileName(name)) + (isLast ? '' : ',') + '</li>';
                    }).join('');
                    label.innerHTML = selectedFiles.length + ' bestanden geselecteerd:'
                        + '<ul class="file-drop-file-list">' + items + '</ul>';
                }
            }

            // Zodra er bestanden gekozen zijn, klopt "of zoek" niet meer naast de
            // bestandsnaam/het aantal. We verbergen "of " en herformuleren de link.
            // Is het maximum bereikt, dan verdwijnt de "kies-meer"-knop helemaal
            // (droppen/klikken kan dan toch niets toevoegen) en blijft alleen
            // "selectie wissen" over als uitweg.
            if (ofText) {
                ofText.hidden = hasFiles;
            }
            if (trigger) {
                trigger.hidden = atMax;
                trigger.textContent = hasFiles
                    ? trigger.dataset.selectedText
                    : trigger.dataset.defaultText;
            }
            if (sep) {
                sep.hidden = !(hasFiles && !atMax);
            }
            if (clearBtn) {
                clearBtn.hidden = !hasFiles;
            }
        }

        // Voegt nieuwe bestanden toe aan de bestaande selectie. Zou de
        // TOTALE selectie (bestaand + nieuw) het maximum overschrijden, dan
        // wordt de hele toevoeging geweigerd (niets wordt gedeeltelijk
        // toegevoegd) en verschijnt een duidelijke foutmelding, zodat het
        // voor de gebruiker nooit onduidelijk is welke bestanden klaarstaan.
        function addFiles(newFileList) {
            const existingKeys = new Set(selectedFiles.map(fileKey));
            const incoming = Array.from(newFileList).filter(function (file) {
                return !existingKeys.has(fileKey(file));
            });

            if (incoming.length > 0) {
                const total = selectedFiles.length + incoming.length;

                if (total > maxFiles) {
                    showWarning(
                        'Je kunt maximaal ' + maxFiles + ' bestanden per keer uploaden'
                        + (selectedFiles.length > 0
                            ? ' (je hebt er al ' + selectedFiles.length + ' geselecteerd)'
                            : '')
                        + '. Probeer het opnieuw met minder bestanden.'
                    );
                } else {
                    selectedFiles = selectedFiles.concat(incoming);
                    showWarning('');
                }
            }

            // Altijd de input opnieuw synchroniseren met onze eigen, leidende
            // lijst: dit herstelt een afgewezen (te grote) native selectie.
            setInputFiles(selectedFiles);
            render();
        }

        function clearFiles() {
            selectedFiles = [];
            setInputFiles(selectedFiles);
            showWarning('');
            render();
        }

        dropZone.addEventListener('click', function (event) {
            if (event.target === input) {
                return;
            }
            if (isAtMax()) {
                showWarning(maxReachedMessage());
                return;
            }
            input.click();
        });

        dropZone.addEventListener('keydown', function (event) {
            if (event.target === dropZone && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                if (isAtMax()) {
                    showWarning(maxReachedMessage());
                    return;
                }
                input.click();
            }
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function (event) {
                // Voorkom dat de klik doorborrelt naar de dropzone, die anders
                // meteen de bestandskiezer weer zou openen.
                event.stopPropagation();
                clearFiles();
            });
        }

        input.addEventListener('change', function () {
            if (input.files && input.files.length > 0) {
                addFiles(input.files);
            }
        });

        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropZone.addEventListener(eventName, function (event) {
                event.preventDefault();
                // Geen visuele "hier droppen"-highlight tonen als droppen
                // toch niets kan toevoegen; dat zou een leeg gebaar suggereren.
                if (!isAtMax()) {
                    dropZone.classList.add('is-dragover');
                }
            });
        });

        ['dragleave', 'dragend'].forEach(function (eventName) {
            dropZone.addEventListener(eventName, function () {
                dropZone.classList.remove('is-dragover');
            });
        });

        dropZone.addEventListener('drop', function (event) {
            event.preventDefault();
            dropZone.classList.remove('is-dragover');

            if (isAtMax()) {
                showWarning(maxReachedMessage());
                return;
            }

            const files = event.dataTransfer && event.dataTransfer.files;
            if (files && files.length > 0) {
                addFiles(files);
            }
        });
    });

    // Voortgangsbalk bij uploaden: het formulier wordt via XMLHttpRequest
    // verstuurd (in plaats van een normale form-post) omdat alleen XHR
    // upload-progress-events geeft. De server-respons van upload.php is en
    // blijft een complete HTML-pagina; die vervangt hier gewoon het hele
    // document, zodat upload.php zelf niets van een JSON-API hoeft te weten.
    document.querySelectorAll('.upload-form').forEach(function (form) {
        const progressWrap = form.querySelector('[data-upload-progress]');
        const progressBar = form.querySelector('[data-upload-progress-bar]');
        const progressLabel = form.querySelector('[data-upload-progress-label]');
        const submitBtn = form.querySelector('button[type="submit"]');

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();

            if (submitBtn) {
                submitBtn.disabled = true;
            }
            if (progressWrap) {
                progressWrap.hidden = false;
            }
            if (progressBar) {
                progressBar.style.width = '0%';
            }
            if (progressLabel) {
                progressLabel.textContent = '0%';
            }

            xhr.upload.addEventListener('progress', function (progressEvent) {
                if (!progressEvent.lengthComputable) {
                    return;
                }
                const percent = Math.round((progressEvent.loaded / progressEvent.total) * 100);
                if (progressBar) {
                    progressBar.style.width = percent + '%';
                }
                if (progressLabel) {
                    progressLabel.textContent = percent + '%';
                }
            });

            xhr.addEventListener('load', function () {
                // Vervang de volledige pagina door de statuspagina die
                // upload.php net als bij een normale form-post rendert
                // (succes- of foutmelding, inclusief eventuele HTTP-foutcode).
                document.open();
                document.write(xhr.responseText);
                document.close();
            });

            xhr.addEventListener('error', function () {
                if (progressLabel) {
                    progressLabel.textContent = 'Mislukt';
                }
                showWarningOnUploadError(form, 'Uploaden mislukt. Controleer je internetverbinding en probeer het opnieuw.');
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
            });

            xhr.open('POST', form.getAttribute('action'));
            xhr.send(formData);
        });
    });

    function showWarningOnUploadError(form, message) {
        const warning = form.querySelector('[data-file-drop-warning]');
        if (warning) {
            warning.textContent = message;
            warning.hidden = false;
        } else {
            window.alert(message);
        }
    }
});
