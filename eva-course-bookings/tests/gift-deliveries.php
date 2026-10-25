<?php
/** Run with wp eval-file; all mail is intercepted and fixtures are removed. Optional args: legacy|hpos, preview directory. */
use Eva_Course_Bookings\Gift_Delivery;
use Eva_Course_Bookings\Plugin;
use Eva_Course_Bookings\Voucher_Pdf;

if (! defined('WP_CLI') || ! WP_CLI || ! class_exists(Plugin::class)) {
    throw new RuntimeException('Run with WP-CLI and WooCommerce active.');
}
$check = static function ($condition, $message) {
    if (! $condition) { throw new RuntimeException($message); }
};
$mode = $args[0] ?? '';
$preview_directory = $args[1] ?? '';
$storage_filter = static function ($value) use ($mode) { return in_array($mode, array('legacy', 'hpos'), true) ? ('hpos' === $mode ? 'yes' : 'no') : $value; };
add_filter('pre_option_woocommerce_custom_orders_table_enabled', $storage_filter);
$posts = array();
$orders = array();
$mails = array();
$fail_recipient = '';
$fail_alert = false;
$temporary_files = array();
$saved_post = $_POST;
$saved_get = $_GET;
$saved_request = $_REQUEST;
$saved_cart = WC()->cart;
$saved_session = WC()->session;
$saved_user = get_current_user_id();
$saved_timezone = get_option('timezone_string');
$saved_vouchers = get_option(Voucher_Pdf::OPTION);
$saved_email_settings = get_option('woocommerce_eva_course_gift_settings');
$saved_completed = get_option('woocommerce_eva_course_completed_order_settings');
if (! class_exists('WC_Admin_Meta_Boxes')) {
    require_once WC_ABSPATH . 'includes/admin/class-wc-admin-meta-boxes.php';
}
$saved_meta_box_errors = WC_Admin_Meta_Boxes::$meta_box_errors;
$capture = static function ($result, $mail) use (&$mails, &$fail_recipient, &$fail_alert, &$temporary_files) {
    $mail['pdf_bytes'] = array();
    foreach ((array) ($mail['attachments'] ?? array()) as $path) {
        if (! $path) { continue; }
        $temporary_files[] = $path;
        $mail['pdf_bytes'][] = file_get_contents($path);
    }
    $mails[] = $mail;
    return (! $fail_recipient || ! in_array($fail_recipient, (array) $mail['to'], true)) && (! $fail_alert || ! in_array('alerts@example.test', (array) $mail['to'], true));
};
add_filter('pre_wp_mail', $capture, PHP_INT_MAX, 2);
$plugin = Plugin::get_instance();
$service = $plugin->gift_delivery;
$fresh = static function ($item) { $item->read_meta_data(true); return $item; };
$make_order = static function ($product, $data, $due, $gift = true) use (&$orders) {
    $order = new WC_Order();
    $order->set_billing_email('buyer@example.test');
    $order->set_billing_address_1('PRIVATE BILLING ADDRESS');
    $order->set_billing_first_name('Private buyer');
    $order->save();
    $orders[] = $order;
    $item = new WC_Order_Item_Product();
    $item->set_product($product);
    $item->set_quantity(2);
    $item->add_meta_data('_eva_pending_booking', 'yes');
    $item->add_meta_data('_eva_gift_email', 'recipient@example.test');
    if ($gift) {
        $item->add_meta_data('_eva_gift_data', $data);
        $item->add_meta_data('_eva_gift_due', $due);
        $item->add_meta_data('_eva_gift_status', 'awaiting_payment');
    }
    $order->add_item($item);
    $order->save();
    return array($order, $item);
};

