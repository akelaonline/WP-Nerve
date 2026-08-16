<?php

/**
 * Taxonomy and term ability tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Abilities;

use WP_Error;
use WP_Post;
use WP_Taxonomy;
use WP_Term;
use WPNerve\Abilities\AbilityRegistrar;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Protocol\AbilityToolRegistry;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class TaxonomyTest extends TestCase
{
    private AbilityToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = new AbilityRegistrar();
        $registrar->registerAbilities();

        $this->registry = new AbilityToolRegistry(new PolicyEngine());
    }

    public function testRegistersTaxonomyAbilities(): void
    {
        $names = array();

        foreach (WPState::$registeredAbilities as $ability) {
            if (str_starts_with($ability->get_name(), 'wp-nerve/list-taxonomies')
                || str_starts_with($ability->get_name(), 'wp-nerve/list-terms')
                || str_starts_with($ability->get_name(), 'wp-nerve/create-term')
                || str_starts_with($ability->get_name(), 'wp-nerve/assign-terms')
            ) {
                $meta = $ability->get_meta_item('wp_nerve', array());
                $names[$ability->get_name()] = array($meta['risk'], $meta['enabled_by_default']);
            }
        }

        self::assertSame(
            array(
                'wp-nerve/list-taxonomies' => array('read', true),
                'wp-nerve/list-terms'      => array('read', true),
                'wp-nerve/create-term'     => array('write', false),
                'wp-nerve/assign-terms'    => array('write', true),
            ),
            $names
        );
    }

    public function testListTaxonomiesReturnsPublicTaxonomies(): void
    {
        WPState::$taxonomies = array(
            'category' => new WP_Taxonomy('category', array('label' => 'Categories', 'hierarchical' => true, 'object_type' => array('post'))),
            'post_tag' => new WP_Taxonomy('post_tag', array('label' => 'Tags', 'object_type' => array('post'))),
            'secret'   => new WP_Taxonomy('secret', array('public' => false)),
        );

        $result = $this->registry->execute('wp_nerve_list_taxonomies', array());

        self::assertNotInstanceOf(WP_Error::class, $result);

        $taxonomies = $result['result']['taxonomies'];

        self::assertCount(2, $taxonomies);
        self::assertSame('category', $taxonomies[0]['name']);
        self::assertTrue($taxonomies[0]['hierarchical']);
        self::assertSame(array('post'), $taxonomies[0]['object_type']);
        self::assertSame('category', $taxonomies[0]['rest_base']);
    }

    public function testListTermsReturnsTerms(): void
    {
        WPState::$taxonomies['category'] = new WP_Taxonomy('category');
        WPState::$terms[1] = $this->term(1, 'SEO', 'category');
        WPState::$terms[2] = $this->term(2, 'WordPress', 'category');
        WPState::$terms[3] = $this->term(3, 'Other', 'post_tag');

        $result = $this->registry->execute('wp_nerve_list_terms', array('taxonomy' => 'category'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(2, $result['result']['total']);
        self::assertSame('SEO', $result['result']['items'][0]['name']);
    }

    public function testListTermsRejectsUnknownTaxonomy(): void
    {
        $result = $this->registry->execute('wp_nerve_list_terms', array('taxonomy' => 'nope'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_invalid_taxonomy', $result->get_error_code());
    }

    public function testCreateTermInsertsTerm(): void
    {
        $this->enableAbility('wp-nerve/create-term');

        WPState::$taxonomies['category'] = new WP_Taxonomy('category');
        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'manage_categories'), true);

        $result = $this->registry->execute('wp_nerve_create_term', array('taxonomy' => 'category', 'name' => 'New Term'));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('New Term', $result['result']['name']);
        self::assertSame('new-term', $result['result']['slug']);
        self::assertSame('category', $result['result']['taxonomy']);
    }

    public function testCreateTermRequiresManageCategories(): void
    {
        $this->enableAbility('wp-nerve/create-term');

        WPState::$taxonomies['category'] = new WP_Taxonomy('category');
        WPState::$userCan = static fn (string $cap): bool => 'edit_posts' === $cap;

        // The tool is not revealed to users without manage_categories.
        $result = $this->registry->execute('wp_nerve_create_term', array('taxonomy' => 'category', 'name' => 'New Term'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());
    }

    public function testCreateTermHiddenByDefault(): void
    {
        WPState::$taxonomies['category'] = new WP_Taxonomy('category');
        WPState::$userCan = static fn (string $cap): bool => in_array($cap, array('edit_posts', 'manage_categories'), true);

        $result = $this->registry->execute('wp_nerve_create_term', array('taxonomy' => 'category', 'name' => 'New Term'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_tool_not_found', $result->get_error_code());
    }

    public function testAssignTermsReturnsPreviousAndNew(): void
    {
        WPState::$posts[7] = $this->post(7);
        WPState::$taxonomies['category'] = new WP_Taxonomy('category');
        WPState::$objectTerms[7]['category'] = array(1, 2);
        WPState::$userCan = static fn (string $cap, mixed $id = null): bool =>
            'edit_posts' === $cap || ('edit_post' === $cap && 7 === $id);

        $result = $this->registry->execute('wp_nerve_assign_terms', array(
            'id'       => 7,
            'taxonomy' => 'category',
            'terms'    => array(3, 4),
        ));

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame(array(1, 2), $result['result']['previous_terms']);
        self::assertSame(array(3, 4), $result['result']['terms']);
    }

    public function testAssignTermsRejectsWithoutEditCapability(): void
    {
        WPState::$posts[7] = $this->post(7);
        WPState::$taxonomies['category'] = new WP_Taxonomy('category');
        WPState::$userCan = static fn (string $cap): bool => 'edit_posts' === $cap;

        $result = $this->registry->execute('wp_nerve_assign_terms', array(
            'id'       => 7,
            'taxonomy' => 'category',
            'terms'    => array(3),
        ));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wp_nerve_forbidden', $result->get_error_code());
    }

    private function enableAbility(string $name): void
    {
        add_filter('wp_nerve_ability_is_enabled', static function (bool $enabled, $ability) use ($name): bool {
            return $enabled || $name === $ability->get_name();
        }, 10, 2);
    }

    private function term(int $id, string $name, string $taxonomy): WP_Term
    {
        $term = new WP_Term($id);

        $term->name     = $name;
        $term->slug     = sanitize_title($name);
        $term->taxonomy = $taxonomy;

        return $term;
    }

    private function post(int $id): WP_Post
    {
        return new WP_Post($id);
    }
}
