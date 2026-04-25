<?php

declare(strict_types=1);

namespace Cleanmenu\Tests;

use PHPUnit\Framework\TestCase;
use Csrf;

/**
 * Covers lib/Csrf.php — unified CSRF helper used by classic POST endpoints.
 *
 * Bearer-authenticated API (api/v1/*) does not rely on Csrf::; browser-driven
 * POST handlers do. This suite locks down the three surfaces of the helper:
 * verify() for the comparison, submitted() for the transport resolution, and
 * token() for the per-session issuance.
 */
final class CsrfGuardTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('Csrf', false)) {
            require_once __DIR__ . '/../lib/Csrf.php';
        }

        $_SESSION = [];
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';
        $_SERVER['CONTENT_TYPE'] = '';
        $_SERVER['HTTP_ACCEPT'] = '';
    }

    public function test_verify_rejects_when_session_token_missing(): void
    {
        $_SESSION['csrf_token'] = '';
        self::assertFalse(Csrf::verify('anything'));
    }

    public function test_verify_rejects_when_submitted_token_missing(): void
    {
        $_SESSION['csrf_token'] = 'expected';
        self::assertFalse(Csrf::verify(''));
        self::assertFalse(Csrf::verify(null));
    }

    public function test_verify_rejects_on_mismatch(): void
    {
        $_SESSION['csrf_token'] = 'expected';
        self::assertFalse(Csrf::verify('wrong'));
    }

    public function test_verify_accepts_matching_token(): void
    {
        $_SESSION['csrf_token'] = 'expected-token-value';
        self::assertTrue(Csrf::verify('expected-token-value'));
    }

    public function test_submitted_prefers_x_csrf_token_header(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'from-header';
        $_POST['csrf_token'] = 'from-body';
        self::assertSame('from-header', Csrf::submitted());
    }

    public function test_submitted_falls_back_to_post_body(): void
    {
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $_POST['csrf_token'] = 'from-body';
        self::assertSame('from-body', Csrf::submitted());
    }

    public function test_submitted_returns_null_when_nothing_present(): void
    {
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        self::assertNull(Csrf::submitted());
    }

    public function test_token_returns_empty_when_no_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::markTestSkipped('Requires inactive session state; session was started elsewhere.');
        }
        self::assertSame('', Csrf::token());
    }
}
