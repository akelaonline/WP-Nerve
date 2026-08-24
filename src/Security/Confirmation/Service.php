<?php

/**
 * Issues and verifies confirmation challenges for high-risk tools.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Confirmation;

use JsonException;
use Throwable;
use WP_Error;
use WPNerve\Security\Idempotency\CanonicalJson;

final class Service
{
    private const DEFAULT_TTL = 300;
    private const MINIMUM_TTL = 60;
    private const MAXIMUM_TTL = 900;
    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D';
    private const TOKEN_PATTERN = '/^wpc_[A-Za-z0-9_-]{43}$/D';

    public function __construct(
        private readonly Repository $repository,
        private readonly CanonicalJson $canonicalJson
    ) {
    }

    /** @param array<string, mixed> $arguments */
    public function authorize(
        string $toolName,
        string $risk,
        array $arguments,
        mixed $idempotencyKey,
        string $credentialId,
        mixed $token
    ): bool|WP_Error {
        if (! in_array($risk, array('destructive', 'privileged'), true)) {
            return true;
        }

        if (! is_string($idempotencyKey) || 1 !== preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey)) {
            return $this->error('wp_nerve_confirmation_idempotency_key_required', __('A valid idempotency key is required before requesting confirmation.', 'wp-nerve'));
        }

        $userId = get_current_user_id();
        if ($userId <= 0 || '' === $credentialId) {
            return $this->error('wp_nerve_confirmation_identity_missing', __('An authenticated user and credential identity are required for confirmation.', 'wp-nerve'));
        }

        try {
            $requestHash = $this->canonicalJson->hash($toolName, $arguments);
        } catch (JsonException) {
            return $this->error('wp_nerve_confirmation_input_invalid', __('Tool arguments cannot be bound to a confirmation safely.', 'wp-nerve'));
        }

        if (null === $token || '' === $token) {
            return $this->issue($userId, $credentialId, $toolName, $risk, $requestHash, $idempotencyKey);
        }

        if (! is_string($token) || 1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            return $this->error('wp_nerve_confirmation_invalid', __('The confirmation token is invalid.', 'wp-nerve'));
        }

        $authorization = $this->repository->authorize($userId, $credentialId, $toolName, $requestHash, $idempotencyKey, self::hash($token));
        if (in_array($authorization->state, array(AuthorizationState::Approved, AuthorizationState::Replay), true)) {
            return true;
        }

        return match ($authorization->state) {
            AuthorizationState::Pending => $this->error(
                'wp_nerve_confirmation_pending',
                __('This operation is waiting for approval in WPNerve → Dashboard.', 'wp-nerve'),
                $this->metadata('pending', $toolName, $risk, $authorization->displayCode, $authorization->expiresAt)
            ),
            AuthorizationState::Denied => $this->error('wp_nerve_confirmation_denied', __('This operation was denied by a WordPress administrator.', 'wp-nerve')),
            AuthorizationState::Expired => $this->error('wp_nerve_confirmation_expired', __('This confirmation expired. Request a new confirmation before retrying.', 'wp-nerve')),
            AuthorizationState::Conflict => $this->error('wp_nerve_confirmation_conflict', __('The confirmation does not match this user, credential, tool, arguments, or idempotency key.', 'wp-nerve')),
            AuthorizationState::Invalid => $this->error('wp_nerve_confirmation_invalid', __('The confirmation token is invalid.', 'wp-nerve')),
            default => $this->error('wp_nerve_confirmation_unavailable', __('The confirmation store is unavailable; the operation was not executed.', 'wp-nerve')),
        };
    }

    private function issue(
        int $userId,
        string $credentialId,
        string $toolName,
        string $risk,
        string $requestHash,
        string $idempotencyKey
    ): WP_Error {
        try {
            $token = 'wpc_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (Throwable) {
            return $this->error('wp_nerve_confirmation_entropy_unavailable', __('A secure confirmation token could not be generated.', 'wp-nerve'));
        }

        $digest = self::hash($token);
        $displayCode = strtoupper(substr($digest, 0, 4) . '-' . substr($digest, 4, 4));
        $expiresAt = time() + self::ttl();
        $issued = $this->repository->issue($userId, $credentialId, $toolName, $risk, $requestHash, $idempotencyKey, $digest, $displayCode, $expiresAt);
        if (! $issued) {
            return $this->error('wp_nerve_confirmation_unavailable', __('The confirmation store is unavailable; the operation was not executed.', 'wp-nerve'));
        }

        return $this->error(
            'wp_nerve_confirmation_required',
            __('Approval is required in WPNerve → Dashboard before this operation can execute.', 'wp-nerve'),
            $this->metadata('pending', $toolName, $risk, $displayCode, $expiresAt, $token)
        );
    }

    /** @return array<string, mixed> */
    private function metadata(
        string $status,
        string $toolName,
        string $risk,
        string $displayCode,
        int $expiresAt,
        ?string $token = null
    ): array {
        $confirmation = array(
            'status' => $status,
            'displayCode' => $displayCode,
            'expiresAt' => gmdate(DATE_ATOM, $expiresAt),
            'tool' => $toolName,
            'risk' => $risk,
            'instructions' => __('Match this code in WPNerve → Dashboard, approve it, then retry the exact call with the same keys.', 'wp-nerve'),
        );
        if (null !== $token) {
            $confirmation['token'] = $token;
        }
        return array('wp_nerve_confirmation' => $confirmation);
    }

    /** @param array<string, mixed> $data */
    private function error(string $code, string $message, array $data = array()): WP_Error
    {
        return new WP_Error($code, $message, $data);
    }

    private static function ttl(): int
    {
        /** @param int $seconds Confirmation lifetime in seconds. */
        $seconds = apply_filters('wp_nerve_confirmation_ttl', self::DEFAULT_TTL);
        return is_int($seconds) && $seconds >= self::MINIMUM_TTL && $seconds <= self::MAXIMUM_TTL ? $seconds : self::DEFAULT_TTL;
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
