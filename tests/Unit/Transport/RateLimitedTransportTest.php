<?php

/**
 * MCP transport rate-limit integration tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Transport;

use WP_Error;
use WP_REST_Request;
use WPNerve\Audit\AuditRecorder;
use WPNerve\Protocol\JsonRpcHandler;
use WPNerve\Protocol\RequestValidator;
use WPNerve\Protocol\ToolRegistry;
use WPNerve\Security\RateLimit\ClientAddress;
use WPNerve\Security\RateLimit\RateLimiter;
use WPNerve\Security\RateLimit\WpdbRepository;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;
use WPNerve\Transport\HttpTransport;

final class RateLimitedTransportTest extends TestCase
{
    public function testMcpRateLimitRejectsBeforeAuthentication(): void
    {
        WPState::$wpdb->queryResults = array(0, 0);
        WPState::$wpdb->rows         = array(array('request_count' => '120'));
        $_SERVER['REMOTE_ADDR']      = '198.51.100.25';

        $transport = $this->transport();
        $result    = $transport->checkPermission(new WP_REST_Request('POST', '/wp-nerve/v1/mcp'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_rate_limited', $result->get_error_code());
        self::assertSame(429, $result->get_error_data()['status']);
        self::assertSame(120, $result->get_error_data()['limit']);
        self::assertSame(0, $result->get_error_data()['remaining']);
    }

    public function testMcpRateLimitStorageFailureFailsClosed(): void
    {
        WPState::$wpdb->queryResults = array(false);
        $_SERVER['REMOTE_ADDR']      = '198.51.100.26';

        $transport = $this->transport();
        $result    = $transport->checkPermission(new WP_REST_Request('POST', '/wp-nerve/v1/mcp'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_rate_limit_unavailable', $result->get_error_code());
        self::assertSame(503, $result->get_error_data()['status']);
    }

    private function transport(): HttpTransport
    {
        $tools = $this->createMock(ToolRegistry::class);

        return new HttpTransport(
            new RequestValidator(),
            new JsonRpcHandler($tools, $this->createMock(AuditRecorder::class)),
            new RateLimiter(new WpdbRepository(), static fn (): int => 125),
            new ClientAddress()
        );
    }
}
