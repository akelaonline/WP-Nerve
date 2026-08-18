<?php

/**
 * Persistent confirmation repository tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\Confirmation;

use WPNerve\Security\Confirmation\AuthorizationState;
use WPNerve\Security\Confirmation\WpdbRepository;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class WpdbRepositoryTest extends TestCase
{
    public function testInstallSchemaCreatesBoundTokenTable(): void
    {
        WpdbRepository::installSchema();

        self::assertCount(1, WPState::$schemaCalls);
        self::assertStringContainsString('wp_wp_nerve_confirmations', WPState::$schemaCalls[0]);
        self::assertStringContainsString('UNIQUE KEY token_hash', WPState::$schemaCalls[0]);
        self::assertStringContainsString('credential_hash char(64)', WPState::$schemaCalls[0]);
    }

    public function testIssueStoresOnlyHashesForSecretsAndIdentity(): void
    {
        WPState::$wpdb->queryResults[] = 1;
        $issued = (new WpdbRepository())->issue(
            1,
            'application-password:secret-uuid',
            'delete-user',
            'destructive',
            str_repeat('a', 64),
            'request-123',
            str_repeat('b', 64),
            'ABCD-EF12',
            time() + 300
        );

        self::assertTrue($issued);
        self::assertStringContainsString('INSERT INTO', WPState::$wpdb->lastQuery);
        self::assertStringNotContainsString('secret-uuid', WPState::$wpdb->lastQuery);
        self::assertStringNotContainsString('request-123', WPState::$wpdb->lastQuery);
    }

    public function testApprovedChallengeIsConsumedAtomically(): void
    {
        WPState::$wpdb->rows[]        = $this->row('approved');
        WPState::$wpdb->queryResults[] = 1;

        $result = $this->authorize();

        self::assertSame(AuthorizationState::Approved, $result->state);
        self::assertStringContainsString("status = 'approved'", WPState::$wpdb->lastQuery);
        self::assertStringContainsString("status = 'consumed'", WPState::$wpdb->lastQuery);
    }

    public function testConsumedChallengeAllowsOnlySameLogicalOperationReplay(): void
    {
        WPState::$wpdb->rows[] = $this->row('consumed');

        $result = $this->authorize();

        self::assertSame(AuthorizationState::Replay, $result->state);

        WPState::$wpdb->rows[] = $this->row('consumed');
        $conflict = (new WpdbRepository())->authorize(
            1,
            'application-password:test',
            'delete-user',
            str_repeat('c', 64),
            'request-123',
            str_repeat('b', 64)
        );

        self::assertSame(AuthorizationState::Conflict, $conflict->state);
    }

    /**
     * @dataProvider scopeMismatchProvider
     */
    public function testChallengeCannotTransferAcrossAuthorizationScope(
        int $userId,
        string $credentialId,
        string $toolName,
        string $requestHash,
        string $idempotencyKey
    ): void {
        WPState::$wpdb->rows[] = $this->row('approved');

        $result = (new WpdbRepository())->authorize(
            $userId,
            $credentialId,
            $toolName,
            $requestHash,
            $idempotencyKey,
            str_repeat('b', 64)
        );

        self::assertSame(AuthorizationState::Conflict, $result->state);
    }

    /** @return array<string, array{int, string, string, string, string}> */
    public static function scopeMismatchProvider(): array
    {
        return array(
            'user'       => array(2, 'application-password:test', 'delete-user', str_repeat('a', 64), 'request-123'),
            'credential' => array(1, 'oauth:other', 'delete-user', str_repeat('a', 64), 'request-123'),
            'tool'       => array(1, 'application-password:test', 'delete-plugin', str_repeat('a', 64), 'request-123'),
            'arguments'  => array(1, 'application-password:test', 'delete-user', str_repeat('c', 64), 'request-123'),
            'key'        => array(1, 'application-password:test', 'delete-user', str_repeat('a', 64), 'request-456'),
        );
    }

    public function testConcurrentConsumeRecognizesSameApprovedOperationReplay(): void
    {
        WPState::$wpdb->rows[]         = $this->row('approved');
        WPState::$wpdb->queryResults[] = 0;
        WPState::$wpdb->rows[]         = $this->row('consumed');

        $result = $this->authorize();

        self::assertSame(AuthorizationState::Replay, $result->state);
    }

    public function testExpiredApprovedChallengeCannotExecute(): void
    {
        $row = $this->row('approved');
        $row['expires_at'] = '2000-01-01 00:00:00';
        WPState::$wpdb->rows[] = $row;

        $result = $this->authorize();

        self::assertSame(AuthorizationState::Expired, $result->state);
    }

    public function testConsumedChallengeCannotReplayAfterExpiry(): void
    {
        $row = $this->row('consumed');
        $row['expires_at'] = '2000-01-01 00:00:00';
        WPState::$wpdb->rows[] = $row;

        $result = $this->authorize();

        self::assertSame(AuthorizationState::Expired, $result->state);
    }

    public function testDecisionUpdatesOnlyLivePendingChallenge(): void
    {
        WPState::$wpdb->queryResults[] = 1;

        $result = (new WpdbRepository())->decide(41, 9, true);

        self::assertTrue($result);
        self::assertStringContainsString("status = 'approved'", WPState::$wpdb->lastQuery);
        self::assertStringContainsString("status = 'pending'", WPState::$wpdb->lastQuery);
        self::assertStringContainsString('decided_by = 9', WPState::$wpdb->lastQuery);
    }

    public function testPendingReturnsOnlySafeDisplayMetadata(): void
    {
        WPState::$wpdb->resultSets[] = array(
            array(
                'id'           => '41',
                'user_id'      => '7',
                'tool_name'    => 'delete-user',
                'risk'         => 'destructive',
                'display_code' => 'ABCD-EF12',
                'created_at'   => '2026-08-18 00:00:00',
                'expires_at'   => '2026-08-18 00:05:00',
            ),
        );

        $pending = (new WpdbRepository())->pending();

        self::assertCount(1, $pending);
        self::assertSame(41, $pending[0]['id']);
        self::assertSame('ABCD-EF12', $pending[0]['display_code']);
        self::assertArrayNotHasKey('request_hash', $pending[0]);
        self::assertArrayNotHasKey('credential_hash', $pending[0]);
    }

    private function authorize(): \WPNerve\Security\Confirmation\Authorization
    {
        return (new WpdbRepository())->authorize(
            1,
            'application-password:test',
            'delete-user',
            str_repeat('a', 64),
            'request-123',
            str_repeat('b', 64)
        );
    }

    /** @return array<string, mixed> */
    private function row(string $status): array
    {
        return array(
            'id'              => 41,
            'user_id'         => 1,
            'credential_hash' => hash('sha256', 'application-password:test'),
            'tool_hash'       => hash('sha256', 'delete-user'),
            'request_hash'    => str_repeat('a', 64),
            'key_hash'        => hash('sha256', 'request-123'),
            'status'          => $status,
            'display_code'    => 'ABCD-EF12',
            'expires_at'      => '2099-01-01 00:00:00',
        );
    }
}
