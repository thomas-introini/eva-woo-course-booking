<?php

/**
 * Plugin Name: Eva Course Bookings
 * Plugin URI: https://github.com/eva-course-bookings
 * Description: Plugin per la gestione di prenotazioni di corsi con date e orari.
 * Version: 1.0.0
 * Author: Thomas Introini
 * Author URI: https://thomasintroini.it
 * Text Domain: eva-course-bookings
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Eva_Course_Bookings
 */

// Prevent direct file access.
if (! defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
define('EVA_COURSE_BOOKINGS_VERSION', '1.0.0');
define('EVA_COURSE_BOOKINGS_PLUGIN_FILE', __FILE__);
define('EVA_COURSE_BOOKINGS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EVA_COURSE_BOOKINGS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EVA_COURSE_BOOKINGS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function eva_course_bookings_is_woocommerce_active()
{
    return class_exists('WooCommerce');
}

/**
 * Display admin notice if WooCommerce is not active.
 */
function eva_course_bookings_woocommerce_missing_notice()
{
?>
    <div class="notice notice-error">
        <p><strong>Eva Course Bookings:</strong> Questo plugin richiede WooCommerce per funzionare. Per favore, installa e attiva WooCommerce.</p>
    </div>
<?php
}

/**
 * Initialize the plugin.
 */
function eva_course_bookings_init()
{
    // Check WooCommerce dependency.
    if (! eva_course_bookings_is_woocommerce_active()) {
        add_action('admin_notices', 'eva_course_bookings_woocommerce_missing_notice');
        return;
    }

    // Load plugin classes.
    require_once EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'includes/class-slot-repository.php';
    require_once EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'includes/class-admin.php';
    require_once EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'includes/class-frontend.php';
    require_once EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'includes/class-woo-integration.php';
    require_once EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'includes/class-plugin.php';

    // Initialize main plugin class.
    Eva_Course_Bookings\Plugin::get_instance();
}
add_action('plugins_loaded', 'eva_course_bookings_init', 20);

/**
 * Plugin activation hook.
 */
function eva_course_bookings_activate()
{
    // Register CPT on activation to flush rewrite rules.
    require_once EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'includes/class-slot-repository.php';
    Eva_Course_Bookings\Slot_Repository::register_post_type();

    // Flush rewrite rules.
    flush_rewrite_rules();

    // Set activation flag for welcome notice.
    set_transient('eva_course_bookings_activated', true, 30);
}
register_activation_hook(__FILE__, 'eva_course_bookings_activate');

/**
 * Plugin deactivation hook.
 */
function eva_course_bookings_deactivate()
{
    // Flush rewrite rules on deactivation.
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'eva_course_bookings_deactivate');

/**
 * Declare HPOS compatibility.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
