<?php

/**
 * ProtocolError unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use WPNerve\Protocol\ProtocolError;

final class ProtocolErrorTest extends TestCase
{
    public function testBuildsAJsonRpcErrorEnvelope(): void
    {
        $error    = new ProtocolError(-32022, 'Unsupported protocol version.', 400, array('requested' => 'old'));
        $response = $error->response('request-1');

        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame('request-1', $response['id']);
        self::assertSame(-32022, $response['error']['code']);
        self::assertSame('old', $response['error']['data']['requested']);
    }
}
