<?php

/**
 * Minimal WP_REST_Request and WP_REST_Response runtime doubles for unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, array<int, string>> Lowercased header name => values. */
        private array $headers = array();

        private string $body = '';

        /** @var array<string, mixed> */
        private array $parameters = array();

        public function __construct(
            private readonly string $method = 'GET',
            private readonly string $route = '',
            array $parameters = array()
        ) {
            $this->parameters = $parameters;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->parameters[$key] = $value;
        }

        public function get_method(): string
        {
            return strtoupper($this->method);
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function set_header(string $name, string $value): void
        {
            $this->headers[strtolower($name)] = array($value);
        }

        public function get_header(string $name): ?string
        {
            $values = $this->headers[strtolower($name)] ?? array();

            return isset($values[0]) ? $values[0] : null;
        }

        /** @return array<string, array<int, string>> */
        public function get_headers(): array
        {
            return $this->headers;
        }

        public function set_body(string $body): void
        {
            $this->body = $body;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        public function get_json_params(): ?array
        {
            if ('' === $this->body) {
                return array();
            }

            $decoded = json_decode($this->body, true);

            return is_array($decoded) ? $decoded : null;
        }

        public function get_param(string $key): mixed
        {
            return $this->parameters[$key] ?? null;
        }

        /** @return array<string, mixed> */
        public function get_params(): array
        {
            return $this->parameters;
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var array<string, string> */
        private array $headers = array();

        public function __construct(private readonly mixed $data = null, private readonly int $status = 200)
        {
        }

        public function header(string $name, string $value): void
        {
            $this->headers[strtolower($name)] = $value;
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        /** @return array<string, string> */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}
