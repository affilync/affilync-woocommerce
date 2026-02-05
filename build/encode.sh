#!/bin/bash
#
# Affilync WooCommerce Plugin - IonCube Encoding Script
#
# This script encodes the plugin source files using IonCube Encoder
# and creates a distribution-ready ZIP package.
#
# Prerequisites:
# - IonCube Encoder (Cerberus or Pro edition)
# - IONCUBE_ENCODER_PATH environment variable set
# - IONCUBE_PASSPHRASE environment variable set
#
# Usage: ./encode.sh [version]
# Example: ./encode.sh 1.0.0

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="$SCRIPT_DIR"
DIST_DIR="$BUILD_DIR/dist"
SRC_DIR="$BUILD_DIR/src"

# Get version from argument or plugin file
if [ -n "$1" ]; then
    VERSION="$1"
else
    VERSION=$(grep "Version:" "$PLUGIN_DIR/affilync-woocommerce.php" | head -1 | sed 's/.*Version:\s*//' | tr -d ' ')
fi

PACKAGE_NAME="affilync-woocommerce-v${VERSION}"

echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}  Affilync WooCommerce Plugin Encoder${NC}"
echo -e "${GREEN}  Version: ${VERSION}${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""

# Check for IonCube Encoder
if [ -z "$IONCUBE_ENCODER_PATH" ]; then
    echo -e "${RED}Error: IONCUBE_ENCODER_PATH environment variable not set${NC}"
    echo "Please set it to your IonCube Encoder installation directory"
    echo "Example: export IONCUBE_ENCODER_PATH=/usr/local/ioncube"
    exit 1
fi

ENCODER="$IONCUBE_ENCODER_PATH/ioncube_encoder"
if [ ! -f "$ENCODER" ]; then
    # Try common alternative paths
    if [ -f "$IONCUBE_ENCODER_PATH/bin/ioncube_encoder" ]; then
        ENCODER="$IONCUBE_ENCODER_PATH/bin/ioncube_encoder"
    elif [ -f "$IONCUBE_ENCODER_PATH/ioncube_encoder74" ]; then
        ENCODER="$IONCUBE_ENCODER_PATH/ioncube_encoder74"
    else
        echo -e "${RED}Error: IonCube Encoder not found at $IONCUBE_ENCODER_PATH${NC}"
        exit 1
    fi
fi

echo -e "${GREEN}Using encoder: $ENCODER${NC}"

# Check for passphrase
if [ -z "$IONCUBE_PASSPHRASE" ]; then
    echo -e "${YELLOW}Warning: IONCUBE_PASSPHRASE not set, generating random passphrase${NC}"
    IONCUBE_PASSPHRASE=$(openssl rand -base64 32)
    echo "Passphrase: $IONCUBE_PASSPHRASE"
    echo "Save this passphrase securely - you'll need it to generate licenses!"
fi

# Clean previous builds
echo -e "${YELLOW}Cleaning previous builds...${NC}"
rm -rf "$SRC_DIR" "$DIST_DIR"
mkdir -p "$SRC_DIR" "$DIST_DIR"

# Copy source files to staging area
echo -e "${YELLOW}Copying source files...${NC}"
rsync -av --exclude='build' \
          --exclude='.git' \
          --exclude='.gitignore' \
          --exclude='tests' \
          --exclude='phpunit.xml' \
          --exclude='composer.lock' \
          --exclude='node_modules' \
          --exclude='.DS_Store' \
          --exclude='*.log' \
          "$PLUGIN_DIR/" "$SRC_DIR/"

# Update version in staging copy if needed
echo -e "${YELLOW}Setting version to ${VERSION}...${NC}"
sed -i "s/Version:.*$/Version: ${VERSION}/" "$SRC_DIR/affilync-woocommerce.php"
sed -i "s/define( 'AFFILYNC_VERSION', '.*' )/define( 'AFFILYNC_VERSION', '${VERSION}' )/" "$SRC_DIR/affilync-woocommerce.php"

# Run IonCube Encoder
echo -e "${YELLOW}Running IonCube Encoder...${NC}"
"$ENCODER" \
    --project-file "$BUILD_DIR/ioncube.ini" \
    --passphrase "$IONCUBE_PASSPHRASE" \
    --into "$DIST_DIR/$PACKAGE_NAME" \
    "$SRC_DIR"

# Copy unencoded files
echo -e "${YELLOW}Copying unencoded assets...${NC}"
cp -r "$SRC_DIR/assets" "$DIST_DIR/$PACKAGE_NAME/" 2>/dev/null || true
cp -r "$SRC_DIR/languages" "$DIST_DIR/$PACKAGE_NAME/" 2>/dev/null || true
cp -r "$SRC_DIR/templates" "$DIST_DIR/$PACKAGE_NAME/" 2>/dev/null || true
cp "$SRC_DIR/readme.txt" "$DIST_DIR/$PACKAGE_NAME/" 2>/dev/null || true
cp "$SRC_DIR/uninstall.php" "$DIST_DIR/$PACKAGE_NAME/" 2>/dev/null || true

