<?php

/**
 * Minimal $wpdb runtime double for unit tests.
 *
 * Records insert calls and schema queries so tests can assert what WPNerve
 * writes without touching a real database.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Fixtures;

final class WpDb
{
    public string $prefix = 'wp_';

    /** @var array<int, array{table: string, data: array<string, mixed>, format: array<int, string>}> */
    public array $insertCalls = array();

    /** @var array{table: string, data: array<string, mixed>, format: array<int, string>}|null */
    public ?array $lastInsert = null;

    public string $lastQuery = '';

    public string $lastPrepared = '';

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data = array(), array $format = array()): int
    {
        $call = array(
            'table'  => $table,
            'data'   => $data,
            'format' => $format,
        );

        $this->insertCalls[] = $call;
        $this->lastInsert    = $call;

        return 1;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $this->lastPrepared = $query;

        $args   = array_values($args);
        $index  = 0;
        $result = preg_replace_callback(
            '/%[idsf%]/',
            static function (array $match) use (&$index, $args): string {
                $placeholder = $match[0];

                if ('%%' === $placeholder) {
                    return '%';
                }

                if (! array_key_exists($index, $args)) {
                    return $placeholder;
                }

                $value = $args[$index++];

                if ('%i' === $placeholder) {
                    return '`' . str_replace('`', '``', (string) $value) . '`';
                }

                if ('%d' === $placeholder) {
                    return (string) (int) $value;
                }

                if ('%f' === $placeholder) {
                    return (string) (float) $value;
                }

                return "'" . addslashes((string) $value) . "'";
            },
            $query
        );

        return is_string($result) ? $result : $query;
    }

    public function query(string $query): void
    {
        $this->lastQuery = $query;
    }
}
