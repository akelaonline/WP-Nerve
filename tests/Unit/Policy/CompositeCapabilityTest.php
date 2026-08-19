<?php

/**
 * Composite capability policy regression tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Policy;

use WP_Error;
use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Protocol\AbilityToolRegistry;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class CompositeCapabilityTest extends TestCase
{
    public function testPluginUploadDiscoveryRequiresInstallAndUploadCapabilities(): void
    {
        WPState::$options['wp_nerve_enabled_risk_classes'] = array('read', 'write', 'destructive', 'privileged');
        add_filter(
            'wp_nerve_ability_is_enabled',
            static fn (bool $enabled, $ability): bool => $enabled || 'wp-nerve/upload-plugin' === $ability->get_name(),
            10,
            2
        );

        $registrar = new AbilityRegistrar();
        $registrar->registerAbilities();
        $registry = new AbilityToolRegistry(new PolicyEngine());

        WPState::$userCan = static fn (string $capability): bool => in_array(
            $capability,
            array('edit_posts', 'install_plugins'),
            true
        );

        $result = $registry->execute('wp_nerve_upload_plugin', array());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());

        WPState::$userCan = static fn (string $capability): bool => in_array(
            $capability,
            array('edit_posts', 'install_plugins', 'upload_plugins'),
            true
        );

        $tools = $registry->tools();
        $names = array_column($tools, 'name');

        self::assertContains('wp_nerve_upload_plugin', $names);
    }
}
