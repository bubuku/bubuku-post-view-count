#!/usr/bin/env bash
# build.sh — Generate a production zip for {plugin-slug}
# Usage: bash scripts/build.sh
# Output: dist/{plugin-slug}-{version}.zip

set -euo pipefail

# ─── Colors ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
RESET='\033[0m'

ok()   { echo -e "${GREEN}✓${RESET} $*"; }
fail() { echo -e "${RED}✗${RESET} $*"; exit 1; }
info() { echo -e "${CYAN}→${RESET} $*"; }
warn() { echo -e "${YELLOW}⚠${RESET} $*"; }

# ─── Paths ────────────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_SLUG="$(basename "${PLUGIN_DIR}")"
MAIN_FILE="${PLUGIN_DIR}/${PLUGIN_SLUG}.php"
DISTIGNORE="${PLUGIN_DIR}/.distignore"
DIST_DIR="${PLUGIN_DIR}/dist"

# ─── Detect version from plugin header ────────────────────────────────────────
if [[ ! -f "${MAIN_FILE}" ]]; then
    fail "Main plugin file not found: ${MAIN_FILE}"
fi

VERSION=$(grep -m1 '^\s*\*\s*Version:' "${MAIN_FILE}" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

if [[ -z "${VERSION}" ]]; then
    fail "Could not read Version from plugin header in ${MAIN_FILE}"
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="${DIST_DIR}/${ZIP_NAME}"

# ─── Header ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}Building ${PLUGIN_SLUG} v${VERSION}${RESET}"
echo -e "────────────────────────────────────"

# ─── Validate .distignore ─────────────────────────────────────────────────────
if [[ ! -f "${DISTIGNORE}" ]]; then
    warn ".distignore not found — all files will be included"
fi

# ─── Prepare dist/ ────────────────────────────────────────────────────────────
info "Preparing dist/ directory..."
mkdir -p "${DIST_DIR}"

# Remove previous zip for this version if it exists
if [[ -f "${ZIP_PATH}" ]]; then
    rm "${ZIP_PATH}"
    warn "Removed previous ${ZIP_NAME}"
fi
ok "dist/ ready"

# ─── Build exclusion list from .distignore ────────────────────────────────────
info "Reading exclusions from .distignore..."

EXCLUDES=()

# Always exclude the dist/ folder itself to avoid nesting
EXCLUDES+=("--exclude=./${PLUGIN_SLUG}/dist/*")
EXCLUDES+=("--exclude=./${PLUGIN_SLUG}/dist")

if [[ -f "${DISTIGNORE}" ]]; then
    while IFS= read -r line || [[ -n "${line}" ]]; do
        # Skip empty lines and comments
        [[ -z "${line}" || "${line}" =~ ^# ]] && continue

        # Strip leading slash if present (zip uses relative paths)
        clean="${line#/}"

        # Handle directory patterns — always add both the dir entry and /* wildcard
        if [[ "${clean}" == */ ]]; then
            EXCLUDES+=("--exclude=./${PLUGIN_SLUG}/${clean}*")
            EXCLUDES+=("--exclude=./${PLUGIN_SLUG}/${clean%/}")
        else
            EXCLUDES+=("--exclude=./${PLUGIN_SLUG}/${clean}")
            EXCLUDES+=("--exclude=./${PLUGIN_SLUG}/${clean}/*")
        fi
    done < "${DISTIGNORE}"
fi
ok "Exclusions loaded (${#EXCLUDES[@]} rules)"

# ─── Create zip ───────────────────────────────────────────────────────────────
info "Creating ${ZIP_NAME}..."

PARENT_DIR="$(dirname "${PLUGIN_DIR}")"

(
    cd "${PARENT_DIR}"
    zip -rq "${ZIP_PATH}" "./${PLUGIN_SLUG}" \
        "${EXCLUDES[@]}"
)

if [[ ! -f "${ZIP_PATH}" ]]; then
    fail "Zip file was not created — something went wrong"
fi
ok "Zip created"

# ─── Show size ────────────────────────────────────────────────────────────────
SIZE=$(du -sh "${ZIP_PATH}" | cut -f1)
echo ""
echo -e "${BOLD}${GREEN}✓ Build complete${RESET}"
echo -e "  File:    ${CYAN}dist/${ZIP_NAME}${RESET}"
echo -e "  Size:    ${SIZE}"
echo -e "  Version: ${VERSION}"
echo ""

# ─── Open dist/ folder ────────────────────────────────────────────────────────
if command -v open &>/dev/null; then
    # macOS
    open "${DIST_DIR}"
elif command -v xdg-open &>/dev/null; then
    # Linux
    xdg-open "${DIST_DIR}" &>/dev/null &
fi