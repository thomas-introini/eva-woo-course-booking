# Eva Course Bookings

WordPress + WooCommerce plugin for managing course bookings with dates and times.

## Features

- ✅ Convert existing WooCommerce products into bookable courses
- ✅ Slot management with date, time, and maximum capacity
- ✅ Interactive calendar for date selection
- ✅ Overselling prevention with atomic queries
- ✅ Full integration with cart and checkout (Classic + Block)
- ✅ Order confirmation emails with course details
- ✅ Course reminder emails with customizable templates
- ✅ Admin page for booking management
- ✅ Compatible with WooCommerce HPOS

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+

## Installation

### Option 1: Manual Installation

1. Download or clone this repository
2. Copy the `eva-course-bookings` folder to `wp-content/plugins/`
3. Activate the plugin from the WordPress admin panel

### Option 2: Docker Development Environment

See the [Local Development](#local-development) section below.

## Usage

### Enable a product as a course

1. Go to **Products → Edit product**
2. In the "Product data" section, check **"Enable course booking"**
3. Save the product
4. The **"Course dates"** section will appear where you can add slots

### Manage slots

In the "Course dates" section of the product:

- **Add slot**: Date, start time, end time (optional), capacity
- **Edit**: Change date, time, or capacity
- **Close/Open**: Temporarily disable a slot
- **Delete**: Remove a slot (only if it has no bookings)

### Admin bookings page

Go to **WooCommerce → Courses** to:

- View all slots
- Filter by product, status, or date
- Manage slots from a single page
- View booking details for each slot
- Send reminder emails to participants

### Bulk enable

Go to **WooCommerce → Courses → Enabled Courses** tab to enable booking on multiple products at once.

### Course Reminder Emails

The plugin includes a WooCommerce transactional email for sending reminders to course participants.

#### Configure the reminder email

1. Go to **WooCommerce → Settings → Emails**
2. Find **"Course Reminder"** in the list
3. Click to configure:
   - Enable/disable the email
   - Customize subject and heading (supports placeholders)
   - Set the course location/address
   - Add custom notes (e.g., "Please bring a yoga mat")
   - Set additional content

#### Send reminders

1. Go to **WooCommerce → Courses → Bookings** tab
2. Click **"View bookings"** on a slot
3. Use the **"✉ Reminder"** button to send to individual participants
4. Use **"✉ Send reminder to all participants"** to send to everyone

The email includes:
- Customer name
- Course name, date, and time
- Number of participants
- Location (if configured)
- Custom notes (if configured)

### Course purchase confirmation emails

Orders containing only products flagged as Eva courses receive **Conferma acquisto corsi** when marked **Completed**, replacing WooCommerce’s standard completed-order email. The subject and content describe a course purchase, with dates, participant counts, order totals, and billing details. The product’s WooCommerce **Virtual** setting does not affect eligibility; mixed orders retain the standard email.

Configure the email under **WooCommerce → Settings → Emails → Conferma acquisto corsi**:

1. Customize the subject, heading, additional instructions, and HTML/plain-text format. Supported placeholders: `{site_title}`, `{order_number}`, `{order_date}`.
2. Publish a page containing `[eva_course_bookings_manage]`, then select it under **Pagina gestione prenotazioni**.
3. Purchases with a date still to choose receive a **Gestisci prenotazione** link. Customers enter the lookup number shown in the email and their purchase email, or the gift-recipient email. The email is sent only to the purchaser.

Without a valid booking page, customers are asked to contact the store. Older purchases without a selectable booking also receive contact instructions. Course summaries read current order-item data, including dates assigned after purchase.

The new email initially inherits the existing completed-order email’s enabled/disabled setting, then has an independent switch. Disabling it means course-only orders receive no completion email. Other transactional emails and order-status automation are unchanged.

HTML/plain templates can be overridden at `yourtheme/woocommerce/emails/course-completed-order.php` and `yourtheme/woocommerce/emails/plain/course-completed-order.php`.

### Self-test

Go to **WooCommerce → Eva Self Test** to verify the plugin is configured correctly.

For the course confirmation email, run this check in the local development environment:

```bash
docker exec eva-wpcli wp eval-file /var/www/html/wp-content/plugins/eva-course-bookings/tests/course-completed-email.php
```

It checks email routing, settings persistence, HTML/plain content, and booking-page fallbacks. Mail is intercepted; temporary products, orders, pages, slots, and email settings are cleaned up afterwards. Tests are excluded from the distributable zip.

## Frontend

When a product is enabled as a course:

1. The customer sees a calendar with available dates
2. Selecting a date shows available time slots
3. After selecting a slot, they can proceed to checkout
4. The quantity represents the number of participants

## Local Development

### Prerequisites

- Docker and Docker Compose installed
- Ports 8080 and 8081 available

The development stack uses WordPress 6.9 / PHP 8.2, MySQL 9.6.0, and WooCommerce 10.4.0 for new installations. MySQL is accessible inside Docker and through phpMyAdmin; host port 3306 is not required. Keep the database image compatible with existing volumes when changing versions.

### Startup

```bash
# Start the environment
./bin/up.sh

# Wait for automatic setup to complete
```

### URLs

| Service     | URL                           | Credentials          |
|-------------|-------------------------------|----------------------|
| WordPress   | http://localhost:8080         | -                    |
| WP Admin    | http://localhost:8080/wp-admin| admin / admin        |
| phpMyAdmin  | http://localhost:8081         | wordpress / wordpress|

### Available commands

```bash
# Start environment
./bin/up.sh

# Stop environment (keeps data)
./bin/down.sh

# Full reset (deletes all data)
./bin/reset.sh

# View logs
./bin/logs.sh

# Logs for a specific service
./bin/logs.sh wordpress
./bin/logs.sh db
```

### Automatic setup

The `up.sh` script automatically runs:

1. Start Docker containers
2. Wait for authenticated database access and the WordPress installer
3. Install WordPress and WooCommerce if missing
4. Activate and verify WooCommerce and Eva Course Bookings
5. Apply development defaults and create sample products for a new setup

Rerun `./bin/up.sh` after a failure: unfinished setup resumes, and existing sample products are reused by slug. Completed setups retain their store settings and installed WooCommerce version. Plugin activation errors stop startup rather than reporting success. Use `./bin/down.sh` to stop while preserving data; `./bin/reset.sh` deletes it.

Run the startup regression checks with `python3 bin/tests/test-startup.py`. They simulate Docker/WP-CLI failures and recovery without starting containers or changing development data.

### Sample products

Three products are automatically created:

1. **Basic Photography Course** - €150.00
2. **Italian Cooking Workshop** - €89.00
3. **Yoga Course** - €120.00

### Testing the plugin

1. Go to **Products** in the admin panel
2. Edit one of the sample products
3. Check "Enable course booking"
4. Add some slots in the "Course dates" section
5. View the product on the frontend
6. Test the purchase flow

## Build

To create an installable zip package:

```bash
./build.sh
```

This generates `eva-course-bookings.zip` ready for upload to WordPress.

## Plugin Structure

```
eva-course-bookings/
├── eva-course-bookings.php    # Main file
├── includes/
│   ├── class-plugin.php       # Main class
│   ├── class-admin.php        # Admin functionality
│   ├── class-frontend.php     # Frontend functionality
│   ├── class-slot-repository.php    # Slot CRUD
│   ├── class-woo-integration.php    # WooCommerce integration
│   └── emails/
│       └── class-wc-email-course-reminder.php  # Reminder email class
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── frontend.css
│   └── js/
│       ├── admin.js
│       └── frontend.js
└── templates/
    └── emails/
        ├── course-reminder.php        # HTML email template
        └── plain/
            └── course-reminder.php    # Plain text email template
```

## Hooks and Filters

### Actions

```php
// After seat reservation
do_action( 'eva_course_bookings_seats_reserved', $slot_id, $quantity, $order_id );

// After seat release
do_action( 'eva_course_bookings_seats_released', $slot_id, $quantity, $order_id );
```

### Filters

```php
// Modify available dates
add_filter( 'eva_course_bookings_available_dates', function( $dates, $product_id ) {
    return $dates;
}, 10, 2 );

// Modify available slots
add_filter( 'eva_course_bookings_available_slots', function( $slots, $product_id ) {
    return $slots;
}, 10, 2 );
```

## Technical Notes

### Overselling Prevention

The plugin uses atomic SQL queries to prevent race conditions:

```sql
UPDATE postmeta pm1
INNER JOIN postmeta pm2 ON pm1.post_id = pm2.post_id
SET pm1.meta_value = pm1.meta_value + {qty}
WHERE pm1.post_id = {slot_id}
AND pm1.meta_key = '_eva_booked'
AND pm2.meta_key = '_eva_capacity'
AND (pm1.meta_value + {qty}) <= pm2.meta_value
```

### Block Checkout Compatibility

The plugin supports both classic checkout and WooCommerce's new Block Checkout:

- Cart validation via `woocommerce_check_cart_items`
- Checkout validation via `woocommerce_store_api_checkout_update_order_from_request`
- Slot data persistence in cart

### Caching

For compatibility with caching plugins, slots are loaded via AJAX on the frontend.

## Changelog

### 1.2.0

- Added course reminder email functionality
- WooCommerce transactional email integration
- Configurable email template with location and notes
- Send reminders to individual or all participants
- Email settings accessible from WooCommerce → Settings → Emails

### 1.1.0

- Admin improvements
- Pending bookings management
- Order slot assignment

### 1.0.0

- Initial release
- Slot management for products
- Cart and checkout integration
- Admin bookings panel
- Block Checkout support

## License

GPL v2 or later

## Support

For bugs and feature requests, open an issue on GitHub.
