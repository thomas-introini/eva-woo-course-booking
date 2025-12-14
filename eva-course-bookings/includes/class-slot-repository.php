<?php

/**
 * Slot Repository class.
 *
 * Handles all slot CRUD operations and the custom post type.
 *
 * @package Eva_Course_Bookings
 */

namespace Eva_Course_Bookings;

// Prevent direct file access.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Slot_Repository
 */
class Slot_Repository
{

    /**
     * Custom post type name.
     *
     * @var string
     */
    const POST_TYPE = 'eva_course_slot';

    /**
     * Meta keys.
     */
    const META_PRODUCT_ID     = '_eva_product_id';
    const META_START_DATETIME = '_eva_start_datetime';
    const META_END_DATETIME   = '_eva_end_datetime';
    const META_CAPACITY       = '_eva_capacity';
    const META_BOOKED         = '_eva_booked';
    const META_STATUS         = '_eva_status';

    /**
     * Singleton instance.
     *
     * @var Slot_Repository|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return Slot_Repository
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        add_action('init', array(__CLASS__, 'register_post_type'));
    }

    /**
     * Register the custom post type.
     */
    public static function register_post_type()
    {
        $args = array(
            'labels'              => array(
                'name'          => __('Slot Corso', 'eva-course-bookings'),
                'singular_name' => __('Slot Corso', 'eva-course-bookings'),
            ),
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'query_var'           => false,
            'rewrite'             => false,
            'capability_type'     => 'post',
            'has_archive'         => false,
            'hierarchical'        => false,
            'supports'            => array('title'),
            'show_in_rest'        => false,
        );

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Create a new slot.
     *
     * @param array $data Slot data.
     * @return int|WP_Error Slot ID or error.
     */
    public function create_slot(array $data)
    {
        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
        $start      = isset($data['start_datetime']) ? sanitize_text_field($data['start_datetime']) : '';
        $end        = isset($data['end_datetime']) ? sanitize_text_field($data['end_datetime']) : '';
        $capacity   = isset($data['capacity']) ? absint($data['capacity']) : 0;
        $status     = isset($data['status']) ? sanitize_text_field($data['status']) : 'open';

        if (! $product_id || ! $start || ! $capacity) {
            return new \WP_Error('invalid_data', 'Dati slot non validi.');
        }

        // Validate datetime format.
        $start_time = \DateTime::createFromFormat('Y-m-d H:i:s', $start);
        if (! $start_time) {
            return new \WP_Error('invalid_datetime', 'Formato data/ora non valido.');
        }

        // Create the slot post.
        $post_title = sprintf(
            'Slot %s - %s',
            get_the_title($product_id),
            $start
        );

        $slot_id = wp_insert_post(
            array(
                'post_type'   => self::POST_TYPE,
                'post_title'  => $post_title,
                'post_status' => 'publish',
            ),
            true
        );

        if (is_wp_error($slot_id)) {
            return $slot_id;
        }

        // Save meta data.
        update_post_meta($slot_id, self::META_PRODUCT_ID, $product_id);
        update_post_meta($slot_id, self::META_START_DATETIME, $start);
        update_post_meta($slot_id, self::META_END_DATETIME, $end);
        update_post_meta($slot_id, self::META_CAPACITY, $capacity);
        update_post_meta($slot_id, self::META_BOOKED, 0);
        update_post_meta($slot_id, self::META_STATUS, $status);

        self::log(sprintf('Slot created: ID %d for product %d', $slot_id, $product_id));

        return $slot_id;
    }

    /**
     * Update a slot.
     *
     * @param int   $slot_id Slot ID.
     * @param array $data    Slot data to update.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function update_slot(int $slot_id, array $data)
    {
        $slot = get_post($slot_id);
        if (! $slot || self::POST_TYPE !== $slot->post_type) {
            return new \WP_Error('invalid_slot', 'Slot non trovato.');
        }

        if (isset($data['start_datetime'])) {
            $start = sanitize_text_field($data['start_datetime']);
            $start_time = \DateTime::createFromFormat('Y-m-d H:i:s', $start);
            if ($start_time) {
                update_post_meta($slot_id, self::META_START_DATETIME, $start);
            }
        }

        if (isset($data['end_datetime'])) {
            update_post_meta($slot_id, self::META_END_DATETIME, sanitize_text_field($data['end_datetime']));
        }

        if (isset($data['capacity'])) {
            update_post_meta($slot_id, self::META_CAPACITY, absint($data['capacity']));
        }

        if (isset($data['status']) && in_array($data['status'], array('open', 'closed'), true)) {
            update_post_meta($slot_id, self::META_STATUS, sanitize_text_field($data['status']));
        }

        self::log(sprintf('Slot updated: ID %d', $slot_id));

        return true;
    }

    /**
     * Delete a slot.
     *
     * @param int $slot_id Slot ID.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function delete_slot(int $slot_id)
    {
        $slot = get_post($slot_id);
        if (! $slot || self::POST_TYPE !== $slot->post_type) {
            return new \WP_Error('invalid_slot', 'Slot non trovato.');
        }

        $booked = absint(get_post_meta($slot_id, self::META_BOOKED, true));
        if ($booked > 0) {
            return new \WP_Error('has_bookings', 'Impossibile eliminare: ci sono prenotazioni attive per questo slot.');
        }

        wp_delete_post($slot_id, true);

        self::log(sprintf('Slot deleted: ID %d', $slot_id));

        return true;
    }

    /**
     * Get a single slot.
     *
     * @param int $slot_id Slot ID.
     * @return array|null Slot data or null if not found.
     */
    public function get_slot(int $slot_id)
    {
        $slot = get_post($slot_id);
        if (! $slot || self::POST_TYPE !== $slot->post_type) {
            return null;
        }

        return $this->format_slot($slot);
    }

    /**
     * Get slots for a product.
     *
     * @param int    $product_id Product ID.
     * @param string $status     Optional. Filter by status.
     * @param bool   $only_available Optional. Only return slots with remaining capacity.
     * @return array Array of slot data.
     */
    public function get_slots_for_product(int $product_id, string $status = '', bool $only_available = false)
    {
        $meta_query = array(
            array(
                'key'     => self::META_PRODUCT_ID,
                'value'   => $product_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ),
        );

        if ($status) {
            $meta_query[] = array(
                'key'     => self::META_STATUS,
                'value'   => $status,
                'compare' => '=',
            );
        }

        // Only future slots.
        $meta_query[] = array(
            'key'     => self::META_START_DATETIME,
            'value'   => current_time('mysql'),
            'compare' => '>=',
            'type'    => 'DATETIME',
        );

        $args = array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => $meta_query,
            'meta_key'       => self::META_START_DATETIME,
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        );

        $posts = get_posts($args);
        $slots = array();

        foreach ($posts as $post) {
            $slot = $this->format_slot($post);

            if ($only_available && $slot['remaining'] <= 0) {
                continue;
            }

            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * Get all slots with filters.
     *
     * @param array $filters Filter options.
     * @return array Array of slot data.
     */
    public function get_all_slots(array $filters = array())
    {
        $meta_query = array();

        if (! empty($filters['product_id'])) {
            $meta_query[] = array(
                'key'     => self::META_PRODUCT_ID,
                'value'   => absint($filters['product_id']),
                'compare' => '=',
                'type'    => 'NUMERIC',
            );
        }

        if (! empty($filters['status'])) {
            $meta_query[] = array(
                'key'     => self::META_STATUS,
                'value'   => sanitize_text_field($filters['status']),
                'compare' => '=',
            );
        }

        if (! empty($filters['date_from'])) {
            $meta_query[] = array(
                'key'     => self::META_START_DATETIME,
                'value'   => sanitize_text_field($filters['date_from']) . ' 00:00:00',
                'compare' => '>=',
                'type'    => 'DATETIME',
            );
        }

        if (! empty($filters['date_to'])) {
            $meta_query[] = array(
                'key'     => self::META_START_DATETIME,
                'value'   => sanitize_text_field($filters['date_to']) . ' 23:59:59',
                'compare' => '<=',
                'type'    => 'DATETIME',
            );
        }

        $args = array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => isset($filters['per_page']) ? absint($filters['per_page']) : 50,
            'paged'          => isset($filters['page']) ? absint($filters['page']) : 1,
            'post_status'    => 'publish',
            'meta_key'       => self::META_START_DATETIME,
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        );

        if (! empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        $query = new \WP_Query($args);
        $slots = array();

        foreach ($query->posts as $post) {
            $slots[] = $this->format_slot($post);
        }

        return array(
            'slots'       => $slots,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        );
    }

    /**
     * Get available dates for a product.
     *
     * @param int $product_id Product ID.
     * @return array Array of dates with available slots.
     */
    public function get_available_dates(int $product_id)
    {
        $slots = $this->get_slots_for_product($product_id, 'open', true);
        $dates = array();

        foreach ($slots as $slot) {
            $date = substr($slot['start_datetime'], 0, 10);
            if (! in_array($date, $dates, true)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * Get available slots for a specific date.
     *
     * @param int    $product_id Product ID.
     * @param string $date       Date in Y-m-d format.
     * @return array Array of available slots for that date.
     */
    public function get_slots_for_date(int $product_id, string $date)
    {
        $slots = $this->get_slots_for_product($product_id, 'open', true);
        $result = array();

        foreach ($slots as $slot) {
            if (substr($slot['start_datetime'], 0, 10) === $date) {
                $result[] = $slot;
            }
        }

        return $result;
    }

    /**
     * Reserve seats atomically.
     *
     * Uses a single atomic SQL UPDATE to prevent overselling.
     *
     * @param int $slot_id  Slot ID.
     * @param int $quantity Number of seats to reserve.
     * @return bool True on success, false on failure.
     */
    public function reserve_seats(int $slot_id, int $quantity)
    {
        global $wpdb;

        if ($quantity <= 0) {
            self::log(sprintf('Reserve seats failed: Invalid quantity %d for slot %d', $quantity, $slot_id), 'error');
            return false;
        }

        $slot = $this->get_slot($slot_id);
        if (! $slot) {
            self::log(sprintf('Reserve seats failed: Slot %d not found', $slot_id), 'error');
            return false;
        }

        self::log(sprintf('Attempting to reserve %d seats for slot %d (current booked: %d, capacity: %d)', $quantity, $slot_id, $slot['booked'], $slot['capacity']));

        // Atomic update: only increment if capacity allows.
        // Use CAST to ensure proper integer arithmetic.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} pm1
                 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
                 SET pm1.meta_value = CAST(pm1.meta_value AS SIGNED) + %d
                 WHERE pm1.post_id = %d
                 AND pm1.meta_key = %s
                 AND pm2.meta_key = %s
                 AND (CAST(pm1.meta_value AS SIGNED) + %d) <= CAST(pm2.meta_value AS SIGNED)",
                $quantity,
                $slot_id,
                self::META_BOOKED,
                self::META_CAPACITY,
                $quantity
            )
        );

        self::log(sprintf('Reserve seats SQL result: %s (rows affected)', var_export($result, true)));

        if ($result > 0) {
            // Clear meta cache.
            wp_cache_delete($slot_id, 'post_meta');
            self::log(sprintf('Seats reserved successfully: %d seats for slot %d', $quantity, $slot_id));
            return true;
        }

        // Log SQL error if any.
        if ($wpdb->last_error) {
            self::log(sprintf('Reserve seats SQL error: %s', $wpdb->last_error), 'error');
        }

        self::log(sprintf('Reserve seats failed: Not enough capacity for slot %d (qty: %d, remaining: %d)', $slot_id, $quantity, $slot['remaining']), 'error');
        return false;
    }

    /**
     * Release seats.
     *
     * @param int $slot_id  Slot ID.
     * @param int $quantity Number of seats to release.
     * @return bool True on success, false on failure.
     */
    public function release_seats(int $slot_id, int $quantity)
    {
        global $wpdb;

        if ($quantity <= 0) {
            return false;
        }

        $slot = $this->get_slot($slot_id);
        if (! $slot) {
            self::log(sprintf('Release seats failed: Slot %d not found', $slot_id), 'error');
            return false;
        }

        // Atomic update: decrement but never go below 0.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta}
                 SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) - %d)
                 WHERE post_id = %d
                 AND meta_key = %s",
                $quantity,
                $slot_id,
                self::META_BOOKED
            )
        );

