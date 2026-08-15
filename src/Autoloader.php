<?php

/**
 * Lightweight PSR-4 compatible autoloader for the runtime package.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve;

final class Autoloader
{
    private const PREFIX = __NAMESPACE__ . '\\';

    public static function register(): void
    {
        spl_autoload_register(array(self::class, 'autoload'));
    }

    private static function autoload(string $class): void
    {
        if (! str_starts_with($class, self::PREFIX)) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        $path     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
