#!/bin/bash
#
# Build script for ezPayments WooCommerce plugin.
# Creates a distributable zip file ready for WordPress plugin upload.
#
# Usage: ./build.sh
# Output: ../ezpayments-woocommerce.zip

set -e

PLUGIN_SLUG="ezpayments-woocommerce"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$(mktemp -d)"
DIST_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
OUTPUT_DIR="$(dirname "$SCRIPT_DIR")"
OUTPUT_FILE="${OUTPUT_DIR}/${PLUGIN_SLUG}.zip"

echo "Building ${PLUGIN_SLUG}..."

# Create build directory.
mkdir -p "${DIST_DIR}"

# Copy all plugin files.
rsync -av --quiet \
    --exclude-from="${SCRIPT_DIR}/.distignore" \
    "${SCRIPT_DIR}/" "${DIST_DIR}/"

# Remove the build script itself from the dist.
rm -f "${DIST_DIR}/build.sh"

# Remove any existing zip.
rm -f "${OUTPUT_FILE}"

# Create the zip.
cd "${BUILD_DIR}"
zip -r "${OUTPUT_FILE}" "${PLUGIN_SLUG}/" -x "*.DS_Store" "*__MACOSX*"

# Cleanup.
rm -rf "${BUILD_DIR}"

echo ""
echo "Done! Plugin zip created at:"
echo "  ${OUTPUT_FILE}"
echo ""
echo "Size: $(du -h "${OUTPUT_FILE}" | cut -f1)"
echo ""
echo "You can upload this zip via WordPress Admin > Plugins > Add New > Upload Plugin."
