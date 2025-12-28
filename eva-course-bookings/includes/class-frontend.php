<?php

/**
 * Frontend class.
 *
 * Handles all frontend functionality including product page UI.
 *
 * @package Eva_Course_Bookings
 */

namespace Eva_Course_Bookings;

// Prevent direct file access.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Frontend
 */
class Frontend
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

        // Product page.
        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_slot_selection'), 15);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        // Disable add to cart for courses without slot selection.
        add_filter('woocommerce_is_purchasable', array($this, 'check_purchasable'), 10, 2);

        // Replace add to cart button with redirect button on shop page for course products.
        add_filter('woocommerce_loop_add_to_cart_link', array($this, 'replace_shop_add_to_cart_button'), 10, 3);

        // Shortcode for order booking management.
        add_shortcode('eva_course_bookings_manage', array($this, 'render_booking_shortcode'));
    }

    /**
     * Enqueue frontend assets.
     */
    public function enqueue_frontend_assets()
    {
        $is_product_page = is_product();
        $is_booking_page = $this->is_booking_shortcode_page();

        if (! $is_product_page && ! $is_booking_page) {
            return;
        }

        // Enqueue jQuery UI datepicker.
        wp_enqueue_script('jquery-ui-datepicker');

        // Enqueue frontend CSS.
        wp_enqueue_style(
            'eva-course-bookings-frontend',
            EVA_COURSE_BOOKINGS_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            EVA_COURSE_BOOKINGS_VERSION
        );

        // Add custom color CSS.
        $custom_css = $this->generate_custom_color_css();
        wp_add_inline_style('eva-course-bookings-frontend', $custom_css);

        if ($is_product_page) {
            global $post;
            if (! $post || ! Plugin::is_course_enabled($post->ID)) {
                return;
            }

            // Enqueue frontend JS.
            wp_enqueue_script(
                'eva-course-bookings-frontend',
                EVA_COURSE_BOOKINGS_PLUGIN_URL . 'assets/js/frontend.js',
                array('jquery', 'jquery-ui-datepicker'),
                EVA_COURSE_BOOKINGS_VERSION,
                true
            );

            // Get available dates for this product.
            $available_dates = $this->slot_repository->get_available_dates($post->ID);
            $available_dates = $this->filter_dates_by_lead_time($available_dates);

            wp_localize_script(
                'eva-course-bookings-frontend',
                'evaFrontendData',
                array(
                    'ajaxUrl'        => admin_url('admin-ajax.php'),
                    'nonce'          => wp_create_nonce('eva_course_bookings_frontend'),
                    'productId'      => $post->ID,
                    'availableDates' => $available_dates,
                    'leadTimeDays'   => Plugin::get_lead_time_days(),
                    'i18n'           => array(
                        'selectDate'      => 'Seleziona una data',
                        'selectTime'      => 'Seleziona un orario',
                        'noSlots'         => 'Nessun orario disponibile per questa data.',
                        'loading'         => 'Caricamento...',
                        'errorLoading'    => 'Errore nel caricamento degli orari.',
                    ),
                )
            );
        }

        if ($is_booking_page) {
            wp_enqueue_script(
                'eva-course-bookings-booking',
                EVA_COURSE_BOOKINGS_PLUGIN_URL . 'assets/js/booking-manage.js',
                array('jquery', 'jquery-ui-datepicker'),
                EVA_COURSE_BOOKINGS_VERSION,
                true
            );

            wp_localize_script(
                'eva-course-bookings-booking',
                'evaBookingData',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce('eva_course_bookings_frontend'),
                    'leadTimeDays' => Plugin::get_lead_time_days(),
                    'i18n'    => array(
                        'selectDate'   => 'Seleziona una data',
                        'selectTime'   => 'Seleziona un orario',
                        'noSlots'      => 'Nessun orario disponibile per questa data.',
                        'loading'      => 'Caricamento...',
                        'errorLoading' => 'Errore nel caricamento degli orari.',
                    ),
                )
            );
        }
    }

    /**
     * Check if current page contains the booking shortcode.
     *
     * @return bool
     */
    private function is_booking_shortcode_page()
    {
        if (! is_singular()) {
            return false;
        }

        global $post;
        if (! $post) {
            return false;
        }

        return has_shortcode($post->post_content, 'eva_course_bookings_manage');
    }

    /**
     * Filter available dates based on lead time.
     *
     * @param array $dates Dates in Y-m-d format.
     * @return array
     */
    private function filter_dates_by_lead_time(array $dates)
    {
        $min_date = Plugin::get_min_booking_date();
        $filtered = array();

        foreach ($dates as $date) {
            if ($date >= $min_date) {
                $filtered[] = $date;
            }
        }

        return $filtered;
    }

    /**
     * Render slot selection UI on product page.
     */
    public function render_slot_selection()
    {
        global $post;

        if (! Plugin::is_course_enabled($post->ID)) {
            return;
        }

        $available_dates = $this->slot_repository->get_available_dates($post->ID);
        $available_dates = $this->filter_dates_by_lead_time($available_dates);

        if (empty($available_dates)) {
            $this->render_no_slots_notice();
            return;
        }

?>
        <div class="eva-slot-selection" id="eva-slot-selection">
            <h3 class="eva-slot-title">Scegli data e orario</h3>

            <div class="eva-slot-form">
                <!-- Hidden input for slot ID -->
                <input type="hidden" name="eva_slot_id" id="eva-slot-id" value="">
                <input type="hidden" name="eva_slot_start" id="eva-slot-start" value="">
                <input type="hidden" name="eva_slot_end" id="eva-slot-end" value="">
                <input type="hidden" name="eva_skip_slot" id="eva-skip-slot" value="">

                <!-- Skip slot option for gifts -->
                <div class="eva-skip-slot-option">
                    <label class="eva-skip-slot-label">
                        <input type="checkbox" id="eva-skip-slot-checkbox" class="eva-skip-slot-checkbox">
                        <span class="eva-skip-slot-text">Non scegliere la data ora (regalo o prenotazione futura)</span>
                    </label>
                    <p class="eva-skip-slot-description">
                        Seleziona questa opzione se stai acquistando per qualcun altro o se preferisci scegliere la data in seguito.
                        Potrai prenotare la data contattandoci dopo l'acquisto.
                    </p>
                </div>

                <!-- Date picker -->
                <div class="eva-field eva-date-field" id="eva-date-field">
                    <label for="eva-date-picker">Data *</label>
                    <input type="text" id="eva-date-picker" class="eva-datepicker"
                        placeholder="Seleziona una data" readonly>
                </div>

                <!-- Time slots container -->
                <div class="eva-field eva-time-field" id="eva-time-container" style="display: none;">
                    <label>Orario *</label>
                    <div class="eva-time-slots" id="eva-time-slots">
                        <!-- Slots will be loaded via AJAX -->
                    </div>
                </div>

                <!-- Selected slot summary -->
                <div class="eva-selected-summary" id="eva-selected-summary" style="display: none;">
                    <div class="eva-summary-content">
                        <span class="eva-summary-icon">✓</span>
                        <span class="eva-summary-text" id="eva-summary-text"></span>
                    </div>
                </div>

                <!-- Skip slot confirmation -->
                <div class="eva-skip-slot-summary" id="eva-skip-slot-summary" style="display: none;">
                    <div class="eva-summary-content">
                        <span class="eva-summary-icon">🎁</span>
                        <span class="eva-summary-text">Data da definire dopo l'acquisto</span>
                    </div>
                </div>

                <!-- Validation message -->
                <div class="eva-validation-message" id="eva-validation-message" style="display: none;">
                    Seleziona una data e un orario per continuare.
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render shortcode for managing bookings by order ID and email.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_booking_shortcode($atts)
    {
        $atts = shortcode_atts(
            array(
                'title' => 'Gestisci prenotazione',
            ),
            $atts,
            'eva_course_bookings_manage'
        );

        $messages = array();
        $errors = array();
        $order = null;
        $order_id = '';
        $order_email = '';

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['eva_booking_action'])) {
            $action = sanitize_key(wp_unslash($_POST['eva_booking_action']));

            if ('lookup' === $action) {
                if (! isset($_POST['_eva_booking_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_eva_booking_nonce'])), 'eva_course_booking_lookup')) {
                    $errors[] = 'Richiesta non valida. Riprova.';
                } else {
                    $order_id = isset($_POST['eva_order_id']) ? absint($_POST['eva_order_id']) : 0;
                    $order_email = isset($_POST['eva_order_email']) ? sanitize_email(wp_unslash($_POST['eva_order_email'])) : '';
                    $order = $this->get_order_for_booking($order_id, $order_email);

                    if (! $order) {
                        $errors[] = 'Ordine non trovato o email non corrisponde.';
                    }
                }
            }

            if ('assign' === $action) {
                $order_id = isset($_POST['eva_order_id']) ? absint($_POST['eva_order_id']) : 0;
                $order_email = isset($_POST['eva_order_email']) ? sanitize_email(wp_unslash($_POST['eva_order_email'])) : '';
                $item_id = isset($_POST['eva_item_id']) ? absint($_POST['eva_item_id']) : 0;

                $nonce_action = sprintf('eva_course_booking_assign_%d_%d', $order_id, $item_id);
                if (! isset($_POST['_eva_booking_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_eva_booking_nonce'])), $nonce_action)) {
                    $errors[] = 'Richiesta non valida. Riprova.';
                } else {
                    $order = $this->get_order_for_booking($order_id, $order_email);
                    if (! $order) {
                        $errors[] = 'Ordine non trovato o email non corrisponde.';
                    } else {
                        $assign_result = $this->assign_slot_to_order_item($order, $item_id);
                        if (! empty($assign_result['error'])) {
                            $errors[] = $assign_result['error'];
                        } else {
                            $messages[] = 'Prenotazione aggiornata con successo.';
                        }
                    }
                }
            }
        }

        $course_items = $order ? $this->get_course_items_for_order($order) : array();

        ob_start();
