-- MeoDrive — Databaseschema
--
-- Dit bestand legt de structuur van de database `meo_share` vast. Voer dit bestand uit op een
-- lege database om de volledige structuur in één keer aan te maken:
--
--   mysql -u root -p meo_share < schema.sql
--
-- Bij een bestaande database die nog niet alle kolommen heeft, kun je de
-- ALTER TABLE-commando's onderaan losstaand uitvoeren (ze zijn idempotent
-- via `ADD COLUMN IF NOT EXISTS` waar de MySQL/MariaDB-versie dat ondersteunt).

CREATE DATABASE IF NOT EXISTS meo_share
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE meo_share;

-- ---------------------------------------------------------------------------
-- uploads: één rij per geüploade (of gezipte) upload.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uploads (
    id                     CHAR(36)     NOT NULL,
    file_name              VARCHAR(255) NOT NULL,
    file_path              VARCHAR(255) NOT NULL,
    encryption_iv          CHAR(32)     NULL,
    encryption_key         CHAR(32)     NULL,
    cancel_token           CHAR(64)     NOT NULL,
    email                  VARCHAR(255) NOT NULL,
    email_receiver         VARCHAR(255) NOT NULL,
    verification_code      CHAR(6)      NOT NULL,
    verification_attempts  INT          NOT NULL DEFAULT 0,
    locked_until           DATETIME     NULL,
    is_verified            TINYINT(1)   NOT NULL DEFAULT 0,
    expires_at             DATETIME     NOT NULL,
    created_at             TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UUID's zijn hier bewust géén auto-increment: een oplopend nummer zou het
-- voor iedereen mogelijk maken om simpelweg id's op te sommen (enumeratie)
-- om bij andermans upload te komen. Een willekeurige UUID v4 is dat niet.
--
-- verification_attempts + locked_until: samen voorkomen ze dat de
-- 6-cijferige verificatiecode onbeperkt gegokt kan worden op verify.php.

-- ---------------------------------------------------------------------------
-- rate_limits: generieke "max N pogingen per tijdvenster"-tellers.
-- Herbruikt voor zowel upload-rate-limiting per IP-adres als de cooldown op
-- de "code opnieuw versturen"-knop. Zie private/RateLimiter.php.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
    scope             VARCHAR(50)  NOT NULL,
    identifier        VARCHAR(255) NOT NULL,
    attempt_count     INT          NOT NULL DEFAULT 0,
    window_started_at DATETIME     NOT NULL,
    PRIMARY KEY (scope, identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Migratie-commando's voor een reeds bestaande database (draai deze los als
-- schema.sql niet vanaf een lege database is uitgevoerd):
--
-- ALTER TABLE uploads ADD COLUMN verification_attempts INT NOT NULL DEFAULT 0;
-- ALTER TABLE uploads ADD COLUMN locked_until DATETIME NULL;
-- ALTER TABLE uploads ADD COLUMN cancel_token CHAR(64) NOT NULL DEFAULT '';
