<?php

declare(strict_types=1);

namespace MeoDrive\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testMatchingTokensAreAccepted(): void
    {
        $token = bin2hex(random_bytes(20));

        self::assertTrue(csrfTokensMatch($token, $token));
    }

    public function testDifferentTokensAreRejected(): void
    {
        self::assertFalse(csrfTokensMatch(bin2hex(random_bytes(20)), bin2hex(random_bytes(20))));
    }

    public function testEmptyCookieTokenIsRejected(): void
    {
        self::assertFalse(csrfTokensMatch('', bin2hex(random_bytes(20))));
    }

    public function testEmptyFormTokenIsRejected(): void
    {
        self::assertFalse(csrfTokensMatch(bin2hex(random_bytes(20)), ''));
    }

    public function testBothEmptyIsRejected(): void
    {
        self::assertFalse(csrfTokensMatch('', ''));
    }
}
