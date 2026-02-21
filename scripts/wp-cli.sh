#!/bin/bash
# Shortcut to run WP-CLI commands inside the dev container
# Usage: ./scripts/wp-cli.sh plugin list
#        ./scripts/wp-cli.sh option get affilync_connection_status
#        ./scripts/wp-cli.sh wc product list

docker exec affilync-woocommerce-wp wp --allow-root "$@"
