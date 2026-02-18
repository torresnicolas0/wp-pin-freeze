#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="pin-freeze"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/${SLUG}"
ZIP_FILE="${DIST_DIR}/${SLUG}.zip"

rm -rf "${DIST_DIR}"
mkdir -p "${STAGE_DIR}"

rsync -a "${ROOT_DIR}/" "${STAGE_DIR}/" \
	--exclude '.git/' \
	--exclude '.gitignore' \
	--exclude 'node_modules/' \
	--exclude 'dist/' \
	--exclude 'scripts/' \
	--exclude 'src/' \
	--exclude 'package.json' \
	--exclude 'package-lock.json' \
	--exclude 'README.md' \
	--exclude '.DS_Store' \
	--exclude '*.log'

(
	cd "${DIST_DIR}"
	zip -rq "${ZIP_FILE}" "${SLUG}"
)

echo "Package created: ${ZIP_FILE}"
