<?php

/**
 * Executes mutating tools exactly once for a user/tool/key tuple.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Security\Idempotency;

use JsonException;
use WP_Error;

final class Service
{
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D';

    public function __construct(
        private readonly Repository $repository,
        private readonly CanonicalJson $canonicalJson
    ) {
    }

    /**
     * @param array<string, mixed>            $arguments
     * @param callable(): (array<string, mixed>|WP_Error) $operation
     * @return array<string, mixed>|WP_Error
     */
    public function execute(
        string $toolName,
        string $risk,
        array $arguments,
        mixed $key,
        string $credentialId,
        callable $operation
    ): array|WP_Error {
        if ('read' === $risk) {
            return $operation();
        }

        if (! is_string($key) || '' === $key) {
            return $this->error(
                'wp_nerve_idempotency_key_required',
                __('Mutating tools require an idempotency key in params._meta["wp-nerve/idempotencyKey"].', 'wp-nerve')
            );
        }

        if (1 !== preg_match(self::KEY_PATTERN, $key)) {
            return $this->error(
                'wp_nerve_idempotency_key_invalid',
                __('The idempotency key must be 8-128 ASCII letters, digits, dots, colons, underscores, or hyphens.', 'wp-nerve')
            );
        }

        try {
            $requestHash = $this->canonicalJson->hash($toolName, $arguments);
        } catch (JsonException) {
            return $this->error(
                'wp_nerve_idempotency_input_invalid',
                __('Tool arguments cannot be canonicalized safely.', 'wp-nerve')
            );
        }

        $userId = get_current_user_id();

        if ($userId <= 0) {
            return $this->error(
                'wp_nerve_idempotency_actor_missing',
                __('An authenticated user is required.', 'wp-nerve')
            );
        }

        if ('' === $credentialId) {
            return $this->error(
                'wp_nerve_idempotency_credential_missing',
                __('An authenticated credential identity is required for mutating tools.', 'wp-nerve')
            );
        }

        $claim = $this->repository->claim($userId, $credentialId, $toolName, $key, $requestHash);

        if (ClaimState::Conflict === $claim->state) {
            return $this->error(
                'wp_nerve_idempotency_conflict',
                __('This idempotency key was already used with different tool arguments.', 'wp-nerve')
            );
        }

        if (ClaimState::InProgress === $claim->state) {
            return $this->error(
                'wp_nerve_idempotency_in_progress',
                __('This operation is already running or its final state is indeterminate. It will not be repeated automatically.', 'wp-nerve')
            );
        }

        if (ClaimState::Expired === $claim->state) {
            return $this->error(
                'wp_nerve_idempotency_expired',
                __('The stored outcome has expired. Use a new idempotency key after verifying the original operation.', 'wp-nerve')
            );
        }

        if (ClaimState::Unavailable === $claim->state) {
            return $this->error(
                'wp_nerve_idempotency_unavailable',
                __('The idempotency store is unavailable; the mutation was not executed.', 'wp-nerve')
            );
        }

        if (ClaimState::Replay === $claim->state) {
            return $this->decodeOutcome($claim->outcome);
        }

        $result  = $operation();
        $outcome = $this->encodeOutcome($result);

        if (! $this->repository->complete($userId, $credentialId, $toolName, $key, $requestHash, $outcome)) {
            return $this->error(
                'wp_nerve_idempotency_completion_failed',
                __('The operation ran but its result could not be persisted. Its key is locked to prevent an unsafe retry.', 'wp-nerve')
            );
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function encodeOutcome(array|WP_Error $result): array
    {
        if ($result instanceof WP_Error) {
            return array(
                'kind'    => 'error',
                'code'    => (string) $result->get_error_code(),
                'message' => $result->get_error_message(),
            );
        }

        return array('kind' => 'success', 'value' => $result);
    }

    /**
     * @param array<string, mixed>|null $outcome
     * @return array<string, mixed>|WP_Error
     */
    private function decodeOutcome(?array $outcome): array|WP_Error
    {
        if (! is_array($outcome)) {
            return $this->error(
                'wp_nerve_idempotency_corrupt',
                __('The stored idempotency outcome is invalid.', 'wp-nerve')
            );
        }

        if ('error' === ($outcome['kind'] ?? null)) {
            $code    = is_string($outcome['code'] ?? null) ? $outcome['code'] : 'wp_nerve_idempotency_replayed_error';
            $message = is_string($outcome['message'] ?? null)
                ? $outcome['message']
                : __('The previous execution failed.', 'wp-nerve');

            return new WP_Error($code, $message);
        }

        $value = $outcome['value'] ?? null;

        return 'success' === ($outcome['kind'] ?? null) && is_array($value)
            ? $value
            : $this->error(
                'wp_nerve_idempotency_corrupt',
                __('The stored idempotency outcome is invalid.', 'wp-nerve')
            );
    }

    private function error(string $code, string $message): WP_Error
    {
        return new WP_Error($code, $message);
    }
}
