<?php

/**
 * Shared ability registration plumbing.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Abilities;

use WP_Post;

abstract class AbstractAbilityRegistrar
{
    /**
     * Registers an ability with WPNerve policy metadata derived from its risk
     * class.
     *
     * @param lowercase-string&non-falsy-string $name
     * @param array<string, mixed>              $inputSchema
     * @param array<string, mixed>              $outputSchema
     * @param callable                          $execute
     * @param array<int, non-empty-string>      $additionalCapabilities
     */
    protected function registerAbility(
        string $name,
        string $label,
        string $description,
        array $inputSchema,
        array $outputSchema,
        callable $execute,
        string $risk = 'read',
        bool $enabledByDefault = true,
        string $capability = '',
        array $additionalCapabilities = array()
    ): void {
        $primaryCapability = '' !== $capability ? $capability : $this->transportCapability();
        $capabilities      = array_values(array_unique(array_merge(array($primaryCapability), $additionalCapabilities)));

        wp_register_ability(
            $name,
            array(
                'label'               => $label,
                'description'         => $description,
                'category'            => 'wp-nerve-site',
                'input_schema'        => $inputSchema,
                'output_schema'       => $outputSchema,
                'execute_callback'    => $execute,
                'permission_callback' => array($this, 'canAccess'),
                'meta'                => array(
                    'public'       => true,
                    'show_in_rest' => false,
                    'annotations'  => $this->annotations($risk),
                    'wp_nerve'     => array(
                        'risk'               => $risk,
                        'capability'         => $primaryCapability,
                        'capabilities'       => $capabilities,
                        'enabled_by_default' => $enabledByDefault,
                    ),
                ),
            )
        );
    }

    /**
     * @param lowercase-string&non-falsy-string $name
     * @param array<string, mixed>              $inputSchema
     * @param array<string, mixed>              $outputSchema
     * @param callable                          $execute
     */
    protected function registerReadAbility(
        string $name,
        string $label,
        string $description,
        array $inputSchema,
        array $outputSchema,
        callable $execute
    ): void {
        $this->registerAbility($name, $label, $description, $inputSchema, $outputSchema, $execute, 'read', true);
    }

    public function canAccess(mixed $input = null): bool
    {
        unset($input);

        return current_user_can($this->transportCapability());
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyInputSchema(): array
    {
        return array(
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'type'                 => 'object',
            'properties'           => array(),
            'additionalProperties' => false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentItemSchema(bool $withContent): array
    {
        $schema = array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array(
                'id',
                'title',
                'type',
                'status',
                'date',
                'modified',
                'author',
                'link',
                'excerpt',
            ),
            'properties'           => array(
                'id'       => array('type' => 'integer'),
                'title'    => array('type' => 'string'),
                'type'     => array('type' => 'string'),
                'status'   => array('type' => 'string'),
                'date'     => array('type' => 'string'),
                'modified' => array('type' => 'string'),
                'author'   => array('type' => 'integer'),
                'link'     => array('type' => 'string', 'format' => 'uri'),
                'excerpt'  => array('type' => 'string'),
            ),
        );

        if ($withContent) {
            $schema['required'][]            = 'content';
            $schema['properties']['content'] = array('type' => 'string');
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    protected function contentItem(WP_Post $post, bool $withContent): array
    {
        $item = array(
            'id'       => $post->ID,
            'title'    => $post->post_title,
            'type'     => $post->post_type,
            'status'   => $post->post_status,
            'date'     => $post->post_date,
            'modified' => $post->post_modified,
            'author'   => (int) $post->post_author,
            'link'     => (string) get_permalink($post),
            'excerpt'  => '' !== $post->post_excerpt
                ? $post->post_excerpt
                : wp_trim_words($post->post_content, 30),
        );

        if ($withContent) {
            $item['content'] = $post->post_content;
        }

        return $item;
    }

    protected function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    protected function transportCapability(): string
    {
        /**
         * Filters the minimum capability required to access WPNerve.
         *
         * @param string $capability WordPress capability name.
         */
        $capability = apply_filters('wp_nerve_transport_capability', 'edit_posts');

        return is_string($capability) && '' !== $capability ? $capability : 'do_not_allow';
    }

    /**
     * @return array<string, bool>
     */
    private function annotations(string $risk): array
    {
        return array(
            'readonly'    => 'read' === $risk,
            'destructive' => 'destructive' === $risk,
            'idempotent'  => 'read' === $risk,
        );
    }
}
