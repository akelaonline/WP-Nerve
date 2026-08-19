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

    /** @var array<int, string> */
    public array $queries = array();

    /** @var array<int, int|false> Results queued for insert(). */
    public array $insertResults = array();

    /** @var array<int, int|false> Results queued for query(). */
    public array $queryResults = array();

    /** @var array<int, scalar|null|false> Results queued for get_var(). */
    public array $varResults = array();

    /** @var array<int, array<string, mixed>> Rows queued for get_row(). */
    public array $rows = array();

    /** @var array<int, array<int, array<string, mixed>>> Result sets queued for get_results(). */
    public array $resultSets = array();

    /** @var array<int, array{table: string, where: array<string, mixed>}> */
    public array $deletes = array();

    /** @var array<string, array<int, array<string, mixed>>> In-memory tables. */
    public array $tables = array();

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data = array(), array $format = array()): int|false
    {
        $call = array(
            'table'  => $table,
            'data'   => $data,
            'format' => $format,
        );

        $this->insertCalls[] = $call;
        $this->lastInsert    = $call;

        if (array() !== $this->insertResults) {
            $result = array_shift($this->insertResults);

            if (false === $result || $result < 1) {
                return $result;
            }
        }

        $this->tables[$table][] = $data;

        return count($this->tables[$table]);
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

    public function query(string $query): int|false
    {
        $this->lastQuery = $query;
        $this->queries[] = $query;

        if (array() !== $this->queryResults) {
            return array_shift($this->queryResults);
        }

        return 1;
    }

    public function get_var(?string $query = null, int $x = 0, int $y = 0): string|int|float|bool|null
    {
        unset($x, $y);

        $this->lastQuery = (string) $query;

        if (array() !== $this->varResults) {
            return array_shift($this->varResults);
        }

        if (preg_match('/SELECT COUNT\(\*\) FROM `?(\w+_oauth_clients)`?/', (string) $query, $matches)) {
            return count($this->tables[$matches[1]] ?? array());
        }

        return null;
    }

    public function get_row(?string $query = null, string $output = OBJECT, int $y = 0): object|array|null
    {
        unset($y);

        $this->lastQuery = (string) $query;

        if (array() !== $this->rows) {
            $row = array_shift($this->rows);

            return OBJECT === $output ? (object) $row : $row;
        }

        // Fall back to the in-memory tables when no rows were queued.
        if (preg_match('/FROM `?(\w+_oauth_\w+)`?/', (string) $query, $matches)) {
            $table = $matches[1];
            $conditions = array();

            preg_match_all("/([a-z_]+) = '([^']*)'/", (string) $query, $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                $conditions[$pair[1]] = $pair[2];
            }

            foreach ($this->tables[$table] ?? array() as $row) {
                $match = true;

                foreach ($conditions as $column => $value) {
                    if ((string) ($row[$column] ?? '') !== $value) {
                        $match = false;
                        break;
                    }
                }

                if ($match) {
                    return OBJECT === $output ? (object) $row : $row;
                }
            }
        }

        return null;
    }

    /** @return array<int, object|array<string, mixed>> */
    public function get_results(?string $query = null, string $output = OBJECT): array
    {
        $this->lastQuery = (string) $query;
        $rows            = array() !== $this->resultSets ? array_shift($this->resultSets) : array();

        if (OBJECT === $output) {
            return array_map(static fn (array $row): object => (object) $row, $rows);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $where
     * @param array<int, string>   $format
     */
    public function delete(string $table, array $where, ?array $format = null): int
    {
        unset($format);

        $this->deletes[] = array('table' => $table, 'where' => $where);

        if (! isset($this->tables[$table])) {
            return 1;
        }

        $kept    = array();
        $removed = 0;

        foreach ($this->tables[$table] as $row) {
            $match = true;

            foreach ($where as $column => $value) {
                if ((string) ($row[$column] ?? '') !== (string) $value) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                ++$removed;
            } else {
                $kept[] = $row;
            }
        }

        $this->tables[$table] = $kept;

        return $removed;
    }
}
