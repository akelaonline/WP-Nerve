<?php

/**
 * Retention manager tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Maintenance;

use WPNerve\Maintenance\RetentionManager;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class RetentionManagerTest extends TestCase
{
    public function testCleanupUsesBoundedDeleteQueriesForOwnedPersistence(): void
    {
        $manager = new RetentionManager();
        $result  = $manager->cleanup();

        self::assertSame(
            array(
                'audit'         => 1,
                'idempotency'   => 1,
                'confirmations' => 1,
                'oauth_tokens'  => 1,
                'oauth_clients' => 0,
            ),
            $result
        );
        self::assertCount(4, WPState::$wpdb->queries);

        $sql = implode("\n", WPState::$wpdb->queries);

        self::assertStringContainsString('wp_nerve_audit_log', $sql);
        self::assertStringContainsString('wp_nerve_idempotency', $sql);
        self::assertStringContainsString("status = 'completed'", $sql);
        self::assertStringContainsString('wp_nerve_confirmations', $sql);
        self::assertStringContainsString('wp_nerve_oauth_tokens', $sql);
        self::assertSame(4, substr_count($sql, 'LIMIT 200'));
    }

    public function testCleanupFailsClosedPerTableWithoutTurningFailureIntoSuccess(): void
    {
        WPState::$wpdb->queryResults = array(false, 1, false, 1);

        $result = (new RetentionManager())->cleanup();

        self::assertFalse($result['audit']);
        self::assertSame(1, $result['idempotency']);
        self::assertFalse($result['confirmations']);
        self::assertSame(1, $result['oauth_tokens']);
        self::assertSame(0, $result['oauth_clients']);
    }

    public function testOauthClientCleanupIsExplicitOptInAndBounded(): void
    {
        add_filter('wp_nerve_oauth_client_retention_ttl', static fn (): int => 7776000);

        $result = (new RetentionManager())->cleanup();

        self::assertSame(1, $result['oauth_clients']);
        self::assertCount(5, WPState::$wpdb->queries);

        $sql = (string) end(WPState::$wpdb->queries);
        self::assertStringContainsString('wp_nerve_oauth_clients', $sql);
        self::assertStringContainsString('wp_nerve_oauth_tokens', $sql);
        self::assertStringContainsString('NOT IN', $sql);
        self::assertStringContainsString('LIMIT 200', $sql);
    }

    public function testRetentionAndBatchFiltersAreClamped(): void
    {
        add_filter('wp_nerve_audit_retention_ttl', static fn (): int => 1);
        add_filter('wp_nerve_confirmation_retention_ttl', static fn (): int => PHP_INT_MAX);
        add_filter('wp_nerve_oauth_client_retention_ttl', static fn (): int => PHP_INT_MAX);
        add_filter('wp_nerve_retention_cleanup_batch', static fn (): int => PHP_INT_MAX);

        $manager = new RetentionManager();

        self::assertSame(86400, $manager->auditRetentionTtl());
        self::assertSame(2592000, $manager->confirmationRetentionTtl());
        self::assertSame(31536000, $manager->oauthClientRetentionTtl());
        self::assertSame(1000, $manager->batchSize());
    }

    public function testInvalidRetentionFilterTypesFallBackToSafeDefaults(): void
    {
        add_filter('wp_nerve_audit_retention_ttl', static fn (): string => 'forever');
        add_filter('wp_nerve_confirmation_retention_ttl', static fn (): array => array());
        add_filter('wp_nerve_oauth_client_retention_ttl', static fn (): string => 'forever');
        add_filter('wp_nerve_retention_cleanup_batch', static fn (): string => 'all');

        $manager = new RetentionManager();

        self::assertSame(2592000, $manager->auditRetentionTtl());
        self::assertSame(604800, $manager->confirmationRetentionTtl());
        self::assertSame(0, $manager->oauthClientRetentionTtl());
        self::assertSame(200, $manager->batchSize());
    }
}
