<?php

/**
 * Minimal WP_Ability runtime double for unit tests.
 *
 * Mirrors the public surface used by WPNerve: name, label, description,
 * schemas, meta, permission callback, and execute callback.
 *
 * @package WPNerve
 */

declare(strict_types=1);

if (! class_exists('WP_Ability')) {
    class WP_Ability
    {
        /** @var array<string, mixed> */
        private array $args;

        public function __construct(private readonly string $name, array $args)
        {
            $this->args = $args;
        }

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_label(): string
        {
            return (string) ($this->args['label'] ?? '');
        }

        public function get_description(): string
        {
            return (string) ($this->args['description'] ?? '');
        }

        public function get_category(): string
        {
            return (string) ($this->args['category'] ?? '');
        }

        /** @return array<string, mixed> */
        public function get_input_schema(): array
        {
            return (array) ($this->args['input_schema'] ?? array());
        }

        /** @return array<string, mixed> */
        public function get_output_schema(): array
        {
            return (array) ($this->args['output_schema'] ?? array());
        }

        /** @return array<string, mixed> */
        public function get_meta(): array
        {
            return (array) ($this->args['meta'] ?? array());
        }

        public function get_meta_item(string $key, mixed $default_value = null): mixed
        {
            $meta = $this->get_meta();

            return array_key_exists($key, $meta) ? $meta[$key] : $default_value;
        }

        public function normalize_input(mixed $input = null): mixed
        {
            return $input;
        }

        public function validate_input(mixed $input = null): bool
        {
            return true;
        }

        public function check_permissions(mixed $input = null): bool
        {
            $callback = $this->args['permission_callback'] ?? null;

            return is_callable($callback) ? (bool) $callback($input) : true;
        }

        public function execute(mixed $input = null): mixed
        {
            $callback = $this->args['execute_callback'] ?? null;

            return is_callable($callback) ? $callback($input) : null;
        }
    }
}
