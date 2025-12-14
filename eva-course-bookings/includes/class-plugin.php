<?php
/**
 * Main Plugin class.
 *
 * @package Eva_Course_Bookings
 */

namespace Eva_Course_Bookings;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Plugin
 */
class Plugin {

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Admin handler.
     *
     * @var Admin
     */
    public $admin;

    /**
     * Frontend handler.
     *
     * @var Frontend
     */
    public $frontend;

    /**
     * WooCommerce integration.
     *
     * @var Woo_Integration
     */
    public $woo_integration;

    /**
     * Slot repository.
     *
     * @var Slot_Repository
     */
    public $slot_repository;

    /**
     * Get singleton instance.
     *
     * @return Plugin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init();
    }

    /**
     * Initialize the plugin.
     */
    private function init() {
        // Initialize components.
        $this->slot_repository = Slot_Repository::get_instance();
        $this->admin           = new Admin( $this->slot_repository );
        $this->frontend        = new Frontend( $this->slot_repository );
        $this->woo_integration = new Woo_Integration( $this->slot_repository );

        // Load textdomain.
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Register AJAX handlers.
        add_action( 'wp_ajax_eva_get_available_dates', array( $this, 'ajax_get_available_dates' ) );
        add_action( 'wp_ajax_nopriv_eva_get_available_dates', array( $this, 'ajax_get_available_dates' ) );
        add_action( 'wp_ajax_eva_get_slots_for_date', array( $this, 'ajax_get_slots_for_date' ) );
        add_action( 'wp_ajax_nopriv_eva_get_slots_for_date', array( $this, 'ajax_get_slots_for_date' ) );
    }

    /**
     * Load plugin textdomain.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'eva-course-bookings',
            false,
            dirname( EVA_COURSE_BOOKINGS_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * AJAX handler: Get available dates for a product.
     */
    public function ajax_get_available_dates() {
        // Verify nonce.
        check_ajax_referer( 'eva_course_bookings_frontend', 'nonce' );

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => 'ID prodotto non valido.' ) );
        }

        $dates = $this->slot_repository->get_available_dates( $product_id );

        wp_send_json_success( array( 'dates' => $dates ) );
    }

    /**
     * AJAX handler: Get slots for a specific date.
     */
    public function ajax_get_slots_for_date() {
        // Verify nonce.
        check_ajax_referer( 'eva_course_bookings_frontend', 'nonce' );

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $date       = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';

        if ( ! $product_id || ! $date ) {
            wp_send_json_error( array( 'message' => 'Dati non validi.' ) );
        }

        // Validate date format.
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            wp_send_json_error( array( 'message' => 'Formato data non valido.' ) );
        }

        $slots = $this->slot_repository->get_slots_for_date( $product_id, $date );

        // Format slots for frontend.
        $formatted_slots = array();
        foreach ( $slots as $slot ) {
            $start_time = \DateTime::createFromFormat( 'Y-m-d H:i:s', $slot['start_datetime'] );
            $end_time   = $slot['end_datetime'] ? \DateTime::createFromFormat( 'Y-m-d H:i:s', $slot['end_datetime'] ) : null;

            $formatted_slots[] = array(
                'id'         => $slot['id'],
                'time'       => $start_time ? $start_time->format( 'H:i' ) : '',
                'end_time'   => $end_time ? $end_time->format( 'H:i' ) : '',
                'remaining'  => $slot['remaining'],
                'start_full' => $slot['start_datetime'],
                'end_full'   => $slot['end_datetime'],
            );
        }

        wp_send_json_success( array( 'slots' => $formatted_slots ) );
    }

    /**
     * Check if a product is a bookable course.
     *
     * @param int $product_id Product ID.
     * @return bool
     */
    public static function is_bookable_course( int $product_id ) {
        $enabled = get_post_meta( $product_id, '_eva_course_enabled', true );
        if ( 'yes' !== $enabled ) {
            return false;
        }

        // Check if there are any active slots.
        $slot_repo = Slot_Repository::get_instance();
        $slots     = $slot_repo->get_slots_for_product( $product_id, 'open', true );

        return ! empty( $slots );
    }

    /**
     * Check if a product has course booking enabled (regardless of slots).
     *
     * @param int $product_id Product ID.
     * @return bool
     */
    public static function is_course_enabled( int $product_id ) {
        return 'yes' === get_post_meta( $product_id, '_eva_course_enabled', true );
    }

    /**
     * Format a datetime for display.
     *
     * @param string $datetime MySQL datetime string.
     * @param string $format   Optional. PHP date format.
     * @return string Formatted date.
     */
    public static function format_datetime( string $datetime, string $format = '' ) {
        if ( empty( $datetime ) ) {
            return '';
        }

        if ( empty( $format ) ) {
            $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        }

        $dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $datetime, wp_timezone() );
        if ( ! $dt ) {
            return $datetime;
        }

        return $dt->format( $format );
    }

    /**
     * Format a date for display (Italian style).
     *
     * @param string $datetime MySQL datetime string.
     * @return string Formatted date in Italian style (dd/mm/yyyy HH:MM).
     */
    public static function format_datetime_italian( string $datetime ) {
        if ( empty( $datetime ) ) {
            return '';
        }

        $dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $datetime, wp_timezone() );
        if ( ! $dt ) {
            return $datetime;
        }

        return $dt->format( 'd/m/Y H:i' );
    }
}

