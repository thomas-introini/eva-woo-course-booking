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
- PHP 8.0+ (mbstring and zlib for PDF gift vouchers)

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

- Browse the monthly **Calendario** view (the default tab), with course/status filters
- Click a calendar slot to see its details and complete reservation history in a modal
- Click a day (or its **+ Slot** button) from today onwards to create a slot in a modal
- View all slots
- Filter by product, status, or date
- Manage slots from a single page
- View booking details for each slot
- Send reminder emails to participants

The creation modal prefills the clicked date and the filtered course, when selected. Choose a course, start time, optional end time, and capacity. The calendar refreshes to show the new open slot, adjusting course/status filters if needed. Creation requires a future start time; an optional end time must be later on the same day.

The slot details modal also offers CSV export and editing for upcoming slots. Date and time are locked when a slot has reserved seats or reservation history, including cancelled/refunded orders. Capacity can still change, but cannot fall below reserved seats; past slots are read-only. These editing rules also apply in the product editor.

Use **Sposta prenotazione** on an active reservation to move its entire participant group to another future, open slot of the same course. Choose a destination, review its date and available seats, and confirm. Slots with insufficient capacity are disabled. The modal and calendar refresh after the move, with a link to open the destination slot. Seats and order dates update together, and an internal order note records the change. No email is sent automatically; use the destination slot's reminder action when needed. Cancelled/refunded/released reservations and past slots cannot be moved.

Historical participant totals include cancelled/refunded orders and can differ from the slot's current reserved-seat count. Reminder recipients follow the existing booking-history behavior; the bulk confirmation explicitly includes cancelled/refunded orders.

Run the calendar/admin regression check in the local development environment:

```bash
docker exec eva-wpcli wp eval-file /var/www/html/wp-content/plugins/eva-course-bookings/tests/admin-calendar.php
```

Pass `legacy` or `hpos` after the file path to check either order-storage mode without changing the store's storage setting. The check intercepts mail, cleans up temporary products/orders/slots, and restores settings.

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

### Scheduled gift vouchers

Courses that allow purchasing without choosing a date can send a personalized PDF gift voucher to a recipient. This applies only to **new purchases made with the gift delivery fields**; older gifts keep their existing behavior.

1. Open the course product and enable **Abilita prenotazione corso** and **Permetti prenotazioni future senza specificare data e orario**.
2. In the product’s **Buono regalo PDF** box, select a PDF from the media library and configure its text positions. Page dimensions and orientation are read automatically from the PDF. Click **Aggiorna** to save. The PDF selection stays saved even if the layout is unfinished; an invalid layout does not replace the previous configuration.
3. Alternatively, open **WooCommerce → Corsi → Impostazioni → Buoni regalo PDF**, select a course (including courses without a PDF), edit the same PDF/layout controls, and click **Salva configurazione del corso**. Each course has an independent layout. The error notification email remains global (default: **info@evacaffe.it**) and has its own save button.
4. In either screen, enter sample names/message and generate a preview before accepting purchases. Preview uses the selected PDF and unsaved layout changes without saving them. Changing courses asks for confirmation when edits are pending.

Gift purchasers enter the recipient email/name, sender display name, and an optional message of up to 250 characters (including spaces) and two manual line breaks. Blank lines and surrounding whitespace are removed. These limits also apply to sample previews; existing purchased gifts retain their saved messages. They can choose immediate sending after payment or a date whose **08:00 in the store timezone** is still in the future. Today is selectable before 08:00; from 08:00 onwards, the earliest scheduled date is tomorrow. The same cutoff is checked again at checkout. If payment arrives after the requested time, the voucher is queued immediately. One voucher covers the participant quantity on that course line. Classic and Block checkout use the same validation.

The recipient email includes the PDF, message, booking lookup reference, and a link to the existing booking page configured under **WooCommerce → Settings → Emails → Conferma acquisto corsi**. Without a valid booking page, it asks the recipient to contact the store. Recipient emails exclude purchaser billing details, prices, and unrelated order lines. Configure **Buono regalo corso** under WooCommerce email settings for its subject, heading, content, and HTML/plain format.

