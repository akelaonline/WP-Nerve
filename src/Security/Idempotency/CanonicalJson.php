<?php

/**
 * Deterministic JSON encoding used to bind a key to exact tool arguments.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Idempotency;

use JsonException;

final class CanonicalJson
{
    /**
     * @param array<string, mixed> $arguments
     * @throws JsonException When the value cannot be represented as JSON.
     */
    public function hash(string $toolName, array $arguments): string
    {
        $json = json_encode(
            $this->normalize($arguments),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return hash('sha256', $toolName . "\n" . $json);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
