<?php

/**
 * Idempotent registry decorator tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\Idempotency;

use WPNerve\Protocol\ToolRegistry;
use WPNerve\Security\Idempotency\CanonicalJson;
use WPNerve\Security\Idempotency\Claim;
use WPNerve\Security\Idempotency\IdempotentToolRegistry;
use WPNerve\Security\Idempotency\Repository;
use WPNerve\Security\Idempotency\Service;
use WPNerve\Tests\Unit\TestCase;

final class IdempotentToolRegistryTest extends TestCase
{
    public function testContextKeyProtectsMutatingRegistryExecution(): void
    {
        $inner = $this->createMock(ToolRegistry::class);
        $inner->method('risk')->with('create')->willReturn('write');
        $inner->expects(self::once())->method('execute')->willReturn(array('result' => array('id' => 1), 'risk' => 'write'));

        $repository = $this->createMock(Repository::class);
        $repository->method('claim')->willReturn(Claim::acquired());
        $repository->method('complete')->willReturn(true);

        $registry = new IdempotentToolRegistry($inner, new Service($repository, new CanonicalJson()));
        $result   = $registry->execute(
            'create',
            array('title' => 'A'),
            array(
                'idempotency_key' => 'request-123',
                'credential_id'   => 'application-password:test',
            )
        );

        self::assertSame(1, $result['result']['id']);
    }

    public function testUnknownToolDelegatesWithoutMaskingNotFoundError(): void
    {
        $inner = $this->createMock(ToolRegistry::class);
        $inner->method('risk')->with('missing')->willReturn(null);
        $inner->expects(self::once())->method('execute')->willReturn(new \WP_Error('not_found', 'Missing.'));

        $repository = $this->createMock(Repository::class);
        $repository->expects(self::never())->method('claim');
        $registry = new IdempotentToolRegistry($inner, new Service($repository, new CanonicalJson()));

        $result = $registry->execute('missing', array());

        self::assertSame('not_found', $result->get_error_code());
    }

    public function testReplayWithInvalidRegistryShapeFailsClosed(): void
    {
        $inner = $this->createMock(ToolRegistry::class);
        $inner->method('risk')->with('create')->willReturn('write');
        $inner->expects(self::never())->method('execute');

        $repository = $this->createMock(Repository::class);
        $repository->method('claim')->willReturn(
            Claim::replay(array('kind' => 'success', 'value' => array('result' => array('id' => 1))))
        );

        $registry = new IdempotentToolRegistry($inner, new Service($repository, new CanonicalJson()));
        $result   = $registry->execute(
            'create',
            array('title' => 'A'),
            array(
                'idempotency_key' => 'request-123',
                'credential_id'   => 'application-password:test',
            )
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wp_nerve_idempotency_corrupt', $result->get_error_code());
    }
}
