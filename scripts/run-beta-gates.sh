#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-all}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

run_quality() {
  command -v php >/dev/null 2>&1 || fail "php is required"
  command -v composer >/dev/null 2>&1 || fail "composer is required for the quality gate"
  [[ -f vendor/autoload.php ]] || fail "Composer dependencies are missing; run composer install first"

  echo "==> Quality: lint + PHPCS + PHPStan + PHPUnit"
  composer check

  echo "==> Release contract + reproducible archive"
  bash scripts/build-release.sh
}

candidate_zip() {
  if [[ -n "${CANDIDATE_ZIP:-}" ]]; then
    printf '%s\n' "${CANDIDATE_ZIP}"
    return
  fi

  local version
  version="$(sed -n 's/^ \* Version:[[:space:]]*//p' wp-nerve.php | head -n 1)"
  printf '%s/dist/wp-nerve-%s.zip\n' "${ROOT_DIR}" "${version}"
}

run_runtime() {
  [[ -n "${WP_PATH:-}" ]] || fail "WP_PATH is required for runtime gates"

  echo "==> G6 single-site runtime"
  WP_PATH="${WP_PATH}" bash scripts/test-real-wordpress.sh single

  echo "==> G8 archive + retention scale"
  WP_PATH="${WP_PATH}" bash scripts/test-abuse-resistance.sh all

  local zip
  zip="$(candidate_zip)"
  [[ -f "${zip}" ]] || fail "candidate ZIP is missing (${zip}); run quality/build first or set CANDIDATE_ZIP"

  echo "==> G10 clean install / upgrade / uninstall lifecycle"
  WP_PATH="${WP_PATH}" \
  CANDIDATE_ZIP="${zip}" \
  PREVIOUS_ZIP="${PREVIOUS_ZIP:-}" \
  WP_NERVE_RELEASE_TEST=1 \
    bash scripts/test-release-engineering.sh
}

run_multisite() {
  [[ -n "${WP_MULTISITE_PATH:-}" ]] || fail "WP_MULTISITE_PATH is required for the Multisite gate"

  echo "==> G6/G8 Multisite runtime + prefix isolation"
  WP_PATH="${WP_MULTISITE_PATH}" bash scripts/test-real-wordpress.sh multisite
}

run_wire() {
  [[ -n "${WP_NERVE_BASE_URL:-}" ]] || fail "WP_NERVE_BASE_URL is required for wire gates"
  [[ -n "${WP_NERVE_USER:-}" ]] || fail "WP_NERVE_USER is required for wire gates"
  [[ -n "${WP_NERVE_APPLICATION_PASSWORD:-}" ]] || fail "WP_NERVE_APPLICATION_PASSWORD is required for wire gates"
  command -v python3 >/dev/null 2>&1 || fail "python3 is required for wire gates"

  echo "==> G7 strict deterministic MCP wire contract"
  python3 tests/wire/mcp_contract.py

  echo "==> G8 deterministic real-HTTP mutation corpus"
  python3 tests/wire/mcp_mutation_fuzz.py
}

case "${MODE}" in
  quality)
    run_quality
    ;;
  runtime)
    run_runtime
    ;;
  multisite)
    run_multisite
    ;;
  wire)
    run_wire
    ;;
  all)
    run_quality
    run_runtime
    if [[ -n "${WP_MULTISITE_PATH:-}" ]]; then
      run_multisite
    else
      echo "PENDING: WP_MULTISITE_PATH not set; Multisite evidence not executed." >&2
    fi
    if [[ -n "${WP_NERVE_BASE_URL:-}" && -n "${WP_NERVE_USER:-}" && -n "${WP_NERVE_APPLICATION_PASSWORD:-}" ]]; then
      run_wire
    else
      echo "PENDING: wire environment variables not set; G7/G8 HTTP evidence not executed." >&2
    fi
    ;;
  *)
    echo "Usage: bash $0 [quality|runtime|multisite|wire|all]" >&2
    exit 2
    ;;
esac

echo "WPNERVE_BETA_GATE_RUNNER_OK mode=${MODE}"
