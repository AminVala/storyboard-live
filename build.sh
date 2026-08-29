#!/usr/bin/env bash
# ============================================================
# StoryBoard Live — Build Script
# ============================================================
#
# Usage:
#   ./build.sh            Build the plugin (runs composer install)
#   ./build.sh --clean    Remove vendor/ and rebuild
#   ./build.sh --zip      Build + create a distributable ZIP
#   ./build.sh --check    Check requirements only (no install)
#
# Requirements:
#   - PHP 7.4+
#   - Composer (https://getcomposer.org/)
#
# WordPress.org SVN note:
#   Commit vendor/ into SVN trunk/vendor/ so users who install
#   from the plugin directory get a complete, self-contained package.
#   Run: svn add vendor/ --force
#
# ============================================================

set -euo pipefail

PLUGIN_NAME="storyboard-live"
PLUGIN_SLUG="sh-sequence-engine"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Colours ─────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${GREEN}[StoryBoard Live]${NC} $*"; }
warn()    { echo -e "${YELLOW}[StoryBoard Live]${NC} $*"; }
error()   { echo -e "${RED}[StoryBoard Live] ERROR:${NC} $*" >&2; }

# ── Argument parsing ─────────────────────────────────────────
BUILD_CLEAN=false
BUILD_ZIP=false
CHECK_ONLY=false
for arg in "$@"; do
  case "$arg" in
    --clean) BUILD_CLEAN=true ;;
    --zip)   BUILD_ZIP=true ;;
    --check) CHECK_ONLY=true ;;
    *) error "Unknown argument: $arg"; exit 1 ;;
  esac
done

# ── Requirement checks ───────────────────────────────────────
info "Checking requirements..."

# PHP
if ! command -v php &>/dev/null; then
  error "PHP is not installed or not in PATH."
  error "Install PHP 7.4+ from https://www.php.net/"
  exit 1
fi
PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
PHP_MAJOR="$(php -r 'echo PHP_MAJOR_VERSION;')"
PHP_MINOR="$(php -r 'echo PHP_MINOR_VERSION;')"
if [[ "$PHP_MAJOR" -lt 7 ]] || { [[ "$PHP_MAJOR" -eq 7 ]] && [[ "$PHP_MINOR" -lt 4 ]]; }; then
  error "PHP 7.4 or later is required. Found: $PHP_VERSION"
  exit 1
fi
info "  PHP $PHP_VERSION ✓"

# Composer
if ! command -v composer &>/dev/null; then
  error "Composer is not installed or not in PATH."
  error "Install Composer from https://getcomposer.org/download/"
  error "Or run: php -r \"copy('https://getcomposer.org/installer', 'composer-setup.php');\" && php composer-setup.php && mv composer.phar /usr/local/bin/composer"
  exit 1
fi
COMPOSER_VERSION="$(composer --version 2>&1 | head -1)"
info "  $COMPOSER_VERSION ✓"

if [[ "$CHECK_ONLY" == true ]]; then
  info "Requirements OK. Run ./build.sh to install dependencies."
  exit 0
fi

# ── Clean ────────────────────────────────────────────────────
if [[ "$BUILD_CLEAN" == true ]]; then
  warn "Removing vendor/ directory..."
  rm -rf "$SCRIPT_DIR/vendor"
fi

# ── Composer install ─────────────────────────────────────────
if [[ -f "$SCRIPT_DIR/vendor/autoload.php" ]] && [[ "$BUILD_CLEAN" == false ]]; then
  info "vendor/autoload.php already exists. Skipping composer install."
  info "Run ./build.sh --clean to force a fresh install."
else
  info "Running composer install (no-dev, optimised autoloader)..."
  composer install \
    --working-dir="$SCRIPT_DIR" \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist
  info "  Composer install complete ✓"
fi

# ── Verify ───────────────────────────────────────────────────
if [[ ! -f "$SCRIPT_DIR/vendor/autoload.php" ]]; then
  error "vendor/autoload.php not found after composer install."
  error "Check the output above for errors."
  exit 1
fi
info "  vendor/autoload.php verified ✓"

# ── ZIP packaging ────────────────────────────────────────────
if [[ "$BUILD_ZIP" == true ]]; then
  info "Creating distributable ZIP..."

  VERSION="$(php -r "
    \$file = file_get_contents('$SCRIPT_DIR/$PLUGIN_SLUG.php');
    preg_match('/^\s*\*\s*Version:\s*(.+)$/m', \$file, \$m);
    echo trim(\$m[1] ?? 'unknown');
  ")"

  ZIP_NAME="${PLUGIN_NAME}-${VERSION}.zip"
  ZIP_PATH="/tmp/$ZIP_NAME"

  # Files and directories to include
  INCLUDES=(
    "$PLUGIN_SLUG.php"
    "readme.txt"
    "changelog.txt"
    "uninstall.php"
    "composer.json"
    "assets/"
    "languages/"
    "src/"
    "vendor/"
  )

  # Remove old ZIP
  rm -f "$ZIP_PATH"

  # Create ZIP from plugin root
  cd "$SCRIPT_DIR"
  zip_args=()
  for item in "${INCLUDES[@]}"; do
    if [[ -e "$item" ]]; then
      zip_args+=("$item")
    else
      warn "  Skipping missing item: $item"
    fi
  done

  zip -r "$ZIP_PATH" "${zip_args[@]}" \
    --exclude "*.git*" \
    --exclude "*.DS_Store" \
    --exclude "node_modules/*" \
    --exclude "*.log" \
    --exclude "build.sh" \
    --exclude "*/tests/*" \
    --exclude "vendor/composer/installed.php" \
    -x "*.git/*"

  ZIP_SIZE="$(du -sh "$ZIP_PATH" | cut -f1)"
  info "  ZIP created: $ZIP_PATH ($ZIP_SIZE) ✓"
  echo ""
  echo "  To test locally:"
  echo "    1. Upload to WordPress: Plugins → Add New → Upload Plugin"
  echo "    2. Select $ZIP_NAME"
  echo ""
  echo "  For WordPress.org SVN submission:"
  echo "    1. cd /path/to/svn/storyboard-live"
  echo "    2. svn checkout https://plugins.svn.wordpress.org/storyboard-live"
  echo "    3. Copy plugin files to trunk/"
  echo "    4. svn add --force trunk/vendor/"
  echo "    5. svn commit -m 'Release $VERSION'"
fi

info "Build complete!"
echo ""
echo "Next steps:"
echo "  1. Test the plugin: copy the plugin directory to wp-content/plugins/"
echo "  2. Activate: wp plugin activate $PLUGIN_SLUG  (WP-CLI)"
echo "      or Plugins → Installed Plugins → Activate"
echo "  3. Go to StoryBoard Live → Dashboard"
