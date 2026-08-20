<?php

/**
 * Real WordPress runtime gate for WPNerve.
 *
 * Run with:
 *   wp eval-file tests/real-wordpress/single-site.php --path=/path/to/wordpress
 *
 * This file intentionally does not load the unit-test runtime doubles.
 *
 * @package WPNerve
 */

declare(strict_types=1);

use WPNerve\Audit\AuditRepository;
use WPNerve\Infrastructure\Activator;
use WPNerve\OAuth\OAuthStore;
use WPNerve\Security\Confirmation\AuthorizationState;
use WPNerve\Security\Confirmation\WpdbRepository as ConfirmationRepository;
use WPNerve\Security\Idempotency\ClaimState;
use WPNerve\Security\Idempotency\WpdbRepository as IdempotencyRepository;
use WPNerve\Security\RateLimit\RateLimiter;
use WPNerve\Security\RateLimit\WpdbRepository as RateLimitRepository;

if (! defined('ABSPATH') || ! function_exists('wp_get_abilities')) {
    throw new RuntimeException('This gate must run inside WordPress 6.9+ through WP-CLI.');
}

if (! defined('WP_NERVE_VERSION')) {
    throw new RuntimeException('WPNerve is not active in this WordPress runtime.');
}

/** @param mixed $actual */
function wp_nerve_runtime_assert(bool $condition, string $message, mixed $actual = null): void
{
    if (! $condition) {
        $detail = null === $actual ? '' : ' Actual: ' . wp_json_encode($actual);
        throw new RuntimeException('FAIL: ' . $message . $detail);
    }

    fwrite(STDOUT, "PASS: {$message}\n");
}

function wp_nerve_runtime_admin_id(): int
{
    $users = get_users(
        array(
            'role'   => 'administrator',
            'number' => 1,
            'fields' => 'ID',
        )
    );

    $id = isset($users[0]) ? (int) $users[0] : 0;
    wp_nerve_runtime_assert($id > 0, 'an administrator user exists');

    return $id;
}

/** @return array<int, string> */
function wp_nerve_runtime_tables(): array
{
    global $wpdb;

    return array(
        AuditRepository::tableName(),
        IdempotencyRepository::tableName(),
        ConfirmationRepository::tableName(),
        RateLimitRepository::tableName(),
        $wpdb->prefix . 'wp_nerve_oauth_clients',
        $wpdb->prefix . 'wp_nerve_oauth_tokens',
    );
}

function wp_nerve_runtime_cleanup(string $toolName, string $rateBucket, string $oauthClientId): void
{
    global $wpdb;

    $wpdb->delete(IdempotencyRepository::tableName(), array('tool_name' => $toolName), array('%s'));
    $wpdb->delete(ConfirmationRepository::tableName(), array('tool_name' => $toolName), array('%s'));
    $wpdb->delete(RateLimitRepository::tableName(), array('bucket_name' => $rateBucket), array('%s'));

    if ('' !== $oauthClientId) {
        $wpdb->delete($wpdb->prefix . 'wp_nerve_oauth_tokens', array('client_id' => $oauthClientId), array('%s'));
        $wpdb->delete($wpdb->prefix . 'wp_nerve_oauth_clients', array('client_id' => $oauthClientId), array('%s'));
    }
}

$adminId = wp_nerve_runtime_admin_id();
wp_set_current_user($adminId);

wp_nerve_runtime_assert(version_compare((string) get_bloginfo('version'), '6.9', '>='), 'WordPress is 6.9 or newer');
wp_nerve_runtime_assert(version_compare(PHP_VERSION, '8.1', '>='), 'PHP is 8.1 or newer');
wp_nerve_runtime_assert('0.1.0-alpha.10' === WP_NERVE_VERSION, 'WPNerve alpha.10 is active', WP_NERVE_VERSION);
wp_nerve_runtime_assert(Activator::SCHEMA_VERSION === (string) get_option('wp_nerve_schema_version'), 'schema contract is current');

foreach (wp_nerve_runtime_tables() as $table) {
    global $wpdb;
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    wp_nerve_runtime_assert($table === $found, 'database table exists: ' . $table, $found);
}

$abilities = wp_get_abilities();
$wpNerveAbilities = array_values(
    array_filter(
        $abilities,
        static fn ($ability): bool => $ability instanceof WP_Ability
            && str_starts_with($ability->get_name(), 'wp-nerve/')
    )
);
wp_nerve_runtime_assert(53 === count($wpNerveAbilities), '53 WPNerve abilities are registered', count($wpNerveAbilities));

$siteStatus = wp_get_ability('wp-nerve/site-status');
wp_nerve_runtime_assert($siteStatus instanceof WP_Ability, 'site-status resolves through the native Abilities API');
$status = $siteStatus->execute(array());
wp_nerve_runtime_assert(! is_wp_error($status) && is_array($status), 'native site-status executes successfully', $status);
wp_nerve_runtime_assert(WP_NERVE_VERSION === ($status['wpnerve_version'] ?? null), 'site-status reports the active WPNerve version');
wp_nerve_runtime_assert(is_multisite() === ($status['multisite'] ?? null), 'site-status reports the real Multisite state');

$runId       = bin2hex(random_bytes(8));
$toolName    = 'runtime-probe-' . $runId;
$rateBucket  = 'runtime-rate-' . $runId;
$credential  = 'runtime-credential-' . $runId;
$key         = 'runtime-key-' . $runId;
$requestHash = hash('sha256', 'runtime-request-' . $runId);
$oauthClientId = '';

