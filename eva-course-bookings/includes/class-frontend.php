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
    }

    /**
     * Enqueue frontend assets.
     */
    public function enqueue_frontend_assets()
    {
        if (! is_product()) {
            return;
        }

        global $post;
        if (! Plugin::is_course_enabled($post->ID)) {
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

        wp_localize_script(
            'eva-course-bookings-frontend',
            'evaFrontendData',
            array(
                'ajaxUrl'        => admin_url('admin-ajax.php'),
                'nonce'          => wp_create_nonce('eva_course_bookings_frontend'),
                'productId'      => $post->ID,
                'availableDates' => $available_dates,
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

                <!-- Date picker -->
                <div class="eva-field eva-date-field">
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

                <!-- Validation message -->
                <div class="eva-validation-message" id="eva-validation-message" style="display: none;">
                    Seleziona una data e un orario per continuare.
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Render notice when no slots are available.
     */
    private function render_no_slots_notice()
    {
    ?>
        <div class="eva-no-slots-notice">
            <p>
                <strong>Nessuna data disponibile al momento.</strong>
            </p>
            <p>Contattaci per informazioni sulle prossime date disponibili.</p>
        </div>
        <?php

        // Also add a script to disable add to cart.
        ?>
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
<?php
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

        if (empty($available_dates)) {
            return false;
        }

        return $purchasable;
    }
}
