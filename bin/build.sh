#!/usr/bin/env bash
#
# Builds a distributable plugin archive.
#
# Produces dist/static-site-deployer.zip containing a single top-level
# `static-site-deployer/` directory. The plugin has no runtime dependencies.
#
# Usage: bin/build.sh

set -euo pipefail

SLUG="static-site-deployer"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/${SLUG}"
ZIP_PATH="${DIST_DIR}/${SLUG}.zip"

INCLUDE=(
	"static-site-deployer.php"
	"src"
	"readme.txt"
	"README.md"
	"LICENSE"
	"CHANGELOG.md"
)

echo "Cleaning previous build..."
rm -rf "${STAGE_DIR}" "${ZIP_PATH}"
mkdir -p "${STAGE_DIR}"

echo "Staging files..."
for path in "${INCLUDE[@]}"; do
	if [[ -e "${ROOT_DIR}/${path}" ]]; then
		cp -R "${ROOT_DIR}/${path}" "${STAGE_DIR}/"
	else
		echo "  warning: missing ${path}, skipping" >&2
	fi
done

echo "Creating archive..."
( cd "${DIST_DIR}" && zip -qr "${SLUG}.zip" "${SLUG}" )
rm -rf "${STAGE_DIR}"

echo "Built ${ZIP_PATH}"
