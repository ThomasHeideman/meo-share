<?php

declare(strict_types=1);

require_once __DIR__ . '/mailer_autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Verzendt e-mails via Mailtrap SMTP (PHPMailer).
 */
class MailService
{
    private const COLOR_NAVY        = '#12283f';
    private const COLOR_BLUE_LIGHT  = '#eaf6fd';
    private const COLOR_BLUE_DASHED = '#9ed8f2';
    private const COLOR_TEXT_MUTED  = '#5b6b78';
    private const COLOR_CTA_BLUE    = '#2fa6da';

    private string $fromEmail;
    private string $fromName;
    private string $appUrl;

    /** @var array{host: string, port: int, username: string, password: string, encryption: string} */
    private array $smtp;

    public function __construct()
    {
        $credentialsFile = __DIR__ . '/credentials.php';

        if (!is_readable($credentialsFile)) {
            throw new RuntimeException('credentials.php ontbreekt.');
        }

        /** @var array<string, mixed> $config */
        $config = require $credentialsFile;

        $this->fromEmail = (string) ($config['mail_from'] ?? 'noreply@wijzijnmeo.nl');
        $this->fromName  = (string) ($config['mail_from_name'] ?? 'MeoDrive');
        $this->appUrl    = rtrim((string) ($config['app_url'] ?? 'http://localhost/meo-share/public_html'), '/');

        if (!isset($config['smtp']) || !is_array($config['smtp'])) {
            throw new RuntimeException('SMTP-configuratie ontbreekt in credentials.php.');
        }

        $smtp = $config['smtp'];

        foreach (['host', 'port', 'username', 'password', 'encryption'] as $key) {
            if (!isset($smtp[$key]) || (is_string($smtp[$key]) && trim($smtp[$key]) === '')) {
                throw new RuntimeException("SMTP-instelling '{$key}' ontbreekt in credentials.php.");
            }
        }

        $username = trim((string) $smtp['username']);
        $password = trim((string) $smtp['password']);

        if (str_starts_with($username, 'VUL_')) {
            throw new RuntimeException(
                'SMTP-username bevat nog een placeholder. Vul je echte Mailtrap-gegevens in credentials.php in en sla het bestand op.'
            );
        }

        $this->smtp = [
            'host'       => trim((string) $smtp['host']),
            'port'       => (int) $smtp['port'],
            'username'   => $username,
            'password'   => $password,
            'encryption' => trim((string) $smtp['encryption']),
        ];
    }

    /**
     * Mail 1: verificatiecode naar de uploader.
     */
    public function sendVerificationCode(
        string $recipientEmail,
        string $verificationCode,
        string $uploadId,
        string $fileName
    ): void {
        $verifyUrl = $this->appUrl . '/verify.php?id=' . urlencode($uploadId);
        $subject   = 'Uw verificatiecode voor MeoDrive';

        $body = $this->wrapTemplate(
            'Verificatiecode',
            '<p>Beste gebruiker,</p>'
            . '<p>U heeft een bestand geüpload via <strong>MeoDrive</strong>.</p>'
            . '<p><strong>Bestand:</strong> ' . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Gebruik onderstaande 6-cijferige code om uw upload te verifiëren:</p>'
            . '<p style="' . $this->codeBoxStyle() . '">'
            . htmlspecialchars($verificationCode, ENT_QUOTES, 'UTF-8')
            . '</p>'
            . '<p>Ga naar de verificatiepagina en voer de code in:</p>'
            . '<p style="text-align:center;">' . $this->buttonHtml($verifyUrl, 'Verificatie starten') . '</p>'
            . '<p>Deze code is 24 uur geldig.</p>'
            . '<p>Met vriendelijke groet,<br>MeoDrive</p>'
        );

        $this->dispatch($recipientEmail, $subject, $body);
    }

