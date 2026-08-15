<?php

/**
 * Risk classifications for agent-executable abilities.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Policy;

enum RiskLevel: string
{
    case Read        = 'read';
    case Write       = 'write';
    case Destructive = 'destructive';
    case Privileged  = 'privileged';
}
