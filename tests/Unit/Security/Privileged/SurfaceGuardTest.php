<?php

/**
 * Privileged surface guard tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\Privileged;

use stdClass;
use WPNerve\Security\Privileged\SurfaceGuard;
use WPNerve\Tests\Unit\TestCase;

final class SurfaceGuardTest extends TestCase
{
    public function testSafeOptionAllowlistIsConservative(): void
    {
        $guard = new SurfaceGuard();

        self::assertTrue($guard->canReadOption('blogname'));
        self::assertTrue($guard->canWriteOption('blogdescription'));
        self::assertFalse($guard->canReadOption('siteurl'));
        self::assertFalse($guard->canWriteOption('wp_nerve_enabled_risk_classes'));
        self::assertFalse($guard->canReadOption('vendor_api_key'));
    }

    public function testProtectedOptionCannotBeReenabledByAllowlistFilter(): void
    {
        add_filter('wp_nerve_allowed_option_keys', static fn (array $keys): array => array_merge($keys, array('siteurl')));
        add_filter('wp_nerve_writable_option_keys', static fn (array $keys): array => array_merge($keys, array('siteurl')));

        $guard = new SurfaceGuard();

        self::assertFalse($guard->canReadOption('siteurl'));
        self::assertFalse($guard->canWriteOption('siteurl'));
    }

    public function testSafeValueRejectsObjectsDepthAndOversizedStrings(): void
    {
        $guard = new SurfaceGuard();

        self::assertTrue($guard->valueIsSafe(array('a' => array(1, true, 'ok'))));
        self::assertFalse($guard->valueIsSafe(new stdClass()));
        self::assertFalse($guard->valueIsSafe(str_repeat('x', 65537)));
        self::assertFalse($guard->valueIsSafe(array(array(array(array(array(array('too-deep'))))))));
    }

    public function testLogRedactionRemovesCommonCredentialForms(): void
    {
        $guard = new SurfaceGuard();
        $log = implode("\n", array(
            'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature',
            'password=super-secret-password',
            'api_key:abc123',
            'client_secret="very-secret"',
            'request https://user:pass@example.test/private',
        ));

        $redacted = $guard->redactLog($log);

        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $redacted);
        self::assertStringNotContainsString('super-secret-password', $redacted);
        self::assertStringNotContainsString('abc123', $redacted);
        self::assertStringNotContainsString('very-secret', $redacted);
        self::assertStringNotContainsString('user:pass', $redacted);
        self::assertGreaterThanOrEqual(5, substr_count($redacted, '[REDACTED]'));
    }
}