try {
    $idempotency = new IdempotencyRepository();
    $firstClaim  = $idempotency->claim($adminId, $credential, $toolName, $key, $requestHash);
    wp_nerve_runtime_assert(ClaimState::Acquired === $firstClaim->state, 'real DB idempotency claim is acquired');

    $outcome = array('ok' => true, 'run_id' => $runId);
    wp_nerve_runtime_assert(
        $idempotency->complete($adminId, $credential, $toolName, $key, $requestHash, $outcome),
        'real DB idempotency claim completes atomically'
    );

    $replay = $idempotency->claim($adminId, $credential, $toolName, $key, $requestHash);
    wp_nerve_runtime_assert(ClaimState::Replay === $replay->state, 'completed mutation replays instead of reacquiring');
    wp_nerve_runtime_assert($outcome === $replay->outcome, 'replayed outcome matches persisted outcome');

    $conflict = $idempotency->claim(
        $adminId,
        $credential,
        $toolName,
        $key,
        hash('sha256', 'changed-request-' . $runId)
    );
    wp_nerve_runtime_assert(ClaimState::Conflict === $conflict->state, 'changed request cannot reuse an idempotency key');

    add_filter(
        'wp_nerve_rate_limit_budget',
        static function (array $budget, string $bucket) use ($rateBucket): array {
            return $bucket === $rateBucket ? array('limit' => 1, 'window' => 60) : $budget;
        },
        10,
        2
    );

    $limiter = new RateLimiter(new RateLimitRepository(), static fn (): int => 1787140800);
    $first   = $limiter->consume($rateBucket, 'runtime-subject-' . $runId);
    $second  = $limiter->consume($rateBucket, 'runtime-subject-' . $runId);
    wp_nerve_runtime_assert($first->available && $first->allowed, 'real DB rate-limit first request is allowed');
    wp_nerve_runtime_assert($second->available && ! $second->allowed, 'real DB rate-limit exhaustion is enforced');

    $confirmation = new ConfirmationRepository();
    $tokenHash    = hash('sha256', 'confirmation-token-' . $runId);
    $displayCode  = strtoupper(substr($runId, 0, 8));
    $expiresAt    = time() + 300;

    wp_nerve_runtime_assert(
        $confirmation->issue(
            $adminId,
            $credential,
            $toolName,
            'privileged',
            $requestHash,
            $key,
            $tokenHash,
            $displayCode,
            $expiresAt
        ),
        'real DB confirmation challenge is persisted'
    );

    $pending = $confirmation->authorize($adminId, $credential, $toolName, $requestHash, $key, $tokenHash);
    wp_nerve_runtime_assert(AuthorizationState::Pending === $pending->state, 'confirmation is pending before admin decision');

    $challengeId = 0;
    foreach ($confirmation->pending() as $challenge) {
        if (($challenge['display_code'] ?? '') === $displayCode) {
            $challengeId = (int) ($challenge['id'] ?? 0);
            break;
        }
    }
    wp_nerve_runtime_assert($challengeId > 0, 'pending confirmation is visible to the admin repository');
    wp_nerve_runtime_assert($confirmation->decide($challengeId, $adminId, true), 'admin approval is persisted');

    $approved = $confirmation->authorize($adminId, $credential, $toolName, $requestHash, $key, $tokenHash);
    wp_nerve_runtime_assert(AuthorizationState::Approved === $approved->state, 'approved confirmation is consumed once');
    $confirmationReplay = $confirmation->authorize($adminId, $credential, $toolName, $requestHash, $key, $tokenHash);
    wp_nerve_runtime_assert(AuthorizationState::Replay === $confirmationReplay->state, 'consumed confirmation cannot authorize twice');

    $oauth = new OAuthStore();
    $oauthClientId = $oauth->createClient(
        array(
            'client_name'   => 'runtime-' . $runId,
            'redirect_uris' => array('https://example.test/runtime-callback'),
        )
    );
    wp_nerve_runtime_assert('' !== $oauthClientId, 'OAuth client persists in the real database');

    $authorizationCode = 'runtime-code-' . $runId;
    wp_nerve_runtime_assert(
        $oauth->storeAuthorizationCode(
            $authorizationCode,
            $oauthClientId,
            $adminId,
            str_repeat('A', 43),
            'https://example.test/runtime-callback'
        ),
        'OAuth authorization code persists in the real database'
    );
    wp_nerve_runtime_assert(is_array($oauth->consumeAuthorizationCode($authorizationCode)), 'OAuth authorization code is consumable once');
    wp_nerve_runtime_assert(null === $oauth->consumeAuthorizationCode($authorizationCode), 'OAuth authorization-code replay is rejected');

    $tokens = $oauth->issueTokens($oauthClientId, $adminId);
    wp_nerve_runtime_assert(! is_wp_error($tokens), 'OAuth access/refresh pair is issued against the real database', $tokens);
    wp_nerve_runtime_assert($adminId === $oauth->validateAccessToken($tokens['access_token']), 'OAuth access token validates to the real user');

    $rotated = $oauth->refreshAccessToken($tokens['refresh_token'], $oauthClientId);
    wp_nerve_runtime_assert(! is_wp_error($rotated), 'OAuth refresh token rotates successfully', $rotated);
    wp_nerve_runtime_assert(is_wp_error($oauth->refreshAccessToken($tokens['refresh_token'], $oauthClientId)), 'rotated refresh token cannot be replayed');
    wp_nerve_runtime_assert($oauth->revokeToken($rotated['access_token'], $oauthClientId), 'OAuth access token can be revoked');
    wp_nerve_runtime_assert(null === $oauth->validateAccessToken($rotated['access_token']), 'revoked OAuth access token no longer authenticates');
} finally {
    wp_nerve_runtime_cleanup($toolName, $rateBucket, $oauthClientId);
}

fwrite(STDOUT, "WPNERVE_REAL_WORDPRESS_SINGLE_SITE_OK\n");
