<?php

/**
 * Autoloader unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit;

use ReflectionMethod;
use WPNerve\Autoloader;

final class AutoloaderTest extends TestCase
{
    public function testRegisterAddsAutoloaderCallback(): void
    {
        Autoloader::register();

        $registered = spl_autoload_functions();

        self::assertContains(array(Autoloader::class, 'autoload'), $registered, '', true);
    }

    public function testAutoloadIgnoresForeignClasses(): void
    {
        $class = 'Some\\Other\\Vendor\\Class';

        $this->invokeAutoload($class);

        self::assertFalse(class_exists($class));
    }

    public function testAutoloadIgnoresUnknownWpNerveClasses(): void
    {
        $class = 'WPNerve\\Does\\Not\\Exist';

        $this->invokeAutoload($class);

        self::assertFalse(class_exists($class));
    }

    public function testAutoloadResolvesExistingWpNerveClasses(): void
    {
        $class = 'WPNerve\\Policy\\RiskLevel';

        $this->invokeAutoload($class);

        self::assertTrue(class_exists($class));
    }

    private function invokeAutoload(string $class): void
    {
        $method = new ReflectionMethod(Autoloader::class, 'autoload');

        $method->invoke(null, $class);
    }
}
