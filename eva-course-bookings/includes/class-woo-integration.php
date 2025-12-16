<?php

/**
 * WooCommerce Integration class.
 *
 * Handles all WooCommerce integration including cart, checkout, and orders.
 *
 * @package Eva_Course_Bookings
 */

namespace Eva_Course_Bookings;

// Prevent direct file access.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Woo_Integration
 */
class Woo_Integration
{

    /**
     * Slot repository instance.
     *
     * @var Slot_Repository
     */
    private $slot_repository;

    /**
     * Constructor.
     *
     * @param Slot_Repository $slot_repository Slot repository instance.
     */
    public function __construct(Slot_Repository $slot_repository)
    {
        $this->slot_repository = $slot_repository;

        // Add to cart validation.
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_add_to_cart'), 10, 5);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);

        // Cart display.
        add_filter('woocommerce_get_item_data', array($this, 'display_cart_item_data'), 10, 2);
        add_filter('woocommerce_cart_item_quantity', array($this, 'cart_item_quantity_limits'), 10, 3);

        // Cart validation before checkout.
        add_action('woocommerce_check_cart_items', array($this, 'validate_cart_items'));

        // Checkout validation (classic).
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout'));

        // Block checkout validation.
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'validate_block_checkout'), 10, 2);

        // Order processing.
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_order_item_meta'), 10, 4);

        // Reserve seats - use multiple hooks to ensure it works with both Classic and Block Checkout.
        add_action('woocommerce_checkout_order_processed', array($this, 'reserve_seats_on_order_by_id'), 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'reserve_seats_on_order'), 10, 1);

        // Order status changes.
        add_action('woocommerce_order_status_cancelled', array($this, 'release_seats_on_cancel'));
        add_action('woocommerce_order_status_refunded', array($this, 'release_seats_on_cancel'));
        add_action('woocommerce_order_status_failed', array($this, 'release_seats_on_cancel'));

        // Order display.
        add_filter('woocommerce_order_item_display_meta_key', array($this, 'format_order_item_meta_key'), 10, 3);
        add_filter('woocommerce_order_item_display_meta_value', array($this, 'format_order_item_meta_value'), 10, 3);

        // Thank you page.
        add_action('woocommerce_thankyou', array($this, 'display_thankyou_slot_info'), 5);

        // View order page (My Account).
        add_action('woocommerce_view_order', array($this, 'display_thankyou_slot_info'), 5);

        // Emails.
        add_action('woocommerce_email_order_details', array($this, 'add_email_slot_info'), 5, 4);

        // Store API cart item schema for Block Checkout.
        add_filter('woocommerce_store_api_cart_item_schema', array($this, 'extend_cart_item_schema'));
    }

    /**
     * Validate add to cart.
     *
     * @param bool $passed     Validation passed.
     * @param int  $product_id Product ID.
     * @param int  $quantity   Quantity.
     * @param int  $variation_id Variation ID.
     * @param array $variations Variations.
     * @return bool
     */
    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = array())
    {
        if (! $passed) {
            return $passed;
        }

        // Check if this is a course product.
        if (! Plugin::is_course_enabled($product_id)) {
            return $passed;
        }

        // Check if skip slot is enabled (gift purchase / booking later).
        $skip_slot = isset($_POST['eva_skip_slot']) && '1' === $_POST['eva_skip_slot'];

        if ($skip_slot) {
            // Allow purchase without slot - will be assigned later by admin.
            return $passed;
        }

        // Get slot ID from POST.
        $slot_id = isset($_POST['eva_slot_id']) ? absint($_POST['eva_slot_id']) : 0;

        if (! $slot_id) {
            wc_add_notice('Seleziona una data e un orario per procedere.', 'error');
            return false;
        }

        // Validate slot.
        $slot = $this->slot_repository->get_slot($slot_id);

        if (! $slot) {
            wc_add_notice('Lo slot selezionato non è valido.', 'error');
            return false;
        }

        // Check slot belongs to product.
        if ((int) $slot['product_id'] !== (int) $product_id) {
            wc_add_notice('Lo slot selezionato non è valido per questo prodotto.', 'error');
            return false;
        }

        // Check slot status.
        if ('open' !== $slot['status']) {
            wc_add_notice('Lo slot selezionato non è più disponibile.', 'error');
            return false;
        }

        // Check capacity.
        if ($slot['remaining'] < $quantity) {
            if ($slot['remaining'] <= 0) {
                wc_add_notice('Posti esauriti per l\'orario selezionato. Scegli un altro slot.', 'error');
            } else {
                wc_add_notice(
                    sprintf(
                        'Sono disponibili solo %d posti per l\'orario selezionato.',
                        $slot['remaining']
                    ),
                    'error'
                );
            }
            return false;
        }

        // Check if same slot is already in cart.
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['eva_slot_id']) && (int) $cart_item['eva_slot_id'] === $slot_id) {
                // Check total quantity.
                $total_qty = $cart_item['quantity'] + $quantity;
                if ($total_qty > $slot['remaining']) {
                    wc_add_notice(
                        sprintf(
                            'Sono disponibili solo %d posti per l\'orario selezionato. Ne hai già %d nel carrello.',
                            $slot['remaining'],
                            $cart_item['quantity']
                        ),
                        'error'
                    );
                    return false;
                }
            }
        }

        return $passed;
    }

    /**
     * Add cart item data.
     *
     * @param array $cart_item_data Cart item data.
     * @param int   $product_id     Product ID.
     * @param int   $variation_id   Variation ID.
     * @return array
     */
    public function add_cart_item_data($cart_item_data, $product_id, $variation_id)
    {
        if (! Plugin::is_course_enabled($product_id)) {
            return $cart_item_data;
        }

        // Check if skip slot is enabled (gift purchase / booking later).
        $skip_slot = isset($_POST['eva_skip_slot']) && '1' === $_POST['eva_skip_slot'];

        if ($skip_slot) {
            $cart_item_data['eva_pending_booking'] = true;
            $cart_item_data['unique_key'] = md5($product_id . '_pending_' . time() . '_' . wp_rand());
            return $cart_item_data;
        }

        $slot_id    = isset($_POST['eva_slot_id']) ? absint($_POST['eva_slot_id']) : 0;
        $slot_start = isset($_POST['eva_slot_start']) ? sanitize_text_field(wp_unslash($_POST['eva_slot_start'])) : '';
        $slot_end   = isset($_POST['eva_slot_end']) ? sanitize_text_field(wp_unslash($_POST['eva_slot_end'])) : '';

        if ($slot_id) {
            $cart_item_data['eva_slot_id']    = $slot_id;
            $cart_item_data['eva_slot_start'] = $slot_start;
            $cart_item_data['eva_slot_end']   = $slot_end;

            // Make cart item unique per slot.
            $cart_item_data['unique_key'] = md5($product_id . '_' . $slot_id);
        }

        return $cart_item_data;
    }

    /**
     * Display cart item data.
     *
     * @param array $item_data Cart item display data.
     * @param array $cart_item Cart item.
     * @return array
     */
    public function display_cart_item_data($item_data, $cart_item)
    {
        // Check if this is a pending booking (gift / booking later).
        if (isset($cart_item['eva_pending_booking']) && $cart_item['eva_pending_booking']) {
            $item_data[] = array(
                'key'   => 'Data corso',
                'value' => 'Da definire (regalo o prenotazione futura)',
            );
            return $item_data;
        }

        if (isset($cart_item['eva_slot_start']) && $cart_item['eva_slot_start']) {
            $formatted_date = Plugin::format_datetime_italian($cart_item['eva_slot_start']);

            $display_value = $formatted_date;
            if (! empty($cart_item['eva_slot_end'])) {
                $end_time = \DateTime::createFromFormat('Y-m-d H:i:s', $cart_item['eva_slot_end']);
                if ($end_time) {
                    $display_value .= ' - ' . $end_time->format('H:i');
                }
            }

            $item_data[] = array(
                'key'   => 'Data corso',
                'value' => $display_value,
            );
        }

        return $item_data;
    }

    /**
     * Limit cart item quantity based on slot capacity.
     *
     * @param string $product_quantity Quantity HTML.
     * @param string $cart_item_key    Cart item key.
     * @param array  $cart_item        Cart item.
     * @return string
     */
    public function cart_item_quantity_limits($product_quantity, $cart_item_key, $cart_item)
    {
        // Skip for pending bookings - no quantity limit.
        if (isset($cart_item['eva_pending_booking']) && $cart_item['eva_pending_booking']) {
            return $product_quantity;
        }

        if (! isset($cart_item['eva_slot_id'])) {
            return $product_quantity;
        }

        $slot = $this->slot_repository->get_slot($cart_item['eva_slot_id']);
        if (! $slot) {
            return $product_quantity;
        }

        // Calculate max quantity (remaining + current in cart).
        $max_qty = $slot['remaining'] + $cart_item['quantity'];

        // Modify the quantity input.
        $product_quantity = woocommerce_quantity_input(
            array(
                'input_name'   => "cart[{$cart_item_key}][qty]",
                'input_value'  => $cart_item['quantity'],
                'max_value'    => $max_qty,
                'min_value'    => 1,
                'product_name' => $cart_item['data']->get_name(),
            ),
            $cart_item['data'],
            false
        );

        return $product_quantity;
    }

    /**
     * Validate cart items.
     */
    public function validate_cart_items()
    {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            // Skip validation for pending bookings.
            if (isset($cart_item['eva_pending_booking']) && $cart_item['eva_pending_booking']) {
                continue;
            }

            if (! isset($cart_item['eva_slot_id'])) {
                continue;
            }

            $slot = $this->slot_repository->get_slot($cart_item['eva_slot_id']);

            if (! $slot) {
                wc_add_notice(
                    sprintf(
                        'Lo slot per "%s" non è più disponibile. Rimuovi il prodotto dal carrello.',
                        $cart_item['data']->get_name()
                    ),
                    'error'
                );
                continue;
            }

            if ('open' !== $slot['status']) {
                wc_add_notice(
                    sprintf(
                        'Lo slot per "%s" è stato chiuso. Scegli un altro orario.',
                        $cart_item['data']->get_name()
                    ),
                    'error'
                );
                continue;
            }

            if ($slot['remaining'] < $cart_item['quantity']) {
                wc_add_notice(
                    sprintf(
                        'Non ci sono abbastanza posti per "%s". Posti disponibili: %d.',
                        $cart_item['data']->get_name(),
                        $slot['remaining']
                    ),
                    'error'
                );
            }
        }
    }

    /**
     * Validate checkout (classic).
     */
    public function validate_checkout()
    {
        $this->validate_cart_items();
    }

    /**
     * Validate block checkout.
     *
     * @param WC_Order        $order   Order object.
     * @param WP_REST_Request $request REST request.
     */
    public function validate_block_checkout($order, $request)
    {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            // Skip validation for pending bookings.
            if (isset($cart_item['eva_pending_booking']) && $cart_item['eva_pending_booking']) {
                continue;
            }

            if (! isset($cart_item['eva_slot_id'])) {
                continue;
            }

            $slot = $this->slot_repository->get_slot($cart_item['eva_slot_id']);

            if (! $slot || 'open' !== $slot['status'] || $slot['remaining'] < $cart_item['quantity']) {
                throw new \Exception('Posti esauriti per l\'orario selezionato. Scegli un altro slot.');
            }
        }
    }

    /**
     * Add order item meta.
     *
     * @param WC_Order_Item_Product $item          Order item.
     * @param string                $cart_item_key Cart item key.
     * @param array                 $values        Cart item values.
     * @param WC_Order              $order         Order.
     */
    public function add_order_item_meta($item, $cart_item_key, $values, $order)
    {
        // Check if this is a pending booking (gift / booking later).
        if (isset($values['eva_pending_booking']) && $values['eva_pending_booking']) {
            $item->add_meta_data('_eva_pending_booking', 'yes', true);
            $item->add_meta_data('_eva_slot_qty', $values['quantity'], true);
            return;
        }

        if (isset($values['eva_slot_id'])) {
            $item->add_meta_data('_eva_slot_id', $values['eva_slot_id'], true);
            $item->add_meta_data('_eva_slot_start', $values['eva_slot_start'], true);
            $item->add_meta_data('_eva_slot_end', $values['eva_slot_end'] ?? '', true);
            $item->add_meta_data('_eva_slot_qty', $values['quantity'], true);
            $item->add_meta_data('_eva_seats_reserved', 'no', true);
        }
    }

    /**
     * Reserve seats when order is created (by order ID).
     * Wrapper for Classic Checkout which passes order ID.
     *
     * @param int $order_id Order ID.
     */
    public function reserve_seats_on_order_by_id($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order) {
            $this->reserve_seats_on_order($order);
        }
    }

    /**
     * Reserve seats when order is created.
     *
     * @param WC_Order $order Order object.
     */
    public function reserve_seats_on_order($order)
    {
        $all_reserved = true;
        $has_items_to_reserve = false;

        foreach ($order->get_items() as $item_id => $item) {
            $slot_id = $item->get_meta('_eva_slot_id');
            if (! $slot_id) {
                continue;
            }

            // Skip if already reserved (idempotency).
            $already_reserved = $item->get_meta('_eva_seats_reserved');
            if ('yes' === $already_reserved) {
                Slot_Repository::log(sprintf('Seats already reserved for order %d, item %d, skipping', $order->get_id(), $item_id));
                continue;
            }

            $has_items_to_reserve = true;

            $quantity = $item->get_meta('_eva_slot_qty');
            if (! $quantity) {
                $quantity = $item->get_quantity();
            }

            // Try atomic reservation.
            $reserved = $this->slot_repository->reserve_seats((int) $slot_id, (int) $quantity);

            if ($reserved) {
                // Update item meta directly on the item object and save.
                $item->update_meta_data('_eva_seats_reserved', 'yes');
                $item->save_meta_data();
                Slot_Repository::log(sprintf('Seats reserved for order %d, item %d, slot %d, qty %d', $order->get_id(), $item_id, $slot_id, $quantity));
            } else {
                $all_reserved = false;
                Slot_Repository::log(sprintf('Failed to reserve seats for order %d, item %d, slot %d', $order->get_id(), $item_id, $slot_id), 'error');
            }
        }

        // If no items needed reservation, exit early.
        if (! $has_items_to_reserve) {
            return;
        }

        if (! $all_reserved) {
            // Add order note.
            $order->add_order_note('Attenzione: alcune prenotazioni non sono state confermate per mancanza di posti.');

            // We could cancel the order here, but it's better to let the admin handle it.
            throw new \Exception('Posti esauriti per l\'orario selezionato. Scegli un altro slot.');
        }

        // Add order meta for aggregate slot info.
        $slot_info = array();
        foreach ($order->get_items() as $item) {
            $slot_id = $item->get_meta('_eva_slot_id');
            if ($slot_id) {
                $slot_info[] = array(
                    'product'  => $item->get_name(),
                    'slot_id'  => $slot_id,
                    'start'    => $item->get_meta('_eva_slot_start'),
                    'end'      => $item->get_meta('_eva_slot_end'),
                    'quantity' => $item->get_meta('_eva_slot_qty'),
                );
            }
        }

        if (! empty($slot_info)) {
            $order->update_meta_data('_eva_slot_info', $slot_info);
            $order->save();
        }
    }

    /**
     * Release seats when order is cancelled/refunded/failed.
     *
     * @param int $order_id Order ID.
     */
    public function release_seats_on_cancel($order_id)
    {
        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        foreach ($order->get_items() as $item_id => $item) {
            $slot_id  = $item->get_meta('_eva_slot_id');
            $reserved = $item->get_meta('_eva_seats_reserved');

            if (! $slot_id || 'yes' !== $reserved) {
                continue;
            }

            $quantity = $item->get_meta('_eva_slot_qty');
            if (! $quantity) {
                $quantity = $item->get_quantity();
            }

            // Release seats.
            $this->slot_repository->release_seats($slot_id, $quantity);

            // Mark as released (idempotency).
            wc_update_order_item_meta($item_id, '_eva_seats_reserved', 'released');

            Slot_Repository::log(sprintf('Seats released for order %d, item %d, slot %d, qty %d', $order_id, $item_id, $slot_id, $quantity));
        }
    }

    /**
     * Format order item meta key for display.
     *
     * @param string        $display_key Display key.
     * @param WC_Meta_Data  $meta        Meta object.
     * @param WC_Order_Item $item        Order item.
     * @return string
     */
    public function format_order_item_meta_key($display_key, $meta, $item)
    {
        switch ($meta->key) {
            case '_eva_slot_start':
                return 'Data corso';
            case '_eva_slot_end':
                return 'Ora fine';
            case '_eva_slot_qty':
                return 'Partecipanti';
            case '_eva_pending_booking':
                return 'Stato prenotazione';
        }

        return $display_key;
    }

    /**
     * Format order item meta value for display.
     *
     * @param string        $display_value Display value.
     * @param WC_Meta_Data  $meta          Meta object.
     * @param WC_Order_Item $item          Order item.
     * @return string
     */
    public function format_order_item_meta_value($display_value, $meta, $item)
    {
        if ('_eva_slot_start' === $meta->key) {
            return Plugin::format_datetime_italian($meta->value);
        }

        if ('_eva_slot_end' === $meta->key && $meta->value) {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $meta->value);
            return $dt ? $dt->format('H:i') : $meta->value;
        }

        if ('_eva_pending_booking' === $meta->key) {
            return 'Da definire (regalo o prenotazione futura)';
        }

        return $display_value;
    }

    /**
     * Display slot info on thank you page.
     *
     * @param int $order_id Order ID.
     */
    public function display_thankyou_slot_info($order_id)
    {
        $order     = wc_get_order($order_id);
        $slot_info = $this->get_slot_info_for_order($order);

        if (empty($slot_info)) {
            return;
        }

?>
        <section class="eva-thankyou-slot-info">
            <h2>Informazioni corso</h2>
            <table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
                <thead>
                    <tr>
                        <th>Corso</th>
                        <th>Data</th>
                        <th>Partecipanti</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slot_info as $info) : ?>
                        <tr>
                            <td><?php echo esc_html($info['product']); ?></td>
                            <td>
                                <?php if (! empty($info['pending_booking'])) : ?>
                                    <em>Da definire (regalo o prenotazione futura)</em>
                                    <br><small>Ti contatteremo per definire la data del corso.</small>
                                <?php elseif (! empty($info['start'])) : ?>
                                    <?php
                                    echo esc_html(Plugin::format_datetime_italian($info['start']));
                                    if (! empty($info['end'])) {
                                        $end_dt = \DateTime::createFromFormat('Y-m-d H:i:s', $info['end']);
                                        if ($end_dt) {
                                            echo ' - ' . esc_html($end_dt->format('H:i'));
                                        }
                                    }
                                    ?>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($info['quantity']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    }

    /**
     * Add slot info to emails.
     *
     * @param WC_Order $order         Order object.
     * @param bool     $sent_to_admin Whether email is sent to admin.
     * @param bool     $plain_text    Whether email is plain text.
     * @param WC_Email $email         Email object.
     */
    public function add_email_slot_info($order, $sent_to_admin, $plain_text, $email)
    {
        $slot_info = $this->get_slot_info_for_order($order);

        if (empty($slot_info)) {
            return;
        }

        if ($plain_text) {
            echo "\n\n";
            echo "INFORMAZIONI CORSO\n";
            echo "==================\n\n";

            foreach ($slot_info as $info) {
                echo esc_html($info['product']) . "\n";
                if (! empty($info['pending_booking'])) {
                    echo "Data corso: Da definire (regalo o prenotazione futura)\n";
                    echo "Ti contatteremo per definire la data del corso.\n";
                } elseif (! empty($info['start'])) {
                    echo 'Data corso: ' . esc_html(Plugin::format_datetime_italian($info['start']));
                    if (! empty($info['end'])) {
                        $end_dt = \DateTime::createFromFormat('Y-m-d H:i:s', $info['end']);
                        if ($end_dt) {
                            echo ' - ' . esc_html($end_dt->format('H:i'));
                        }
                    }
                    echo "\n";
                }
                echo 'Partecipanti: ' . esc_html($info['quantity']) . "\n\n";
            }
        } else {
        ?>
            <h2 style="margin-top: 30px;">Informazioni corso</h2>
            <table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr style="background-color: #f8f8f8;">
                        <th style="text-align: left; padding: 10px;">Corso</th>
                        <th style="text-align: left; padding: 10px;">Data</th>
                        <th style="text-align: left; padding: 10px;">Partecipanti</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slot_info as $info) : ?>
                        <tr>
                            <td style="padding: 10px;"><?php echo esc_html($info['product']); ?></td>
                            <td style="padding: 10px;">
                                <?php if (! empty($info['pending_booking'])) : ?>
                                    <em>Da definire (regalo o prenotazione futura)</em>
                                    <br><small>Ti contatteremo per definire la data del corso.</small>
                                <?php elseif (! empty($info['start'])) : ?>
                                    <?php
                                    echo esc_html(Plugin::format_datetime_italian($info['start']));
                                    if (! empty($info['end'])) {
                                        $end_dt = \DateTime::createFromFormat('Y-m-d H:i:s', $info['end']);
                                        if ($end_dt) {
                                            echo ' - ' . esc_html($end_dt->format('H:i'));
                                        }
                                    }
                                    ?>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px;"><?php echo esc_html($info['quantity']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
<?php
        }
    }

    /**
     * Extend cart item schema for Store API.
     *
     * @param array $schema Schema.
     * @return array
     */
    public function extend_cart_item_schema($schema)
    {
        $schema['eva_slot_info'] = array(
            'description' => 'Course booking slot info',
            'type'        => 'object',
            'context'     => array('view', 'edit'),
            'readonly'    => true,
            'properties'  => array(
                'slot_id'    => array('type' => 'integer'),
                'slot_start' => array('type' => 'string'),
                'slot_end'   => array('type' => 'string'),
            ),
        );

        return $schema;
    }

    /**
     * Get slot info for an order.
     * First tries to get from order meta, then builds from order item meta.
     *
     * @param WC_Order $order Order object.
     * @return array Array of slot info.
     */
    private function get_slot_info_for_order($order)
    {
        // Try to get cached slot info from order meta.
        $slot_info = $order->get_meta('_eva_slot_info');

        if (!empty($slot_info) && is_array($slot_info)) {
            return $slot_info;
        }

        // Build from order item meta (fallback for retroactive assignments).
        $slot_info = array();

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();

            // Check if this is a course product.
            if (! Plugin::is_course_enabled($product_id)) {
                continue;
            }

            $slot_id = $item->get_meta('_eva_slot_id');
            $pending_booking = $item->get_meta('_eva_pending_booking');
            $slot_qty = $item->get_meta('_eva_slot_qty');

            if (!$slot_qty) {
                $slot_qty = $item->get_quantity();
            }

            // Check if this is a pending booking.
            if ('yes' === $pending_booking && !$slot_id) {
                $slot_info[] = array(
                    'product'         => $item->get_name(),
                    'slot_id'         => null,
                    'start'           => null,
                    'end'             => null,
                    'quantity'        => $slot_qty,
                    'pending_booking' => true,
                );
                continue;
            }

            if (!$slot_id) {
                continue;
            }

            $slot_start = $item->get_meta('_eva_slot_start');
            $slot_end = $item->get_meta('_eva_slot_end');

            // If slot_start is not in item meta, try to get from slot repository.
            if (!$slot_start) {
                $slot = $this->slot_repository->get_slot((int) $slot_id);
                if ($slot) {
                    $slot_start = $slot['start_datetime'];
                    $slot_end = $slot['end_datetime'];
                }
            }

            $slot_info[] = array(
                'product'  => $item->get_name(),
                'slot_id'  => $slot_id,
                'start'    => $slot_start,
                'end'      => $slot_end,
                'quantity' => $slot_qty,
            );
        }

        return $slot_info;
    }
}
