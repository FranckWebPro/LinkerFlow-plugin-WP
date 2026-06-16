#!/usr/bin/env bash
#
# Builds the two LinkerFlow plugin zips from the single source in ./linkerflow:
#   - linkerflow.zip       -> APP_CONNECT_URL = production (app.linkerflow.io)
#   - linkerflow-test.zip  -> APP_CONNECT_URL = your test LinkerFlow
#
# The source stays prod-default; only the build swaps APP_CONNECT_URL, then restores it.
# Re-run after ANY change under ./linkerflow so both zips stay current.
#
# Test URL override (staging, custom port, etc.):
#   LINKERFLOW_TEST_URL="https://staging.linkerflow.io/api/wordpress/connect" ./build.sh
#
set -euo pipefail
cd "$(dirname "$0")"

# Refuse to build a zip with a PHP syntax error in it. Skipped (with a warning) when
# php is not installed, so the build still works on machines without a PHP CLI.
if command -v php >/dev/null 2>&1; then
  while IFS= read -r f; do
    if ! php -l "$f" >/dev/null 2>&1; then
      echo "PHP lint failed, aborting build:"
      php -l "$f"
      exit 1
    fi
  done < <(find linkerflow -name '*.php')
  echo "PHP lint OK"
else
  echo "WARNING: php not found, skipping PHP lint"
fi

PROD_URL='https://app.linkerflow.io/api/wordpress/connect'
TEST_URL="${LINKERFLOW_TEST_URL:-https://staging.linkerflow.io/api/wordpress/connect}"
ADMIN='linkerflow/includes/class-admin.php'

# In-place rewrite of the APP_CONNECT_URL constant (BSD/macOS sed).
set_url() {
  sed -i '' "s#\(const APP_CONNECT_URL *= *'\)[^']*\(';\)#\1$1\2#" "$ADMIN"
}

zip_to() {
  rm -f "$1"
  zip -r -X "$1" linkerflow -x '*.DS_Store' >/dev/null
  echo "built $1 ($2)"
}

set_url "$PROD_URL"
zip_to linkerflow.zip "$PROD_URL"

set_url "$TEST_URL"
zip_to linkerflow-test.zip "$TEST_URL"

# Leave the source on the prod default so the committed plugin is prod-ready.
set_url "$PROD_URL"
echo "source restored to prod default ($PROD_URL)"
