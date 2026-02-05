#!/bin/bash
#
# Affilync WooCommerce Plugin - Build Script (FREE - No IonCube)
#
# Creates obfuscated distribution package using custom PHP obfuscator
# NO COST - NO EXTERNAL DEPENDENCIES
#
# Usage: ./build.sh [version]

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="$SCRIPT_DIR"
DIST_DIR="$BUILD_DIR/dist"

# Get version
if [ -n "$1" ]; then
    VERSION="$1"
else
    VERSION=$(grep "Version:" "$PLUGIN_DIR/affilync-woocommerce.php" | head -1 | sed 's/.*Version:\s*//' | tr -d ' ')
fi

PACKAGE_NAME="affilync-woocommerce-v${VERSION}"

echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}  Affilync WooCommerce - Build Script${NC}"
echo -e "${GREEN}  Version: ${VERSION}${NC}"
echo -e "${GREEN}  FREE - No external tools required${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""

# Check PHP
if ! command -v php &> /dev/null; then
    echo -e "${RED}Error: PHP is required${NC}"
    exit 1
fi

# Run obfuscator
echo -e "${YELLOW}Running PHP obfuscator...${NC}"
php "$BUILD_DIR/obfuscate.php" --output="$DIST_DIR/$PACKAGE_NAME" --level=high

# Copy license file
echo -e "${YELLOW}Adding license file...${NC}"
cp "$PLUGIN_DIR/LICENSE.txt" "$DIST_DIR/$PACKAGE_NAME/"

# Create installation guide
cat > "$DIST_DIR/$PACKAGE_NAME/INSTALLATION.md" << 'EOF'
# Affilync for WooCommerce - Installation Guide

## Requirements
- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+

## Installation
1. Upload `affilync-woocommerce` folder to `/wp-content/plugins/`
2. Activate through 'Plugins' menu
3. Go to WooCommerce > Affilync > License
4. Enter your license key and click Activate

## Configuration (Recommended)
Add to wp-config.php:
```php
define('AFFILYNC_ENCRYPTION_KEY', 'your-64-character-random-key');
define('AFFILYNC_PRODUCTION_MODE', true);
```

## Support
- Email: support@affilync.com
- Docs: https://docs.affilync.com
EOF

# Create ZIP
echo -e "${YELLOW}Creating ZIP package...${NC}"
cd "$DIST_DIR"
zip -r "${PACKAGE_NAME}.zip" "$PACKAGE_NAME"

# Checksum
sha256sum "${PACKAGE_NAME}.zip" > "${PACKAGE_NAME}.zip.sha256"

echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}  Build Complete!${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo "Package: $DIST_DIR/${PACKAGE_NAME}.zip"
echo "SHA256: $(cat ${PACKAGE_NAME}.zip.sha256)"
echo ""
