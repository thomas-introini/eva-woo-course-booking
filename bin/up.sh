#!/bin/bash

# Eva Course Bookings - Start Development Environment
set -Eeuo pipefail
trap 'echo "❌ Startup failed. Check ./bin/logs.sh, fix the error, then rerun ./bin/up.sh." >&2' ERR

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

wp() {
    docker compose exec -T wpcli wp "$@"
}

create_sample_product() {
    local slug="$1"
    shift
    local existing
    existing=$(wp post list --post_type=product --name="$slug" --post_status=any --format=ids)
    if [ -z "$existing" ]; then
        wp wc product create --slug="$slug" --user="$ADMIN_USER" "$@"
    fi
}

echo "🚀 Starting Eva Course Bookings development environment..."

# Start containers
docker compose up -d

echo ""
echo "⏳ Waiting for WordPress to be ready..."

# Wait for WordPress to be ready
MAX_ATTEMPTS=30
ATTEMPT=0
until docker compose exec -T wordpress curl --fail --silent --output /dev/null --max-time 5 http://localhost/wp-admin/install.php; do
    ATTEMPT=$((ATTEMPT + 1))
    if [ $ATTEMPT -ge $MAX_ATTEMPTS ]; then
        echo "❌ Timeout waiting for WordPress. Check logs with: ./bin/logs.sh"
        exit 1
    fi
    echo "   Waiting... ($ATTEMPT/$MAX_ATTEMPTS)"
    sleep 3
done

# Check if WordPress is installed
if ! wp core is-installed --url=http://localhost:8080; then
    echo ""
    echo "📦 WordPress not installed. Running initial setup..."

    # Install WordPress
    wp core install \
        --url="http://localhost:8080" \
        --title="Eva Course Bookings Dev" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.com \
        --skip-email

    echo "✅ WordPress installed"
    wp option update eva_dev_setup_pending yes
else
    echo "✅ WordPress already installed"
fi

# Persist setup intent before downloading so interrupted installs can resume.
if ! wp plugin is-installed woocommerce; then
    wp option update eva_dev_setup_pending yes
    echo ""
    echo "📦 Installing WooCommerce..."
    wp plugin install woocommerce --version=10.4.0
fi

for plugin in woocommerce eva-course-bookings; do
    if ! wp plugin is-active "$plugin"; then
        wp plugin activate "$plugin"
    fi
    wp plugin is-active "$plugin"
done

if wp option get eva_dev_setup_pending >/dev/null 2>&1; then
    # Set up WooCommerce defaults
    echo ""
    echo "⚙️ Configuring WooCommerce..."
    wp option update woocommerce_store_address "Via Roma 1"
    wp option update woocommerce_store_city "Milano"
    wp option update woocommerce_store_postcode "20100"
    wp option update woocommerce_default_country "IT"
    wp option update woocommerce_currency "EUR"
    wp option update woocommerce_currency_pos "right_space"
    wp option update woocommerce_price_decimal_sep ","
    wp option update woocommerce_price_thousand_sep "."
    wp option update blogdescription "Ambiente di sviluppo per Eva Course Bookings"

    # Set timezone
    wp option update timezone_string "Europe/Rome"

    # Create sample products
    echo ""
    echo "📦 Creating sample products..."

    ADMIN_USER=$(wp user list --role=administrator --number=1 --field=ID)
    if [ -z "$ADMIN_USER" ]; then
        echo "❌ An administrator account is required to create sample products." >&2
        exit 1
    fi
    create_sample_product corso-di-fotografia-base \
        --name="Corso di Fotografia Base" \
        --type=simple \
        --regular_price=150.00 \
        --description="Impara le basi della fotografia digitale in questo corso completo." \
        --short_description="Corso base di fotografia per principianti."

    create_sample_product workshop-di-cucina-italiana \
        --name="Workshop di Cucina Italiana" \
        --type=simple \
        --regular_price=89.00 \
        --description="Un workshop pratico per imparare a cucinare i piatti della tradizione italiana." \
        --short_description="Workshop pratico di cucina italiana."

    create_sample_product corso-di-yoga \
        --name="Corso di Yoga" \
        --type=simple \
        --regular_price=120.00 \
        --description="Corso di yoga per tutti i livelli, dalla teoria alla pratica." \
        --short_description="Corso di yoga per tutti i livelli."

    echo "✅ Sample products created"

    wp option delete eva_dev_setup_pending
fi

wp core is-installed
wp plugin is-active woocommerce
wp plugin is-active eva-course-bookings

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Eva Course Bookings development environment is ready!"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "🌐 WordPress:   http://localhost:8080"
echo "👤 Admin:       http://localhost:8080/wp-admin"
echo "🗄️  phpMyAdmin:  http://localhost:8081"
echo ""
echo "📋 Default credentials (new installs):"
echo "   WordPress Admin: admin / admin"
echo "   Database: wordpress / wordpress"
echo ""
echo "📂 Plugin mounted at: wp-content/plugins/eva-course-bookings"
echo ""
echo "🛠️  Next steps:"
echo "   1. Go to Products → Edit a product"
echo "   2. Check 'Abilita prenotazione corso'"
echo "   3. Add slots in the 'Date del corso' metabox"
echo "   4. View the product on frontend"
echo ""
echo "📝 Useful commands:"
echo "   ./bin/logs.sh    - View logs"
echo "   ./bin/down.sh    - Stop environment"
echo "   ./bin/reset.sh   - Reset everything"
echo ""

