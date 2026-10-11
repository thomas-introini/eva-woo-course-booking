<?php
/**
 * Run with wp eval-file in the local WordPress/WooCommerce environment.
 * Mail is intercepted; temporary fixtures and settings are restored.
 */

use Eva_Course_Bookings\Plugin;
use Eva_Course_Bookings\Slot_Repository;

if (! defined('WP_CLI') || ! WP_CLI || ! class_exists(Plugin::class)) {
    throw new RuntimeException('Run through WP-CLI with WooCommerce and Eva Course Bookings active.');
}
if (! defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

$check = static function ($condition, $message) {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$plugin = Plugin::get_instance();
$repo = $plugin->slot_repository;
$admin = $plugin->admin;
$posts = array();
$orders = array();
$mails = array();
$saved_user = get_current_user_id();
$saved_timezone = get_option('timezone_string');
$saved_get = $_GET;
$saved_cart = WC()->cart;
$saved_session = WC()->session;
$storage_mode = $args[0] ?? '';
$storage_filter = static function () use ($storage_mode) {
    return 'hpos' === $storage_mode ? 'yes' : 'no';
};
if (in_array($storage_mode, array('hpos', 'legacy'), true)) {
    add_filter('pre_option_woocommerce_custom_orders_table_enabled', $storage_filter);
}
$email_key = 'woocommerce_eva_course_reminder_settings';
$saved_email = get_option($email_key);
$capture_mail = static function ($result, $mail) use (&$mails) {
    $mails[] = $mail;
    return true;
};
$stop_ajax = static function () {
    return static function ($message) {
        echo $message;
        throw new RuntimeException('calendar-test-ajax-complete');
    };
};
add_filter('pre_wp_mail', $capture_mail, PHP_INT_MAX, 2);
add_filter('wp_die_ajax_handler', $stop_ajax);

$ajax = static function ($method, $data, $nonce = true) use ($admin) {
    $saved_post = $_POST;
    $saved_request = $_REQUEST;
    $_POST = $data;
    $_POST['nonce'] = $nonce ? wp_create_nonce('eva_admin_nonce') : 'invalid';
    $_REQUEST = $_POST;
    ob_start();
    try {
        $admin->$method();
    } catch (RuntimeException $error) {
        if ('calendar-test-ajax-complete' !== $error->getMessage()) {
            throw $error;
        }
    } finally {
        $output = ob_get_clean();
        $_POST = $saved_post;
        $_REQUEST = $saved_request;
    }
    return json_decode($output, true) ?? $output;
};
$make_product = static function ($name) use (&$posts) {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price('30');
    $product->update_meta_data('_eva_course_enabled', 'yes');
    $product->save();
    $posts[] = $product->get_id();
    return $product;
};
$make_slot = static function ($product, $start, $end = '', $status = 'open') use ($repo, &$posts, $check) {
    $id = $repo->create_slot(array('product_id' => $product->get_id(), 'start_datetime' => $start, 'end_datetime' => $end, 'capacity' => 10, 'status' => $status));
    $check(! is_wp_error($id), 'Test slot must be created.');
    $posts[] = $id;
    return $id;
};
$make_order = static function ($product, $slot_id, $status) use (&$orders) {
    $order = new WC_Order();
    $order->set_billing_first_name('Calendar');
    $order->set_billing_last_name('Test');
    $order->set_billing_email('calendar@example.test');
    $order->save();
    $orders[] = $order;
    $item = new WC_Order_Item_Product();
    $item->set_product($product);
    $item->set_quantity(2);
    $item->add_meta_data('_eva_slot_id', $slot_id);
    $item->add_meta_data('_eva_slot_qty', 2);
    $item->add_meta_data('_eva_seats_reserved', 'released');
    $order->add_item($item);
    $order->set_status($status);
    $order->save();
    return $order;
};

try {
    $administrators = get_users(array('role' => 'administrator', 'number' => 1));
    $check(! empty($administrators), 'An administrator is required.');
    wp_set_current_user($administrators[0]->ID);
    update_option('timezone_string', 'Pacific/Kiritimati');
    $course = $make_product('Calendar & Course');
    $other = $make_product('Other calendar course');
    $first = $make_slot($course, '2048-02-01 00:00:00', '2048-02-01 01:00:00');
    for ($i = 0; $i < 54; $i++) {
        $make_slot($course, sprintf('2048-02-15 %02d:%02d:00', 9 + intdiv($i, 60), $i % 60));
    }
    $last = $make_slot($course, '2048-02-29 23:59:00', '', 'closed');
    $outside = $make_slot($course, '2048-03-01 00:00:00');
    $other_slot = $make_slot($other, '2048-02-05 10:00:00');
    $response = $ajax('ajax_get_calendar_slots', array('month' => '2048-02', 'product_id' => $course->get_id()));
    $html = $response['data']['html'];
    $check($response['success'] && substr_count($html, 'data-slot-id=') === 56, 'All month slots must load beyond the default 50-slot limit.');
    $check(str_contains($html, 'data-slot-id="' . $first . '"') && str_contains($html, 'data-slot-id="' . $last . '"'), 'Leap-month boundaries must remain in the site timezone.');
    $check(! str_contains($html, 'data-slot-id="' . $outside . '"') && ! str_contains($html, 'data-slot-id="' . $other_slot . '"'), 'Month and course filters must exclude other slots.');
    $check(str_contains($html, 'Calendar &amp; Course') && str_contains($html, 'datetime="2048-02-29"'), 'Calendar must escape titles and include leap day.');
    $check(29 === substr_count($html, 'class="eva-calendar-create"') && str_contains($html, 'data-create-date="2048-02-29"') && ! str_contains($html, 'data-create-date="2048-03-01"'), 'Every future day in the month must offer a keyboard-accessible create action.');
    $past_calendar = $ajax('ajax_get_calendar_slots', array('month' => '2000-01'));
    $check(! str_contains($past_calendar['data']['html'], 'data-create-date='), 'Past days must not offer slot creation.');
    $closed = $ajax('ajax_get_calendar_slots', array('month' => '2048-02', 'product_id' => $course->get_id(), 'status' => 'closed'));
    $check(substr_count($closed['data']['html'], 'data-slot-id=') === 1, 'Status filter must work.');
    $paged = $repo->get_all_slots(array('product_id' => $course->get_id(), 'per_page' => 20));
    $check(count($paged['slots']) === 20 && $paged['total_pages'] === 3, 'Existing table pagination must remain unchanged.');
    $create = array('product_id' => $course->get_id(), 'date' => '16/02/2048', 'time' => '14:30', 'end_time' => '16:00', 'capacity' => '8');
    $created = $ajax('ajax_create_slot', $create);
    $check($created['success'], 'Calendar creation must reuse the product editor AJAX endpoint.');
    $created_id = $created['data']['slot']['id'];
    $posts[] = $created_id;
    $created_slot = $repo->get_slot($created_id);
    $check('2048-02-16 14:30:00' === $created_slot['start_datetime'] && '2048-02-16 16:00:00' === $created_slot['end_datetime'] && 8 === $created_slot['capacity'] && 0 === $created_slot['booked'] && 'open' === $created_slot['status'], 'A new slot must retain local dates, times, capacity, and open status.');
    $check(str_contains($created['data']['html'], 'data-slot-id="' . $created_id . '"') && str_contains($ajax('ajax_get_calendar_slots', array('month' => '2048-02', 'product_id' => $course->get_id()))['data']['html'], 'data-slot-id="' . $created_id . '"'), 'Created slots must work in the product table and refreshed calendar.');
    $without_end = $create;
    unset($without_end['end_time']);
    $created = $ajax('ajax_create_slot', $without_end);
    $check($created['success'], 'End time must remain optional for existing callers.');
    $posts[] = $created['data']['slot']['id'];
    $check('' === $created['data']['slot']['end_datetime'], 'Omitting end time must save an empty end time.');
    $before_invalid_create = $repo->get_all_slots(array('product_id' => $course->get_id(), 'per_page' => -1))['total'];
    foreach (array(array('date' => '31/02/2048'), array('date' => '01/01/2000'), array('date' => array()), array('time' => '24:00'), array('time' => array()), array('end_time' => '14:30'), array('end_time' => '13:00'), array('end_time' => '25:00'), array('end_time' => array()), array('capacity' => '0'), array('capacity' => '-2'), array('capacity' => '1.5'), array('capacity' => array()), array('product_id' => $first), array('product_id' => array())) as $invalid) {
        $check(false === $ajax('ajax_create_slot', array_merge($create, $invalid))['success'], 'Invalid creation requests must fail before saving a slot.');
    }
    update_post_meta($other->get_id(), '_eva_course_enabled', 'no');
    $check(false === $ajax('ajax_create_slot', array_merge($create, array('product_id' => $other->get_id())))['success'], 'Creation must require an enabled course.');
    update_post_meta($other->get_id(), '_eva_course_enabled', 'yes');
    update_option('timezone_string', 'Europe/Rome');
    $dst_day = (new DateTimeImmutable('last sunday of March 2048', wp_timezone()))->format('d/m/Y');
    $check(false === $ajax('ajax_create_slot', array_merge($create, array('date' => $dst_day, 'time' => '02:30', 'end_time' => '04:00')))['success'], 'A nonexistent local start time during daylight saving must be rejected.');
    $check(false === $ajax('ajax_create_slot', array_merge($create, array('date' => $dst_day, 'time' => '01:00', 'end_time' => '02:30')))['success'], 'A nonexistent local end time during daylight saving must be rejected.');
    update_option('timezone_string', 'Pacific/Kiritimati');
    $check($before_invalid_create === $repo->get_all_slots(array('product_id' => $course->get_id(), 'per_page' => -1))['total'], 'Rejected creation requests must not leave partial slots.');
    foreach (array(array('month' => '2048-13'), array('month' => array()), array('status' => array()), array('product_id' => '-2')) as $invalid) {
        $check(false === $ajax('ajax_get_calendar_slots', $invalid)['success'], 'Malformed calendar filters must be rejected.');
    }
    $check(-1 === $ajax('ajax_get_calendar_slots', array(), false), 'Invalid nonce must be rejected.');
    $check(-1 === $ajax('ajax_create_slot', $create, false), 'Slot creation must require a valid nonce.');
    wp_set_current_user(0);
    $check(false === $ajax('ajax_get_calendar_slots', array())['success'], 'Unauthorized calendar access must be rejected.');
    $check(false === $ajax('ajax_get_slot_details', array('slot_id' => $first))['success'], 'Unauthorized customer-detail access must be rejected.');
    $check(false === $ajax('ajax_create_slot', $create)['success'], 'Slot creation must require admin capability.');
    wp_set_current_user($administrators[0]->ID);
    $check(false === $ajax('ajax_get_slot_details', array('slot_id' => $course->get_id()))['success'], 'Non-slot IDs must be rejected.');
    $check(false === $ajax('ajax_get_slot_details', array('slot_id' => array()))['success'], 'Malformed slot IDs must be rejected.');

    $snapshot = $repo->get_slot($first);
    foreach (array(array('start_datetime' => '2048-02-30 10:00:00', 'capacity' => 20), array('end_datetime' => '2048-01-31 10:00:00'), array('capacity' => 0), array('capacity' => -2), array('capacity' => '1.5'), array('status' => 'invalid')) as $invalid) {
        $check(is_wp_error($repo->update_slot($first, $invalid)), 'Invalid edits must fail.');
        $check($repo->get_slot($first) === $snapshot, 'Rejected edits must not partially change slot metadata.');
    }
    $update = array('slot_id' => $first, 'date' => '01/02/2048', 'time' => '00:00', 'end_time' => '', 'capacity' => '12');
    $check($ajax('ajax_update_slot', $update)['success'], 'Valid AJAX edits must succeed.');
    $check('' === $repo->get_slot($first)['end_datetime'] && 12 === $repo->get_slot($first)['capacity'], 'End time must be clearable and capacity updated.');
    $check($ajax('ajax_toggle_slot_status', array('slot_id' => $first, 'current_status' => 'closed'))['data']['new_status'] === 'closed', 'Toggle must use saved status rather than stale client data.');
    $cancelled = $make_order($course, $first, 'cancelled');
    $refunded = $make_order($course, $first, 'refunded');
    $check($repo->has_bookings($first), 'Released historical bookings must lock dates.');
    $snapshot = $repo->get_slot($first);
    $check(is_wp_error($repo->update_slot($first, array('start_datetime' => '2048-02-02 10:00:00', 'capacity' => 30))), 'Booked date changes must fail.');
    $check($repo->get_slot($first) === $snapshot, 'Booked date rejection must not alter capacity.');
    $check(true === $repo->update_slot($first, array('capacity' => 15)), 'Capacity edits on historical booked slots must remain available.');
    $check($repo->reserve_seats($first, 3), 'Reserve test seats.');
    $check(is_wp_error($repo->update_slot($first, array('capacity' => 2))), 'Capacity must not fall below reserved seats.');
    $details = $ajax('ajax_get_slot_details', array('slot_id' => $first));
    $html = $details['data']['html'];
    $check($details['success'] && str_contains($html, 'calendar@example.test') && str_contains($html, 'status-cancelled') && str_contains($html, 'status-refunded'), 'Modal must include full booking history and customer details.');
    $check(str_contains($html, 'Totale partecipanti (storico)') && 3 === preg_match_all('/id="eva-edit-slot-(?:date|time|end-time)"[^>]*disabled=/', $html), 'Historical totals and locked dates must be clear.');
    $check(str_contains($html, 'Scarica CSV') && str_contains($html, 'eva-send-reminder-all'), 'Existing booking actions must appear in the modal.');
    $check(! str_contains($html, 'Torna alla lista'), 'Modal content must omit page navigation.');
    $check(! str_contains($ajax('ajax_get_calendar_slots', array('month' => '2048-02'))['data']['html'], 'calendar@example.test'), 'Month responses must not contain customer details.');

    $past = $make_slot($course, '2000-01-01 10:00:00');
    $check(is_wp_error($repo->update_slot($past, array('status' => 'closed'))), 'Past slots must reject edits on the server.');
    $past_details = $ajax('ajax_get_slot_details', array('slot_id' => $past));
    $check(! str_contains($past_details['data']['html'], 'eva-save-slot-btn'), 'Past-slot modal must omit editing controls.');
    $_GET = array();
    ob_start();
    $admin->render_main_page();
    $main = ob_get_clean();
    $check(str_contains($main, 'id="eva-calendar-content"') && str_contains($main, current_time('Y-m')), 'Calendar must be the default tab in the site timezone.');
    $check(str_contains($main, 'id="eva-slot-create-modal"') && str_contains($main, 'id="eva-create-slot-form"') && str_contains($main, 'data-create-date="' . current_time('Y-m-d') . '"') && str_contains($main, 'min="' . current_time('Y-m-d') . '"'), 'The calendar must render its create form and allow today in the site timezone.');
    $_GET = array('tab' => 'prenotazioni', 'view' => 'slot', 'slot_id' => $first);
    ob_start();
    $admin->render_main_page();
    $legacy = ob_get_clean();
    $check(str_contains($legacy, 'Torna alla lista') && str_contains($legacy, esc_url($cancelled->get_edit_order_url())), 'Existing booking page and HPOS/legacy order links must still work.');

    WC()->session = new WC_Session_Handler();
    WC()->cart = new WC_Cart();
    WC()->cart->get_cart();
    WC()->cart->cart_contents = array('calendar-test' => array('product_id' => $course->get_id(), 'data' => $course, 'quantity' => 2, 'eva_slot_id' => $outside));
    wc_clear_notices();
    $plugin->woo_integration->validate_checkout();
    $check(0 === wc_notice_count('error'), 'Classic checkout must accept an available slot.');
    $plugin->woo_integration->validate_block_checkout(null, null);
    $check($ajax('ajax_toggle_slot_status', array('slot_id' => $outside))['success'], 'Admin must be able to close the test slot.');
    $plugin->woo_integration->validate_checkout();
    $check(wc_notice_count('error') > 0, 'Classic checkout must reject an admin-closed slot.');
    $block_rejected = false;
    try {
        $plugin->woo_integration->validate_block_checkout(null, null);
    } catch (Exception $error) {
        $block_rejected = true;
    }
    $check($block_rejected, 'Block checkout must reject an admin-closed slot.');
    $check(true === $repo->update_slot($outside, array('status' => 'open', 'capacity' => 1)), 'Reopen the slot with reduced capacity.');
    wc_clear_notices();
    $plugin->woo_integration->validate_checkout();
    $check(wc_notice_count('error') > 0, 'Classic checkout must honor edited capacity.');
    $check(! $repo->reserve_seats($outside, 2), 'Edited capacity must still prevent overselling.');

    update_option($email_key, array('enabled' => 'yes'));
    $reminder = WC()->mailer()->get_emails()['WC_Email_Course_Reminder'];
    $reminder->init_settings();
    $reminder->enabled = 'yes';
    $mails = array();
    $reminders = $ajax('ajax_send_slot_reminder_all', array('slot_id' => $first));
    $check($reminders['success'] && count($mails) === 2, 'Bulk reminders must retain existing historical recipient behavior with mail intercepted.');

    $source = $make_slot($course, '2049-01-10 10:00:00', '2049-01-10 12:00:00');
    $destination = $make_slot($course, '2049-01-11 11:00:00', '2049-01-11 13:00:00');
    $too_small = $make_slot($course, '2049-01-12 10:00:00');
    $check(true === $repo->update_slot($too_small, array('capacity' => 1)), 'Prepare an insufficient-capacity destination.');
    $active = $make_order($course, $source, 'processing');
    $item = current($active->get_items());
    $item->update_meta_data('_eva_slot_qty', 3);
    $item->update_meta_data('_eva_seats_reserved', 'yes');
    $item->update_meta_data('_eva_slot_start', '2049-01-10 10:00:00');
    $item->update_meta_data('_eva_slot_end', '2049-01-10 12:00:00');
    $item->save();
    $second = new WC_Order_Item_Product();
    $second->set_product($course);
    $second->set_quantity(2);
    foreach (array('_eva_slot_id' => $source, '_eva_slot_qty' => 2, '_eva_seats_reserved' => 'yes', '_eva_slot_start' => '2049-01-10 10:00:00', '_eva_slot_end' => '2049-01-10 12:00:00') as $key => $value) {
        $second->add_meta_data($key, $value);
    }
    $active->add_item($second);
    $active->save();
    $check($repo->reserve_seats($source, 5), 'Prepare the active group reservations.');
    $move = array('order_id' => $active->get_id(), 'item_id' => $item->get_id(), 'old_slot_id' => $source, 'new_slot_id' => $destination);
    $movable = $ajax('ajax_get_slot_details', array('slot_id' => $source));
    $check(2 === substr_count($movable['data']['html'], 'class="button button-small eva-move-reservation"'), 'Each active order item must have its own move action.');
    $check(! str_contains($details['data']['html'], 'class="button button-small eva-move-reservation"'), 'Historical released reservations must not offer move actions.');
    $options = $ajax('ajax_get_product_slots', array('product_id' => $course->get_id()));
    $option_ids = array_column($options['data']['slots'], 'id');
    $check(in_array($destination, $option_ids, true) && ! in_array($past, $option_ids, true) && ! in_array($last, $option_ids, true) && ! in_array($other_slot, $option_ids, true), 'Destination options must be future open slots of the same course.');
    $check(false === $ajax('ajax_get_product_slots', array('product_id' => array()))['success'], 'Malformed destination lookup must be rejected.');
    foreach (array(array('new_slot_id' => $source), array('new_slot_id' => $other_slot), array('new_slot_id' => $past), array('new_slot_id' => $last), array('new_slot_id' => $too_small), array('old_slot_id' => $outside), array('order_id' => $cancelled->get_id()), array('item_id' => array())) as $invalid) {
        $check(false === $ajax('ajax_change_order_item_slot', array_merge($move, $invalid))['success'], 'Invalid reservation moves must be rejected.');
        $check(5 === $repo->get_slot($source)['booked'] && 0 === $repo->get_slot($destination)['booked'], 'Rejected moves must preserve both seat counts.');
    }
    $check(-1 === $ajax('ajax_change_order_item_slot', $move, false), 'Moving requires a valid nonce.');
    wp_set_current_user(0);
    $check(false === $ajax('ajax_change_order_item_slot', $move)['success'], 'Moving requires admin capability.');
    wp_set_current_user($administrators[0]->ID);
    $mails = array();
    $moved = $ajax('ajax_change_order_item_slot', array_merge($move, array('slot_start' => '2000-01-01 00:00:00', 'slot_end' => 'tampered')));
    $check($moved['success'] && $moved['data']['destination_slot_id'] === $destination, 'A valid group reservation must move successfully.');
    $check(2 === $repo->get_slot($source)['booked'] && 3 === $repo->get_slot($destination)['booked'], 'Moving must use the reserved quantity and preserve the other group’s seats.');
    $fresh = wc_get_order($active->get_id());
    $moved_item = $fresh->get_item($item->get_id());
    $check((int) $moved_item->get_meta('_eva_slot_id') === $destination && '2049-01-11 11:00:00' === $moved_item->get_meta('_eva_slot_start') && '2049-01-11 13:00:00' === $moved_item->get_meta('_eva_slot_end'), 'Order dates must come from the destination slot, ignoring client dates.');
    $summary = $fresh->get_meta('_eva_slot_info');
    $check(count($summary) === 2 && in_array($source, array_map('intval', array_column($summary, 'slot_id')), true) && in_array($destination, array_map('intval', array_column($summary, 'slot_id')), true), 'Rebuilding the summary must retain other items booked for the same course and slot.');
    update_post_meta($course->get_id(), '_eva_course_enabled', 'no');
    $check(2 === count($plugin->woo_integration->get_slot_info_for_order($fresh)), 'Existing assigned bookings must survive products being disabled later.');
    update_post_meta($course->get_id(), '_eva_course_enabled', 'yes');
    $check(count($mails) === 0, 'Moving must not send automatic customer mail.');
    $check(false === $ajax('ajax_change_order_item_slot', $move)['success'], 'Repeated or stale move requests must not reserve seats again.');
    $check(2 === $repo->get_slot($source)['booked'] && 3 === $repo->get_slot($destination)['booked'], 'Repeated moves must preserve seat counts.');

    $block_meta = static function ($result, $meta_id, $value, $key) {
        return '_eva_slot_start' === $key ? false : $result;
    };
    add_filter('update_order_item_metadata_by_mid', $block_meta, 10, 4);
    try {
        $failed = $ajax('ajax_change_order_item_slot', array_merge($move, array('old_slot_id' => $destination, 'new_slot_id' => $source)));
        $check(false === $failed['success'], 'A failed order-item write must abort the move.');
    } finally {
        remove_filter('update_order_item_metadata_by_mid', $block_meta);
    }
    $fresh = wc_get_order($active->get_id());
    $fresh_item = $fresh->get_item($item->get_id());
    $fresh_item->read_meta_data(true);
    $check(2 === $repo->get_slot($source)['booked'] && 3 === $repo->get_slot($destination)['booked'] && (int) $fresh_item->get_meta('_eva_slot_id') === $destination, 'Rollback must restore counters and order-item metadata after a partial write.');
    $fail_after_save = static function ($order) use ($active) {
        if ($order->get_id() === $active->get_id()) {
            throw new Error('Simulated failure after order save.');
        }
    };
    add_action('woocommerce_after_order_object_save', $fail_after_save);
    try {
        $failed = $ajax('ajax_change_order_item_slot', array_merge($move, array('old_slot_id' => $destination, 'new_slot_id' => $source)));
        $check(false === $failed['success'], 'Failure after saving the order must abort the transfer.');
    } finally {
        remove_action('woocommerce_after_order_object_save', $fail_after_save);
    }
    $restored = wc_get_order($active->get_id());
    $check($restored->get_meta('_eva_slot_info') === $summary && (int) $restored->get_item($item->get_id())->get_meta('_eva_slot_id') === $destination && 2 === $repo->get_slot($source)['booked'] && 3 === $repo->get_slot($destination)['booked'], 'Late rollback must restore the summary, metadata caches, and both counters.');
    $restored->update_status('cancelled');
    $check(0 === $repo->get_slot($source)['booked'] && 0 === $repo->get_slot($destination)['booked'], 'Cancelling after a move must release each group from its current slot.');
    WP_CLI::success('Calendar, creation, access, edits, history, checkout, reminders, reservation moves, and rollback checks passed (' . ($storage_mode ?: 'current storage') . ').');
} finally {
    foreach (array_reverse($orders) as $order) {
        $order->delete(true);
    }
    foreach (array_reverse($posts) as $post_id) {
        wp_delete_post($post_id, true);
    }
    update_option('timezone_string', $saved_timezone);
    if (false === $saved_email) {
        delete_option($email_key);
    } else {
        update_option($email_key, $saved_email);
    }
    $_GET = $saved_get;
    WC()->cart = $saved_cart;
    WC()->session = $saved_session;
    wp_set_current_user($saved_user);
    remove_filter('pre_wp_mail', $capture_mail, PHP_INT_MAX);
    remove_filter('wp_die_ajax_handler', $stop_ajax);
    remove_filter('pre_option_woocommerce_custom_orders_table_enabled', $storage_filter);
}
