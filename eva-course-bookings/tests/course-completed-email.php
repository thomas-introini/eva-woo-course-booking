<?php
/**
 * Run with wp eval-file in a disposable local WordPress/WooCommerce environment.
 * Mail is intercepted; fixtures and email settings are restored afterwards.
 * An optional directory argument receives HTML/plain-text previews.
 */

use Eva_Course_Bookings\Plugin;
use Eva_Course_Bookings\WC_Email_Course_Completed_Order;

if (! defined('WP_CLI') || ! WP_CLI || ! class_exists(Plugin::class)) {
    throw new RuntimeException('Run through WP-CLI with WooCommerce and Eva Course Bookings active.');
}

$check = static function ($condition, $message) {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$posts = array();
$orders = array();
$mails = array();
$settings_key = 'woocommerce_eva_course_completed_order_settings';
$standard_key = 'woocommerce_customer_completed_order_settings';
$saved_settings = array($settings_key => get_option($settings_key), $standard_key => get_option($standard_key));
$capture = static function ($result, $mail) use (&$mails) {
    $mails[] = $mail;
    return true;
};
add_filter('pre_wp_mail', $capture, PHP_INT_MAX, 2);

$make_product = static function ($name, $course, $virtual = false) use (&$posts) {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price('30');
    $product->set_virtual($virtual);
    $product->update_meta_data('_eva_course_enabled', $course ? 'yes' : 'no');
    $product->save();
    $posts[] = $product->get_id();
    return $product;
};
$make_order = static function ($lines) use (&$orders) {
    $order = new WC_Order();
    $order->set_billing_first_name('Eva & Amici');
    $order->set_billing_last_name('Test');
    $order->set_billing_email('buyer@example.test');
    $order->set_billing_phone('020000000');
    $order->set_billing_address_1('Via Test 1');
    $order->set_billing_city('Milano');
    $order->set_billing_country('IT');
    $order->set_shipping_address_1('SHIPPING ADDRESS MUST NOT APPEAR');
    $order->set_currency('EUR');
    $order->save();
    $orders[] = $order;
    foreach ($lines as $line) {
        $item = new WC_Order_Item_Product();
        $item->set_product($line[0]);
        $item->set_quantity(2);
        $item->set_subtotal('60');
        $item->set_total('60');
        foreach ($line[1] ?? array() as $key => $value) {
            $item->add_meta_data($key, $value, true);
        }
        $order->add_item($item);
    }
    $order->calculate_totals();
    $order->save();
    return $order;
};
$notify = static function ($order) use (&$mails) {
    $mails = array();
    do_action('woocommerce_order_status_completed_notification', $order->get_id(), $order);
};

try {
    $mailer = WC()->mailer();
    $emails = $mailer->get_emails();
    $email = $emails['WC_Email_Course_Completed_Order'];
    $standard = $emails['WC_Email_Customer_Completed_Order'];
    $check($email instanceof WC_Email_Course_Completed_Order, 'Custom email must be registered.');
    $check(str_contains(apply_filters('woocommerce_email_styles', '', $email), '14px'), 'Course email must retain readable mobile body text.');
    $check('unchanged' === apply_filters('woocommerce_email_styles', 'unchanged', $standard), 'Other email styles must remain unchanged.');

    delete_option($settings_key);
    update_option($standard_key, array('enabled' => 'no'));
    $probe = new WC_Email_Course_Completed_Order();
    remove_action('woocommerce_order_status_completed_notification', array($probe, 'trigger'), 10);
    remove_filter('woocommerce_email_styles', array($probe, 'email_styles'), 10);
    $check(! $probe->is_enabled(), 'First use must preserve a disabled standard email.');
    update_option($standard_key, array('enabled' => 'yes'));
    $probe = new WC_Email_Course_Completed_Order();
    remove_action('woocommerce_order_status_completed_notification', array($probe, 'trigger'), 10);
    remove_filter('woocommerce_email_styles', array($probe, 'email_styles'), 10);
    $check(! $probe->is_enabled(), 'Course settings must remain independent after initialization.');

    $page_id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Test booking page', 'post_content' => '[eva_course_bookings_manage]'));
    $posts[] = $page_id;
    $email->init_form_fields();
    $check(isset($email->form_fields['booking_page_id']['options'][$page_id]), 'Booking selector must include shortcode pages.');
    $saved_post = $_POST;
    $_POST = array(
        $email->get_field_key('enabled') => '1',
        $email->get_field_key('subject') => '',
        $email->get_field_key('heading') => '',
        $email->get_field_key('additional_content') => 'Istruzioni ordine {order_number}',
        $email->get_field_key('email_type') => 'html',
        $email->get_field_key('booking_page_id') => (string) $page_id,
    );
    try {
        $email->process_admin_options();
    } finally {
        $_POST = $saved_post;
    }
    $email->init_settings();
    $email->enabled = 'yes';
    $standard->enabled = 'yes';
    $check((int) get_option($settings_key)['booking_page_id'] === $page_id, 'Booking-page setting must persist.');
    $check($email->get_booking_page_url() === get_permalink($page_id), 'Booking-page link must resolve.');

    $course = $make_product('Fotografia & Luce', true);
    $virtual_course = $make_product('Corso virtuale', true, true);
    $merchandise = $make_product('Merchandise', false);
    $virtual_product = $make_product('Other virtual product', false, true);
    $slot_id = Plugin::get_instance()->slot_repository->create_slot(array('product_id' => $course->get_id(), 'start_datetime' => '2050-06-15 10:00:00', 'end_datetime' => '2050-06-15 12:00:00', 'capacity' => 10));
    $check(! is_wp_error($slot_id), 'Test slot must be created.');
    $posts[] = $slot_id;
    $scheduled_meta = array('_eva_slot_id' => $slot_id, '_eva_slot_start' => '2050-06-15 10:00:00', '_eva_slot_end' => '2050-06-15 12:00:00', '_eva_slot_qty' => 2);
    $scheduled = $make_order(array(array($course, $scheduled_meta)));
    $fee = new WC_Order_Item_Fee();
    $fee->set_name('Test fee');
    $fee->set_total('5');
    $scheduled->add_item($fee);
    $scheduled->calculate_totals();
    $scheduled->save();
    $check(Plugin::is_course_only_order($scheduled), 'Fees must not disqualify a course-only order.');
    $notify($scheduled);
    $check(count($mails) === 1 && str_contains($mails[0]['subject'], 'Conferma acquisto corsi'), 'Scheduled course must receive exactly one course confirmation.');
    $check(str_contains($mails[0]['subject'], (string) $scheduled->get_order_number()), 'Subject placeholders must resolve.');
    $html = $email->get_content_html();
    $check(str_contains($html, '15/06/2050 10:00') && str_contains($html, '12:00'), 'Scheduled date and end time must appear.');
    $check(str_contains($html, 'Fotografia &amp; Luce') && str_contains($html, 'Test fee'), 'HTML must escape names and retain order totals.');
    $check(! str_contains($html, 'SHIPPING ADDRESS MUST NOT APPEAR') && ! str_contains($html, 'Scegli la data dei tuoi corsi'), 'Scheduled email must omit shipping address and date-selection action.');
    $check(substr_count($html, 'Informazioni corso') === 1, 'Course summary must appear once.');
    $check(str_contains($html, 'Istruzioni ordine ' . $scheduled->get_order_number()), 'Additional-content placeholders must resolve.');

    $pending = $make_order(array(array($virtual_course, array('_eva_pending_booking' => 'yes', '_eva_gift_email' => 'gift@example.test')), array($course, $scheduled_meta)));
    $notify($pending);
    $check(count($mails) === 1 && (array) $mails[0]['to'] === array('buyer@example.test'), 'Gift order must send one email only to the purchaser.');
    $html = $email->get_content_html();
    $plain = $email->get_content_plain();
    foreach (array($html, $plain) as $content) {
        $check(str_contains($content, 'Data da scegliere') && str_contains($content, get_permalink($page_id)), 'Pending email must contain date-selection instructions and booking URL.');
        $check(! str_contains($content, 'Ti contatteremo') && str_contains($content, '15/06/2050'), 'Mixed booking states must show correct next steps and scheduled dates.');
    }
    $check(str_contains($plain, 'Fotografia & Luce') && ! str_contains($plain, '<table'), 'Plain text must contain readable names without HTML.');
    $check(! str_contains($email->get_booking_page_url(), 'buyer') && ! str_contains($email->get_booking_page_url(), 'gift'), 'Booking URL must not carry customer information.');
    if (! empty($args[0])) {
        file_put_contents($args[0] . '/course-confirmation.html', $email->style_inline($html));
    }

    $saved_request = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $saved_post = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    try {
        foreach (array('buyer@example.test' => true, 'gift@example.test' => true, 'stranger@example.test' => false) as $lookup_email => $allowed) {
            $_POST = array('eva_booking_action' => 'lookup', 'eva_order_id' => $pending->get_id(), 'eva_order_email' => $lookup_email, '_eva_booking_nonce' => wp_create_nonce('eva_course_booking_lookup'));
            $portal = Plugin::get_instance()->frontend->render_booking_shortcode(array());
            $check(str_contains($portal, 'Corso virtuale') === $allowed, 'Booking portal must accept purchaser/gift email and reject unrelated email.');
            if ('gift@example.test' === $lookup_email) {
                $check(! str_contains($portal, 'Fotografia'), 'Gift recipient must see only their gift course.');
            }
        }
    } finally {
        $_SERVER['REQUEST_METHOD'] = $saved_request;
        $_POST = $saved_post;
    }

    $email->email_type = 'plain';
    $notify($pending);
    $check(count($mails) === 1 && ! preg_match('/<[^>]+>/', $mails[0]['message']) && str_contains($mails[0]['message'], get_permalink($page_id)), 'Plain-text sending must retain the booking link without HTML.');
    $check(str_contains($mails[0]['message'], 'Fotografia & Luce'), 'Sent plain text must decode HTML entities in WooCommerce order details.');
    if (! empty($args[0])) {
        file_put_contents($args[0] . '/course-confirmation.txt', $mails[0]['message']);
    }
    $email->email_type = 'html';

    $email->settings['booking_page_id'] = '';
    $check(! $email->get_booking_page_url() && str_contains($email->get_content_html(), 'Contattaci per scegliere'), 'Missing page must use contact fallback.');
    $email->settings['booking_page_id'] = $page_id;
    wp_update_post(array('ID' => $page_id, 'post_status' => 'draft'));
    $check(! $email->get_booking_page_url(), 'Unpublished booking pages must not be linked.');
    wp_update_post(array('ID' => $page_id, 'post_status' => 'publish', 'post_content' => 'No shortcode'));
    $check(! $email->get_booking_page_url(), 'Pages without the booking shortcode must not be linked.');
    wp_update_post(array('ID' => $page_id, 'post_content' => '[eva_course_bookings_manage]'));

    $legacy = $make_order(array(array($course)));
    $notify($legacy);
    $check(count($mails) === 1 && str_contains($email->get_content_html(), 'Data da concordare'), 'Legacy unscheduled courses must have contact instructions.');
    $check(! str_contains($email->get_content_html(), 'Scegli la data dei tuoi corsi'), 'Legacy purchases must not promise portal date selection.');

    foreach (array(array(array($merchandise)), array(array($virtual_product)), array(array($course), array($merchandise)), array(array($course), array($virtual_product)), array()) as $lines) {
        $order = $make_order($lines);
        $check(! Plugin::is_course_only_order($order), 'Non-course, mixed, and empty orders must fail eligibility.');
        $notify($order);
        $check(count($mails) === 1 && ! str_contains($mails[0]['subject'], 'Conferma acquisto corsi'), 'Nonqualifying order must retain its standard completion email.');
    }
    $check(! Plugin::is_course_only_order(false), 'Missing order must not qualify.');
    $email->enabled = 'no';
    $notify($scheduled);
    $check(count($mails) === 0, 'Disabled course email must not fall back to the standard email.');
    $email->enabled = 'yes';
    $standard->enabled = 'no';
    $notify($scheduled);
    $check(count($mails) === 1, 'Course email must be independently enabled.');
    $standard->enabled = 'yes';

    $scheduled->update_status('processing');
    $mails = array();
    $scheduled->update_status('completed');
    $check(count($mails) === 1 && str_contains($mails[0]['subject'], 'Conferma acquisto corsi'), 'A real Completed status transition must send one course confirmation.');

    $parent = new WC_Product_Variable();
    $parent->set_name('Variable course');
    $parent->update_meta_data('_eva_course_enabled', 'yes');
    $parent->save();
    $posts[] = $parent->get_id();
    $variation = new WC_Product_Variation();
    $variation->set_parent_id($parent->get_id());
    $variation->set_regular_price('30');
    $variation->save();
    $posts[] = $variation->get_id();
    $check(Plugin::is_course_only_order($make_order(array(array($variation)))), 'Variations must use the parent course flag.');
    update_post_meta($course->get_id(), '_eva_course_enabled', 'no');
    $check(! Plugin::is_course_only_order($scheduled), 'Eligibility must use the current course flag.');
    update_post_meta($course->get_id(), '_eva_course_enabled', 'yes');

    $scheduled->update_meta_data('_eva_slot_info', array(array('product' => 'STALE CACHE', 'start' => '2000-01-01 10:00:00')));
    $scheduled->save();
    $item = current($scheduled->get_items());
    $item->update_meta_data('_eva_slot_start', '2050-06-16 11:00:00');
    $item->save();
    $notify($scheduled);
    $check(str_contains($email->get_content_html(), '16/06/2050 11:00') && ! str_contains($email->get_content_html(), 'STALE CACHE'), 'Updated order metadata must override stale aggregate data.');
    $item->delete_meta_data('_eva_slot_start');
    $item->save();
    $check(str_contains($email->get_content_html(), '15/06/2050 10:00'), 'Missing date metadata must fall back to the slot repository.');

    $deleted = $make_product('Deleted course', true);
    $deleted_order = $make_order(array(array($deleted)));
    wp_delete_post($deleted->get_id(), true);
    $check(! Plugin::is_course_only_order($deleted_order), 'Deleted products must not qualify.');
    $mails = array();
    $locale = get_locale();
    $check(false === $email->trigger(0), 'Invalid orders must not reuse a previous recipient.');
    $check(count($mails) === 0 && $locale === get_locale(), 'Invalid triggers must not send or change locale.');
    $scheduled->set_billing_email('');
    $scheduled->save();
    $notify($scheduled);
    $check(count($mails) === 0 && $locale === get_locale(), 'Missing recipient must not send or leave a changed locale.');

    WP_CLI::success('Course completion email routing, settings, HTML/plain content, and booking fallbacks passed.');
} finally {
    foreach (array_reverse($orders) as $order) {
        $order->delete(true);
    }
    foreach (array_reverse($posts) as $post_id) {
        wp_delete_post($post_id, true);
    }
    foreach ($saved_settings as $key => $value) {
        if (false === $value) {
            delete_option($key);
        } else {
            update_option($key, $value);
        }
    }
    remove_filter('pre_wp_mail', $capture, PHP_INT_MAX);
}
