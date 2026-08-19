<?php

/**
 * Confirmation registry decorator tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\Confirmation;

use WP_Error;
use WPNerve\Protocol\ToolRegistry;
use WPNerve\Security\Confirmation\Authorization;
use WPNerve\Security\Confirmation\AuthorizationState;
use WPNerve\Security\Confirmation\ConfirmedToolRegistry;
use WPNerve\Security\Confirmation\Repository;
use WPNerve\Security\Confirmation\Service;
use WPNerve\Security\Idempotency\CanonicalJson;
use WPNerve\Tests\Unit\TestCase;

final class ConfirmedToolRegistryTest extends TestCase
{
    public function testPendingConfirmationNeverCallsInnerRegistry(): void
    {
        $inner = $this->createMock(ToolRegistry::class);
        $inner->method('risk')->willReturn('destructive');
        $inner->expects(self::never())->method('execute');

        $repository = $this->createMock(Repository::class);
        $repository->method('issue')->willReturn(true);
        $registry = new ConfirmedToolRegistry($inner, new Service($repository, new CanonicalJson()));

        $result = $registry->execute(
            'delete-user',
            array('id' => 7),
            array(
                'idempotency_key' => 'request-123',
                'credential_id'   => 'application-password:test',
            )
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_confirmation_required', $result->get_error_code());
    }

    public function testApprovedConfirmationExecutesInnerRegistry(): void
    {
        $inner = $this->createMock(ToolRegistry::class);
        $inner->method('risk')->willReturn('privileged');
        $inner->expects(self::once())->method('execute')->willReturn(
            array('result' => array('updated' => true), 'risk' => 'privileged')
        );

        $repository = $this->createMock(Repository::class);
        $repository->method('authorize')->willReturn(new Authorization(AuthorizationState::Approved));
        $registry = new ConfirmedToolRegistry($inner, new Service($repository, new CanonicalJson()));

        $result = $registry->execute(
            'update-option',
            array('name' => 'blogname', 'value' => 'New'),
            array(
                'idempotency_key'    => 'request-123',
                'credential_id'      => 'application-password:test',
                'confirmation_token' => 'wpc_' . str_repeat('a', 43),
            )
        );

        self::assertIsArray($result);
        self::assertTrue($result['result']['updated']);
    }
}