#### Create the PDF artwork

Design one page per course, with its logo, course name, artwork, and fixed instructions. Export a flattened, unencrypted PDF, preferably **PDF 1.4**; the free importer does not support encrypted files or compressed PDF object/cross-reference streams. Product saving and preview report incompatible files without discarding the selected PDF. The PDF needs no editable form fields or placeholder tokens: the plugin adds text over blank areas.

Default page size: **A4 landscape, 297 × 210 mm**. Reserve these areas, measured from the top-left corner:

| Added content | X (mm) | Y (mm) | Width (mm) | Height (mm) | Font (pt) |
|---|---:|---:|---:|---:|---:|
| `Per: recipient name` | 20 | 85 | 257 | 12 | 18 |
| `Da: sender name` | 20 | 105 | 257 | 10 | 14 |
| Gift message | 25 | 125 | 247 | 45 | 12 |
| Booking reference | 20 | 185 | 190 | 8 | 10 |
| Participants | 220 | 185 | 57 | 8 | 10 |

Each course can use a different page size and layout, even when sharing a PDF attachment. Use the **Stampa** checkbox beside each field to include or exclude recipient, sender, message, booking reference, or participants in that course’s PDF. Existing layouts keep all fields enabled. Exclusion affects the text added by the plugin; text already present in the PDF artwork remains. Coordinates are retained when a field is excluded. Positions, box sizes, font sizes, and alignment are editable per course; page dimensions are detected from the PDF and cannot be overridden. Existing global coordinates remain the initial values for courses without a saved layout. Changing one course does not change another. Replacing a PDF retains the coordinates, so check them against the new artwork; fields are never automatically scaled. Recipient, sender and message use the bundled **Yrsa** serif font (SIL Open Font License). Booking details use DejaVu Sans; it also preserves character support when a personal field contains glyphs absent from Yrsa. Text wraps and shrinks to a minimum of 9 pt. Text that still cannot fit, or characters absent from the bundled font (including many emoji), are rejected before checkout. Template/layout changes apply to unsent gifts and retries; preview real gift details from the delivery list after changing a layout. Previews always use the **current** artwork/layout, including for an already sent gift.

#### Review and send gifts

Open **WooCommerce → Corsi → Invii regalo** to filter past/future deliveries by course and status. The list retains requested delivery time, actual send time, latest error, and attempt history while the order exists. **Invia ora** sends a future voucher immediately; **Riprova** retries a failed delivery. Both require a paid order and use the same send routine as cron. Successful sends cannot be sent again from this screen.

Failures stop automatic sending and notify the configured administrator address once per failed attempt. Check/fix the error, then retry manually. An interrupted send is marked **Da verificare** after 15 minutes: check your mail provider's sent log before retrying because the recipient might already have received it. Failed error notifications are logged under source `eva-course-gifts` without recursive notifications.

Cancelled, failed, or fully refunded purchases do not send unsent vouchers. Partial quantity refunds reduce participants on the voucher. Successfully sent emails cannot be withdrawn. Plugin updates/deactivation retain history; the five-minute reconciliation job restores missing unsent queue jobs after reactivation or an outage. It does not retry known failed sends.

“Inviato” and its timestamp mean that the configured WordPress mail transport **accepted the message**. Inbox delivery and later bounces require mail-provider tracking. Personalized attachments are generated in private temporary storage, deleted after each attempt, and never published in the media library.

#### Reliable cron and installation requirements

Vouchers use WooCommerce's bundled Action Scheduler. For reliable dispatch without website traffic, configure the production server to run WordPress cron every minute, as the web-server user. Example crontab (replace both paths):

```cron
* * * * * cd /path/to/wordpress && /usr/local/bin/wp cron event run --due-now --quiet
```

After confirming server cron works, set `define('DISABLE_WP_CRON', true);` in `wp-config.php` to stop request-triggered cron. Check **WooCommerce → Status → Scheduled Actions**, group `eva-course-gifts`, to diagnose queue processing. Jobs are due at 08:00 local time, including daylight-saving changes; server outages, queue backlogs, or mail transport delays can postpone dispatch.

