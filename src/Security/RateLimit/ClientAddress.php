<?php

/**
 * Resolves the transport peer used as the anonymous rate-limit subject.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\RateLimit;

final class ClientAddress
{
    public function resolve(): string
    {
        $remoteAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        if ('' === $remoteAddress) {
            return 'unknown';
        }

        $normalized = filter_var($remoteAddress, FILTER_VALIDATE_IP);

        return is_string($normalized) ? $normalized : 'unknown';
    }
}
