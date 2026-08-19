<?php

/**
 * Rate-limit security boundary tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\RateLimit;

use WPNerve\Security\RateLimit\ClientAddress;
use WPNerve\Security\RateLimit\RateLimiter;
use WPNerve\Security\RateLimit\WpdbRepository;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testInstallSchemaCreatesRateLimitTable(): void
    {
        WpdbRepository::installSchema();

        self::assertCount(1, WPState::$schemaCalls);
        self::assertStringContainsString('wp_nerve_rate_limits', WPState::$schemaCalls[0]);
        self::assertStringContainsString('UNIQUE KEY rate_window', WPState::$schemaCalls[0]);
    }

    public function testMcpBudgetUsesDeterministicWindow(): void
    {
        WPState::$wpdb->queryResults = array(0, 1);
        WPState::$wpdb->rows         = array(array('request_count' => '1'));

        $limiter  = new RateLimiter(new WpdbRepository(), static fn (): int => 125);
        $decision = $limiter->consume('mcp', '203.0.113.10');

        self::assertTrue($decision->available);
        self::assertTrue($decision->allowed);
        self::assertSame(120, $decision->limit);
        self::assertSame(119, $decision->remaining);
        self::assertSame(180, $decision->resetAt);
        self::assertSame(55, $decision->retryAfter(125));
    }

    public function testEndpointBudgetsAreIndependent(): void
    {
        WPState::$wpdb->queryResults = array(0, 1);
        WPState::$wpdb->rows         = array(array('request_count' => '1'));

        $limiter  = new RateLimiter(new WpdbRepository(), static fn (): int => 125);
        $decision = $limiter->consume('oauth_register', '203.0.113.10');

        self::assertTrue($decision->allowed);
        self::assertSame(10, $decision->limit);
        self::assertSame(9, $decision->remaining);
        self::assertSame(3600, $decision->resetAt);
    }

    public function testExhaustedWindowIsRejected(): void
    {
        WPState::$wpdb->queryResults = array(0, 0);
        WPState::$wpdb->rows         = array(array('request_count' => '120'));

        $limiter  = new RateLimiter(new WpdbRepository(), static fn (): int => 125);
        $decision = $limiter->consume('mcp', '203.0.113.10');

        self::assertTrue($decision->available);
        self::assertFalse($decision->allowed);
        self::assertSame(0, $decision->remaining);
    }

    public function testDatabaseFailureFailsClosed(): void
    {
        WPState::$wpdb->queryResults = array(false);

        $limiter  = new RateLimiter(new WpdbRepository(), static fn (): int => 125);
        $decision = $limiter->consume('mcp', '203.0.113.10');

        self::assertFalse($decision->available);
        self::assertFalse($decision->allowed);
        self::assertSame(120, $decision->limit);
        self::assertSame(180, $decision->resetAt);
    }

    public function testRepositoryNeverPersistsRawNetworkSubject(): void
    {
        WPState::$wpdb->queryResults = array(0, 1);
        WPState::$wpdb->rows         = array(array('request_count' => '1'));

        $repository = new WpdbRepository();
        $result     = $repository->consume('mcp', '203.0.113.99', 120, 120, 240);
        $sql        = implode("\n", array_merge(WPState::$wpdb->queries, array(WPState::$wpdb->lastQuery)));

        self::assertNotNull($result);
        self::assertStringNotContainsString('203.0.113.99', $sql);
        self::assertStringContainsString(hash('sha256', '203.0.113.99'), WPState::$wpdb->lastQuery);
    }

    public function testClientAddressIgnoresUntrustedForwardingHeaders(): void
    {
        $_SERVER['REMOTE_ADDR']          = '198.51.100.20';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.200';

        try {
            self::assertSame('198.51.100.20', (new ClientAddress())->resolve());
        } finally {
            unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        }
    }

    public function testClientAddressFallsBackClosedForInvalidPeer(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';

        try {
            self::assertSame('unknown', (new ClientAddress())->resolve());
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }
}