?>
        <div class="eva-booking-portal">
            <form class="eva-booking-lookup" method="post">
                <h3 class="eva-slot-title"><?php echo esc_html($atts['title']); ?></h3>
                <div class="eva-slot-form eva-booking-fields">
                    <div class="eva-field">
                        <label for="eva-order-id">Numero ordine *</label>
                        <input type="text" id="eva-order-id" name="eva_order_id" inputmode="numeric" value="<?php echo esc_attr($order_id); ?>" required>
                    </div>
                    <div class="eva-field">
                        <label for="eva-order-email">Email usata per l'ordine *</label>
                        <input type="email" id="eva-order-email" name="eva_order_email" value="<?php echo esc_attr($order_email); ?>" required>
                    </div>
                </div>
                <input type="hidden" name="eva_booking_action" value="lookup">
                <?php wp_nonce_field('eva_course_booking_lookup', '_eva_booking_nonce'); ?>
                <button type="submit" class="button"><?php echo esc_html__('Visualizza prenotazioni', 'eva-course-bookings'); ?></button>
            </form>

            <?php if (! empty($messages)) : ?>
                <div class="eva-selected-summary eva-booking-message">
                    <div class="eva-summary-content">
                        <span class="eva-summary-icon">✓</span>
                        <span class="eva-summary-text"><?php echo esc_html(implode(' ', $messages)); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (! empty($errors)) : ?>
                <div class="eva-validation-message eva-booking-message">
                    <?php echo esc_html(implode(' ', $errors)); ?>
                </div>
            <?php endif; ?>

            <?php if ($order) : ?>
                <div class="eva-booking-order">
                    <h3><?php echo esc_html(sprintf('Ordine #%d', $order->get_id())); ?></h3>
                    <?php if (empty($course_items)) : ?>
                        <p><?php echo esc_html__('Nessun corso presente in questo ordine.', 'eva-course-bookings'); ?></p>
                    <?php else : ?>
                        <?php foreach ($course_items as $item) : ?>
                            <div class="eva-booking-item" data-item-id="<?php echo esc_attr($item['item_id']); ?>">
                                <div class="eva-booking-item-header">
                                    <div>
                                        <h4><?php echo esc_html($item['product_name']); ?></h4>
                                        <div class="eva-booking-qty"><?php echo esc_html(sprintf('Partecipanti: %d', $item['quantity'])); ?></div>
                                    </div>
                                    <span class="eva-booking-status <?php echo esc_attr($item['pending'] ? 'eva-booking-status-pending' : 'eva-booking-status-confirmed'); ?>">
                                        <?php echo esc_html($item['pending'] ? 'Da definire' : 'Confermato'); ?>
                                    </span>
                                </div>

                                <?php if ($item['pending']) : ?>
                                    <?php if (empty($item['available_dates'])) : ?>
                                        <?php $this->render_no_slots_notice(false); ?>
                                    <?php else : ?>
                                        <div class="eva-slot-selection eva-booking-slot-selection"
                                            data-product-id="<?php echo esc_attr($item['product_id']); ?>"
                                            data-available-dates="<?php echo esc_attr(wp_json_encode($item['available_dates'])); ?>">
                                            <h3 class="eva-slot-title">Scegli data e orario</h3>
                                            <form class="eva-booking-item-form" method="post">
                                                <div class="eva-slot-form">
                                                    <input type="hidden" name="eva_booking_action" value="assign">
                                                    <input type="hidden" name="eva_order_id" value="<?php echo esc_attr($order->get_id()); ?>">
                                                    <input type="hidden" name="eva_order_email" value="<?php echo esc_attr($order_email); ?>">
                                                    <input type="hidden" name="eva_item_id" value="<?php echo esc_attr($item['item_id']); ?>">
                                                    <input type="hidden" name="eva_slot_id" class="eva-slot-id" value="">
                                                    <input type="hidden" name="eva_slot_start" class="eva-slot-start" value="">
                                                    <input type="hidden" name="eva_slot_end" class="eva-slot-end" value="">

                                                    <div class="eva-field eva-date-field">
                                                        <label>Data *</label>
                                                        <input type="text" class="eva-datepicker" placeholder="Seleziona una data" readonly>
                                                    </div>

                                                    <div class="eva-field eva-time-field" style="display: none;">
                                                        <label>Orario *</label>
                                                        <div class="eva-time-slots"></div>
                                                    </div>

                                                    <div class="eva-selected-summary" style="display: none;">
                                                        <div class="eva-summary-content">
                                                            <span class="eva-summary-icon">✓</span>
                                                            <span class="eva-summary-text"></span>
                                                        </div>
                                                    </div>

                                                    <div class="eva-validation-message" style="display: none;">
                                                        Seleziona una data e un orario per continuare.
                                                    </div>
                                                </div>
                                                <?php wp_nonce_field(sprintf('eva_course_booking_assign_%d_%d', $order->get_id(), $item['item_id']), '_eva_booking_nonce'); ?>
                                                <button type="submit" class="button eva-booking-submit" disabled>Prenota posto</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <div class="eva-selected-summary eva-booking-summary">
                                        <div class="eva-summary-content">
                                            <span class="eva-summary-icon">✓</span>
                                            <span class="eva-summary-text">
                                                <?php echo esc_html($item['display_date']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
<?php

        return ob_get_clean();
    }

    /**
     * Render notice when no slots are available.
     */
    private function render_no_slots_notice($disable_cart = true)
    {
    ?>
        <div class="eva-no-slots-notice">
            <p>
                <strong>Nessuna data disponibile al momento.</strong>
            </p>
            <p>Contattaci per informazioni sulle prossime date disponibili.</p>
        </div>
        <?php if ($disable_cart) : ?>
            <script>
                (function() {
                    document.addEventListener('DOMContentLoaded', function() {
                        var addToCartBtn = document.querySelector('.single_add_to_cart_button');
                        if (addToCartBtn) {
                            addToCartBtn.classList.add('disabled');
                            addToCartBtn.disabled = true;
                        }
                    });
                })();
            </script>
        <?php endif; ?>
<?php
    }

    /**
     * Validate order access by order ID and email.
     *
     * @param int    $order_id Order ID.
     * @param string $email    Email address.
     * @return \WC_Order|null
     */
    private function get_order_for_booking($order_id, $email)
    {
        if (! $order_id || ! $email) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return null;
        }

        $billing_email = $order->get_billing_email();
        if (! $billing_email) {
            return null;
        }

        if (! hash_equals(strtolower($billing_email), strtolower($email))) {
            return null;
        }

        return $order;
    }

    /**
     * Assign a slot to a pending booking order item.
     *
     * @param \WC_Order $order   Order object.
     * @param int       $item_id Order item ID.
     * @return array Result data with optional error.
     */
    private function assign_slot_to_order_item($order, $item_id)
    {
        $item = $order->get_item($item_id);
        if (! $item) {
            return array('error' => 'Elemento ordine non trovato.');
        }

        $product_id = $item->get_product_id();
        if (! Plugin::is_course_enabled($product_id)) {
            return array('error' => 'Questo prodotto non supporta la prenotazione.');
        }

        $pending_booking = $item->get_meta('_eva_pending_booking');
        $existing_slot_id = $item->get_meta('_eva_slot_id');
        if ('yes' !== $pending_booking || $existing_slot_id) {
            return array('error' => 'Questa prenotazione non può essere modificata.');
        }

        $slot_id = isset($_POST['eva_slot_id']) ? absint($_POST['eva_slot_id']) : 0;
        if (! $slot_id) {
            return array('error' => 'Seleziona uno slot valido.');
        }

        $slot = $this->slot_repository->get_slot($slot_id);
        if (! $slot) {
            return array('error' => 'Slot non trovato.');
        }

        if ((int) $slot['product_id'] !== (int) $product_id) {
            return array('error' => 'Lo slot selezionato non corrisponde al corso.');
        }

        if ('open' !== $slot['status']) {
            return array('error' => 'Lo slot selezionato non disponibile.');
        }

        if (! Plugin::is_slot_datetime_allowed($slot['start_datetime'])) {
            return array('error' => 'La data selezionata non è più disponibile.');
        }

        $quantity = $item->get_meta('_eva_slot_qty');
        if (! $quantity) {
            $quantity = $item->get_quantity();
        }
        $quantity = max(1, (int) $quantity);

        if (! $this->slot_repository->can_reserve_seats((int) $slot_id, (int) $quantity)) {
            return array('error' => 'Posti esauriti per lo slot selezionato.');
        }

        $reserved = $this->slot_repository->reserve_seats((int) $slot_id, (int) $quantity);
        if (! $reserved) {
            return array('error' => 'Impossibile confermare la prenotazione. Riprova.');
        }

        $item->update_meta_data('_eva_slot_id', $slot_id);
        $item->update_meta_data('_eva_slot_start', $slot['start_datetime']);
        $item->update_meta_data('_eva_slot_end', $slot['end_datetime']);
        $item->update_meta_data('_eva_slot_qty', $quantity);
        $item->update_meta_data('_eva_seats_reserved', 'yes');
        $item->delete_meta_data('_eva_pending_booking');
        $item->save_meta_data();

        $this->update_order_slot_info($order);
        $order->add_order_note(sprintf('Slot scelto dal cliente per "%s": %s', $item->get_name(), Plugin::format_datetime_italian($slot['start_datetime'])));

        return array('success' => true);
    }

    /**
     * Build course item data for display.
     *
     * @param \WC_Order $order Order object.
     * @return array
     */
    private function get_course_items_for_order($order)
    {
        $items = array();

        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            if (! Plugin::is_course_enabled($product_id)) {
                continue;
            }

            $slot_id = $item->get_meta('_eva_slot_id');
            $pending_booking = $item->get_meta('_eva_pending_booking');
            $quantity = $item->get_meta('_eva_slot_qty');
            if (! $quantity) {
                $quantity = $item->get_quantity();
            }

            $slot_start = $item->get_meta('_eva_slot_start');
            $slot_end = $item->get_meta('_eva_slot_end');

            if ($slot_id && ! $slot_start) {
                $slot = $this->slot_repository->get_slot((int) $slot_id);
                if ($slot) {
                    $slot_start = $slot['start_datetime'];
                    $slot_end = $slot['end_datetime'];
                }
            }

            $display_date = '';
            if ($slot_start) {
                $display_date = Plugin::format_datetime_italian($slot_start);
                if ($slot_end) {
                    $end_dt = \DateTime::createFromFormat('Y-m-d H:i:s', $slot_end);
                    if ($end_dt) {
                        $display_date .= ' - ' . $end_dt->format('H:i');
                    }
                }
            } else {
                $display_date = 'Da definire (regalo o prenotazione futura)';
            }

            $items[] = array(
                'item_id'         => $item_id,
                'product_id'      => $product_id,
                'product_name'    => $item->get_name(),
                'quantity'        => (int) $quantity,
                'slot_id'         => $slot_id ? (int) $slot_id : 0,
                'pending'         => ('yes' === $pending_booking && ! $slot_id),
                'display_date'    => $display_date,
                'available_dates' => $this->filter_dates_by_lead_time($this->slot_repository->get_available_dates($product_id)),
            );
        }

        return $items;
    }

    /**
     * Update order meta with slot info.
     *
     * @param \WC_Order $order Order object.
     */
    private function update_order_slot_info($order)
    {
        $slot_info = array();

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (! Plugin::is_course_enabled($product_id)) {
                continue;
            }

            $slot_id = $item->get_meta('_eva_slot_id');
            $pending_booking = $item->get_meta('_eva_pending_booking');
            $slot_qty = $item->get_meta('_eva_slot_qty');
            if (! $slot_qty) {
                $slot_qty = $item->get_quantity();
            }

            if ('yes' === $pending_booking && ! $slot_id) {
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

            if (! $slot_id) {
                continue;
            }

            $slot_start = $item->get_meta('_eva_slot_start');
            $slot_end = $item->get_meta('_eva_slot_end');
            if (! $slot_start) {
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

        $order->update_meta_data('_eva_slot_info', $slot_info);
        $order->save();
    }

    /**
     * Check if product is purchasable.
     *
     * @param bool       $purchasable Whether the product is purchasable.
     * @param WC_Product $product     Product object.
     * @return bool
     */
    public function check_purchasable($purchasable, $product)
    {
        if (! $purchasable) {
            return $purchasable;
        }

        // Only check on frontend.
        if (is_admin()) {
            return $purchasable;
        }

        // Check if this is a course product.
        if (! Plugin::is_course_enabled($product->get_id())) {
            return $purchasable;
        }

        // Check if there are available slots.
        $available_dates = $this->slot_repository->get_available_dates($product->get_id());
        $available_dates = $this->filter_dates_by_lead_time($available_dates);

        if (empty($available_dates)) {
            return false;
        }

        return $purchasable;
    }

    /**
     * Generate custom color CSS based on settings.
     *
     * @return string CSS string.
     */
    private function generate_custom_color_css()
    {
        $colors = Admin::get_color_settings();

        $css = "
/* Eva Course Bookings - Custom Colors */
.eva-slot-selection {
    background: linear-gradient(135deg, {$colors['box_background']} 0%, {$colors['box_background_end']} 100%);
    border-color: {$colors['box_border']};
}

.eva-slot-title {
    color: {$colors['title_color']};
    border-bottom-color: {$colors['title_border']};
}

.eva-field label {
    color: {$colors['label_color']};
}

.eva-datepicker {
    border-color: {$colors['input_border']};
}

.eva-datepicker:hover {
    border-color: {$colors['input_border_hover']};
}

.eva-datepicker:focus {
    border-color: {$colors['input_border_hover']};
    box-shadow: 0 0 0 3px {$colors['input_focus_shadow']};
}

.eva-time-slot {
    background: {$colors['slot_background']};
    border-color: {$colors['slot_border']};
}

.eva-time-slot:hover {
    border-color: {$colors['slot_hover_border']};
    box-shadow: 0 2px 8px {$colors['input_focus_shadow']};
}

.eva-time-slot.selected {
    background: {$colors['slot_selected_bg']};
    border-color: {$colors['slot_selected_bg']};
    color: {$colors['slot_selected_text']};
}

.eva-time-slot-time {
    color: {$colors['slot_time_color']};
}

.eva-time-slot.selected .eva-time-slot-time {
    color: {$colors['slot_selected_text']};
}

.eva-selected-summary {
    background: {$colors['summary_background']};
    border-color: {$colors['summary_border']};
}

.eva-summary-icon {
    color: {$colors['summary_icon']};
}

.eva-summary-text {
    color: {$colors['summary_text']};
}

.eva-validation-message {
    background: {$colors['validation_bg']};
    border-color: {$colors['validation_border']};
    color: {$colors['validation_text']};
}

/* Datepicker highlight color */
.ui-datepicker td a.ui-state-highlight {
    background: {$colors['slot_selected_bg']} !important;
    color: {$colors['slot_selected_text']} !important;
}
";

        return $css;
    }

    /**
     * Replace add to cart button with redirect button on shop page for course products.
     *
     * @param string      $link    Add to cart link HTML.
     * @param WC_Product  $product Product object.
     * @param array       $args    Additional arguments.
     * @return string Modified link HTML.
     */
    public function replace_shop_add_to_cart_button($link, $product, $args = array())
    {
        // Only modify on shop/archive pages, not on single product page.
        if (is_product()) {
            return $link;
        }

        // Check if this is a course product.
        if (! Plugin::is_course_enabled($product->get_id())) {
            return $link;
        }

        // Replace with redirect button.
        $product_url = get_permalink($product->get_id());
        $button_text = 'Iscriviti';
        $button_class = isset($args['class']) ? $args['class'] : 'button';

        return sprintf(
            '<a href="%s" class="%s">%s</a>',
            esc_url($product_url),
            esc_attr($button_class),
            esc_html($button_text)
        );
    }
}
