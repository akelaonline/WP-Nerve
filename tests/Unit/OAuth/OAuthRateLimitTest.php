<?php

/**
 * OAuth endpoint rate-limit integration tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\OAuth;

use WP_REST_Request;
use WPNerve\OAuth\OAuthServer;
use WPNerve\OAuth\OAuthStore;
use WPNerve\Security\RateLimit\ClientAddress;
use WPNerve\Security\RateLimit\RateLimiter;
use WPNerve\Security\RateLimit\WpdbRepository;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class OAuthRateLimitTest extends TestCase
{
    public function testRegistrationBudgetReturns429WithRetryHeaders(): void
    {
        WPState::$wpdb->queryResults = array(0, 0);
        WPState::$wpdb->rows         = array(array('request_count' => '10'));
        $_SERVER['REMOTE_ADDR']      = '198.51.100.30';

        $server   = $this->server();
        $request  = new WP_REST_Request('POST', '/wp-nerve/v1/oauth/register');
        $response = $server->registerClient($request);

        self::assertSame(429, $response->get_status());
        self::assertSame('slow_down', $response->get_data()['error']);
        self::assertSame('10', $response->get_headers()['x-ratelimit-limit']);
        self::assertSame('0', $response->get_headers()['x-ratelimit-remaining']);
        self::assertSame('3475', $response->get_headers()['retry-after']);
    }

    public function testTokenBudgetStorageFailureReturns503(): void
    {
        WPState::$wpdb->queryResults = array(false);
        $_SERVER['REMOTE_ADDR']      = '198.51.100.31';

        $server   = $this->server();
        $request  = new WP_REST_Request('POST', '/wp-nerve/v1/oauth/token');
        $response = $server->token($request);

        self::assertSame(503, $response->get_status());
        self::assertSame('temporarily_unavailable', $response->get_data()['error']);
        self::assertSame('no-store', $response->get_headers()['cache-control']);
    }

    public function testRevocationHasIndependentBudget(): void
    {
        WPState::$wpdb->queryResults = array(0, 0);
        WPState::$wpdb->rows         = array(array('request_count' => '30'));
        $_SERVER['REMOTE_ADDR']      = '198.51.100.32';

        $server  = $this->server();
        $request = new WP_REST_Request('POST', '/wp-nerve/v1/oauth/revoke');
        $request->set_param('client_id', 'client');
        $request->set_param('token', 'token');

        $response = $server->revoke($request);

        self::assertSame(429, $response->get_status());
        self::assertSame('slow_down', $response->get_data()['error']);
        self::assertSame('30', $response->get_headers()['x-ratelimit-limit']);
        self::assertSame('0', $response->get_headers()['x-ratelimit-remaining']);
    }

    private function server(): OAuthServer
    {
        return new OAuthServer(
            new OAuthStore(),
            new RateLimiter(new WpdbRepository(), static fn (): int => 125),
            new ClientAddress()
        );
    }
}
