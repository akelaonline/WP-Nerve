<?php

/**
 * AbilityToolRegistry unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Protocol;

use WP_Error;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Protocol\AbilityToolRegistry;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class AbilityToolRegistryTest extends TestCase
{
    private AbilityToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new AbilityToolRegistry(new PolicyEngine());
    }

    public function testToolsReturnsDescriptorsSortedByName(): void
    {
        WPState::$abilities = array(
            $this->makeAbility('wp-nerve/get-content'),
            $this->makeAbility('wp-nerve/list-content-types'),
            $this->makeAbility('wp-nerve/site-status'),
        );

        $tools = $this->registry->tools();

        self::assertCount(3, $tools);
        self::assertSame(array('wp_nerve_get_content', 'wp_nerve_list_content_types', 'wp_nerve_site_status'), array_column($tools, 'name'));
    }

    public function testToolsDescriptorShape(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array('label' => 'Get site status'));
        WPState::$abilities = array($ability);

        $tool = $this->registry->tools()[0];

        self::assertSame('wp_nerve_site_status', $tool['name']);
        self::assertSame('Get site status', $tool['title']);
        self::assertSame('Test ability description.', $tool['description']);
        self::assertSame('object', $tool['inputSchema']['type']);
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertFalse($tool['annotations']['destructiveHint']);
        self::assertTrue($tool['annotations']['idempotentHint']);
        self::assertFalse($tool['annotations']['openWorldHint']);
        self::assertSame('wp-nerve/site-status', $tool['_meta']['wp-nerve/ability']);
        self::assertSame('read', $tool['_meta']['wp-nerve/risk']);
        self::assertArrayNotHasKey('outputSchema', $tool);
    }

    public function testToolsIncludesOutputSchemaWhenPresent(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'output_schema' => array('type' => 'object', 'required' => array('site_name')),
        ));
        WPState::$abilities = array($ability);

        $tool = $this->registry->tools()[0];

        self::assertSame(array('site_name'), $tool['outputSchema']['required']);
    }

    public function testToolsOnlyExposesDiscoverableAbilities(): void
    {
        WPState::$abilities = array(
            $this->makeAbility('wp-nerve/site-status'),
            $this->makeAbility('core/foreign-ability'),
        );

        self::assertCount(1, $this->registry->tools());
    }

    public function testExecuteReturnsResultAndRisk(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'execute_callback' => static fn (): array => array('site_name' => 'Test'),
        ));
        WPState::$abilities = array($ability);

        $result = $this->registry->execute('wp_nerve_site_status', array());

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('read', $result['risk']);
        self::assertSame(array('site_name' => 'Test'), $result['result']);
    }

    public function testExecuteReturnsErrorForUnknownTool(): void
    {
        $result = $this->registry->execute('wp_nerve_does_not_exist', array());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());
    }

    public function testExecuteHidesToolsFromUnauthorizedUsers(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status');
        WPState::$abilities = array($ability);

        WPState::$userCan = false;

        $result = $this->registry->execute('wp_nerve_site_status', array());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());
    }

    public function testExecuteDeniesDestructiveRisk(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->makeAbilityMeta('destructive'),
        ));
        WPState::$abilities = array($ability);

        $result = $this->registry->execute('wp_nerve_site_status', array());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('confirmation_required', $result->get_error_code());
    }

    /**
     * @return array<string, mixed>
     */
    private function makeAbilityMeta(string $risk): array
    {
        return array(
            'public'       => true,
            'show_in_rest' => false,
            'annotations'  => array('readonly' => false, 'destructive' => 'destructive' === $risk, 'idempotent' => false),
            'wp_nerve'     => array(
                'risk'               => $risk,
                'capability'         => 'edit_posts',
                'enabled_by_default' => true,
            ),
        );
    }

    public function testExecutePassesThroughAbilityErrors(): void
    {
        $error = new WP_Error('wp_nerve_boom', 'Boom');

        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'execute_callback' => static fn (): WP_Error => $error,
        ));
        WPState::$abilities = array($ability);

        $result = $this->registry->execute('wp_nerve_site_status', array());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_boom', $result->get_error_code());
    }

    public function testToolNameSanitizesAbilityNames(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status');
        WPState::$abilities = array($ability);

        self::assertSame('wp_nerve_site_status', $this->registry->tools()[0]['name']);
    }
}
