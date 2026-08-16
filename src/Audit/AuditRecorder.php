<?php

/**
 * Contract for the privacy-preserving execution audit log.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Audit;

interface AuditRecorder
{
    /**
     * @param array<string, int|string> $event
     */
    public function record(array $event): void;
}
