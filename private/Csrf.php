<?php

declare(strict_types=1);

/**
 * CSRF-bescherming via het "double-submit cookie"-patroon: géén PHP-sessies
 * nodig (MeoDrive is verder helemaal stateless per request), dus dit blijft
 * consistent met de rest van de architectuur.
 *
 * Werking: bij het tonen van een formulier zet de server een willekeurige
 * token zowel in een cookie als in een verborgen formulierveld. Een
 * kwaadwillende site kan een gebruiker wél laten "posten" naar MeoDrive
 * (de browser stuurt de cookie automatisch mee), maar kan de bijbehorende
 * verborgen veldwaarde niet kennen — die staat alleen in de HTML die enkel
 * via hetzelfde origin valt te lezen. Komen cookie en formulierveld niet
 * overeen, dan wordt het verzoek geweigerd.
 */

const CSRF_COOKIE_NAME = 'meodrive_csrf';

/**
 * Geef de huidige CSRF-token terug, of genereer en zet er één als er nog
 * geen (geldige) token bekend is.
 */
function csrfToken(): string
{
    $existing = $_COOKIE[CSRF_COOKIE_NAME] ?? '';

    if (is_string($existing) && preg_match('/^[0-9a-f]{40}$/', $existing)) {
        return $existing;
    }

    $token = bin2hex(random_bytes(20));

    setcookie(CSRF_COOKIE_NAME, $token, [
        'expires'  => 0, // sessiecookie: verdwijnt zodra de browser sluit
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // setcookie() werkt pas bij het VOLGENDE request; binnen deze request-
    // cyclus (het formulier dat we nu renderen) moet $_COOKIE al de nieuwe
    // waarde bevatten, anders zou een gebruiker die zijn formulier binnen
    // dezelfde seconde verstuurt, ten onrechte worden afgewezen.
    $_COOKIE[CSRF_COOKIE_NAME] = $token;

    return $token;
}

/**
 * Kant-en-klaar verborgen formulierveld met de huidige CSRF-token. Te
 * gebruiken binnen elk <form method="post"> dat hier beveiligd moet worden.
 */
function csrfHiddenField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Pure vergelijkingsfunctie (geen superglobals), zodat de kernlogica zonder
 * cookies/POST-data getest kan worden — zie tests/Unit/.
 */
function csrfTokensMatch(string $cookieToken, string $formToken): bool
{
    if ($cookieToken === '' || $formToken === '') {
        return false;
    }

    return hash_equals($cookieToken, $formToken);
}

/**
 * Valideer het huidige POST-verzoek tegen de CSRF-cookie.
 */
function csrfValidateRequest(): bool
{
    $cookieToken = $_COOKIE[CSRF_COOKIE_NAME] ?? '';
    $formToken   = $_POST['csrf_token'] ?? '';

    return csrfTokensMatch(
        is_string($cookieToken) ? $cookieToken : '',
        is_string($formToken) ? $formToken : ''
    );
}
