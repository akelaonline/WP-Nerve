<?php

/**
 * Reject known-vulnerable WordPress runtime baselines before G6 evidence runs.
 *
 * WordPress security releases 6.9.5 and 7.0.2 patched the July 2026 REST API
 * batch-route / SQL-injection chain. G6 evidence must not be collected from the
 * affected 6.9.0-6.9.4 or 7.0.0-7.0.1 releases.
 *
 * @package WPNerve
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    throw new RuntimeException('This gate must run inside WordPress through WP-CLI.');
}

$version = (string) get_bloginfo('version');
$affected69 = version_compare($version, '6.9.0', '>=') && version_compare($version, '6.9.5', '<');
$affected70 = version_compare($version, '7.0.0', '>=') && version_compare($version, '7.0.2', '<');

if ($affected69 || $affected70) {
    throw new RuntimeException(
        sprintf(
            'Refusing G6 evidence on WordPress %s: use 6.9.5+ on the 6.9 branch or 7.0.2+ on the 7.0 branch.',
            $version
        )
    );
}

if (version_compare($version, '6.9', '<')) {
    throw new RuntimeException('WPNerve requires WordPress 6.9 or newer.');
}

fwrite(STDOUT, "PASS: WordPress {$version} is outside the known July 2026 vulnerable ranges\n");
fwrite(STDOUT, "WPNERVE_PLATFORM_SECURITY_BASELINE_OK\n");
