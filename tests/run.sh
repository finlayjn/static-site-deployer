#!/usr/bin/env bash
#
# Lints PHP and runs the standalone unit tests. No WordPress required.
#
# Usage: tests/run.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "Linting PHP..."
find "${ROOT_DIR}/src" "${ROOT_DIR}/tests" "${ROOT_DIR}/static-site-deployer.php" \
	-name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
echo "  ok"

exit_code=0
for test in "${ROOT_DIR}"/tests/test-*.php; do
	echo ""
	echo "==> $(basename "${test}")"
	if ! php "${test}"; then
		exit_code=1
	fi
done

exit "${exit_code}"
