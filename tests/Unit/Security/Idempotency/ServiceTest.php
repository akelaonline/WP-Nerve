<?php

/**
 * Idempotency service unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\Idempotency;

use WP_Error;
use WPNerve\Security\Idempotency\CanonicalJson;
use WPNerve\Security\Idempotency\Claim;
use WPNerve\Security\Idempotency\ClaimState;
use WPNerve\Security\Idempotency\Repository;
use WPNerve\Security\Idempotency\Service;
use WPNerve\Tests\Unit\TestCase;

final class ServiceTest extends TestCase
{
    public function testReadOperationDoesNotRequireOrClaimKey(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->expects(self::never())->method('claim');
        $service = new Service($repository, new CanonicalJson());

        $result = $service->execute(
            'status',
            'read',
            array(),
            null,
            'application-password:test',
            static fn (): array => array('ok' => true)
        );

        self::assertSame(array('ok' => true), $result);
    }

    public function testMutationWithoutKeyFailsBeforeExecution(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->expects(self::never())->method('claim');
        $service  = new Service($repository, new CanonicalJson());
        $executed = false;

        $result = $service->execute(
            'create-draft',
            'write',
            array('title' => 'A'),
            null,
            'application-password:test',
            static function () use (&$executed): array {
                $executed = true;
                return array();
            }
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_idempotency_key_required', $result->get_error_code());
        self::assertFalse($executed);
    }

    public function testAcquiredClaimExecutesAndPersistsOutcome(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->expects(self::once())->method('claim')->willReturn(Claim::acquired());
        $repository->expects(self::once())->method('complete')->with(
            1,
            'application-password:test',
            'create-draft',
            'request-123',
            self::isType('string'),
            array('kind' => 'success', 'value' => array('id' => 41, 'risk' => 'write'))
        )->willReturn(true);
        $service = new Service($repository, new CanonicalJson());

        $result = $service->execute(
            'create-draft',
            'write',
            array('title' => 'A'),
            'request-123',
            'application-password:test',
            static fn (): array => array('id' => 41, 'risk' => 'write')
        );

        self::assertSame(41, $result['id']);
    }

    public function testMutationWithoutAuthoritativeCredentialFailsClosed(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->expects(self::never())->method('claim');
        $service = new Service($repository, new CanonicalJson());

        $result = $service->execute(
            'create-draft',
            'write',
            array('title' => 'A'),
            'request-123',
            '',
            static fn (): array => array('id' => 41)
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_idempotency_credential_missing', $result->get_error_code());
    }

    public function testCompletedClaimReplaysWithoutExecuting(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('claim')->willReturn(
            Claim::replay(array('kind' => 'success', 'value' => array('id' => 41, 'risk' => 'write')))
        );
        $repository->expects(self::never())->method('complete');
        $service  = new Service($repository, new CanonicalJson());
        $executed = false;

        $result = $service->execute(
            'create-draft',
            'write',
            array('title' => 'A'),
            'request-123',
            'application-password:test',
            static function () use (&$executed): array {
                $executed = true;
                return array();
            }
        );

        self::assertSame(41, $result['id']);
        self::assertFalse($executed);
    }

    /**
     * @dataProvider rejectedClaimProvider
     */
    public function testRejectedClaimNeverExecutes(ClaimState $state, string $errorCode): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('claim')->willReturn(new Claim($state));
        $service  = new Service($repository, new CanonicalJson());
        $executed = false;

        $result = $service->execute(
            'update-content',
            'write',
            array('id' => 1),
            'request-123',
            'application-password:test',
            static function () use (&$executed): array {
                $executed = true;
                return array();
            }
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($errorCode, $result->get_error_code());
        self::assertFalse($executed);
    }

    /** @return array<string, array{ClaimState, string}> */
    public static function rejectedClaimProvider(): array
    {
        return array(
            'conflict'    => array(ClaimState::Conflict, 'wp_nerve_idempotency_conflict'),
            'in progress' => array(ClaimState::InProgress, 'wp_nerve_idempotency_in_progress'),
            'expired'     => array(ClaimState::Expired, 'wp_nerve_idempotency_expired'),
            'unavailable' => array(ClaimState::Unavailable, 'wp_nerve_idempotency_unavailable'),
        );
    }

    public function testFailedOperationIsPersistedAndReplayedAsError(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('claim')->willReturnOnConsecutiveCalls(
            Claim::acquired(),
            Claim::replay(array('kind' => 'error', 'code' => 'ability_failed', 'message' => 'Nope.'))
        );
        $repository->expects(self::once())->method('complete')->with(
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            array('kind' => 'error', 'code' => 'ability_failed', 'message' => 'Nope.')
        )->willReturn(true);
        $service = new Service($repository, new CanonicalJson());

        $first = $service->execute(
            'update-content',
            'write',
            array('id' => 1),
            'request-123',
            'application-password:test',
            static fn (): WP_Error => new WP_Error('ability_failed', 'Nope.')
        );
        $replay = $service->execute(
            'update-content',
            'write',
            array('id' => 1),
            'request-123',
            'application-password:test',
            static fn (): array => array('must_not' => 'execute')
        );

        self::assertInstanceOf(WP_Error::class, $first);
        self::assertInstanceOf(WP_Error::class, $replay);
        self::assertSame('ability_failed', $replay->get_error_code());
    }

    public function testCompletionFailureLocksKeyAndReturnsIndeterminateError(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('claim')->willReturn(Claim::acquired());
        $repository->method('complete')->willReturn(false);
        $service = new Service($repository, new CanonicalJson());

        $result = $service->execute(
            'update-content',
            'write',
            array('id' => 1),
            'request-123',
            'application-password:test',
            static fn (): array => array('id' => 1)
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_idempotency_completion_failed', $result->get_error_code());
    }
}