# Create proprietary license file
echo -e "${YELLOW}Creating license file...${NC}"
cat > "$DIST_DIR/$PACKAGE_NAME/LICENSE.txt" << 'EOLICENSE'
AFFILYNC WOOCOMMERCE PLUGIN LICENSE AGREEMENT

Copyright (c) 2024-2026 Affilync. All Rights Reserved.

IMPORTANT: READ CAREFULLY BEFORE INSTALLING OR USING THIS SOFTWARE.

This End User License Agreement ("Agreement") is a legal agreement between you
("Licensee") and Affilync ("Licensor") for the Affilync WooCommerce Plugin
software product ("Software").

1. GRANT OF LICENSE
Subject to payment of applicable license fees and compliance with this Agreement,
Licensor grants Licensee a non-exclusive, non-transferable license to:
- Install and use the Software on a single WordPress installation
- Make one backup copy for archival purposes only

2. RESTRICTIONS
Licensee shall NOT:
- Copy, modify, or distribute the Software
- Reverse engineer, decompile, or disassemble the Software
- Remove any proprietary notices from the Software
- Transfer, sublicense, lease, or rent the Software
- Use the Software for any unlawful purpose
- Attempt to circumvent any license verification mechanisms

3. INTELLECTUAL PROPERTY
The Software is protected by copyright and other intellectual property laws.
Licensor retains all rights, title, and interest in the Software.

4. TERMINATION
This license is effective until terminated. It terminates automatically if
Licensee fails to comply with any term. Upon termination, Licensee must
destroy all copies of the Software.

5. WARRANTY DISCLAIMER
THE SOFTWARE IS PROVIDED "AS IS" WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE, AND NONINFRINGEMENT.

6. LIMITATION OF LIABILITY
IN NO EVENT SHALL LICENSOR BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL,
CONSEQUENTIAL, OR PUNITIVE DAMAGES ARISING FROM USE OF THE SOFTWARE.

7. GOVERNING LAW
This Agreement shall be governed by the laws of the State of Delaware, USA.

By installing or using this Software, you acknowledge that you have read,
understood, and agree to be bound by the terms of this Agreement.

For licensing inquiries: licensing@affilync.com
For support: support@affilync.com
Website: https://affilync.com
EOLICENSE

# Create installation guide
cat > "$DIST_DIR/$PACKAGE_NAME/INSTALLATION.md" << 'EOINSTALL'
# Affilync for WooCommerce - Installation Guide

## System Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- **IonCube Loader** (see below)

## IonCube Loader Installation

This plugin is protected with IonCube encoding. You must have the IonCube Loader
installed on your hosting server.

### Checking if IonCube is installed

1. Create a file called `phpinfo.php` with contents: `<?php phpinfo(); ?>`
2. Upload to your website and visit it in browser
3. Search for "ionCube" - if found, the loader is installed

### Installing IonCube Loader

1. Visit https://www.ioncube.com/loaders.php
2. Download the loader for your PHP version and operating system
3. Follow the installation instructions provided

Most managed WordPress hosts already have IonCube Loader installed.
If yours doesn't, contact your hosting provider.

## Plugin Installation

1. Upload the `affilync-woocommerce` folder to `/wp-content/plugins/`
2. Activate through 'Plugins' menu in WordPress
3. Go to WooCommerce > Affilync
4. Enter your license key to activate

## License Activation

1. Purchase a license at https://affilync.com/pricing
2. Go to WooCommerce > Affilync > License tab
3. Enter your license key
4. Click "Activate"

## Configuration

After activation, configure:
- wp-config.php constants (recommended for security):
  ```php
  define('AFFILYNC_ENCRYPTION_KEY', 'your-64-character-key');
  define('AFFILYNC_PRODUCTION_MODE', true);
  ```

## Support

- Documentation: https://docs.affilync.com
- Support: support@affilync.com
- Status: https://status.affilync.com
EOINSTALL

# Create ZIP package
echo -e "${YELLOW}Creating distribution package...${NC}"
cd "$DIST_DIR"
zip -r "${PACKAGE_NAME}.zip" "$PACKAGE_NAME"

# Calculate checksums
echo -e "${YELLOW}Calculating checksums...${NC}"
sha256sum "${PACKAGE_NAME}.zip" > "${PACKAGE_NAME}.zip.sha256"

# Clean up
rm -rf "$SRC_DIR"

# Summary
echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}  Build Complete!${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo -e "Package: ${DIST_DIR}/${PACKAGE_NAME}.zip"
echo -e "Checksum: ${DIST_DIR}/${PACKAGE_NAME}.zip.sha256"
echo ""
echo -e "SHA256: $(cat "${PACKAGE_NAME}.zip.sha256")"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Upload the ZIP file to your distribution server"
echo "2. Store the passphrase securely for license generation"
echo "3. Configure the license validation endpoint on api.affilync.com"
echo ""
