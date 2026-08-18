<?php

/**
 * Result states for a confirmation authorization attempt.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Confirmation;

enum AuthorizationState: string
{
    case Approved    = 'approved';
    case Replay      = 'replay';
    case Pending     = 'pending';
    case Denied      = 'denied';
    case Expired     = 'expired';
    case Conflict    = 'conflict';
    case Invalid     = 'invalid';
    case Unavailable = 'unavailable';
}