    /**
     * Mail 2: downloadlink naar de ontvanger.
     */
    public function sendDownloadLink(
        string $recipientEmail,
        string $uploadId,
        string $encryptionKey,
        string $fileName
    ): void {
        $receiveUrl = $this->appUrl . '/receive.php?id=' . urlencode($uploadId)
            . '&key=' . urlencode($encryptionKey);
        $subject = 'Er staat een beveiligd bestand voor u klaar';

        $body = $this->wrapTemplate(
            'Beveiligd bestand ontvangen',
            '<p>Beste ontvanger,</p>'
            . '<p>Er staat een beveiligd bestand voor u klaar via <strong>MeoDrive</strong>.</p>'
            . '<p><strong>Bestand:</strong> ' . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Klik op onderstaande knop om het bestand te ontvangen. Let op: het bestand kan '
            . '<strong>slechts één keer</strong> worden gedownload.</p>'
            . '<p style="text-align:center;">' . $this->buttonHtml($receiveUrl, 'Bestand ontvangen') . '</p>'
            . '<p>Of kopieer deze link in uw browser:<br>'
            . '<small style="color:' . self::COLOR_TEXT_MUTED . ';">' . htmlspecialchars($receiveUrl, ENT_QUOTES, 'UTF-8') . '</small></p>'
            . '<p>Met vriendelijke groet,<br>MeoDrive</p>'
        );

        $this->dispatch($recipientEmail, $subject, $body);
    }

    /**
     * Mail 3: bevestiging aan de uploader zodra de ontvanger het bestand
     * daadwerkelijk heeft gedownload (en het dus, via download-and-destroy,
     * al van de server is verwijderd).
     */
    public function sendDownloadConfirmation(
        string $recipientEmail,
        string $fileName
    ): void {
        $subject = 'Uw bestand is gedownload — MeoDrive';

        $body = $this->wrapTemplate(
            'Bestand gedownload',
            '<p>Beste gebruiker,</p>'
            . '<p>Het bestand dat u via <strong>MeoDrive</strong> heeft verstuurd, is zojuist '
            . 'gedownload door de ontvanger:</p>'
            . '<p><strong>Bestand:</strong> ' . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Zoals bij elke MeoDrive-verzending is het bestand direct na deze ene download '
            . '<strong>permanent van de server verwijderd</strong>. De downloadlink werkt dus niet meer.</p>'
            . '<p>Met vriendelijke groet,<br>MeoDrive</p>'
        );

        $this->dispatch($recipientEmail, $subject, $body);
    }

    /**
     * Alertmail naar de MEO-organisatie zelf wanneer de opschoon-cronjob
     * (private/cron.php) op één of meer uploads is misgelopen. Zonder dit
     * signaal merkt niemand een mislukte cronrun op, want het script draait
     * onbeheerd buiten de browser.
     */
    public function sendCronFailureAlert(string $recipientEmail, string $details): void
    {
        $subject = '[MeoDrive] Opschoon-cronjob gaf een fout';

        $body = $this->wrapTemplate(
            'Cronjob mislukt',
            '<p>Beste beheerder,</p>'
            . '<p>De opschoon-cronjob (<code>private/cron.php</code>) is net gedraaid, maar gaf '
            . 'een fout of kon niet alle verlopen uploads opruimen.</p>'
            . '<p><strong>Details:</strong><br>' . nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<p>Controleer <code>private/cron.php</code> en de serverlogs voor meer informatie.</p>'
            . '<p>Met vriendelijke groet,<br>MeoDrive</p>'
        );

        $this->dispatch($recipientEmail, $subject, $body);
    }

