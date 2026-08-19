#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-all}"
WP_PATH="${WP_PATH:-}"
ALLOW_PRODUCTION="${WP_NERVE_ALLOW_PRODUCTION_RUNTIME_TEST:-0}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARCHIVE_GATE="${ROOT_DIR}/tests/real-wordpress/abuse-resistance.php"
RETENTION_GATE="${ROOT_DIR}/tests/real-wordpress/retention-scale.php"

if ! command -v wp >/dev/null 2>&1; then
  echo "ERROR: wp (WP-CLI) is required." >&2
  exit 2
fi

if [[ -z "${WP_PATH}" ]]; then
  echo "ERROR: set WP_PATH to a disposable/staging WordPress installation." >&2
  exit 2
fi

if ! wp --path="${WP_PATH}" core is-installed >/dev/null 2>&1; then
  echo "ERROR: WP_PATH is not an installed WordPress site." >&2
  exit 2
fi

ENVIRONMENT="$(wp --path="${WP_PATH}" eval 'echo wp_get_environment_type();')"
if [[ "${ENVIRONMENT}" == "production" && "${ALLOW_PRODUCTION}" != "1" ]]; then
  echo "ERROR: refusing to run G8 stateful abuse probes on a production environment." >&2
  echo "Use a disposable/staging WordPress install. Override only with WP_NERVE_ALLOW_PRODUCTION_RUNTIME_TEST=1." >&2
  exit 3
fi

wp --path="${WP_PATH}" eval '
$v = (string) get_bloginfo("version");
$affected69 = version_compare($v, "6.9.0", ">=") && version_compare($v, "6.9.5", "<");
$affected70 = version_compare($v, "7.0.0", ">=") && version_compare($v, "7.0.2", "<");
if ($affected69 || $affected70 || version_compare($v, "6.9", "<")) {
    fwrite(STDERR, "ERROR: refusing G8 evidence on WordPress {$v}; use a patched supported Core release.\n");
    exit(4);
}
echo "PASS: patched WordPress baseline {$v}\n";
'

case "${MODE}" in
  archive)
    wp --path="${WP_PATH}" eval-file "${ARCHIVE_GATE}"
    ;;
  retention)
    wp --path="${WP_PATH}" eval-file "${RETENTION_GATE}"
    ;;
  all)
    wp --path="${WP_PATH}" eval-file "${ARCHIVE_GATE}"
    wp --path="${WP_PATH}" eval-file "${RETENTION_GATE}"
    ;;
  *)
    echo "Usage: WP_PATH=/path/to/wordpress bash $0 [archive|retention|all]" >&2
    exit 2
    ;;
esac

echo "WPNERVE_REAL_WORDPRESS_G8_RUNTIME_OK mode=${MODE}"
