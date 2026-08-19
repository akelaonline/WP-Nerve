<?php

/**
 * Fixed-window rate limiter with separate endpoint budgets.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\RateLimit;

use Closure;

final class RateLimiter
{
    /**
     * @var array<string, array{limit: int, window: int}>
     */
    private const DEFAULTS = array(
        'mcp'             => array('limit' => 120, 'window' => 60),
        'oauth_authorize' => array('limit' => 60, 'window' => 60),
        'oauth_token'     => array('limit' => 30, 'window' => 60),
        'oauth_register'  => array('limit' => 10, 'window' => 3600),
    );

    private Closure $clock;

    public function __construct(private readonly WpdbRepository $repository, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function consume(string $bucket, string $subject): Result
    {
        $budget = $this->budget($bucket);
        $now    = ($this->clock)();
        $start  = intdiv($now, $budget['window']) * $budget['window'];
        $reset  = $start + $budget['window'];
        $record = $this->repository->consume(
            $bucket,
            '' !== $subject ? $subject : 'unknown',
            $budget['limit'],
            $start,
            $reset + min($budget['window'], 3600)
        );

        if (null === $record) {
            return Result::unavailable($budget['limit'], $reset);
        }

        return new Result(
            $record['accepted'],
            true,
            $budget['limit'],
            max(0, $budget['limit'] - $record['count']),
            $reset
        );
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /**
     * @return array{limit: int, window: int}
     */
    private function budget(string $bucket): array
    {
        $defaults = self::DEFAULTS[$bucket] ?? array('limit' => 30, 'window' => 60);

        /**
         * Filters a WPNerve endpoint rate-limit budget.
         *
         * The network subject is deliberately not passed to this filter so plugins
         * cannot accidentally log or persist client addresses while tuning limits.
         *
         * @param array{limit: int, window: int} $budget Budget settings.
         * @param string                         $bucket Endpoint bucket name.
         */
        $filtered = apply_filters('wp_nerve_rate_limit_budget', $defaults, $bucket);

        if (! is_array($filtered)) {
            return $defaults;
        }

        $limit  = $filtered['limit'] ?? $defaults['limit'];
        $window = $filtered['window'] ?? $defaults['window'];

        if (! is_int($limit) || $limit < 1 || $limit > 10000) {
            $limit = $defaults['limit'];
        }

        if (! is_int($window) || $window < 1 || $window > DAY_IN_SECONDS) {
            $window = $defaults['window'];
        }

        return array('limit' => $limit, 'window' => $window);
    }
}
