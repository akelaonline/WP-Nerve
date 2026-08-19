<?php

/**
 * Persistent idempotency repository tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\Idempotency;

use WPNerve\Security\Idempotency\ClaimState;
use WPNerve\Security\Idempotency\WpdbRepository;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class WpdbRepositoryTest extends TestCase
{
    public function testInstallSchemaCreatesDedicatedTableWithAtomicKey(): void
    {
        WpdbRepository::installSchema();

        self::assertCount(1, WPState::$schemaCalls);
        self::assertStringContainsString('wp_wp_nerve_idempotency', WPState::$schemaCalls[0]);
        self::assertStringContainsString('credential_hash char(64)', WPState::$schemaCalls[0]);
        self::assertStringContainsString('UNIQUE KEY operation', WPState::$schemaCalls[0]);
    }

    public function testFreshClaimIsAcquiredAtomically(): void
    {
        WPState::$wpdb->queryResults[] = 1;
        $claim = (new WpdbRepository())->claim(
            1,
            'application-password:test',
            'create-draft',
            'request-123',
            str_repeat('a', 64)
        );

        self::assertSame(ClaimState::Acquired, $claim->state);
        self::assertStringContainsString('INSERT IGNORE', WPState::$wpdb->lastQuery);
    }

    public function testExistingCompletedClaimReturnsOutcomeForReplay(): void
    {
        WPState::$wpdb->queryResults[] = 0;
        WPState::$wpdb->rows[] = array(
            'request_hash' => str_repeat('a', 64),
            'status'       => 'completed',
            'outcome'      => '{"kind":"success","value":{"id":41}}',
            'expires_at'   => '2099-01-01 00:00:00',
        );

        $claim = (new WpdbRepository())->claim(
            1,
            'application-password:test',
            'create-draft',
            'request-123',
            str_repeat('a', 64)
        );

        self::assertSame(ClaimState::Replay, $claim->state);
        self::assertSame(41, $claim->outcome['value']['id']);
    }

    public function testSameKeyWithDifferentArgumentsConflicts(): void
    {
        WPState::$wpdb->queryResults[] = 0;
        WPState::$wpdb->rows[] = array(
            'request_hash' => str_repeat('b', 64),
            'status'       => 'completed',
            'outcome'      => '{}',
            'expires_at'   => '2099-01-01 00:00:00',
        );

        $claim = (new WpdbRepository())->claim(
            1,
            'application-password:test',
            'create-draft',
            'request-123',
            str_repeat('a', 64)
        );

        self::assertSame(ClaimState::Conflict, $claim->state);
    }

    public function testExpiredOutcomeDoesNotExecuteAsAReplay(): void
    {
        WPState::$wpdb->queryResults[] = 0;
        WPState::$wpdb->rows[] = array(
            'request_hash' => str_repeat('a', 64),
            'status'       => 'completed',
            'outcome'      => '{"kind":"success","value":{"id":41}}',
            'expires_at'   => '2000-01-01 00:00:00',
        );

        $claim = (new WpdbRepository())->claim(
            1,
            'application-password:test',
            'create-draft',
            'request-123',
            str_repeat('a', 64)
        );

        self::assertSame(ClaimState::Expired, $claim->state);
    }

    public function testCompletionUpdatesOnlyOwnedInProgressClaim(): void
    {
        WPState::$wpdb->queryResults[] = 1;
        $completed = (new WpdbRepository())->complete(
            1,
            'application-password:test',
            'create-draft',
            'request-123',
            str_repeat('a', 64),
            array('kind' => 'success', 'value' => array('id' => 41))
        );

        self::assertTrue($completed);
        self::assertStringContainsString("status = 'completed'", WPState::$wpdb->lastQuery);
        self::assertStringContainsString("status = 'in_progress'", WPState::$wpdb->lastQuery);
    }
}