try {
    update_option('timezone_string', 'Europe/Rome');
    $settings = Voucher_Pdf::defaults();
    $settings['error_email'] = 'alerts@example.test';
    update_option(Voucher_Pdf::OPTION, $settings);
    update_option('woocommerce_eva_course_gift_settings', array('enabled' => 'yes', 'email_type' => 'html'));
    Voucher_Pdf::load_library();
    $background = new tFPDF('L', 'mm', 'A4');
    $background->AddPage();
    $background->SetFont('Helvetica', '', 28);
    $background->SetXY(20, 25);
    $background->Cell(257, 20, 'EVA - CORSO IN REGALO', 0, 0, 'C');
    $background->SetFontSize(15);
    $background->SetXY(20, 50);
    $background->Cell(257, 10, 'Un viaggio nel mondo del caffe', 0, 0, 'C');
    $upload = wp_upload_bits('eva-voucher-test.pdf', null, $background->Output('S'));
    $check(! $upload['error'], 'Test PDF upload must succeed.');
    $attachment = wp_insert_attachment(array('post_title' => 'Voucher test', 'post_mime_type' => 'application/pdf'), $upload['file']);
    $posts[] = $attachment;
    $product = new WC_Product_Simple();
    $product->set_name('Corso caffè regalo');
    $product->set_regular_price('30');
    $product->update_meta_data('_eva_course_enabled', 'yes');
    $product->update_meta_data('_eva_course_skip_slot', 'yes');
    $product->update_meta_data('_eva_gift_pdf_id', $attachment);
    $product->save();
    $posts[] = $product->get_id();
    $admin = get_users(array('role' => 'administrator', 'number' => 1));
    wp_set_current_user($admin[0]->ID);
    update_post_meta($product->get_id(), '_eva_gift_pdf_id', 0);
    $unfinished_layout = $settings;
    $unfinished_layout['width'] = 300;
    update_option(Voucher_Pdf::OPTION, $unfinished_layout);
    $_POST = array('_eva_gift_pdf_id' => (string) $attachment);
    WC_Admin_Meta_Boxes::$meta_box_errors = array();
    $plugin->gift_admin->save_product($product->get_id());
    $check((int) get_post_meta($product->get_id(), '_eva_gift_pdf_id', true) === $attachment, 'A PDF selection must persist before its layout is configured.');
    $check(strpos(implode(' ', WC_Admin_Meta_Boxes::$meta_box_errors), 'PDF associato') !== false, 'Unfinished layout must show an actionable warning after saving the PDF.');
    ob_start(); $plugin->gift_admin->render_settings(); $unfinished_html = ob_get_clean();
    $check(strpos($unfinished_html, '<option value="' . $product->get_id() . '">') !== false, 'An assigned PDF must make the course selectable while configuring its layout.');
    $unfinished_rejected = false;
    try { Voucher_Pdf::render($attachment, Voucher_Pdf::sample()); } catch (Throwable $error) { $unfinished_rejected = true; }
    $check($unfinished_rejected, 'Persisting a PDF selection must not bypass rendering validation.');
    update_option(Voucher_Pdf::OPTION, $settings);
    $_POST = array('_eva_gift_pdf_id' => (string) $product->get_id());
    $plugin->gift_admin->save_product($product->get_id());
    $check((int) get_post_meta($product->get_id(), '_eva_gift_pdf_id', true) === $attachment, 'A non-PDF media selection must not overwrite the existing PDF.');
    wp_set_current_user(0);
    $_POST = array('_eva_gift_pdf_id' => '0');
    $plugin->gift_admin->save_product($product->get_id());
    $check((int) get_post_meta($product->get_id(), '_eva_gift_pdf_id', true) === $attachment, 'Unauthorized users must not change the PDF assignment.');
    wp_set_current_user($admin[0]->ID);
    $plugin->gift_admin->save_product($product->get_id());
    $check(0 === (int) get_post_meta($product->get_id(), '_eva_gift_pdf_id', true), 'Removing a PDF must persist.');
    ob_start(); $plugin->frontend->render_booking_selector($product->get_id()); $legacy_selector = ob_get_clean();
    $check(strpos($legacy_selector, 'name="eva_gift_email"') !== false && strpos($legacy_selector, 'name="eva_gift_mode"') === false, 'Courses without a saved PDF must retain the legacy gift form.');
    $_POST = array('_eva_gift_pdf_id' => (string) $attachment);
    WC_Admin_Meta_Boxes::$meta_box_errors = array();
    $plugin->gift_admin->save_product($product->get_id());
    $check((int) get_post_meta($product->get_id(), '_eva_gift_pdf_id', true) === $attachment && ! WC_Admin_Meta_Boxes::$meta_box_errors, 'A configured PDF must save without errors.');
    wp_set_current_user($saved_user);
    $_POST = $saved_post;
    WC_Admin_Meta_Boxes::$meta_box_errors = $saved_meta_box_errors;
    $date = (new DateTimeImmutable('tomorrow', wp_timezone()))->format('Y-m-d');
    $input = array('eva_skip_slot' => '1', 'eva_gift_recipient' => '1', 'eva_gift_email' => 'recipient@example.test', 'eva_gift_recipient_name' => 'Giulia Èva', 'eva_gift_sender' => 'Marco & amici', 'eva_gift_note' => "Buon compleanno!\nUn’esperienza speciale.", 'eva_gift_mode' => 'scheduled', 'eva_gift_date' => $date);
    $data = Gift_Delivery::purchase_data($product->get_id(), $input);
    $check(! is_wp_error($data), 'Valid Unicode gift details must render: ' . (is_wp_error($data) ? $data->get_error_message() : ''));
    foreach (array('UTC', '+06:00', '+12:00', '-06:00') as $zone) {
        $today = (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('Y-m-d');
        $same_day = array_merge($data, array('date' => $today, 'timezone' => $zone));
        $allowed = Gift_Delivery::due_at($today, $zone) > time();
        $check($allowed === ! is_wp_error(Gift_Delivery::validate_data($product->get_id(), $same_day)), 'Same-day delivery must be allowed exactly while its 08:00 is still in the future (' . $zone . ').');
    }
    foreach (array(
        array('2026-10-26 00:19:00', '+02:00', '2026-10-26'),
        array('2026-10-26 07:59:59.999999', '+02:00', '2026-10-26'),
        array('2026-10-26 08:00:00', '+02:00', '2026-10-27'),
        array('2026-10-26 08:00:00.000001', '+02:00', '2026-10-27'),
        array('2026-10-26 23:59:59', '+02:00', '2026-10-27'),
        array('2026-12-31 08:00:00', 'Europe/Rome', '2027-01-01'),
        array('2027-03-28 07:59:59', 'Europe/Rome', '2027-03-28'),
        array('2027-10-31 08:00:00', 'Europe/Rome', '2027-11-01'),
    ) as $case) {
        $check($case[2] === Gift_Delivery::min_delivery_date(new DateTimeImmutable($case[0], new DateTimeZone($case[1]))), 'Picker minimum must follow the local 08:00 cutoff, including DST and year rollover: ' . $case[0]);
    }
    $check(Gift_Delivery::due_at('2027-03-28', 'Europe/Rome') === strtotime('2027-03-28 06:00:00 UTC'), 'Summer delivery must remain 08:00 local.');
    $check(Gift_Delivery::due_at('2027-10-31', 'Europe/Rome') === strtotime('2027-10-31 07:00:00 UTC'), 'Winter delivery must remain 08:00 local.');
    foreach (array(array('eva_gift_date' => '2027-02-30'), array('eva_gift_date' => current_datetime()->modify('yesterday')->format('Y-m-d')), array('eva_gift_sender' => ''), array('eva_gift_note' => str_repeat('x', 501)), array('eva_gift_note' => 'Buon compleanno 🎂'), array('eva_gift_sender' => array('bad'))) as $invalid) {
        $check(is_wp_error(Gift_Delivery::purchase_data($product->get_id(), array_merge($input, $invalid))), 'Invalid gift input must fail.');
    }
    $bad = $settings;
    $bad['fields']['note']['x'] = 1000;
    $check(is_wp_error(Voucher_Pdf::validate_settings($bad)), 'Out-of-page coordinates must fail.');
    $bad = $settings;
    $bad['error_email'] = 'invalid';
    $check(is_wp_error(Voucher_Pdf::validate_settings($bad)), 'Invalid alert email must fail.');
    $bad = $settings;
    $bad['fields']['note']['height'] = 1;
    $overflow = false;
    try { Voucher_Pdf::render($attachment, $data, $bad); } catch (Throwable $error) { $overflow = true; }
    $check($overflow, 'Text overflow must fail rather than truncate.');

    WC()->session = new WC_Session_Handler();
    WC()->cart = new WC_Cart();
    $_POST = $input;
    $check($plugin->woo_integration->validate_add_to_cart(true, $product->get_id(), 2), 'Gift must pass product validation.');
    $cart = $plugin->woo_integration->add_cart_item_data(array(), $product->get_id(), 0);
    $cart = array_merge($cart, array('quantity' => 2, 'product_id' => $product->get_id(), 'data' => $product));
    $check($cart['eva_gift_delivery'] === $data, 'Gift payload must survive the cart.');
    WC()->cart->cart_contents = array('gift-test' => $cart);
    wc_clear_notices();
    $plugin->woo_integration->validate_checkout();
    $check(! wc_notice_count('error'), 'Valid gift must pass Classic checkout.');
    $plugin->woo_integration->validate_block_checkout(new WC_Order(), new WP_REST_Request());
    $cart_item = new WC_Order_Item_Product();
    $plugin->woo_integration->add_order_item_meta($cart_item, 'gift-test', $cart, new WC_Order());
    $check($cart_item->get_meta('_eva_gift_data') === $data && 'awaiting_payment' === $cart_item->get_meta('_eva_gift_status'), 'Gift state must persist at checkout.');
    $cart['eva_gift_delivery']['note'] = str_repeat('x', 501);
    WC()->cart->cart_contents = array('gift-test' => $cart);
    wc_clear_notices();
    $plugin->woo_integration->validate_checkout();
    $check(wc_notice_count('error') > 0, 'Invalid gift must fail Classic checkout.');
    $blocked = false;
    try { $plugin->woo_integration->validate_block_checkout(new WC_Order(), new WP_REST_Request()); } catch (Throwable $error) { $blocked = true; }
    $check($blocked, 'Invalid gift must fail Block checkout.');
    wc_clear_notices();

    ob_start();
    $plugin->frontend->render_booking_selector($product->get_id());
    $selector = ob_get_clean();
    $check(strpos($selector, 'name="eva_gift_recipient_name"') !== false && strpos($selector, 'name="eva_gift_note"') !== false && strpos($selector, 'name="eva_gift_date"') !== false, 'Product selector must contain gift controls.');
    $check(strpos($selector, 'name="eva_gift_mode"') !== false && strpos($selector, 'value="now"') !== false && strpos($selector, 'value="scheduled"') !== false, 'A saved PDF must expose immediate/scheduled delivery choices alongside the date picker.');
    $check(1 === preg_match('/id="eva-gift-date"[^>]* min="([^"]+)"/', $selector, $date_min) && $date_min[1] === Gift_Delivery::min_delivery_date(current_datetime()), 'Rendered date picker must use the same delivery cutoff as validation.');

    list($order, $item) = $make_order($product, $data, Gift_Delivery::due_at($date, $data['timezone']));
    $service->sync_order($order->get_id());
    $check(! as_has_scheduled_action(Gift_Delivery::HOOK, array($order->get_id(), $item->get_id()), Gift_Delivery::GROUP), 'Unpaid gifts must not queue.');
    $order->payment_complete();
    $fresh($item);
    $check('scheduled' === $item->get_meta('_eva_gift_status'), 'Paid Processing gifts must become scheduled.');
    $action_id = as_next_scheduled_action(Gift_Delivery::HOOK, array($order->get_id(), $item->get_id()), Gift_Delivery::GROUP);
    $check((int) $action_id === (int) $item->get_meta('_eva_gift_due'), 'Requested delivery time must be scheduled exactly.');
    $service->sync_order($order->get_id());
    $jobs = as_get_scheduled_actions(array('hook' => Gift_Delivery::HOOK, 'args' => array($order->get_id(), $item->get_id()), 'status' => 'pending', 'per_page' => -1), 'ids');
    $check(1 === count($jobs), 'Repeated payment hooks must not create duplicate jobs.');
    $check(is_wp_error($service->send($order->get_id(), $item->get_id())), 'Cron must not send before the requested date.');
    $email = WC()->mailer()->get_emails()['WC_Email_Course_Gift'];
    $email->enabled = 'yes';
    $mails = array();
    $check(true === $service->send($order->get_id(), $item->get_id(), true), 'Admin must be able to send future gifts.');
    $fresh($item);
    $check('sent' === $item->get_meta('_eva_gift_status') && $item->get_meta('_eva_gift_sent_at') > 0, 'Successful send must record timestamp.');
    $check(1 === count($mails) && in_array('recipient@example.test', (array) $mails[0]['to'], true), 'Gift email must go only to its recipient.');
    $check(strpos($mails[0]['message'], 'PRIVATE BILLING ADDRESS') === false && strpos($mails[0]['message'], 'Marco &amp; amici') !== false, 'Gift email must show the sender and protect billing details.');
    $check(1 === count($mails[0]['pdf_bytes']) && strncmp($mails[0]['pdf_bytes'][0], '%PDF-', 5) === 0, 'Email must attach a real PDF.');
    $check(! as_has_scheduled_action(Gift_Delivery::HOOK, array($order->get_id(), $item->get_id()), Gift_Delivery::GROUP), 'Manual success must cancel the scheduled job.');
    $check(is_wp_error($service->send($order->get_id(), $item->get_id(), true)) && 1 === count($mails), 'Sent gifts must never be sent twice.');
    foreach ($temporary_files as $file) { $check(! file_exists($file), 'Temporary attachments must be deleted.'); }
    if ($preview_directory) {
        wp_mkdir_p($preview_directory);
        file_put_contents($preview_directory . '/personalized-voucher.pdf', $mails[0]['pdf_bytes'][0]);
        file_put_contents($preview_directory . '/course-template.pdf', $background->Output('S'));
        file_put_contents($preview_directory . '/gift-email.html', $mails[0]['message']);
    }

    list($failed_order, $failed_item) = $make_order($product, $data, time() + DAY_IN_SECONDS);
    $failed_order->payment_complete();
    $fail_recipient = 'recipient@example.test';
    $mails = array();
    $check(is_wp_error($service->send($failed_order->get_id(), $failed_item->get_id(), true)), 'Transport rejection must fail.');
    $fresh($failed_item);
    $check('failed' === $failed_item->get_meta('_eva_gift_status') && ! $failed_item->get_meta('_eva_gift_sent_at'), 'Failed sends must not have a sent timestamp.');
    $alerts = array_filter($mails, static function ($mail) { return in_array('alerts@example.test', (array) $mail['to'], true); });
    $check(1 === count($alerts), 'Each failed attempt must notify the configured address once.');
    $check(! as_has_scheduled_action(Gift_Delivery::HOOK, array($failed_order->get_id(), $failed_item->get_id()), Gift_Delivery::GROUP), 'Failures must not retry automatically.');
    $fail_recipient = '';
    $check(true === $service->send($failed_order->get_id(), $failed_item->get_id(), true), 'Manual retry must succeed after fixing transport.');
    $fresh($failed_item);
    $check(2 === count($failed_item->get_meta('_eva_gift_history')), 'Failure and retry history must remain available.');

    list($alert_order, $alert_item) = $make_order($product, $data, time() + DAY_IN_SECONDS);
    $alert_order->payment_complete();
    $fail_recipient = 'recipient@example.test';
    $fail_alert = true;
    $mails = array();
    $check(is_wp_error($service->send($alert_order->get_id(), $alert_item->get_id(), true)) && 2 === count($mails), 'A failed error notification must not recurse.');
    $fail_recipient = '';
    $fail_alert = false;
    $alert_order->update_status('pending');
    $alert_order->update_status('processing');
    $check('failed' === $fresh($alert_item)->get_meta('_eva_gift_status'), 'Status toggles must not automatically retry failed mail.');
    $alert_order->update_status('cancelled');
    $check('cancelled' === $fresh($alert_item)->get_meta('_eva_gift_status'), 'Cancellation must also cancel previously failed gifts.');

    list($disabled_order, $disabled_item) = $make_order($product, $data, time() + DAY_IN_SECONDS);
    $disabled_order->payment_complete();
    $email->enabled = 'no';
    $mails = array();
    $check(is_wp_error($service->send($disabled_order->get_id(), $disabled_item->get_id(), true)), 'Disabled gift email must be a visible delivery failure.');
    $check(1 === count($mails) && in_array('alerts@example.test', (array) $mails[0]['to'], true), 'Disabled gift email must still notify the administrator.');
    $email->enabled = 'yes';

    list($refund_order, $refund_item) = $make_order($product, $data, time() + DAY_IN_SECONDS);
    $refund_order->payment_complete();
    $refund = wc_create_refund(array('order_id' => $refund_order->get_id(), 'amount' => 0, 'line_items' => array($refund_item->get_id() => array('qty' => 1, 'refund_total' => 0, 'refund_tax' => array()))));
    $check(! is_wp_error($refund), 'Partial quantity refund fixture must succeed.');
    $orders[] = $refund;
    $refund_order = wc_get_order($refund_order->get_id());
    $check(1 === Gift_Delivery::remaining($refund_order, $refund_order->get_item($refund_item->get_id())), 'Partial refunds must reduce voucher participants.');
    $mails = array();
    $check(true === $service->send($refund_order->get_id(), $refund_item->get_id(), true) && strpos($mails[0]['message'], 'per 1 partecipanti') !== false, 'Partially refunded voucher must cover only remaining participants.');
    list($full_order, $full_item) = $make_order($product, $data, time() + DAY_IN_SECONDS);
    $full_order->payment_complete();
    $refund = wc_create_refund(array('order_id' => $full_order->get_id(), 'amount' => 0, 'line_items' => array($full_item->get_id() => array('qty' => 2, 'refund_total' => 0, 'refund_tax' => array()))));
    $check(! is_wp_error($refund), 'Full quantity refund fixture must succeed.');
    $orders[] = $refund;
    $service->sync_order($full_order->get_id());
    $check('cancelled' === $fresh($full_item)->get_meta('_eva_gift_status'), 'Fully refunded course lines must cancel delivery.');

    $page_id = wp_insert_post(array('post_type' => 'page', 'post_title' => 'Gift booking test', 'post_status' => 'publish', 'post_content' => '[eva_course_bookings_manage]'));
    $posts[] = $page_id;
    $completed_email = WC()->mailer()->get_emails()['WC_Email_Course_Completed_Order'];
    $completed_email->settings['booking_page_id'] = $page_id;
    $email->object = $order;
    $email->gift_item = $item;
    $email->gift_data = $data;
    $email->recipient = 'recipient@example.test';
    $plain = $email->get_content_plain();
    $check(strpos($plain, get_permalink($page_id)) !== false && strpos($plain, $data['note']) !== false && strpos($plain, 'PRIVATE BILLING ADDRESS') === false, 'Plain email must contain the booking link and note without billing information.');

    $invalid_pdf = false;
    try { Voucher_Pdf::render($product->get_id(), $data); } catch (Throwable $error) { $invalid_pdf = true; }
    $check($invalid_pdf, 'Non-PDF attachments must fail.');
    list($missing_order, $missing_item) = $make_order($product, $data, time() + DAY_IN_SECONDS);
    $missing_order->payment_complete();
    update_post_meta($product->get_id(), '_eva_gift_pdf_id', 0);
    $mails = array();
    $check(is_wp_error($service->send($missing_order->get_id(), $missing_item->get_id(), true)) && 1 === count($mails), 'Missing templates must fail and notify without mailing the recipient.');
    update_post_meta($product->get_id(), '_eva_gift_pdf_id', $attachment);
    foreach ($temporary_files as $file) { $check(! file_exists($file), 'All success/failure attachments must be cleaned up.'); }

    list($late_order, $late_item) = $make_order($product, $data, time() - DAY_IN_SECONDS);
    $late_order->payment_complete();
    $check(as_next_scheduled_action(Gift_Delivery::HOOK, array($late_order->get_id(), $late_item->get_id()), Gift_Delivery::GROUP) <= time(), 'Late payment must queue an immediate delivery.');
    $service->unschedule($late_order->get_id(), $late_item->get_id());
    $check(true === $service->send($late_order->get_id(), $late_item->get_id()), 'Overdue cron delivery must send.');

    list($legacy_order, $legacy_item) = $make_order($product, $data, time(), false);
    $legacy_order->payment_complete();
    $check(! as_has_scheduled_action(Gift_Delivery::HOOK, array($legacy_order->get_id(), $legacy_item->get_id()), Gift_Delivery::GROUP), 'Older gifts must never queue.');

    list($cancel_order, $cancel_item) = $make_order($product, $data, time() + DAY_IN_SECONDS);
    $cancel_order->payment_complete();
    $cancel_order->update_status('cancelled');
    $check('cancelled' === $fresh($cancel_item)->get_meta('_eva_gift_status'), 'Cancellation must cancel unsent gifts.');
    $check(is_wp_error($service->send($cancel_order->get_id(), $cancel_item->get_id(), true)), 'Cancelled gifts cannot be sent manually.');

    list($stale_order, $stale_item) = $make_order($product, $data, time() + DAY_IN_SECONDS);
    $stale_order->payment_complete();
    $stale_item->read_meta_data(true);
    $stale_item->update_meta_data('_eva_gift_status', 'sending');
    $stale_item->update_meta_data('_eva_gift_last_attempt', time() - HOUR_IN_SECONDS);
    $stale_item->save_meta_data();
    $check(is_wp_error($service->send($stale_order->get_id(), $stale_item->get_id(), true)), 'An in-flight claim must block a competing manual send.');
    $service->reconcile();
    $check('review' === $fresh($stale_item)->get_meta('_eva_gift_status'), 'Interrupted sends must require review.');

    $admin = get_users(array('role' => 'administrator', 'number' => 1));
    wp_set_current_user($admin[0]->ID);
    $_GET = array('gift_status' => 'sent');
    ob_start(); $plugin->gift_admin->render_deliveries(); $html = ob_get_clean();
    $check(strpos($html, 'Invii regalo') !== false && strpos($html, 'recipient@example.test') !== false && strpos($html, 'eva_voucher_send') === false, 'Sent history must render without a send button.');
    ob_start(); $plugin->gift_admin->render_settings(); $html = ob_get_clean();
    $check(strpos($html, 'voucher[fields][note][x]') !== false && strpos($html, 'eva_voucher_preview') !== false && strpos($html, 'alerts@example.test') !== false, 'Layout, preview, and notification settings must render.');
    wp_set_current_user(0);
    ob_start(); $plugin->gift_admin->render_deliveries(); $html = ob_get_clean();
    $check('' === $html, 'Unauthorized users must not see gift history.');
    $stop_die = static function () {
        return static function () { throw new RuntimeException('gift-test-access-denied'); };
    };
    add_filter('wp_die_handler', $stop_die, PHP_INT_MAX);
    try {
        foreach (array('preview', 'save_settings', 'send') as $method) {
            $denied = false;
            try { $plugin->gift_admin->$method(); } catch (RuntimeException $error) { $denied = 'gift-test-access-denied' === $error->getMessage(); }
            $check($denied, 'Unauthorized ' . $method . ' must be blocked.');
        }
        wp_set_current_user($admin[0]->ID);
        $_REQUEST = array('_wpnonce' => 'invalid');
        foreach (array('preview', 'save_settings', 'send') as $method) {
            $denied = false;
            ob_start();
            try { $plugin->gift_admin->$method(); } catch (RuntimeException $error) { $denied = 'gift-test-access-denied' === $error->getMessage(); } finally { ob_end_clean(); }
            $check($denied, 'Invalid nonce must block ' . $method . '.');
        }
    } finally {
        remove_filter('wp_die_handler', $stop_die, PHP_INT_MAX);
    }
    WP_CLI::success('Gift validation, PDFs, checkout, scheduling, payment, manual sends, errors, recovery, privacy, and history passed (' . ($mode ?: 'current storage') . ').');
} finally {
    foreach (array_reverse($orders) as $order) { $service->cancel_order_jobs($order->get_id()); $order->delete(true); }
    foreach (array_reverse($posts) as $id) { 'attachment' === get_post_type($id) ? wp_delete_attachment($id, true) : wp_delete_post($id, true); }
    foreach (array(Voucher_Pdf::OPTION => $saved_vouchers, 'woocommerce_eva_course_gift_settings' => $saved_email_settings, 'woocommerce_eva_course_completed_order_settings' => $saved_completed) as $key => $value) {
        false === $value ? delete_option($key) : update_option($key, $value);
    }
    update_option('timezone_string', $saved_timezone);
    $_POST = $saved_post; $_GET = $saved_get; $_REQUEST = $saved_request;
    WC()->cart = $saved_cart; WC()->session = $saved_session;
    wp_set_current_user($saved_user);
    WC_Admin_Meta_Boxes::$meta_box_errors = $saved_meta_box_errors;
    remove_filter('pre_wp_mail', $capture, PHP_INT_MAX);
    remove_filter('pre_option_woocommerce_custom_orders_table_enabled', $storage_filter);
}
