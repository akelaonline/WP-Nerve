<?php

/**
 * High-risk confirmation service tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\Confirmation;

use WP_Error;
use WPNerve\Security\Confirmation\Authorization;
use WPNerve\Security\Confirmation\AuthorizationState;
use WPNerve\Security\Confirmation\Repository;
use WPNerve\Security\Confirmation\Service;
use WPNerve\Security\Idempotency\CanonicalJson;
use WPNerve\Tests\Unit\TestCase;

final class ServiceTest extends TestCase
{
    public function testWriteRiskBypassesConfirmation(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->expects(self::never())->method('issue');
        $repository->expects(self::never())->method('authorize');

        $result = (new Service($repository, new CanonicalJson()))->authorize(
            'create-draft',
            'write',
            array('title' => 'A'),
            null,
            '',
            null
        );

        self::assertTrue($result);
    }

    public function testMissingTokenIssuesBoundChallenge(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->expects(self::once())->method('issue')->with(
            1,
            'application-password:test',
            'delete-user',
            'destructive',
            self::isType('string'),
            'request-123',
            self::isType('string'),
            self::matchesRegularExpression('/^[A-F0-9]{4}-[A-F0-9]{4}$/'),
            self::greaterThan(time())
        )->willReturn(true);

        $result = (new Service($repository, new CanonicalJson()))->authorize(
            'delete-user',
            'destructive',
            array('id' => 7),
            'request-123',
            'application-password:test',
            null
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_confirmation_required', $result->get_error_code());

        $metadata = $result->get_error_data()['wp_nerve_confirmation'];

        self::assertSame('pending', $metadata['status']);
        self::assertMatchesRegularExpression('/^wpc_[A-Za-z0-9_-]{43}$/', $metadata['token']);
        self::assertSame('delete-user', $metadata['tool']);
    }

    public function testInvalidIdempotencyKeyCannotIssueChallenge(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->expects(self::never())->method('issue');

        $result = (new Service($repository, new CanonicalJson()))->authorize(
            'delete-user',
            'destructive',
            array('id' => 7),
            'short',
            'application-password:test',
            null
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_confirmation_idempotency_key_required', $result->get_error_code());
    }

    public function testChallengeStoreFailurePreventsExecution(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('issue')->willReturn(false);

        $result = (new Service($repository, new CanonicalJson()))->authorize(
            'delete-user',
            'destructive',
            array('id' => 7),
            'request-123',
            'application-password:test',
            null
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_confirmation_unavailable', $result->get_error_code());
    }

    public function testMissingCredentialIdentityCannotIssueChallenge(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->expects(self::never())->method('issue');

        $result = (new Service($repository, new CanonicalJson()))->authorize(
            'delete-user',
            'destructive',
            array('id' => 7),
            'request-123',
            '',
            null
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_confirmation_identity_missing', $result->get_error_code());
    }

    public function testMalformedTokenCannotReachRepository(): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->expects(self::never())->method('authorize');

        $result = (new Service($repository, new CanonicalJson()))->authorize(
            'delete-user',
            'destructive',
            array('id' => 7),
            'request-123',
            'application-password:test',
            'not-a-token'
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_confirmation_invalid', $result->get_error_code());
    }

    /**
     * @dataProvider authorizationProvider
     */
    public function testAuthorizationStatesAreFailClosed(AuthorizationState $state, string $errorCode): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('authorize')->willReturn(new Authorization($state, 'ABCD-EF12', time() + 300));
        $service = new Service($repository, new CanonicalJson());

        $result = $service->authorize(
            'update-option',
            'privileged',
            array('name' => 'blogname', 'value' => 'New'),
            'request-123',
            'oauth:client-1',
            'wpc_' . str_repeat('a', 43)
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($errorCode, $result->get_error_code());
    }

    /** @return array<string, array{AuthorizationState, string}> */
    public static function authorizationProvider(): array
    {
        return array(
            'pending'     => array(AuthorizationState::Pending, 'wp_nerve_confirmation_pending'),
            'denied'      => array(AuthorizationState::Denied, 'wp_nerve_confirmation_denied'),
            'expired'     => array(AuthorizationState::Expired, 'wp_nerve_confirmation_expired'),
            'conflict'    => array(AuthorizationState::Conflict, 'wp_nerve_confirmation_conflict'),
            'invalid'     => array(AuthorizationState::Invalid, 'wp_nerve_confirmation_invalid'),
            'unavailable' => array(AuthorizationState::Unavailable, 'wp_nerve_confirmation_unavailable'),
        );
    }

    /**
     * @dataProvider acceptedAuthorizationProvider
     */
    public function testApprovedLogicalOperationMayProceed(AuthorizationState $state): void
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('authorize')->willReturn(new Authorization($state));

        $result = (new Service($repository, new CanonicalJson()))->authorize(
            'delete-plugin',
            'destructive',
            array('plugin' => 'example/example.php'),
            'request-123',
            'application-password:test',
            'wpc_' . str_repeat('a', 43)
        );

        self::assertTrue($result);
    }

    /** @return array<string, array{AuthorizationState}> */
    public static function acceptedAuthorizationProvider(): array
    {
        return array(
            'fresh approval' => array(AuthorizationState::Approved),
            'safe retry'     => array(AuthorizationState::Replay),
        );
    }
}
