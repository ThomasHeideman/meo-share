<?php

declare(strict_types=1);

/**
 * Pure tijd-/vensterberekeningen voor rate limiting en lockouts. Los van
 * RateLimiter.php (die de database aanspreekt) gehouden, zodat deze
 * berekeningen zonder database getest kunnen worden — zie tests/Unit/.
 *
 * Elke functie accepteert een optionele $now, zodat tests een vast moment
 * kunnen injecteren in plaats van te moeten wachten op de systeemklok.
 */

/**
 * Is het tijdvenster van een rate-limit-teller (bijv. "5 uploads per
 * 15 minuten") verstreken, gerekend vanaf het moment dat de teller startte?
 */
function isRateLimitWindowExpired(
    string $windowStartedAt,
    int $windowSeconds,
    ?DateTimeImmutable $now = null
): bool {
    $now ??= new DateTimeImmutable('now');
    $windowStart = new DateTimeImmutable($windowStartedAt);

    return ($now->getTimestamp() - $windowStart->getTimestamp()) >= $windowSeconds;
}

/**
 * Bereken het tijdstip waarop een lockout (bijv. na te veel mislukte
 * verificatiepogingen) weer wordt opgeheven.
 */
function nextLockedUntil(int $lockoutSeconds, ?DateTimeImmutable $now = null): string
{
    $now ??= new DateTimeImmutable('now');

    return $now->modify("+{$lockoutSeconds} seconds")->format('Y-m-d H:i:s');
}

/**
 * Is een upload op dit moment vergrendeld op basis van zijn `locked_until`-
 * waarde? Een lege/NULL-waarde betekent: niet vergrendeld.
 */
function isLockedOut(?string $lockedUntil, ?DateTimeImmutable $now = null): bool
{
    if ($lockedUntil === null || $lockedUntil === '') {
        return false;
    }

    $now ??= new DateTimeImmutable('now');

    return $now < new DateTimeImmutable($lockedUntil);
}

/**
 * Hoeveel volle minuten resteren er nog tot een vergrendeling wordt
 * opgeheven? Handig om in een foutmelding te tonen ("probeer het over
 * 12 minuten opnieuw"). Rondt naar boven af, zodat "0 minuten" nooit wordt
 * getoond terwijl de lock nog actief is.
 */
function minutesUntil(string $target, ?DateTimeImmutable $now = null): int
{
    $now ??= new DateTimeImmutable('now');
    $diffSeconds = (new DateTimeImmutable($target))->getTimestamp() - $now->getTimestamp();

    return max(0, (int) ceil($diffSeconds / 60));
}
