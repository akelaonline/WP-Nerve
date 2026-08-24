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

run_runtime_core() {
  [[ -n "${WP_PATH:-}" ]] || fail "WP_PATH is required for single-site runtime gates"

  echo "==> G6 single-site runtime"
  WP_PATH="${WP_PATH}" bash scripts/test-real-wordpress.sh single

  echo "==> G8 archive + retention scale"
  WP_PATH="${WP_PATH}" bash scripts/test-abuse-resistance.sh all
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

  echo "==> G7 deterministic MCP wire contract"
  python3 tests/wire/mcp_contract.py

  echo "==> G8 deterministic real-HTTP mutation corpus"
  python3 tests/wire/mcp_mutation_fuzz.py
}

run_release_case() {
  local label="$1"
  local previous="$2"
  local zip
  zip="$(candidate_zip)"

  [[ -n "${WP_RELEASE_PATH:-}" ]] || fail "WP_RELEASE_PATH is required for destructive G10 lifecycle tests"
  [[ -f "${zip}" ]] || fail "candidate ZIP is missing (${zip}); run quality/build first or set CANDIDATE_ZIP"
  [[ -z "${previous}" || -f "${previous}" ]] || fail "${label} previous ZIP does not exist: ${previous}"

  echo "==> G10 ${label} lifecycle"
  WP_PATH="${WP_RELEASE_PATH}" \
  CANDIDATE_ZIP="${zip}" \
  PREVIOUS_ZIP="${previous}" \
  WP_NERVE_RELEASE_TEST=1 \
    bash scripts/test-release-engineering.sh
}

run_release_lifecycle() {
  run_release_case "clean-install" ""

  if [[ -n "${PREVIOUS_ZIP_ALPHA9:-}" ]]; then
    run_release_case "alpha.9-upgrade" "${PREVIOUS_ZIP_ALPHA9}"
  else
    echo "PENDING: PREVIOUS_ZIP_ALPHA9 not set; alpha.9 upgrade evidence not executed." >&2
  fi

  if [[ -n "${PREVIOUS_ZIP_ALPHA10:-}" ]]; then
    run_release_case "alpha.10-upgrade" "${PREVIOUS_ZIP_ALPHA10}"
  else
    echo "PENDING: PREVIOUS_ZIP_ALPHA10 not set; alpha.10 upgrade evidence not executed." >&2
  fi
}

case "${MODE}" in
  quality)
    run_quality
    ;;
  runtime)
    run_runtime_core
    ;;
  multisite)
    run_multisite
    ;;
  wire)
    run_wire
    ;;
  release)
    run_release_lifecycle
    ;;
  all)
    run_quality
    run_runtime_core

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

    if [[ -n "${WP_RELEASE_PATH:-}" ]]; then
      run_release_lifecycle
    else
      echo "PENDING: WP_RELEASE_PATH not set; destructive G10 lifecycle evidence not executed." >&2
    fi
    ;;
  *)
    echo "Usage: bash $0 [quality|runtime|multisite|wire|release|all]" >&2
    exit 2
    ;;
esac

echo "WPNERVE_BETA_GATE_RUNNER_OK mode=${MODE}"
