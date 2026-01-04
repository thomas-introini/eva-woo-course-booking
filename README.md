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

### Self-test

Go to **WooCommerce → Eva Self Test** to verify the plugin is configured correctly.

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
2. Install WordPress
3. Install and configure WooCommerce
4. Create sample products
5. Activate Eva Course Bookings plugin

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
