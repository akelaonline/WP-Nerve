<?php

/**
 * AuditRepository unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Audit;

use WPNerve\Audit\AuditRepository;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class AuditRepositoryTest extends TestCase
{
    public function testInstallSchemaRunsDbDelta(): void
    {
        AuditRepository::installSchema();

        self::assertCount(1, WPState::$schemaCalls);
        self::assertStringContainsString('wp_wp_nerve_audit_log', WPState::$schemaCalls[0]);
        self::assertStringContainsString('CREATE TABLE', WPState::$schemaCalls[0]);
        self::assertStringContainsString('utf8mb4', WPState::$schemaCalls[0]);
    }

    public function testTableNameUsesWordPressPrefix(): void
    {
        self::assertSame('wp_wp_nerve_audit_log', AuditRepository::tableName());
    }

    public function testRecordInsertsSanitizedEvent(): void
    {
        WPState::$currentUserId = 42;

        $repository = new AuditRepository();

        $repository->record(array(
            'request_id'       => 'req-1',
            'protocol_version' => '2026-07-28',
            'client_name'      => 'claude-desktop',
            'client_version'   => '1.2.3',
            'rpc_method'       => 'tools/call',
            'tool_name'        => 'wp_nerve_site_status',
            'risk'             => 'read',
            'outcome'          => 'success',
            'duration_ms'      => 5,
            'error_code'       => '',
        ));

        self::assertNotNull(WPState::$wpdb->lastInsert);
        self::assertSame('wp_wp_nerve_audit_log', WPState::$wpdb->lastInsert['table']);

        $data = WPState::$wpdb->lastInsert['data'];

        self::assertSame('req-1', $data['request_id']);
        self::assertSame('2026-08-16 12:00:00', $data['occurred_at']);
        self::assertSame(42, $data['user_id']);
        self::assertSame('wp_nerve_site_status', $data['tool_name']);
        self::assertSame('success', $data['outcome']);
        self::assertSame(5, $data['duration_ms']);
    }

    public function testRecordSanitizesAndClampsValues(): void
    {
        $repository = new AuditRepository();

        $repository->record(array(
            'request_id'       => "<script>alert('x')</script>",
            'protocol_version' => '2026-07-28',
            'client_name'      => 'x',
            'client_version'   => 'x',
            'rpc_method'       => 'tools/call',
            'tool_name'        => 'x',
            'risk'             => 'read',
            'outcome'          => 'success',
            'duration_ms'      => PHP_INT_MAX,
            'error_code'       => '',
        ));

        $data = WPState::$wpdb->lastInsert['data'];

        self::assertStringNotContainsString('<script>', $data['request_id']);
        self::assertSame(4294967295, $data['duration_ms']);
    }

    public function testRecordUsesCurrentUserId(): void
    {
        $repository = new AuditRepository();

        $repository->record(array('rpc_method' => 'tools/call', 'tool_name' => 'x'));

        self::assertSame(WPState::$currentUserId, WPState::$wpdb->lastInsert['data']['user_id']);
    }
}
