#!/bin/bash

# Eva Course Bookings - Start Development Environment
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

echo "🚀 Starting Eva Course Bookings development environment..."

# Start containers
docker compose up -d

echo ""
echo "⏳ Waiting for WordPress to be ready..."

# Wait for WordPress to be ready
MAX_ATTEMPTS=30
ATTEMPT=0
until docker exec eva-wordpress curl -s -o /dev/null -w "%{http_code}" http://localhost:80 | grep -q "200\|302\|301"; do
    ATTEMPT=$((ATTEMPT + 1))
    if [ $ATTEMPT -ge $MAX_ATTEMPTS ]; then
        echo "❌ Timeout waiting for WordPress. Check logs with: ./bin/logs.sh"
        exit 1
    fi
    echo "   Waiting... ($ATTEMPT/$MAX_ATTEMPTS)"
    sleep 3
done

echo ""
echo "⏳ Waiting for database to be ready..."
sleep 5

# Fix permissions for wp-content (needed for WP-CLI to install plugins)
# echo ""
# echo "🔧 Fixing wp-content permissions..."
# docker exec eva-wordpress chown -R www-data:www-data /var/www/html/wp-content
# docker exec eva-wordpress chmod -R 755 /var/www/html/wp-content

# Check if WordPress is installed
INSTALLED=$(docker exec eva-wpcli wp core is-installed 2>&1 || echo "not installed")

if [[ "$INSTALLED" == *"not installed"* ]] || [[ "$INSTALLED" == *"Error"* ]]; then
    echo ""
    echo "📦 WordPress not installed. Running initial setup..."

    # Install WordPress
    docker exec eva-wpcli wp core install \
        --url="http://localhost:8080" \
        --title="Eva Course Bookings Dev" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.com \
        --skip-email

    echo "✅ WordPress installed"

    # Install and activate WooCommerce
    echo ""
    echo "📦 Installing WooCommerce..."
    docker exec eva-wpcli wp plugin install woocommerce --activate

    # Set up WooCommerce defaults
    echo ""
    echo "⚙️ Configuring WooCommerce..."
    docker exec eva-wpcli wp option update woocommerce_store_address "Via Roma 1"
    docker exec eva-wpcli wp option update woocommerce_store_city "Milano"
    docker exec eva-wpcli wp option update woocommerce_store_postcode "20100"
    docker exec eva-wpcli wp option update woocommerce_default_country "IT"
    docker exec eva-wpcli wp option update woocommerce_currency "EUR"
    docker exec eva-wpcli wp option update woocommerce_currency_pos "right_space"
    docker exec eva-wpcli wp option update woocommerce_price_decimal_sep ","
    docker exec eva-wpcli wp option update woocommerce_price_thousand_sep "."
    docker exec eva-wpcli wp option update blogdescription "Ambiente di sviluppo per Eva Course Bookings"

    # Set timezone
    docker exec eva-wpcli wp option update timezone_string "Europe/Rome"

    # Create sample products
    echo ""
    echo "📦 Creating sample products..."

    docker exec eva-wpcli wp wc product create \
        --name="Corso di Fotografia Base" \
        --type=simple \
        --regular_price=150.00 \
        --description="Impara le basi della fotografia digitale in questo corso completo." \
        --short_description="Corso base di fotografia per principianti." \
        --user=admin

    docker exec eva-wpcli wp wc product create \
        --name="Workshop di Cucina Italiana" \
        --type=simple \
        --regular_price=89.00 \
        --description="Un workshop pratico per imparare a cucinare i piatti della tradizione italiana." \
        --short_description="Workshop pratico di cucina italiana." \
        --user=admin

    docker exec eva-wpcli wp wc product create \
        --name="Corso di Yoga" \
        --type=simple \
        --regular_price=120.00 \
        --description="Corso di yoga per tutti i livelli, dalla teoria alla pratica." \
        --short_description="Corso di yoga per tutti i livelli." \
        --user=admin

    echo "✅ Sample products created"

    # Activate Eva Course Bookings plugin
    echo ""
    echo "📦 Activating Eva Course Bookings plugin..."
    docker exec eva-wpcli wp plugin activate eva-course-bookings

    echo "✅ Eva Course Bookings activated"

    # Flush rewrite rules
    docker exec eva-wpcli wp rewrite flush
else
    echo ""
    echo "✅ WordPress already installed"

    # Make sure plugins are activated
    docker exec eva-wpcli wp plugin activate woocommerce 2>/dev/null || true
    docker exec eva-wpcli wp plugin activate eva-course-bookings 2>/dev/null || true
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Eva Course Bookings development environment is ready!"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "🌐 WordPress:   http://localhost:8080"
echo "👤 Admin:       http://localhost:8080/wp-admin"
echo "🗄️  phpMyAdmin:  http://localhost:8081"
echo ""
echo "📋 Credentials:"
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