        if (false !== $result) {
            // Clear meta cache.
            wp_cache_delete($slot_id, 'post_meta');
            self::log(sprintf('Seats released: %d seats for slot %d', $quantity, $slot_id));
            return true;
        }

        self::log(sprintf('Release seats failed for slot %d', $slot_id), 'error');
        return false;
    }

    /**
     * Check if seats can be reserved (dry run).
     *
     * @param int $slot_id  Slot ID.
     * @param int $quantity Number of seats.
     * @return bool True if reservation would succeed.
     */
    public function can_reserve_seats(int $slot_id, int $quantity)
    {
        $slot = $this->get_slot($slot_id);
        if (! $slot) {
            return false;
        }

        if ('open' !== $slot['status']) {
            return false;
        }

        return $slot['remaining'] >= $quantity;
    }

    /**
     * Format a slot post into an array.
     *
     * @param WP_Post $post Slot post.
     * @return array Formatted slot data.
     */
    private function format_slot($post)
    {
        $capacity = absint(get_post_meta($post->ID, self::META_CAPACITY, true));
        $booked   = absint(get_post_meta($post->ID, self::META_BOOKED, true));

        return array(
            'id'             => $post->ID,
            'product_id'     => absint(get_post_meta($post->ID, self::META_PRODUCT_ID, true)),
            'start_datetime' => get_post_meta($post->ID, self::META_START_DATETIME, true),
            'end_datetime'   => get_post_meta($post->ID, self::META_END_DATETIME, true),
            'capacity'       => $capacity,
            'booked'         => $booked,
            'remaining'      => max(0, $capacity - $booked),
            'status'         => get_post_meta($post->ID, self::META_STATUS, true) ?: 'open',
        );
    }

    /**
     * Log a message.
     *
     * @param string $message Log message.
     * @param string $level   Log level.
     */
    public static function log(string $message, string $level = 'info')
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $message, array('source' => 'eva-course-bookings'));
        }
    }

    /**
     * Check if CPT is registered (for self-test).
     *
     * @return bool
     */
    public static function is_cpt_registered()
    {
        return post_type_exists(self::POST_TYPE);
    }

    /**
     * Test atomic reservation query (dry run).
     *
     * @return bool
     */
    public static function test_atomic_query()
    {
        global $wpdb;

        // Just validate the query syntax by explaining it.
        $query = $wpdb->prepare(
            "EXPLAIN SELECT pm1.meta_value FROM {$wpdb->postmeta} pm1
             INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
             WHERE pm1.post_id = %d
             AND pm1.meta_key = %s
             AND pm2.meta_key = %s",
            1,
            self::META_BOOKED,
            self::META_CAPACITY
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->get_results($query);

        return ! empty($result);
    }
}
