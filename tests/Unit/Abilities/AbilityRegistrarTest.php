<?php

/**
 * AbilityRegistrar unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Abilities;

use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class AbilityRegistrarTest extends TestCase
{
    private AbilityRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrar = new AbilityRegistrar();
    }

    public function testRegisterCategoryRegistersWpNerveCategory(): void
    {
        $this->registrar->registerCategory();

        self::assertCount(1, WPState::$registeredCategories);
        self::assertSame('wp-nerve-site', WPState::$registeredCategories[0]['name']);
        self::assertSame('WPNerve: Site', WPState::$registeredCategories[0]['args']['label']);
    }

    public function testRegisterAbilitiesRegistersAllReadAbilities(): void
    {
        $this->registrar->registerAbilities();

        $names = array_map(
            static fn ($ability): string => $ability->get_name(),
            WPState::$registeredAbilities
        );

        self::assertSame(
            array('wp-nerve/site-status', 'wp-nerve/list-content-types', 'wp-nerve/search-content', 'wp-nerve/get-content'),
            $names
        );
    }

    public function testRegisteredAbilitiesCarrySafePolicyMetadata(): void
    {
        $this->registrar->registerAbilities();

        foreach (WPState::$registeredAbilities as $ability) {
            $meta = $ability->get_meta_item('wp_nerve', array());

            self::assertSame('read', $meta['risk']);
            self::assertSame('edit_posts', $meta['capability']);
            self::assertTrue($meta['enabled_by_default']);

            $annotations = $ability->get_meta_item('annotations', array());
            self::assertTrue($annotations['readonly']);
            self::assertFalse($annotations['destructive']);
        }
    }

    public function testRegisteredAbilitiesDefineSchemas(): void
    {
        $this->registrar->registerAbilities();

        foreach (WPState::$registeredAbilities as $ability) {
            self::assertSame('object', $ability->get_input_schema()['type']);
            self::assertSame('object', $ability->get_output_schema()['type']);
            self::assertFalse($ability->get_input_schema()['additionalProperties']);
        }
    }

    public function testSearchContentSchemaRequiresQuery(): void
    {
        $this->registrar->registerAbilities();

        $search = $this->ability('wp-nerve/search-content');

        self::assertSame(array('query'), $search->get_input_schema()['required']);
        self::assertSame(200, $search->get_input_schema()['properties']['query']['maxLength']);
    }

    public function testGetContentSchemaRequiresPositiveId(): void
    {
        $this->registrar->registerAbilities();

        $get = $this->ability('wp-nerve/get-content');

        self::assertSame(array('id'), $get->get_input_schema()['required']);
        self::assertSame(1, $get->get_input_schema()['properties']['id']['minimum']);
    }

    public function testCanReadSiteStatusRequiresTransportCapability(): void
    {
        WPState::$userCan = false;

        self::assertFalse($this->registrar->canReadSiteStatus());

        WPState::$userCan = true;

        self::assertTrue($this->registrar->canReadSiteStatus());
    }

    public function testCanReadSiteStatusHonorsCapabilityFilter(): void
    {
        add_filter('wp_nerve_transport_capability', static fn (): string => 'manage_options');

        WPState::$userCan = static fn (string $cap): bool => 'manage_options' === $cap;

        self::assertTrue($this->registrar->canReadSiteStatus());
    }

    private function ability(string $name): \WP_Ability
    {
        foreach (WPState::$registeredAbilities as $ability) {
            if ($name === $ability->get_name()) {
                return $ability;
            }
        }

        self::fail('Ability not registered: ' . $name);
    }
}
