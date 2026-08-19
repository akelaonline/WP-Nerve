<?php

/**
 * Canonical JSON unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Security\Idempotency;

use WPNerve\Security\Idempotency\CanonicalJson;
use WPNerve\Tests\Unit\TestCase;

final class CanonicalJsonTest extends TestCase
{
    public function testObjectKeyOrderDoesNotChangeHash(): void
    {
        $canonical = new CanonicalJson();

        $left = $canonical->hash(
            'wp_nerve_update_content',
            array('id' => 10, 'changes' => array('title' => 'A', 'content' => 'B'))
        );
        $right = $canonical->hash(
            'wp_nerve_update_content',
            array('changes' => array('content' => 'B', 'title' => 'A'), 'id' => 10)
        );

        self::assertSame($left, $right);
    }

    public function testListOrderChangesHash(): void
    {
        $canonical = new CanonicalJson();

        self::assertNotSame(
            $canonical->hash('tool', array('ids' => array(1, 2))),
            $canonical->hash('tool', array('ids' => array(2, 1)))
        );
    }

    public function testToolNameIsBoundIntoHash(): void
    {
        $canonical = new CanonicalJson();

        self::assertNotSame(
            $canonical->hash('tool-a', array('id' => 1)),
            $canonical->hash('tool-b', array('id' => 1))
        );
    }
}
