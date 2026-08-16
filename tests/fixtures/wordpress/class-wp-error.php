<?php

/**
 * Minimal WP_Error runtime double for unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, array<int, string>> */
        private array $errors = array();

        /** @var array<string, mixed> */
        private array $error_data = array();

        public function __construct(string $code = '', string $message = '', mixed $data = '')
        {
            if ('' !== $code) {
                $this->errors[$code][] = $message;

                if ('' !== $data) {
                    $this->error_data[$code] = $data;
                }
            }
        }

        /** @return array<int, string> */
        public function get_error_codes(): array
        {
            return array_keys($this->errors);
        }

        public function get_error_code(): string
        {
            $codes = $this->get_error_codes();

            return isset($codes[0]) ? $codes[0] : '';
        }

        public function get_error_message(string $code = ''): string
        {
            if ('' === $code) {
                $code = $this->get_error_code();
            }

            $messages = $this->errors[$code] ?? array();

            return (string) ($messages[0] ?? '');
        }

        public function get_error_data(string $code = ''): mixed
        {
            if ('' === $code) {
                $code = $this->get_error_code();
            }

            return $this->error_data[$code] ?? null;
        }

        public function add(string $code, string $message, mixed $data = ''): void
        {
            $this->errors[$code][] = $message;

            if ('' !== $data) {
                $this->error_data[$code] = $data;
            }
        }

        public function has_errors(): bool
        {
            return array() !== $this->errors;
        }
    }
}
