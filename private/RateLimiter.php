<?php

declare(strict_types=1);

require_once __DIR__ . '/rate_limit_helpers.php';

/**
 * Generieke, database-gebaseerde rate limiter, herbruikbaar voor elk soort
 * "max N pogingen per tijdvenster"-regel (upload per IP, code opnieuw
 * versturen, etc.). Slaat tellers op in de tabel `rate_limits`
 * (scope + identifier vormen samen de primary key).
 *
 * Bewust géén atomaire "INSERT ... ON DUPLICATE KEY UPDATE" gebruikt: bij de
 * schaal van dit platform (één interne tool, geen publieke high-traffic
 * dienst) is een select-dan-schrijven-aanpak eenvoudiger te lezen en het
 * theoretische race-conditionrisico (twee gelijktijdige requests van
 * dezelfde identifier binnen dezelfde milliseconde) is hier acceptabel.
 */
final class RateLimiter
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Registreert een poging voor $scope + $identifier en geeft terug of
     * de limiet daarmee is overschreden (true = geweigerd).
     */
    public function tooManyAttempts(
        string $scope,
        string $identifier,
        int $maxAttempts,
        int $windowSeconds
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT attempt_count, window_started_at
             FROM rate_limits
             WHERE scope = :scope AND identifier = :identifier
             LIMIT 1'
        );
        $stmt->execute([':scope' => $scope, ':identifier' => $identifier]);
        $row = $stmt->fetch();

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        if ($row === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO rate_limits (scope, identifier, attempt_count, window_started_at)
                 VALUES (:scope, :identifier, 1, :now)'
            );
            $insert->execute([':scope' => $scope, ':identifier' => $identifier, ':now' => $now]);

            return false;
        }

        if (isRateLimitWindowExpired($row['window_started_at'], $windowSeconds)) {
            $reset = $this->pdo->prepare(
                'UPDATE rate_limits
                 SET attempt_count = 1, window_started_at = :now
                 WHERE scope = :scope AND identifier = :identifier'
            );
            $reset->execute([':now' => $now, ':scope' => $scope, ':identifier' => $identifier]);

            return false;
        }

        $newCount = (int) $row['attempt_count'] + 1;
        $update = $this->pdo->prepare(
            'UPDATE rate_limits
             SET attempt_count = :count
             WHERE scope = :scope AND identifier = :identifier'
        );
        $update->execute([':count' => $newCount, ':scope' => $scope, ':identifier' => $identifier]);

        return $newCount > $maxAttempts;
    }
}
