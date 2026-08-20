#!/usr/bin/env bash
set -euo pipefail

WP_PATH="${WP_PATH:-}"
CANDIDATE_ZIP="${CANDIDATE_ZIP:-}"
PREVIOUS_ZIP="${PREVIOUS_ZIP:-}"
ALLOW_PRODUCTION="${WP_NERVE_ALLOW_PRODUCTION_RUNTIME_TEST:-0}"
RELEASE_TEST="${WP_NERVE_RELEASE_TEST:-0}"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

[[ "${RELEASE_TEST}" == "1" ]] || fail "set WP_NERVE_RELEASE_TEST=1 to confirm this is a disposable release-test site"
command -v wp >/dev/null 2>&1 || fail "wp (WP-CLI) is required"
command -v python3 >/dev/null 2>&1 || fail "python3 is required"
[[ -n "${WP_PATH}" ]] || fail "set WP_PATH to a disposable/staging WordPress installation"
[[ -n "${CANDIDATE_ZIP}" && -f "${CANDIDATE_ZIP}" ]] || fail "set CANDIDATE_ZIP to the built release ZIP"
[[ -z "${PREVIOUS_ZIP}" || -f "${PREVIOUS_ZIP}" ]] || fail "PREVIOUS_ZIP does not exist"

wp --path="${WP_PATH}" core is-installed >/dev/null 2>&1 || fail "WP_PATH is not an installed WordPress site"

environment="$(wp --path="${WP_PATH}" eval 'echo wp_get_environment_type();')"
if [[ "${environment}" == "production" && "${ALLOW_PRODUCTION}" != "1" ]]; then
  fail "refusing release lifecycle tests on a production environment"
fi

wp --path="${WP_PATH}" eval '
$v = (string) get_bloginfo("version");
$affected69 = version_compare($v, "6.9.0", ">=") && version_compare($v, "6.9.5", "<");
$affected70 = version_compare($v, "7.0.0", ">=") && version_compare($v, "7.0.2", "<");
if (version_compare($v, "6.9", "<") || $affected69 || $affected70) {
    fwrite(STDERR, "release evidence requires a patched supported WordPress baseline; found {$v}\n");
    exit(4);
}
if (version_compare(PHP_VERSION, "8.1", "<")) {
    fwrite(STDERR, "release evidence requires PHP 8.1+; found " . PHP_VERSION . "\n");
    exit(5);
}
echo "PASS: patched runtime WordPress {$v}, PHP " . PHP_VERSION . "\n";
'

if wp --path="${WP_PATH}" plugin is-installed wp-nerve >/dev/null 2>&1; then
  fail "WPNerve is already installed; use a fresh disposable site so lifecycle evidence is unambiguous"
fi

candidate_version="$(python3 - "${CANDIDATE_ZIP}" <<'PY'
import sys, zipfile
with zipfile.ZipFile(sys.argv[1]) as z:
    text = z.read('wp-nerve/wp-nerve.php').decode('utf-8')
for line in text.splitlines():
    if line.startswith(' * Version:'):
        print(line.split(':', 1)[1].strip())
        break
else:
    raise SystemExit(1)
PY
)"
[[ -n "${candidate_version}" ]] || fail "candidate version could not be read"

if [[ -n "${PREVIOUS_ZIP}" ]]; then
  echo "==> Upgrade path: install previous package"
  wp --path="${WP_PATH}" plugin install "${PREVIOUS_ZIP}" --activate >/dev/null
  previous_version="$(wp --path="${WP_PATH}" plugin get wp-nerve --field=version)"
  echo "PASS: previous WPNerve ${previous_version} active"

  echo "==> Upgrade path: overwrite with candidate ${candidate_version}"
  wp --path="${WP_PATH}" plugin install "${CANDIDATE_ZIP}" --force >/dev/null
else
  echo "==> Clean install candidate ${candidate_version}"
  wp --path="${WP_PATH}" plugin install "${CANDIDATE_ZIP}" --activate >/dev/null
fi