PDF generation requires PHP **mbstring** and **zlib**, plus the bundled FPDI/tFPDF dependencies. Install source dependencies with `composer install --working-dir=eva-course-bookings --no-dev`; `./build.sh` installs locked production dependencies and includes them and the Unicode font in the ZIP. Composer is only needed for development/building, not on the destination WordPress site. The temporary directory reported by PHP must be writable and outside the WordPress web root.

Run the gift regression checks (mail intercepted; temporary products/orders/PDFs/options cleaned up):

```bash
node eva-course-bookings/tests/gift-note.js
docker exec eva-wpcli wp eval-file /var/www/html/wp-content/plugins/eva-course-bookings/tests/gift-deliveries.php legacy
docker exec eva-wpcli wp eval-file /var/www/html/wp-content/plugins/eva-course-bookings/tests/gift-deliveries.php hpos
```

An optional second argument is a container directory for a PDF and HTML preview, for example `legacy /tmp/eva-gift-preview`. Tests never change the store's order-storage setting. Manual retries, no historical gift migration, and the existing booking lookup flow are intentional; no coupon codes or expiry system are introduced.

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

### Custom product layouts

The selector renders automatically at `woocommerce_before_add_to_cart_button` (priority 15). To place it elsewhere, disable automatic rendering in your theme or integration code before the product template renders:

```php
add_filter('eva_course_bookings_auto_render_booking_selector', '__return_false');
```

Then render it once at the desired location **inside the product's add-to-cart form**:

```php
do_action('eva_course_bookings_render_booking_selector', $product_id);
```

The product ID is optional and defaults to the current product. The public renderer is also available as `Eva_Course_Bookings\Plugin::get_instance()->frontend->render_booking_selector($product_id)`. Missing and non-course products render nothing.

For a product-specific opt-out, the filter receives the current product ID as its second argument:

```php
add_filter('eva_course_bookings_auto_render_booking_selector', function ($enabled, $product_id) {
    return 123 === $product_id ? false : $enabled;
}, 10, 2);
```

Use one selector per single-product page, for the product being purchased. Existing calendar styling, JavaScript, AJAX loading, cart fields, and Classic/Block checkout integration are reused. The public action works even when automatic rendering is disabled. Purchases can skip slot selection only when the product's existing skip-slot setting is enabled; otherwise normal slot validation applies.

Run the rendering and purchase regression check in the local development environment:

```bash
docker exec eva-wpcli wp eval-file /var/www/html/wp-content/plugins/eva-course-bookings/tests/booking-selector.php
```

It checks default and custom placement, skip-slot authorization, gift validation, cart/order metadata, and Classic/Block checkout. Temporary products, slots, and settings are cleaned up afterwards.

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

This generates `eva-course-bookings-v<version>.zip` ready for upload to WordPress.

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

### 1.4.11

- Choose which voucher components to render per course, in the product editor and course settings

### 1.4.10

- Limit new gift notes to 250 characters and two manual line breaks, removing blank lines in the storefront and sample previews

### 1.4.9

- Use Yrsa for personal voucher text, keeping DejaVu for booking details and Unicode fallback

### 1.4.8

- Configure independent gift PDF layouts from each product or the course selector in settings
- Detect page dimensions from each PDF and preview unsaved template/layout changes

### 1.4.7

- Allow same-day gift delivery while 08:00 is still in the future, using the same cutoff in the date picker and checkout validation

### 1.4.6

- Keep product PDF selections saved while configuring the voucher layout, so courses remain selectable for preview
- Show actual PDF/layout dimensions in mismatch errors and clarify how to populate the preview course list

### 1.4.5

- Added personalized course gift vouchers with immediate or scheduled delivery after payment
- Added editable PDF layouts and previews, delivery history, manual sends/retries, and configurable error alerts
- Included PDF dependencies and fonts in the distributable plugin ZIP

### 1.4.3

- Added roastery directions, parking and metro links, and arrival video to HTML and plain-text course reminders

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
