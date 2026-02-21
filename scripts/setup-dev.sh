#!/bin/bash
# ===========================================
# Affilync WooCommerce - Dev Environment Setup
# ===========================================
# Usage: ./scripts/setup-dev.sh
#
# Prerequisites: Docker running
# Result: WordPress + WooCommerce at http://localhost:8080

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

echo ""
echo "==========================================="
echo "  Affilync WooCommerce Dev Setup"
echo "==========================================="
echo ""

# Start containers
echo "[1/4] Starting Docker containers..."
docker compose up -d

# Wait for WordPress to be ready
echo "[2/4] Waiting for WordPress..."
until docker exec affilync-woocommerce-wp curl -sf http://localhost/ > /dev/null 2>&1; do
    sleep 2
    printf "."
done
echo " Ready!"

# Install WP-CLI if not present
echo "[3/4] Installing WP-CLI..."
docker exec affilync-woocommerce-wp bash -c '
if ! command -v wp &> /dev/null; then
    curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    chmod +x wp-cli.phar
    mv wp-cli.phar /usr/local/bin/wp
fi
'

# Check if WordPress is already installed
if docker exec affilync-woocommerce-wp wp core is-installed --allow-root 2>/dev/null; then
    echo "[4/4] WordPress already installed. Activating plugins..."
    docker exec affilync-woocommerce-wp wp plugin activate affilync-woocommerce --allow-root 2>/dev/null || true
    docker exec affilync-woocommerce-wp wp plugin activate woocommerce --allow-root 2>/dev/null || true
else
    echo "[4/4] Installing WordPress + WooCommerce..."

    # Install WordPress
    docker exec affilync-woocommerce-wp wp core install \
        --url=http://localhost:8080 \
        --title="Affilync WooCommerce Dev" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=dev@affilync.com \
        --skip-email \
        --allow-root

    # Update to latest WP (for WooCommerce compatibility)
    docker exec affilync-woocommerce-wp wp core update --allow-root

    # Install & activate WooCommerce
    docker exec affilync-woocommerce-wp wp plugin install woocommerce --activate --allow-root

    # Activate Affilync plugin
    docker exec affilync-woocommerce-wp wp plugin activate affilync-woocommerce --allow-root

    # Configure WooCommerce
    docker exec affilync-woocommerce-wp wp option update woocommerce_store_address "123 Test St" --allow-root
    docker exec affilync-woocommerce-wp wp option update woocommerce_store_city "Test City" --allow-root
    docker exec affilync-woocommerce-wp wp option update woocommerce_default_country "US:CA" --allow-root
    docker exec affilync-woocommerce-wp wp option update woocommerce_currency "USD" --allow-root

    # Create test products
    docker exec affilync-woocommerce-wp wp wc product create \
        --name="Test Product 1" --type=simple --regular_price=29.99 --user=1 --allow-root 2>/dev/null || true
    docker exec affilync-woocommerce-wp wp wc product create \
        --name="Test Product 2" --type=simple --regular_price=49.99 --user=1 --allow-root 2>/dev/null || true
fi

echo ""
echo "==========================================="
echo "  Dev Environment READY!"
echo "==========================================="
echo ""
echo "  Site:      http://localhost:8080"
echo "  WP Admin:  http://localhost:8080/wp-admin"
echo "  Username:  admin"
echo "  Password:  admin"
echo ""
echo "  Plugin:    affilync-woocommerce (active)"
echo "  WooCommerce: active"
echo ""
echo "  Plugin settings: WP Admin > Affilync"
echo ""
echo "  Useful commands:"
echo "    docker compose logs -f wordpress   # View logs"
echo "    docker compose down                # Stop"
echo "    docker compose down -v             # Stop + wipe data"
echo "    ./scripts/wp-cli.sh <command>      # Run WP-CLI"
echo ""