    /**
     * Bouw een HTML-layout rond de inhoud, in lijn met de huisstijl van
     * de MeoDrive-pagina's (kleuren, lettertypen en knopvorm).
     */
    private function wrapTemplate(string $heading, string $content): string
    {
        $fontStack = "'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
        $bodyFontStack = "'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

        return '<!DOCTYPE html>'
            . '<html lang="nl"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@100..900&family=Ubuntu:wght@300;400;500;700&display=swap" rel="stylesheet">'
            . '<title>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</title>'
            . '</head><body style="font-family:' . $bodyFontStack . ';line-height:1.6;color:' . self::COLOR_NAVY . ';'
            . 'max-width:600px;margin:0 auto;padding:1.5rem;background:#ffffff;">'
            . '<div style="border-bottom:3px solid ' . self::COLOR_CTA_BLUE . ';padding-bottom:1rem;margin-bottom:1.5rem;">'
            . '<h1 style="margin:0;font-size:1.5rem;font-weight:800;font-family:' . $fontStack . ';color:' . self::COLOR_NAVY . ';">MeoDrive</h1>'
            . '</div>'
            . '<div style="font-family:' . $bodyFontStack . ';">' . $content . '</div>'
            . '<hr style="margin:2rem 0;border:none;border-top:1px solid ' . self::COLOR_BLUE_DASHED . ';">'
            . '<p style="font-size:0.8rem;color:' . self::COLOR_TEXT_MUTED . ';font-family:' . $bodyFontStack . ';">'
            . 'Dit is een automatisch bericht van MeoDrive. Reageer niet op deze e-mail.</p>'
            . '</body></html>';
    }

    /**
     * Bouw een knop die de circelvormige CTA-knop van de website benadert.
     * E-mailclients ondersteunen geen ::after-pseudo-elementen of hover-animaties,
     * daarom wordt hier direct een afgeronde pil-knop in de site-kleuren gebruikt.
     */
    private function buttonHtml(string $url, string $label): string
    {
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;padding:0.9rem 2rem;background:' . self::COLOR_CTA_BLUE . ';'
            . 'color:' . self::COLOR_NAVY . ';text-decoration:none;border-radius:999px;'
            . "font-family:'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;"
            . 'font-size:17px;font-weight:700;text-transform:uppercase;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</a>';
    }

    /**
     * Stijl voor het uitgelichte codeblok, gebaseerd op de ".status-message.is-success"
     * kaartstijl die op de website wordt gebruikt.
     */
    private function codeBoxStyle(): string
    {
        return 'font-size:2rem;font-weight:700;letter-spacing:0.3em;text-align:center;'
            . 'margin:1.5rem 0;padding:1rem;border-radius:0.6rem;'
            . 'background:' . self::COLOR_BLUE_LIGHT . ';border:1px solid ' . self::COLOR_BLUE_DASHED . ';'
            . 'color:' . self::COLOR_NAVY . ';'
            . "font-family:'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
    }

    /**
     * Verstuur de mail via Mailtrap SMTP.
     */
    private function dispatch(string $recipientEmail, string $subject, string $htmlBody): void
    {
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException(
                'PHPMailer niet gevonden. Voer "composer install" uit in de projectmap.'
            );
        }

        $mail = new PHPMailer(true);

        try {
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host       = $this->smtp['host'];
            $mail->Port       = $this->smtp['port'];
            $mail->SMTPAuth   = true;
            $mail->AuthType   = 'LOGIN';
            $mail->Username   = $this->smtp['username'];
            $mail->Password   = $this->smtp['password'];
            $mail->SMTPSecure = $this->resolveEncryption($this->smtp['encryption']);
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => true,
                    'verify_peer_name'  => true,
                    'allow_self_signed' => false,
                ],
            ];

            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($recipientEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

            $mail->send();
        } catch (MailerException $e) {
            throw new RuntimeException('E-mail verzenden mislukt: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Zet de encryptie-instelling om naar een PHPMailer-constante.
     */
    private function resolveEncryption(string $encryption): string
    {
        return match (strtolower($encryption)) {
            'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
            'ssl'             => PHPMailer::ENCRYPTION_SMTPS,
            default           => throw new RuntimeException(
                "Ongeldige SMTP-encryptie '{$encryption}'. Gebruik 'tls' of 'ssl'."
            ),
        };
    }
}