installed_version="$(wp --path="${WP_PATH}" plugin get wp-nerve --field=version)"
[[ "${installed_version}" == "${candidate_version}" ]] || fail "installed ${installed_version}; expected ${candidate_version}"
wp --path="${WP_PATH}" plugin is-active wp-nerve >/dev/null || fail "candidate is not active"

echo "==> Verify schema and real Abilities registry"
wp --path="${WP_PATH}" eval '
global $wpdb;
$expected = array(
    "wp_nerve_audit_log",
    "wp_nerve_idempotency",
    "wp_nerve_confirmations",
    "wp_nerve_rate_limits",
    "wp_nerve_oauth_clients",
    "wp_nerve_oauth_tokens",
);
if ("6" !== (string) get_option("wp_nerve_schema_version")) {
    fwrite(STDERR, "schema version is not 6\n");
    exit(10);
}
foreach ($expected as $suffix) {
    $table = $wpdb->prefix . $suffix;
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
        fwrite(STDERR, "missing table {$table}\n");
        exit(11);
    }
}
if (! did_action("wp_abilities_api_categories_init")) {
    do_action("wp_abilities_api_categories_init");
}
if (! did_action("wp_abilities_api_init")) {
    do_action("wp_abilities_api_init");
}
$abilities = array_filter(
    wp_get_abilities(),
    static fn ($ability): bool => str_starts_with($ability->get_name(), "wp-nerve/")
);
if (53 !== count($abilities)) {
    fwrite(STDERR, "expected 53 WPNerve abilities, found " . count($abilities) . "\n");
    exit(12);
}
echo "PASS: schema v6, six tables, 53 real abilities\n";
'

echo "==> Default uninstall must preserve WPNerve data"
wp --path="${WP_PATH}" option delete wp_nerve_delete_data_on_uninstall >/dev/null 2>&1 || true
wp --path="${WP_PATH}" plugin deactivate wp-nerve >/dev/null
wp --path="${WP_PATH}" plugin uninstall wp-nerve >/dev/null

wp --path="${WP_PATH}" eval '
global $wpdb;
$table = $wpdb->prefix . "wp_nerve_audit_log";
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
    fwrite(STDERR, "default uninstall removed retained tables\n");
    exit(20);
}
if ("6" !== (string) get_option("wp_nerve_schema_version")) {
    fwrite(STDERR, "default uninstall removed schema marker\n");
    exit(21);
}
echo "PASS: default uninstall preserved WPNerve data\n";
'

echo "==> Reinstall candidate after retained-data uninstall"
wp --path="${WP_PATH}" plugin install "${CANDIDATE_ZIP}" --activate >/dev/null
[[ "$(wp --path="${WP_PATH}" plugin get wp-nerve --field=version)" == "${candidate_version}" ]] || fail "reinstall version mismatch"

echo "==> Explicit destructive uninstall must remove WPNerve-owned data"
wp --path="${WP_PATH}" option update wp_nerve_delete_data_on_uninstall 1 >/dev/null
wp --path="${WP_PATH}" plugin deactivate wp-nerve >/dev/null
wp --path="${WP_PATH}" plugin uninstall wp-nerve >/dev/null

wp --path="${WP_PATH}" eval '
global $wpdb;
$expected = array(
    "wp_nerve_audit_log",
    "wp_nerve_idempotency",
    "wp_nerve_confirmations",
    "wp_nerve_rate_limits",
    "wp_nerve_oauth_clients",
    "wp_nerve_oauth_tokens",
);
foreach ($expected as $suffix) {
    $table = $wpdb->prefix . $suffix;
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
        fwrite(STDERR, "explicit uninstall retained {$table}\n");
        exit(30);
    }
}
if (false !== get_option("wp_nerve_schema_version", false)) {
    fwrite(STDERR, "explicit uninstall retained schema marker\n");
    exit(31);
}
echo "PASS: explicit uninstall removed all WPNerve-owned tables and schema marker\n";
'

echo "WPNERVE_RELEASE_ENGINEERING_OK version=${candidate_version}"
