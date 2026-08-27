#!/usr/bin/env bash
# =============================================================================
# build.sh — StoryBoard Live distribution builder
#
# Usage:
#   ./build.sh            Build into dist/storyboard-live/
#   ./build.sh --zip      Build + create dist/storyboard-live-1.0.0.zip
#   ./build.sh --clean    Remove dist/ and exit
#
# Requirements: composer, rsync, zip (for --zip)
# =============================================================================

set -euo pipefail

PLUGIN_SLUG="storyboard-live"
VERSION="1.0.0"
DIST_DIR="dist/${PLUGIN_SLUG}"
ZIP_FILE="dist/${PLUGIN_SLUG}-${VERSION}.zip"

# ── Parse flags ───────────────────────────────────────────────────────────

DO_ZIP=false
DO_CLEAN=false

for arg in "$@"; do
  case $arg in
    --zip)   DO_ZIP=true ;;
    --clean) DO_CLEAN=true ;;
  esac
done

# ── Clean ─────────────────────────────────────────────────────────────────

if $DO_CLEAN; then
  echo "Cleaning dist/…"
  rm -rf dist/
  echo "Done."
  exit 0
fi

# ── Prepare dist dir ──────────────────────────────────────────────────────

echo "Building ${PLUGIN_SLUG} v${VERSION}…"
rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"

# ── Install production Composer dependencies ──────────────────────────────

echo "Installing Composer dependencies (no-dev)…"
composer install --no-dev --optimize-autoloader --quiet

# ── Copy plugin files ─────────────────────────────────────────────────────

rsync -a --exclude-from='.distignore' . "${DIST_DIR}/"

echo "Copied plugin files."

# ── Verify readme.txt ─────────────────────────────────────────────────────

if [[ ! -f "${DIST_DIR}/readme.txt" ]]; then
  echo "ERROR: readme.txt not found in dist." >&2
  exit 1
fi

echo "readme.txt present."

# ── Zip ───────────────────────────────────────────────────────────────────

if $DO_ZIP; then
  echo "Creating zip…"
  rm -f "${ZIP_FILE}"
  (cd dist && zip -r "../${ZIP_FILE}" "${PLUGIN_SLUG}/")
  echo "Created: ${ZIP_FILE}"
fi

echo ""
echo "Build complete: ${DIST_DIR}"
