<?php
/**
 * Run with wp eval-file in the local WordPress/WooCommerce environment.
 * Temporary products, slots, settings, and request/cart state are restored.
 */

use Eva_Course_Bookings\Plugin;

if (! defined('WP_CLI') || ! WP_CLI || ! class_exists(Plugin::class)) {
    throw new RuntimeException('Run through WP-CLI with WooCommerce and Eva Course Bookings active.');
}

$check = static function ($condition, $message) {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$plugin = Plugin::get_instance();
$repo = $plugin->slot_repository;
$posts = array();
$saved_globals = array();
foreach (array('post', 'product', 'wp_query') as $key) {
    $saved_globals[$key] = $GLOBALS[$key] ?? null;
}
$saved_post = $_POST;
$saved_cart = WC()->cart;
$saved_session = WC()->session;
$saved_settings = get_option('eva_course_bookings_settings');

$make_product = static function ($name, $course = true, $skip = false) use (&$posts) {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price('30');
    $product->update_meta_data('_eva_course_enabled', $course ? 'yes' : 'no');
    $product->update_meta_data('_eva_course_skip_slot', $skip ? 'yes' : 'no');
    $product->save();
    $posts[] = $product->get_id();
    return $product;
};
$make_slot = static function ($product, $date, $status = 'open') use ($repo, &$posts, $check) {
    $id = $repo->create_slot(array(
        'product_id' => $product->get_id(),
        'start_datetime' => $date . ' 10:00:00',
        'end_datetime' => $date . ' 12:00:00',
        'capacity' => 4,
        'status' => $status,
    ));
    $check(! is_wp_error($id), 'Test slot must be created.');
    $posts[] = $id;
    return $id;
};
$capture = static function ($callback) {
    ob_start();
    try {
        $callback();
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
};
$render = static function ($action, ...$args) use ($capture) {
    return $capture(static function () use ($action, $args) {
        do_action($action, ...$args);
    });
};
$validate = static function ($product, $data, $quantity = 1, $passed = true) {
    $_POST = $data;
    wc_clear_notices();
    return apply_filters('woocommerce_add_to_cart_validation', $passed, $product->get_id(), $quantity, 0, array());
};
$check_checkout = static function ($allowed, $description) use ($check) {
    wc_clear_notices();
    do_action('woocommerce_checkout_process');
    $check((0 === wc_notice_count('error')) === $allowed, 'Classic checkout: ' . $description);
    $rejected = false;
    try {
        do_action('woocommerce_store_api_checkout_update_order_from_request', new WC_Order(), new WP_REST_Request());
    } catch (Exception $error) {
        $rejected = true;
    }
    $check(! $rejected === $allowed, 'Block checkout: ' . $description);
};
$disable_auto = null;
$relocate = null;

try {
    update_option('eva_course_bookings_settings', array_merge((array) $saved_settings, array('lead_time_days' => 2)));
    WC()->session = new WC_Session_Handler();
    WC()->cart = new WC_Cart();
    WC()->cart->get_cart();

    $course = $make_product('Booking selector course');
    $gift = $make_product('Booking selector gift course', true, true);
    $other = $make_product('Booking selector other course');
    $ordinary = $make_product('Booking selector ordinary product', false);
    $today = new DateTimeImmutable('today', wp_timezone());
    $date = $today->modify('+30 days')->format('Y-m-d');
    $slot_id = $make_slot($course, $date);
    $foreign_id = $make_slot($other, $date);
    $closed_id = $make_slot($course, $date, 'closed');
    $early_id = $make_slot($course, $today->modify('+1 day')->format('Y-m-d'));

    $GLOBALS['post'] = get_post($course->get_id());
    $GLOBALS['product'] = $course;
    $check(15 === has_action('woocommerce_before_add_to_cart_button', array($plugin->frontend, 'render_slot_selection')), 'The original automatic callback and priority must remain available.');
    $default = $render('woocommerce_before_add_to_cart_button');
    $check(1 === substr_count($default, 'id="eva-slot-selection"'), 'Standard courses must automatically render exactly one selector.');
    foreach (array('eva_slot_id', 'eva_slot_start', 'eva_slot_end', 'eva_skip_slot') as $field) {
        $check(str_contains($default, 'name="' . $field . '"'), 'Existing purchase field must remain: ' . $field);
    }
    $check(! str_contains($default, 'id="eva-skip-slot-checkbox"'), 'Skip-slot controls must remain hidden when disabled for the product.');
    $check($default === $render('eva_course_bookings_render_booking_selector'), 'The public action must default to the current product and reuse the existing markup.');
    $check($default === $capture(static function () use ($plugin, $course) {
        $plugin->frontend->render_booking_selector($course->get_id());
    }), 'The public renderer must also be callable directly.');

    $disable_auto = static function ($enabled, $product_id) use ($course) {
        return $course->get_id() === $product_id ? false : $enabled;
    };
    add_filter('eva_course_bookings_auto_render_booking_selector', $disable_auto, 10, 2);
    $check('' === $render('woocommerce_before_add_to_cart_button'), 'Custom layouts must be able to disable automatic rendering.');
    $check($default === $render('eva_course_bookings_render_booking_selector', $course->get_id()), 'Disabling automatic rendering must not disable the public action.');

    $relocate = static function () use ($course) {
        do_action('eva_course_bookings_render_booking_selector', $course->get_id());
    };
    add_action('woocommerce_after_add_to_cart_quantity', $relocate);
    $custom_form = $capture('woocommerce_simple_add_to_cart');
    remove_action('woocommerce_after_add_to_cart_quantity', $relocate);
    $check(1 === substr_count($custom_form, 'id="eva-slot-selection"') && 1 === substr_count($custom_form, 'name="eva_slot_id"'), 'Relocation in a real WooCommerce form must not duplicate the selector or its inputs.');
    $check(strpos($custom_form, '<form') < strpos($custom_form, 'id="eva-slot-selection"') && strpos($custom_form, 'id="eva-slot-selection"') < strpos($custom_form, '</form>'), 'Custom selector inputs must remain inside the purchase form.');

    $GLOBALS['wp_query'] = new WP_Query(array('post_type' => 'product', 'p' => $course->get_id()));
    $plugin->frontend->enqueue_frontend_assets();
    $check(wp_script_is('eva-course-bookings-frontend', 'enqueued') && wp_style_is('eva-course-bookings-frontend', 'enqueued'), 'Custom rendering must retain the existing calendar assets.');
    $localized = wp_scripts()->get_data('eva-course-bookings-frontend', 'data');
    $check(1 === preg_match('/var evaFrontendData = (.+);/', $localized, $matches), 'Existing frontend configuration must remain available.');
    $localized_data = json_decode($matches[1], true);
    $check((int) $localized_data['productId'] === $course->get_id() && in_array($date, $localized_data['availableDates'], true), 'Custom rendering must retain product-specific AJAX and calendar data.');

    $GLOBALS['post'] = get_post($gift->get_id());
    $gift_markup = $render('woocommerce_before_add_to_cart_button');
    $check(str_contains($gift_markup, 'id="eva-skip-slot-checkbox"') && str_contains($gift_markup, 'Nessuna data disponibile'), 'Automatic opt-out must be product-specific and retain enabled skip controls and empty-slot notices.');
    $GLOBALS['post'] = get_post($ordinary->get_id());
    $check('' === $render('woocommerce_before_add_to_cart_button') && '' === $render('eva_course_bookings_render_booking_selector', $ordinary->get_id()), 'Non-course products must never render a selector.');
    $check($default === $render('eva_course_bookings_render_booking_selector', $course->get_id()), 'An explicit product ID must override the current post.');
    $GLOBALS['post'] = null;
    $check('' === $render('woocommerce_before_add_to_cart_button') && '' === $render('eva_course_bookings_render_booking_selector'), 'Missing product context must safely render nothing.');
    $check('' === $render('eva_course_bookings_render_booking_selector', PHP_INT_MAX), 'Missing products must safely render nothing.');
    $check($default === $render('eva_course_bookings_render_booking_selector', $course->get_id()), 'An explicit product ID must work without a global post.');

    $check(! $validate($course, array()), 'A course purchase without a slot must fail.');
    $check(! $validate($course, array('eva_skip_slot' => '1')), 'A forged skip flag must not bypass required slot selection.');
    $check(array() === apply_filters('woocommerce_add_cart_item_data', array(), $course->get_id(), 0), 'A forged skip flag must not create pending cart data.');
    foreach (array('invalid' => $ordinary->get_id(), 'foreign' => $foreign_id, 'closed' => $closed_id, 'too early' => $early_id) as $reason => $invalid_id) {
        $check(! $validate($course, array('eva_skip_slot' => '1', 'eva_slot_id' => $invalid_id)), 'A forged skip flag must not bypass slot validation: ' . $reason);
        $check(wc_notice_count('error') > 0, 'Invalid slot selection must report a purchase error.');
    }
    $selection = array('eva_slot_id' => $slot_id, 'eva_slot_start' => $date . ' 10:00:00', 'eva_slot_end' => $date . ' 12:00:00');
    $check(! $validate($course, $selection, 5), 'A purchase must not exceed slot capacity.');
    $check($validate($course, $selection, 2), 'A valid scheduled purchase must pass.');
    $scheduled = apply_filters('woocommerce_add_cart_item_data', array(), $course->get_id(), 0);
    $check($scheduled['eva_slot_id'] === $slot_id && $scheduled['eva_slot_start'] === $selection['eva_slot_start'] && $scheduled['eva_slot_end'] === $selection['eva_slot_end'], 'Scheduled cart metadata must retain the existing fields.');
    $check($validate($course, array_merge($selection, array('eva_skip_slot' => '1')), 2), 'An unauthorized skip flag must still permit an otherwise valid scheduled purchase.');
    $check($scheduled === apply_filters('woocommerce_add_cart_item_data', array(), $course->get_id(), 0), 'An unauthorized skip flag must retain scheduled data instead of creating a pending booking.');
    $check(! $validate($course, $selection, 1, false), 'Earlier add-to-cart validation failures must be preserved.');
    $check($validate($ordinary, array('eva_skip_slot' => '1')), 'Non-course purchases must retain ordinary validation.');
    $check(array('existing' => 'value') === apply_filters('woocommerce_add_cart_item_data', array('existing' => 'value'), $ordinary->get_id(), 0), 'Non-course cart data must remain unchanged.');

    $cart_values = array_merge($scheduled, array('product_id' => $course->get_id(), 'data' => $course, 'quantity' => 2));
    WC()->cart->cart_contents = array('booking-selector-test' => $cart_values);
    $check(! $validate($course, $selection, 3), 'Adding the same slot again must account for its quantity already in the cart.');
    $check_checkout(true, 'a valid scheduled booking must pass.');
    $check(true === $repo->update_slot($slot_id, array('status' => 'closed')), 'Prepare a closed slot.');
    $check_checkout(false, 'a slot closed after adding to cart must fail.');
    $check(true === $repo->update_slot($slot_id, array('status' => 'open', 'capacity' => 1)), 'Prepare reduced capacity.');
    $check_checkout(false, 'reduced capacity must prevent overselling.');
    $check(true === $repo->update_slot($slot_id, array('capacity' => 4)), 'Restore slot capacity.');
    $item = new WC_Order_Item_Product();
    do_action('woocommerce_checkout_create_order_line_item', $item, 'booking-selector-test', $cart_values, new WC_Order());
    $check((int) $item->get_meta('_eva_slot_id') === $slot_id && $item->get_meta('_eva_slot_start') === $selection['eva_slot_start'] && $item->get_meta('_eva_slot_end') === $selection['eva_slot_end'] && 2 === $item->get_meta('_eva_slot_qty') && 'no' === $item->get_meta('_eva_seats_reserved'), 'Scheduled checkout must preserve order metadata.');

    WC()->cart->cart_contents = array();
    $check($validate($gift, array('eva_skip_slot' => '1')), 'An enabled skip flag must allow purchase without any available slots.');
    $pending = apply_filters('woocommerce_add_cart_item_data', array(), $gift->get_id(), 0);
    $check(true === $pending['eva_pending_booking'] && ! isset($pending['eva_slot_id']), 'Enabled skip purchases must retain pending-booking cart data.');
    foreach (array('', 'invalid-email') as $email) {
        $check(! $validate($gift, array('eva_skip_slot' => '1', 'eva_gift_recipient' => '1', 'eva_gift_email' => $email)), 'Gift purchases must still reject missing or invalid recipient emails.');
    }
    $check($validate($gift, array('eva_skip_slot' => '1', 'eva_gift_recipient' => '1', 'eva_gift_email' => 'recipient@example.test')), 'A valid gift purchase must pass.');
    $gift_data = apply_filters('woocommerce_add_cart_item_data', array(), $gift->get_id(), 0);
    $check(true === $gift_data['eva_pending_booking'] && 'recipient@example.test' === $gift_data['eva_gift_email'], 'Gift-recipient metadata must remain in the cart.');
    $check(! $validate($gift, array('eva_skip_slot' => '0')), 'Only the existing explicit skip value may bypass slot selection.');
    $pending_values = array_merge($gift_data, array('product_id' => $gift->get_id(), 'data' => $gift, 'quantity' => 2));
    WC()->cart->cart_contents = array('booking-selector-gift' => $pending_values);
    $check_checkout(true, 'an enabled pending booking must pass.');
    update_post_meta($gift->get_id(), '_eva_course_skip_slot', 'no');
    $check_checkout(true, 'existing pending bookings must survive disabling skip for new purchases.');
    $check(! $validate($gift, array('eva_skip_slot' => '1')), 'New purchases must honor a disabled skip setting.');
    $pending_item = new WC_Order_Item_Product();
    do_action('woocommerce_checkout_create_order_line_item', $pending_item, 'booking-selector-gift', $pending_values, new WC_Order());
    $check('yes' === $pending_item->get_meta('_eva_pending_booking') && 2 === $pending_item->get_meta('_eva_slot_qty') && 'recipient@example.test' === $pending_item->get_meta('_eva_gift_email') && '' === $pending_item->get_meta('_eva_slot_id'), 'Existing pending purchases must preserve their order metadata.');

    WP_CLI::success('Default/custom selector rendering, purchase validation, and Classic/Block checkout checks passed.');
} finally {
    if ($disable_auto) {
        remove_filter('eva_course_bookings_auto_render_booking_selector', $disable_auto, 10);
    }
    if ($relocate) {
        remove_action('woocommerce_after_add_to_cart_quantity', $relocate);
    }
    foreach (array_reverse($posts) as $post_id) {
        wp_delete_post($post_id, true);
    }
    if (false === $saved_settings) {
        delete_option('eva_course_bookings_settings');
    } else {
        update_option('eva_course_bookings_settings', $saved_settings);
    }
    foreach ($saved_globals as $key => $value) {
        $GLOBALS[$key] = $value;
    }
    $_POST = $saved_post;
    WC()->cart = $saved_cart;
    WC()->session = $saved_session;
}
