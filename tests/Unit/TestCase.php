<?php

/**
 * Shared unit test case.
 *
 * Resets the WordPress runtime doubles before every test and provides small
 * helpers to build WP_Ability fixtures with WPNerve's policy metadata.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use WPNerve\Tests\Fixtures\WPState;

abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WPState::reset();
    }

    /**
     * Builds a WP_Ability with sane WPNerve defaults that individual tests can
     * override.
     *
     * @param array<string, mixed> $args
     */
    protected function makeAbility(string $name, array $args = array()): \WP_Ability
    {
        $defaults = array(
            'label'        => ucfirst(str_replace(array('/', '-'), ' ', $name)),
            'description'  => 'Test ability description.',
            'input_schema' => array(
                '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
                'type'                 => 'object',
                'properties'           => array(),
                'additionalProperties' => false,
            ),
            'output_schema'       => array(),
            'meta'                => array(
                'public'       => true,
                'show_in_rest' => false,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
                'wp_nerve'     => array(
                    'risk'               => 'read',
                    'capability'         => 'edit_posts',
                    'enabled_by_default' => true,
                ),
            ),
            'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            'execute_callback'    => static fn (): array => array('ok' => true),
        );

        return new \WP_Ability($name, array_merge($defaults, $args));
    }
}
