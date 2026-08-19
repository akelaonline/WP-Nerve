#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-single}"
WP_PATH="${WP_PATH:-}"
ALLOW_PRODUCTION="${WP_NERVE_ALLOW_PRODUCTION_RUNTIME_TEST:-0}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLATFORM_GATE="${ROOT_DIR}/tests/real-wordpress/platform-security.php"
SINGLE_GATE="${ROOT_DIR}/tests/real-wordpress/single-site.php"
MULTISITE_GATE="${ROOT_DIR}/tests/real-wordpress/multisite.php"

if ! command -v wp >/dev/null 2>&1; then
  echo "ERROR: wp (WP-CLI) is required." >&2
  exit 2
fi

if [[ -z "${WP_PATH}" ]]; then
  echo "ERROR: set WP_PATH to a real WordPress installation." >&2
  exit 2
fi

if ! wp --path="${WP_PATH}" core is-installed >/dev/null 2>&1; then
  echo "ERROR: WP_PATH is not an installed WordPress site." >&2
  exit 2
fi

ENVIRONMENT="$(wp --path="${WP_PATH}" eval 'echo wp_get_environment_type();')"
if [[ "${ENVIRONMENT}" == "production" && "${ALLOW_PRODUCTION}" != "1" ]]; then
  echo "ERROR: refusing to run stateful runtime probes on a production environment." >&2
  echo "Use a disposable/staging WordPress install. Override only with WP_NERVE_ALLOW_PRODUCTION_RUNTIME_TEST=1." >&2
  exit 3
fi

run_gate() {
  local url="$1"
  local gate="$2"

  if [[ -n "${url}" ]]; then
    echo "==> ${url} :: $(basename "${gate}")"
    wp --path="${WP_PATH}" --url="${url}" eval-file "${gate}"
  else
    echo "==> ${WP_PATH} :: $(basename "${gate}")"
    wp --path="${WP_PATH}" eval-file "${gate}"
  fi
}

case "${MODE}" in
  single)
    run_gate "" "${PLATFORM_GATE}"
    run_gate "" "${SINGLE_GATE}"
    ;;

  multisite)
    if ! wp --path="${WP_PATH}" core is-installed --network >/dev/null 2>&1; then
      echo "ERROR: multisite mode requires a WordPress Multisite installation." >&2
      exit 2
    fi

    SITE_URLS=()
    while IFS= read -r site_url; do
      [[ -n "${site_url}" ]] && SITE_URLS[${#SITE_URLS[@]}]="${site_url}"
    done < <(wp --path="${WP_PATH}" site list --field=url | head -n 10)

    if [[ "${#SITE_URLS[@]}" -eq 0 ]]; then
      echo "ERROR: no Multisite URLs found." >&2
      exit 2
    fi

    # Each URL runs in a fresh WP-CLI process. This matters because WPNerve's
    # composition root is intentionally boot-once per PHP request.
    for site_url in "${SITE_URLS[@]}"; do
      run_gate "${site_url}" "${PLATFORM_GATE}"
      run_gate "${site_url}" "${SINGLE_GATE}"
    done

    run_gate "${SITE_URLS[0]}" "${MULTISITE_GATE}"
    ;;

  *)
    echo "Usage: WP_PATH=/path/to/wordpress bash $0 [single|multisite]" >&2
    exit 2
    ;;
esac

echo "WPNERVE_REAL_WORDPRESS_RUNTIME_OK"
