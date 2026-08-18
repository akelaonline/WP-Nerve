<?php

/**
 * States returned by the persistent idempotency repository.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Idempotency;

enum ClaimState: string
{
    case Acquired    = 'acquired';
    case Replay      = 'replay';
    case Expired     = 'expired';
    case Conflict    = 'conflict';
    case InProgress  = 'in_progress';
    case Unavailable = 'unavailable';
}
