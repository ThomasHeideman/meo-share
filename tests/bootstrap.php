<?php

declare(strict_types=1);

/**
 * PHPUnit-bootstrap: laadt alleen de pure, herbruikbare helperbestanden uit
 * private/ (géén van de top-level scripts zoals upload.php/verify.php, die
 * bij het includen direct $_GET/$_POST/de database zouden aanspreken).
 *
 * Nieuwe helperbestanden die in latere fases worden toegevoegd (bijv.
 * RateLimiter.php, Csrf.php, Logger.php) moeten hier ook worden toegevoegd
 * zodra ze pure, testbare functies bevatten.
 */

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($vendorAutoload)) {
    require_once $vendorAutoload;
}

require_once dirname(__DIR__) . '/private/validation_helpers.php';
require_once dirname(__DIR__) . '/private/rate_limit_helpers.php';
require_once dirname(__DIR__) . '/private/Csrf.php';
require_once dirname(__DIR__) . '/private/Logger.php';
