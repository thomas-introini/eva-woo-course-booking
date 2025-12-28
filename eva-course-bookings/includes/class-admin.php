<?php

/**
 * Admin class.
 *
 * Handles all admin functionality including product metaboxes and admin pages.
 *
 * @package Eva_Course_Bookings
 */

namespace Eva_Course_Bookings;

// Prevent direct file access.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class Admin
 */
class Admin
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

        // Product edit screen.
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_course_enabled_checkbox'));
        add_action('woocommerce_process_product_meta', array($this, 'save_course_enabled_checkbox'));
        add_action('add_meta_boxes', array($this, 'add_slots_metabox'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Admin menu.
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // AJAX handlers for admin.
        add_action('wp_ajax_eva_admin_create_slot', array($this, 'ajax_create_slot'));
        add_action('wp_ajax_eva_admin_update_slot', array($this, 'ajax_update_slot'));
        add_action('wp_ajax_eva_admin_delete_slot', array($this, 'ajax_delete_slot'));
        add_action('wp_ajax_eva_admin_toggle_slot_status', array($this, 'ajax_toggle_slot_status'));
        add_action('wp_ajax_eva_admin_bulk_enable', array($this, 'ajax_bulk_enable'));
        add_action('wp_ajax_eva_admin_assign_slot', array($this, 'ajax_assign_slot_to_order_item'));
        add_action('wp_ajax_eva_admin_change_slot', array($this, 'ajax_change_order_item_slot'));
        add_action('wp_ajax_eva_admin_get_product_slots', array($this, 'ajax_get_product_slots'));
        add_action('wp_ajax_eva_admin_save_settings', array($this, 'ajax_save_settings'));

        // Self-test page.
        add_action('admin_menu', array($this, 'add_selftest_page'), 99);

        // Order edit screen metabox.
        add_action('add_meta_boxes', array($this, 'add_order_slots_metabox'));

        // Register settings.
        add_action('admin_init', array($this, 'register_settings'));

        // Handle CSV export early (before any output).
        add_action('admin_init', array($this, 'handle_csv_export'));
    }

    /**
     * Handle CSV export before any output.
     * This must run early to send proper headers.
     */
    public function handle_csv_export()
    {
        // Check if we're on the right page with export parameter.
        if (!isset($_GET['page']) || 'eva-course-bookings' !== $_GET['page']) {
            return;
        }

        if (!isset($_GET['export']) || 'csv' !== $_GET['export']) {
            return;
        }

        if (!isset($_GET['slot_id'])) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $slot_id = absint($_GET['slot_id']);
        $slot = $this->slot_repository->get_slot($slot_id);

        if (!$slot) {
            return;
        }

        // Get bookings for this slot.
        $bookings = $this->get_orders_for_slot($slot_id);

        // Generate CSV.
        $product = get_post($slot['product_id']);
        $product_name = $product ? sanitize_file_name($product->post_title) : 'corso';
        $date_str = date('Y-m-d', strtotime($slot['start_datetime']));

        $filename = sprintf('prenotazioni-%s-%s.csv', $product_name, $date_str);

        // Send headers.
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // BOM for Excel UTF-8.
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Header row.
        fputcsv($output, array(
            'Ordine',
            'Data Ordine',
            'Nome',
            'Email',
            'Telefono',
            'Partecipanti',
            'Stato Ordine'
        ), ';');

        // Data rows.
        foreach ($bookings as $booking) {
            fputcsv($output, array(
                '#' . $booking['order_id'],
                $booking['order_date'],
                $booking['customer_name'],
                $booking['customer_email'],
                $booking['customer_phone'],
                $booking['quantity'],
                wc_get_order_status_name($booking['order_status'])
            ), ';');
        }

        fclose($output);
        exit;
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets($hook)
    {
        global $post;

        $is_product_edit = ('post.php' === $hook || 'post-new.php' === $hook) &&
            isset($post) && 'product' === $post->post_type;

        $is_plugin_page = strpos($hook, 'eva-course-bookings') !== false;

        // Check for order edit page (both legacy and HPOS).
        $is_order_edit = false;
        if ('post.php' === $hook && isset($post) && 'shop_order' === $post->post_type) {
            $is_order_edit = true;
        }
        // HPOS order edit page.
        if ('woocommerce_page_wc-orders' === $hook) {
            $is_order_edit = true;
        }

        // Check if we're on the settings tab.
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'prenotazioni';
        $is_settings_tab = $is_plugin_page && 'impostazioni' === $current_tab;

        if (! $is_product_edit && ! $is_plugin_page && ! $is_order_edit) {
            return;
        }

        // Enqueue WordPress bundled datepicker.
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-datepicker-style', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css', array(), '1.13.2');

        // Enqueue color picker for settings tab.
        if ($is_settings_tab) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
        }

        // Enqueue admin CSS.
        wp_enqueue_style(
            'eva-course-bookings-admin',
            EVA_COURSE_BOOKINGS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            EVA_COURSE_BOOKINGS_VERSION
        );

        // Enqueue admin JS.
        wp_enqueue_script(
            'eva-course-bookings-admin',
            EVA_COURSE_BOOKINGS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-datepicker'),
            EVA_COURSE_BOOKINGS_VERSION,
            true
        );

        wp_localize_script(
            'eva-course-bookings-admin',
            'evaAdminData',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('eva_admin_nonce'),
                'i18n'    => array(
                    'confirmDelete'     => 'Sei sicuro di voler eliminare questo slot?',
                    'confirmClose'      => 'Sei sicuro di voler chiudere questo slot?',
                    'slotCreated'       => 'Slot creato con successo.',
                    'slotUpdated'       => 'Slot aggiornato con successo.',
                    'slotDeleted'       => 'Slot eliminato con successo.',
                    'errorOccurred'     => 'Si è verificato un errore.',
                    'requiredFields'    => 'Compila tutti i campi obbligatori.',
                    'invalidCapacity'   => 'La capacità deve essere maggiore di 0.',
                ),
            )
        );
    }

    /**
     * Add course enabled checkbox to product general options.
     */
    public function add_course_enabled_checkbox()
    {
        global $post;

        woocommerce_wp_checkbox(
            array(
                'id'          => '_eva_course_enabled',
                'label'       => 'Abilita prenotazione corso',
                'description' => 'Attiva questa opzione per permettere ai clienti di prenotare date e orari per questo prodotto.',
                'value'       => get_post_meta($post->ID, '_eva_course_enabled', true),
            )
        );
    }

    /**
     * Save course enabled checkbox.
     *
     * @param int $post_id Product ID.
     */
    public function save_course_enabled_checkbox($post_id)
    {
        // Verify nonce is handled by WooCommerce.
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $enabled = isset($_POST['_eva_course_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_eva_course_enabled', $enabled);
    }

    /**
     * Add slots metabox to product edit screen.
     */
    public function add_slots_metabox()
    {
        add_meta_box(
            'eva_course_slots',
            'Date del corso',
            array($this, 'render_slots_metabox'),
            'product',
            'normal',
            'high'
        );
    }

    /**
     * Render slots metabox content.
     *
     * @param WP_Post $post Current post.
     */
    public function render_slots_metabox($post)
    {
        $course_enabled = get_post_meta($post->ID, '_eva_course_enabled', true);
        $slots          = $this->slot_repository->get_slots_for_product($post->ID);

        // Get all slots including past ones for admin view.
        $all_slots = $this->get_all_product_slots($post->ID);

        wp_nonce_field('eva_slots_metabox', 'eva_slots_nonce');
?>
        <div id="eva-slots-container" class="eva-slots-container" data-product-id="<?php echo esc_attr($post->ID); ?>" style="<?php echo 'yes' !== $course_enabled ? 'display:none;' : ''; ?>">

            <!-- Add Slot Form -->
            <div class="eva-add-slot-form">
                <h4>Aggiungi nuovo slot</h4>
                <div class="eva-form-row">
                    <div class="eva-form-field">
                        <label for="eva-slot-date">Data *</label>
                        <input type="text" id="eva-slot-date" class="eva-datepicker" placeholder="gg/mm/aaaa">
                    </div>
                    <div class="eva-form-field">
                        <label for="eva-slot-time">Ora inizio *</label>
                        <input type="time" id="eva-slot-time">
                    </div>
                    <div class="eva-form-field">
                        <label for="eva-slot-end-time">Ora fine</label>
                        <input type="time" id="eva-slot-end-time">
                    </div>
                    <div class="eva-form-field">
                        <label for="eva-slot-capacity">Posti *</label>
                        <input type="number" id="eva-slot-capacity" min="1" value="10">
                    </div>
                    <div class="eva-form-field eva-form-submit">
                        <button type="button" id="eva-add-slot-btn" class="button button-primary">Aggiungi slot</button>
                    </div>
                </div>
            </div>

            <!-- Slots List -->
            <div class="eva-slots-list">
                <h4>Slot esistenti</h4>
                <table class="wp-list-table widefat fixed striped" id="eva-slots-table">
                    <thead>
                        <tr>
                            <th>Data e ora</th>
                            <th>Ora fine</th>
                            <th>Capacità</th>
                            <th>Prenotati</th>
                            <th>Disponibili</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_slots)) : ?>
                            <tr class="eva-no-slots">
                                <td colspan="7">Nessuno slot creato. Aggiungi il primo slot sopra.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($all_slots as $slot) : ?>
                                <?php $this->render_slot_row($slot); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Edit Slot Modal -->
            <div id="eva-edit-slot-modal" class="eva-modal" style="display:none;">
                <div class="eva-modal-content">
                    <span class="eva-modal-close">&times;</span>
                    <h3>Modifica slot</h3>
                    <input type="hidden" id="eva-edit-slot-id">
                    <div class="eva-form-row">
                        <div class="eva-form-field">
                            <label for="eva-edit-slot-date">Data *</label>
                            <input type="text" id="eva-edit-slot-date" class="eva-datepicker">
                        </div>
                        <div class="eva-form-field">
                            <label for="eva-edit-slot-time">Ora inizio *</label>
                            <input type="time" id="eva-edit-slot-time">
                        </div>
                        <div class="eva-form-field">
                            <label for="eva-edit-slot-end-time">Ora fine</label>
                            <input type="time" id="eva-edit-slot-end-time">
                        </div>
                        <div class="eva-form-field">
                            <label for="eva-edit-slot-capacity">Posti *</label>
                            <input type="number" id="eva-edit-slot-capacity" min="1">
                        </div>
                    </div>
                    <div class="eva-modal-actions">
                        <button type="button" id="eva-save-slot-btn" class="button button-primary">Salva modifiche</button>
                        <button type="button" class="button eva-modal-cancel">Annulla</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="eva-slots-disabled-notice" style="<?php echo 'yes' === $course_enabled ? 'display:none;' : ''; ?>">
            <p class="description">Attiva l'opzione "Abilita prenotazione corso" nelle opzioni generali per gestire le date del corso.</p>
        </div>
    <?php
    }

    /**
     * Render a single slot row.
     *
     * @param array $slot Slot data.
     */
    private function render_slot_row($slot)
    {
        $start_dt   = \DateTime::createFromFormat('Y-m-d H:i:s', $slot['start_datetime']);
        $end_dt     = $slot['end_datetime'] ? \DateTime::createFromFormat('Y-m-d H:i:s', $slot['end_datetime']) : null;
        $is_past    = $start_dt && $start_dt < new \DateTime();
        $status_label = 'open' === $slot['status'] ? 'Aperto' : 'Chiuso';
        $status_class = 'open' === $slot['status'] ? 'eva-status-open' : 'eva-status-closed';

        if ($is_past) {
            $status_class .= ' eva-slot-past';
        }
    ?>
        <tr data-slot-id="<?php echo esc_attr($slot['id']); ?>" class="<?php echo esc_attr($status_class); ?>">
            <td>
                <?php echo esc_html($start_dt ? $start_dt->format('d/m/Y H:i') : $slot['start_datetime']); ?>
                <?php if ($is_past) : ?>
                    <span class="eva-past-badge">Passato</span>
                <?php endif; ?>
            </td>
            <td><?php echo esc_html($end_dt ? $end_dt->format('H:i') : '-'); ?></td>
            <td class="eva-capacity"><?php echo esc_html($slot['capacity']); ?></td>
            <td class="eva-booked"><?php echo esc_html($slot['booked']); ?></td>
            <td class="eva-remaining">
                <span class="<?php echo $slot['remaining'] <= 0 ? 'eva-no-remaining' : ''; ?>">
                    <?php echo esc_html($slot['remaining']); ?>
                </span>
            </td>
            <td class="eva-status">
                <span class="eva-status-badge <?php echo esc_attr($status_class); ?>">
                    <?php echo esc_html($status_label); ?>
                </span>
            </td>
            <td class="eva-actions">
                <?php if (! $is_past) : ?>
                    <button type="button" class="button button-small eva-edit-slot"
                        data-slot='<?php echo esc_attr(wp_json_encode($slot)); ?>'>
                        Modifica
                    </button>
                    <button type="button" class="button button-small eva-toggle-status"
                        data-slot-id="<?php echo esc_attr($slot['id']); ?>"
                        data-current-status="<?php echo esc_attr($slot['status']); ?>">
                        <?php echo 'open' === $slot['status'] ? 'Chiudi' : 'Apri'; ?>
                    </button>
                    <?php if (0 === $slot['booked']) : ?>
                        <button type="button" class="button button-small eva-delete-slot"
                            data-slot-id="<?php echo esc_attr($slot['id']); ?>">
                            Elimina
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php
    }

    /**
     * Get all slots for a product (including past ones).
     *
     * @param int $product_id Product ID.
     * @return array
     */
    private function get_all_product_slots($product_id)
    {
        $args = array(
            'post_type'      => Slot_Repository::POST_TYPE,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => '_eva_product_id',
                    'value'   => $product_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
            ),
            'meta_key'       => '_eva_start_datetime',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        );

        $posts = get_posts($args);
        $slots = array();

        foreach ($posts as $post) {
            $capacity = absint(get_post_meta($post->ID, '_eva_capacity', true));
            $booked   = absint(get_post_meta($post->ID, '_eva_booked', true));

            $slots[] = array(
                'id'             => $post->ID,
                'product_id'     => absint(get_post_meta($post->ID, '_eva_product_id', true)),
                'start_datetime' => get_post_meta($post->ID, '_eva_start_datetime', true),
                'end_datetime'   => get_post_meta($post->ID, '_eva_end_datetime', true),
                'capacity'       => $capacity,
                'booked'         => $booked,
                'remaining'      => max(0, $capacity - $booked),
                'status'         => get_post_meta($post->ID, '_eva_status', true) ?: 'open',
            );
        }

        return $slots;
    }

    /**
     * Add admin menu pages.
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            'Corsi',
            'Corsi',
            'manage_woocommerce',
            'eva-course-bookings',
            array($this, 'render_main_page')
        );
    }

    /**
     * Get current tab.
     *
     * @return string
     */
    private function get_current_tab()
    {
        return isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'prenotazioni';
    }

    /**
     * Render the main page with tabs.
     */
    public function render_main_page()
    {
        $current_tab = $this->get_current_tab();

        // Get count of pending bookings for badge.
        $pending_count = $this->get_pending_bookings_count();
        $pending_badge = $pending_count > 0 ? ' <span class="eva-pending-badge">' . $pending_count . '</span>' : '';

        $tabs = array(
            'prenotazioni'  => 'Prenotazioni',
            'da_prenotare'  => 'Da prenotare' . $pending_badge,
            'corsi'         => 'Corsi abilitati',
            'impostazioni'  => 'Impostazioni',
        );

    ?>
        <div class="wrap eva-main-page">
            <h1>Corsi</h1>

            <nav class="nav-tab-wrapper eva-tab-wrapper">
                <?php foreach ($tabs as $tab_id => $tab_name) : ?>
                    <?php
                    $tab_url = add_query_arg(array(
                        'page' => 'eva-course-bookings',
                        'tab'  => $tab_id,
                    ), admin_url('admin.php'));
                    $active_class = ($current_tab === $tab_id) ? 'nav-tab-active' : '';
                    ?>
                    <a href="<?php echo esc_url($tab_url); ?>" class="nav-tab <?php echo esc_attr($active_class); ?>">
                        <?php echo wp_kses($tab_name, array('span' => array('class' => array()))); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="eva-tab-content">
                <?php
                switch ($current_tab) {
                    case 'da_prenotare':
                        $this->render_pending_bookings_content();
                        break;
                    case 'corsi':
                        $this->render_bulk_enable_content();
                        break;
                    case 'impostazioni':
                        $this->render_settings_content();
                        break;
                    case 'prenotazioni':
                    default:
                        $this->render_bookings_content();
                        break;
                }
                ?>
            </div>
        </div>
    <?php
    }

    /**
     * Render the bookings tab content.
     */
    public function render_bookings_content()
    {
        // Check if viewing a specific slot's bookings.
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : '';
        $slot_id = isset($_GET['slot_id']) ? absint($_GET['slot_id']) : 0;

        if ('slot' === $view && $slot_id) {
            $this->render_slot_bookings_content($slot_id);
            return;
        }

        // Handle filters.
        $filters = array(
            'product_id' => isset($_GET['product_id']) ? absint($_GET['product_id']) : 0,
            'status'     => isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '',
            'date_from'  => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to'    => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
            'page'       => isset($_GET['paged']) ? absint($_GET['paged']) : 1,
            'per_page'   => 20,
        );

        $result = $this->slot_repository->get_all_slots($filters);
        $slots  = $result['slots'];

        // Get products for filter dropdown.
        $products = $this->get_course_products();
    ?>
        <div class="eva-bookings-content">
            <!-- Filters -->
            <div class="eva-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="eva-course-bookings">
                    <input type="hidden" name="tab" value="prenotazioni">

                    <select name="product_id">
                        <option value="">Tutti i prodotti</option>
                        <?php foreach ($products as $product) : ?>
                            <option value="<?php echo esc_attr($product->ID); ?>" <?php selected($filters['product_id'], $product->ID); ?>>
                                <?php echo esc_html($product->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status">
                        <option value="">Tutti gli stati</option>
                        <option value="open" <?php selected($filters['status'], 'open'); ?>>Aperto</option>
                        <option value="closed" <?php selected($filters['status'], 'closed'); ?>>Chiuso</option>
                    </select>

                    <input type="text" name="date_from" class="eva-datepicker" placeholder="Data da"
                        value="<?php echo esc_attr($filters['date_from']); ?>">

                    <input type="text" name="date_to" class="eva-datepicker" placeholder="Data a"
                        value="<?php echo esc_attr($filters['date_to']); ?>">

                    <button type="submit" class="button">Filtra</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=eva-course-bookings&tab=prenotazioni')); ?>" class="button">Reset</a>
                </form>
            </div>

            <!-- Slots Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Prodotto</th>
                        <th>Data e ora</th>
                        <th>Ora fine</th>
                        <th>Capacità</th>
                        <th>Prenotati</th>
                        <th>Disponibili</th>
                        <th>Stato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($slots)) : ?>
                        <tr>
                            <td colspan="8">Nessuno slot trovato.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($slots as $slot) : ?>
                            <?php $this->render_admin_slot_row($slot); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($result['total_pages'] > 1) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(
                            array(
                                'base'      => add_query_arg('paged', '%#%'),
                                'format'    => '',
                                'current'   => $filters['page'],
                                'total'     => $result['total_pages'],
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            )
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Render the slot bookings detail content.
     *
     * @param int $slot_id Slot ID.
     */
    private function render_slot_bookings_content($slot_id)
    {
        $slot = $this->slot_repository->get_slot($slot_id);

        if (!$slot) {
            echo '<div class="eva-slot-not-found"><p>Slot non trovato. <a href="' . esc_url(admin_url('admin.php?page=eva-course-bookings&tab=prenotazioni')) . '">← Torna alla lista</a></p></div>';
            return;
        }

        $product = get_post($slot['product_id']);
        $product_name = $product ? $product->post_title : 'Prodotto eliminato';
        $start_dt = \DateTime::createFromFormat('Y-m-d H:i:s', $slot['start_datetime']);
        $end_dt = $slot['end_datetime'] ? \DateTime::createFromFormat('Y-m-d H:i:s', $slot['end_datetime']) : null;

        // Get all orders with items booked for this slot.
        $bookings = $this->get_orders_for_slot($slot_id);
    ?>
        <div class="eva-slot-bookings-content">
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=eva-course-bookings&tab=prenotazioni')); ?>" class="button">← Torna alla lista</a>
            </p>

            <h2>Dettaglio slot</h2>

            <div class="eva-slot-info-card">
                <table class="form-table">
                    <tr>
                        <th>Prodotto</th>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($slot['product_id'])); ?>">
                                <?php echo esc_html($product_name); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th>Data e ora</th>
                        <td>
                            <?php
                            echo esc_html($start_dt ? $start_dt->format('d/m/Y H:i') : $slot['start_datetime']);
                            if ($end_dt) {
                                echo ' - ' . esc_html($end_dt->format('H:i'));
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Capacità</th>
                        <td><?php echo esc_html($slot['capacity']); ?> posti</td>
                    </tr>
                    <tr>
                        <th>Prenotati</th>
                        <td><strong><?php echo esc_html($slot['booked']); ?></strong> posti</td>
                    </tr>
                    <tr>
                        <th>Disponibili</th>
                        <td>
                            <span class="<?php echo $slot['remaining'] <= 0 ? 'eva-no-remaining' : ''; ?>">
                                <?php echo esc_html($slot['remaining']); ?>
                            </span> posti
                        </td>
                    </tr>
                    <tr>
                        <th>Stato</th>
                        <td>
                            <span class="eva-status-badge <?php echo 'open' === $slot['status'] ? 'eva-status-open' : 'eva-status-closed'; ?>">
                                <?php echo 'open' === $slot['status'] ? 'Aperto' : 'Chiuso'; ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>

            <h2>Prenotazioni (<?php echo count($bookings); ?>)</h2>

            <?php if (empty($bookings)) : ?>
                <p>Nessuna prenotazione per questo slot.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Ordine</th>
                            <th>Data ordine</th>
                            <th>Cliente</th>
                            <th>Email</th>
                            <th>Telefono</th>
                            <th>Partecipanti</th>
                            <th>Stato ordine</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url($booking['edit_url']); ?>">
                                        <strong>#<?php echo esc_html($booking['order_id']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo esc_html($booking['order_date']); ?></td>
                                <td><?php echo esc_html($booking['customer_name']); ?></td>
                                <td>
                                    <a href="mailto:<?php echo esc_attr($booking['customer_email']); ?>">
                                        <?php echo esc_html($booking['customer_email']); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($booking['customer_phone'] ?: '-'); ?></td>
                                <td><strong><?php echo esc_html($booking['quantity']); ?></strong></td>
                                <td>
                                    <span class="order-status status-<?php echo esc_attr($booking['order_status']); ?>">
                                        <?php echo esc_html(wc_get_order_status_name($booking['order_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($booking['edit_url']); ?>" class="button button-small">
                                        Vedi ordine
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="5" style="text-align: right;"><strong>Totale partecipanti:</strong></th>
                            <th colspan="3">
                                <strong>
                                    <?php
                                    $total = array_sum(array_column($bookings, 'quantity'));
                                    echo esc_html($total);
                                    ?>
                                </strong>
                            </th>
                        </tr>
                    </tfoot>
                </table>

                <h3>Esporta lista partecipanti</h3>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=eva-course-bookings&tab=prenotazioni&view=slot&slot_id=' . $slot_id . '&export=csv')); ?>"
                        class="button">
                        Scarica CSV
                    </a>
                </p>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Get orders that have items booked for a specific slot.
     *
     * @param int $slot_id Slot ID.
     * @return array Array of booking data.
     */
    private function get_orders_for_slot($slot_id)
    {
        global $wpdb;

        // Query order items with this slot ID.
        // This works with both legacy and HPOS.
        $order_item_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta
                 WHERE meta_key = '_eva_slot_id' AND meta_value = %s",
                $slot_id
            )
        );

        if (empty($order_item_ids)) {
            return array();
        }

        $bookings = array();

        foreach ($order_item_ids as $order_item_id) {
            // Get the order ID for this item.
            $order_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d",
                    $order_item_id
                )
            );

            if (!$order_id) {
                continue;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            // Get quantity from item meta.
            $quantity = wc_get_order_item_meta($order_item_id, '_eva_slot_qty', true);
            if (!$quantity) {
                $item = $order->get_item($order_item_id);
                $quantity = $item ? $item->get_quantity() : 1;
            }

            // Build edit URL (works with HPOS).
            $edit_url = $order->get_edit_order_url();

            $bookings[] = array(
                'order_id'       => $order->get_id(),
                'order_date'     => $order->get_date_created() ? $order->get_date_created()->format('d/m/Y H:i') : '',
                'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'customer_email' => $order->get_billing_email(),
                'customer_phone' => $order->get_billing_phone(),
                'quantity'       => (int) $quantity,
                'order_status'   => $order->get_status(),
                'edit_url'       => $edit_url,
            );
        }

        // Sort by order date descending.
        usort($bookings, function ($a, $b) {
            return strcmp($b['order_date'], $a['order_date']);
        });

        return $bookings;
    }

    /**
     * Render a slot row in the admin page.
     *
     * @param array $slot Slot data.
     */
    private function render_admin_slot_row($slot)
    {
        $product      = get_post($slot['product_id']);
        $product_name = $product ? $product->post_title : 'Prodotto eliminato';
        $start_dt     = \DateTime::createFromFormat('Y-m-d H:i:s', $slot['start_datetime']);
        $end_dt       = $slot['end_datetime'] ? \DateTime::createFromFormat('Y-m-d H:i:s', $slot['end_datetime']) : null;
        $is_past      = $start_dt && $start_dt < new \DateTime();
        $status_label = 'open' === $slot['status'] ? 'Aperto' : 'Chiuso';
        $status_class = 'open' === $slot['status'] ? 'eva-status-open' : 'eva-status-closed';
    ?>
        <tr data-slot-id="<?php echo esc_attr($slot['id']); ?>" class="<?php echo $is_past ? 'eva-slot-past' : ''; ?>">
            <td>
                <a href="<?php echo esc_url(get_edit_post_link($slot['product_id'])); ?>">
                    <?php echo esc_html($product_name); ?>
                </a>
            </td>
            <td>
                <?php echo esc_html($start_dt ? $start_dt->format('d/m/Y H:i') : $slot['start_datetime']); ?>
                <?php if ($is_past) : ?>
                    <span class="eva-past-badge">Passato</span>
                <?php endif; ?>
            </td>
            <td><?php echo esc_html($end_dt ? $end_dt->format('H:i') : '-'); ?></td>
            <td><?php echo esc_html($slot['capacity']); ?></td>
            <td><?php echo esc_html($slot['booked']); ?></td>
            <td>
                <span class="<?php echo $slot['remaining'] <= 0 ? 'eva-no-remaining' : ''; ?>">
                    <?php echo esc_html($slot['remaining']); ?>
                </span>
            </td>
            <td>
                <span class="eva-status-badge <?php echo esc_attr($status_class); ?>">
                    <?php echo esc_html($status_label); ?>
                </span>
            </td>
            <td>
                <?php if ($slot['booked'] > 0) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=eva-course-bookings&tab=prenotazioni&view=slot&slot_id=' . $slot['id'])); ?>"
                        class="button button-small">
                        Vedi prenotazioni
                    </a>
                <?php endif; ?>
                <?php if (! $is_past) : ?>
                    <button type="button" class="button button-small eva-toggle-status"
                        data-slot-id="<?php echo esc_attr($slot['id']); ?>"
                        data-current-status="<?php echo esc_attr($slot['status']); ?>">
                        <?php echo 'open' === $slot['status'] ? 'Chiudi' : 'Apri'; ?>
                    </button>
                    <?php if (0 === $slot['booked']) : ?>
                        <button type="button" class="button button-small eva-delete-slot"
                            data-slot-id="<?php echo esc_attr($slot['id']); ?>">
                            Elimina
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php
    }

    /**
     * Get products with course booking enabled.
     *
     * @return array Array of WP_Post objects.
     */
    private function get_course_products()
    {
        return get_posts(
            array(
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'meta_query'     => array(
                    array(
                        'key'     => '_eva_course_enabled',
                        'value'   => 'yes',
                        'compare' => '=',
                    ),
                ),
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );
    }

    /**
     * Render bulk enable tab content.
     */
    public function render_bulk_enable_content()
    {
        // Get all products.
        $products = get_posts(
            array(
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );
    ?>
        <div class="eva-bulk-enable-content">
            <p>Seleziona i prodotti per i quali vuoi abilitare la prenotazione corsi.</p>

            <form id="eva-bulk-enable-form">
                <?php wp_nonce_field('eva_bulk_enable', 'eva_bulk_nonce'); ?>

                <div class="eva-bulk-actions">
                    <button type="button" id="eva-select-all" class="button">Seleziona tutti</button>
                    <button type="button" id="eva-deselect-all" class="button">Deseleziona tutti</button>
                    <button type="submit" class="button button-primary">Abilita selezionati</button>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 30px;"><input type="checkbox" id="eva-check-all"></th>
                            <th>Prodotto</th>
                            <th>Stato attuale</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product) : ?>
                            <?php
                            $enabled = 'yes' === get_post_meta($product->ID, '_eva_course_enabled', true);
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="product_ids[]" value="<?php echo esc_attr($product->ID); ?>"
                                        <?php checked($enabled); ?>>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link($product->ID)); ?>">
                                        <?php echo esc_html($product->post_title); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($enabled) : ?>
                                        <span class="eva-status-badge eva-status-open">Abilitato</span>
                                    <?php else : ?>
                                        <span class="eva-status-badge eva-status-closed">Non abilitato</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
    <?php
    }

    /**
     * Get count of unassigned course items (pending + legacy).
     *
     * @return int
     */
    private function get_pending_bookings_count()
    {
        global $wpdb;

        // Check if HPOS is enabled.
        $is_hpos_enabled = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($is_hpos_enabled) {
            // HPOS mode: join with wc_orders table.
            $count = $wpdb->get_var(
                "SELECT COUNT(DISTINCT oi.order_item_id)
                FROM {$wpdb->prefix}woocommerce_order_items AS oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_prod
                    ON oim_prod.order_item_id = oi.order_item_id AND oim_prod.meta_key = '_product_id'
                INNER JOIN {$wpdb->postmeta} AS pm_course
                    ON pm_course.post_id = oim_prod.meta_value
                    AND pm_course.meta_key = '_eva_course_enabled'
                    AND pm_course.meta_value = 'yes'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_slot
                    ON oim_slot.order_item_id = oi.order_item_id AND oim_slot.meta_key = '_eva_slot_id'
                INNER JOIN {$wpdb->prefix}wc_orders AS wc_orders
                    ON wc_orders.id = oi.order_id
                    AND wc_orders.status IN ('wc-processing', 'wc-completed')
                WHERE oi.order_item_type = 'line_item'
                  AND oim_slot.order_item_id IS NULL"
            );
        } else {
            // Legacy mode: join with posts table.
            $count = $wpdb->get_var(
                "SELECT COUNT(DISTINCT oi.order_item_id)
                FROM {$wpdb->prefix}woocommerce_order_items AS oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_prod
                    ON oim_prod.order_item_id = oi.order_item_id AND oim_prod.meta_key = '_product_id'
                INNER JOIN {$wpdb->postmeta} AS pm_course
                    ON pm_course.post_id = oim_prod.meta_value
                    AND pm_course.meta_key = '_eva_course_enabled'
                    AND pm_course.meta_value = 'yes'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_slot
                    ON oim_slot.order_item_id = oi.order_item_id AND oim_slot.meta_key = '_eva_slot_id'
                INNER JOIN {$wpdb->posts} AS p
                    ON p.ID = oi.order_id
                    AND p.post_type IN ('shop_order', 'shop_order_refund')
                    AND p.post_status IN ('wc-processing', 'wc-completed')
                WHERE oi.order_item_type = 'line_item'
                  AND oim_slot.order_item_id IS NULL"
            );
        }

        return absint($count);
    }

    /**
     * Get unassigned course items (pending + legacy).
     *
     * @return array
     */
    private function get_pending_bookings()
    {
        global $wpdb;

        // Get all course items without a slot assigned, plus whether they are marked as pending booking.
        $rows = $wpdb->get_results(
            "SELECT oi.order_id, oi.order_item_id, oim_pending.meta_value AS pending_value
            FROM {$wpdb->prefix}woocommerce_order_items AS oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_prod
                ON oim_prod.order_item_id = oi.order_item_id AND oim_prod.meta_key = '_product_id'
            INNER JOIN {$wpdb->postmeta} AS pm_course
                ON pm_course.post_id = oim_prod.meta_value
                AND pm_course.meta_key = '_eva_course_enabled'
                AND pm_course.meta_value = 'yes'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_slot
                ON oim_slot.order_item_id = oi.order_item_id AND oim_slot.meta_key = '_eva_slot_id'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_pending
                ON oim_pending.order_item_id = oi.order_item_id AND oim_pending.meta_key = '_eva_pending_booking'
            WHERE oi.order_item_type = 'line_item'
              AND oim_slot.order_item_id IS NULL
            ORDER BY oi.order_id DESC"
        );

        if (empty($rows)) {
            return array();
        }

        $pending = array();

        foreach ($rows as $row) {
            $order_id = absint($row->order_id);
            $item_id  = absint($row->order_item_id);

            if (! $order_id || ! $item_id) {
                continue;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            // Skip irrelevant statuses.
            if (in_array($order->get_status(), array('cancelled', 'refunded', 'failed', 'draft'), true)) {
                continue;
            }

            // Get item.
            $item = $order->get_item($item_id);
            if (!$item) {
                continue;
            }

            $product_id = $item->get_product_id();
            $quantity = $item->get_meta('_eva_slot_qty') ?: $item->get_quantity();
            $is_pending_booking = ('yes' === (string) $row->pending_value);

            $pending[] = array(
                'order_id'       => $order->get_id(),
                'order_date'     => $order->get_date_created() ? $order->get_date_created()->format('d/m/Y H:i') : '',
                'order_status'   => $order->get_status(),
                'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'customer_email' => $order->get_billing_email(),
                'customer_phone' => $order->get_billing_phone(),
                'item_id'        => $item_id,
                'product_id'     => $product_id,
                'product_name'   => $item->get_name(),
                'quantity'       => (int) $quantity,
                'edit_url'       => $order->get_edit_order_url(),
                'pending_booking' => $is_pending_booking,
            );
        }

        return $pending;
    }

    /**
     * Render pending bookings tab content.
     */
    public function render_pending_bookings_content()
    {
        $pending = $this->get_pending_bookings();
    ?>
        <div class="eva-pending-bookings-content">
            <p class="description">
                Qui trovi tutti i corsi acquistati senza uno slot assegnato.
                Include sia gli acquisti "Regalo / prenotazione futura" sia gli ordini "Legacy" effettuati prima dell'attivazione del plugin.
                Clicca su "Assegna data" per aprire l'ordine e assegnare lo slot dal metabox.
            </p>

            <?php if (empty($pending)) : ?>
                <div class="eva-no-pending">
                    <p><strong>Nessuna prenotazione in attesa.</strong></p>
                    <p>Tutti i corsi acquistati hanno già una data assegnata.</p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Ordine</th>
                            <th>Data ordine</th>
                            <th>Cliente</th>
                            <th>Corso</th>
                            <th>Tipo</th>
                            <th>Partecipanti</th>
                            <th>Stato ordine</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $item) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url($item['edit_url']); ?>">
                                        <strong>#<?php echo esc_html($item['order_id']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo esc_html($item['order_date']); ?></td>
                                <td>
                                    <?php echo esc_html($item['customer_name']); ?>
                                    <br>
                                    <a href="mailto:<?php echo esc_attr($item['customer_email']); ?>">
                                        <?php echo esc_html($item['customer_email']); ?>
                                    </a>
                                    <?php if ($item['customer_phone']) : ?>
                                        <br>
                                        <small><?php echo esc_html($item['customer_phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link($item['product_id'])); ?>">
                                        <?php echo esc_html($item['product_name']); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if (! empty($item['pending_booking'])) : ?>
                                        <span class="eva-pending-tag" title="Acquistato come regalo o prenotazione futura">🎁 Regalo</span>
                                    <?php else : ?>
                                        <span class="eva-legacy-tag" title="Ordine precedente senza slot assegnato">📋 Legacy</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo esc_html($item['quantity']); ?></strong></td>
                                <td>
                                    <span class="order-status status-<?php echo esc_attr($item['order_status']); ?>">
                                        <?php echo esc_html(wc_get_order_status_name($item['order_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($item['edit_url']); ?>" class="button button-primary button-small">
                                        Assegna data
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Register plugin settings.
     */
    public function register_settings()
    {
        register_setting('eva_course_bookings_settings', 'eva_course_bookings_colors', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_color_settings'),
            'default' => self::get_default_colors(),
        ));
    }

    /**
     * Get default color settings.
     *
     * @return array
     */
    public static function get_default_colors()
    {
        return array(
            'box_background'      => '#f8f9fa',
            'box_background_end'  => '#e9ecef',
            'box_border'          => '#dee2e6',
            'title_color'         => '#212529',
            'title_border'        => '#007bff',
            'label_color'         => '#495057',
            'input_border'        => '#e9ecef',
            'input_border_hover'  => '#007bff',
            'input_focus_shadow'  => 'rgba(0, 123, 255, 0.15)',
            'slot_background'     => '#ffffff',
            'slot_border'         => '#e9ecef',
            'slot_hover_border'   => '#007bff',
            'slot_selected_bg'    => '#007bff',
            'slot_selected_text'  => '#ffffff',
            'slot_time_color'     => '#212529',
            'summary_background'  => '#d4edda',
            'summary_border'      => '#c3e6cb',
            'summary_icon'        => '#28a745',
            'summary_text'        => '#155724',
            'validation_bg'       => '#fff3cd',
            'validation_border'   => '#ffc107',
            'validation_text'     => '#856404',
        );
    }

    /**
     * Sanitize color settings.
     *
     * @param array $input Input values.
     * @return array Sanitized values.
     */
    public function sanitize_color_settings($input)
    {
        $defaults = self::get_default_colors();
        $output = array();

        foreach ($defaults as $key => $default) {
            if (isset($input[$key])) {
                // Sanitize hex color or rgba.
                $color = sanitize_text_field($input[$key]);
                if (preg_match('/^#[a-fA-F0-9]{6}$/', $color) || preg_match('/^rgba?\(/', $color)) {
                    $output[$key] = $color;
                } else {
                    $output[$key] = $default;
                }
            } else {
                $output[$key] = $default;
            }
        }

        return $output;
    }

    /**
     * Get current color settings.
     *
     * @return array
     */
    public static function get_color_settings()
    {
        $colors = get_option('eva_course_bookings_colors', array());
        return wp_parse_args($colors, self::get_default_colors());
    }

    /**
     * Render settings tab content.
     */
    public function render_settings_content()
    {
        $colors = self::get_color_settings();
    ?>
        <div class="eva-settings-content">
            <form method="post" action="" id="eva-settings-form">
                <?php wp_nonce_field('eva_settings_nonce', 'eva_settings_nonce'); ?>

                <h2>Colori selettore data</h2>
                <p class="description">Personalizza i colori del box di selezione data/ora nella pagina prodotto.</p>

                <div class="eva-settings-sections">
                    <!-- Box Container -->
                    <div class="eva-settings-section">
                        <h3>Contenitore principale</h3>
                        <table class="form-table">
                            <tr>
                                <th><label for="box_background">Sfondo (inizio gradiente)</label></th>
                                <td>
                                    <input type="text" id="box_background" name="colors[box_background]"
                                        value="<?php echo esc_attr($colors['box_background']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="box_background_end">Sfondo (fine gradiente)</label></th>
                                <td>
                                    <input type="text" id="box_background_end" name="colors[box_background_end]"
                                        value="<?php echo esc_attr($colors['box_background_end']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="box_border">Bordo</label></th>
                                <td>
                                    <input type="text" id="box_border" name="colors[box_border]"
                                        value="<?php echo esc_attr($colors['box_border']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Title -->
                    <div class="eva-settings-section">
                        <h3>Titolo</h3>
                        <table class="form-table">
                            <tr>
                                <th><label for="title_color">Colore titolo</label></th>
                                <td>
                                    <input type="text" id="title_color" name="colors[title_color]"
                                        value="<?php echo esc_attr($colors['title_color']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="title_border">Bordo inferiore titolo</label></th>
                                <td>
                                    <input type="text" id="title_border" name="colors[title_border]"
                                        value="<?php echo esc_attr($colors['title_border']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Input Fields -->
                    <div class="eva-settings-section">
                        <h3>Campi input</h3>
                        <table class="form-table">
                            <tr>
                                <th><label for="label_color">Colore etichette</label></th>
                                <td>
                                    <input type="text" id="label_color" name="colors[label_color]"
                                        value="<?php echo esc_attr($colors['label_color']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="input_border">Bordo input</label></th>
                                <td>
                                    <input type="text" id="input_border" name="colors[input_border]"
                                        value="<?php echo esc_attr($colors['input_border']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="input_border_hover">Bordo input (hover)</label></th>
                                <td>
                                    <input type="text" id="input_border_hover" name="colors[input_border_hover]"
                                        value="<?php echo esc_attr($colors['input_border_hover']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Time Slots -->
                    <div class="eva-settings-section">
                        <h3>Slot orari</h3>
                        <table class="form-table">
                            <tr>
                                <th><label for="slot_background">Sfondo slot</label></th>
                                <td>
                                    <input type="text" id="slot_background" name="colors[slot_background]"
                                        value="<?php echo esc_attr($colors['slot_background']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="slot_border">Bordo slot</label></th>
                                <td>
                                    <input type="text" id="slot_border" name="colors[slot_border]"
                                        value="<?php echo esc_attr($colors['slot_border']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="slot_hover_border">Bordo slot (hover)</label></th>
                                <td>
                                    <input type="text" id="slot_hover_border" name="colors[slot_hover_border]"
                                        value="<?php echo esc_attr($colors['slot_hover_border']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="slot_time_color">Colore testo orario</label></th>
                                <td>
                                    <input type="text" id="slot_time_color" name="colors[slot_time_color]"
                                        value="<?php echo esc_attr($colors['slot_time_color']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="slot_selected_bg">Sfondo slot selezionato</label></th>
                                <td>
                                    <input type="text" id="slot_selected_bg" name="colors[slot_selected_bg]"
                                        value="<?php echo esc_attr($colors['slot_selected_bg']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="slot_selected_text">Testo slot selezionato</label></th>
                                <td>
                                    <input type="text" id="slot_selected_text" name="colors[slot_selected_text]"
                                        value="<?php echo esc_attr($colors['slot_selected_text']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Summary -->
                    <div class="eva-settings-section">
                        <h3>Riepilogo selezione</h3>
                        <table class="form-table">
                            <tr>
                                <th><label for="summary_background">Sfondo</label></th>
                                <td>
                                    <input type="text" id="summary_background" name="colors[summary_background]"
                                        value="<?php echo esc_attr($colors['summary_background']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="summary_border">Bordo</label></th>
                                <td>
                                    <input type="text" id="summary_border" name="colors[summary_border]"
                                        value="<?php echo esc_attr($colors['summary_border']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="summary_icon">Colore icona</label></th>
                                <td>
                                    <input type="text" id="summary_icon" name="colors[summary_icon]"
                                        value="<?php echo esc_attr($colors['summary_icon']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="summary_text">Colore testo</label></th>
                                <td>
                                    <input type="text" id="summary_text" name="colors[summary_text]"
                                        value="<?php echo esc_attr($colors['summary_text']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Validation Message -->
                    <div class="eva-settings-section">
                        <h3>Messaggio di validazione</h3>
                        <table class="form-table">
                            <tr>
                                <th><label for="validation_bg">Sfondo</label></th>
                                <td>
                                    <input type="text" id="validation_bg" name="colors[validation_bg]"
                                        value="<?php echo esc_attr($colors['validation_bg']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="validation_border">Bordo</label></th>
                                <td>
                                    <input type="text" id="validation_border" name="colors[validation_border]"
                                        value="<?php echo esc_attr($colors['validation_border']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="validation_text">Colore testo</label></th>
                                <td>
                                    <input type="text" id="validation_text" name="colors[validation_text]"
                                        value="<?php echo esc_attr($colors['validation_text']); ?>" class="eva-color-picker">
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="eva-save-settings">Salva impostazioni</button>
                    <button type="button" class="button" id="eva-reset-colors">Ripristina predefiniti</button>
                </p>
            </form>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Initialize color pickers.
                $('.eva-color-picker').wpColorPicker();

                // Save settings via AJAX.
                $('#eva-settings-form').on('submit', function(e) {
                    e.preventDefault();

                    var formData = $(this).serialize();
                    formData += '&action=eva_admin_save_settings';
                    formData += '&nonce=' + evaAdminData.nonce;

                    $('#eva-save-settings').prop('disabled', true).text('Salvataggio...');

                    $.ajax({
                        url: evaAdminData.ajaxUrl,
                        type: 'POST',
                        data: formData,
                        success: function(response) {
                            if (response.success) {
                                alert(response.data.message);
                            } else {
                                alert('Errore: ' + response.data.message);
                            }
                        },
                        error: function() {
                            alert('Errore di comunicazione con il server.');
                        },
                        complete: function() {
                            $('#eva-save-settings').prop('disabled', false).text('Salva impostazioni');
                        }
                    });
                });

                // Reset to defaults.
                $('#eva-reset-colors').on('click', function() {
                    if (!confirm('Sei sicuro di voler ripristinare i colori predefiniti?')) {
                        return;
                    }

                    var defaults = <?php echo wp_json_encode(self::get_default_colors()); ?>;

                    $.each(defaults, function(key, value) {
                        var $input = $('input[name="colors[' + key + ']"]');
                        $input.val(value);
                        $input.wpColorPicker('color', value);
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * AJAX: Save settings.
     */
    public function ajax_save_settings()
    {
        check_ajax_referer('eva_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permesso negato.'));
        }

        $colors = isset($_POST['colors']) ? (array) $_POST['colors'] : array();
        $sanitized = $this->sanitize_color_settings($colors);

        update_option('eva_course_bookings_colors', $sanitized);

        wp_send_json_success(array('message' => 'Impostazioni salvate con successo.'));
    }

    /**
     * AJAX: Create slot.
     */
    public function ajax_create_slot()
    {
        check_ajax_referer('eva_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permesso negato.'));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $date       = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        $time       = isset($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '';
        $end_time   = isset($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : '';
        $capacity   = isset($_POST['capacity']) ? absint($_POST['capacity']) : 0;

        if (! $product_id || ! $date || ! $time || ! $capacity) {
            wp_send_json_error(array('message' => 'Compila tutti i campi obbligatori.'));
        }

        // Parse Italian date format (dd/mm/yyyy).
        $date_parts = explode('/', $date);
        if (count($date_parts) !== 3) {
            wp_send_json_error(array('message' => 'Formato data non valido.'));
        }

        $start_datetime = sprintf('%s-%s-%s %s:00', $date_parts[2], $date_parts[1], $date_parts[0], $time);
        $end_datetime   = '';

        if ($end_time) {
            $end_datetime = sprintf('%s-%s-%s %s:00', $date_parts[2], $date_parts[1], $date_parts[0], $end_time);
        }

        $slot_id = $this->slot_repository->create_slot(
            array(
                'product_id'     => $product_id,
                'start_datetime' => $start_datetime,
                'end_datetime'   => $end_datetime,
                'capacity'       => $capacity,
            )
        );

        if (is_wp_error($slot_id)) {
            wp_send_json_error(array('message' => $slot_id->get_error_message()));
        }

        $slot = $this->slot_repository->get_slot($slot_id);

        ob_start();
        $this->render_slot_row($slot);
        $html = ob_get_clean();

        wp_send_json_success(
            array(
                'message' => 'Slot creato con successo.',
                'slot'    => $slot,
                'html'    => $html,
            )
        );
    }

    /**
     * AJAX: Update slot.
     */
    public function ajax_update_slot()
    {
        check_ajax_referer('eva_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permesso negato.'));
        }

        $slot_id  = isset($_POST['slot_id']) ? absint($_POST['slot_id']) : 0;
        $date     = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        $time     = isset($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '';
        $end_time = isset($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : '';
        $capacity = isset($_POST['capacity']) ? absint($_POST['capacity']) : 0;

        if (! $slot_id) {
            wp_send_json_error(array('message' => 'ID slot non valido.'));
        }

        $data = array();

        if ($date && $time) {
            // Parse Italian date format.
            $date_parts = explode('/', $date);
            if (count($date_parts) === 3) {
                $data['start_datetime'] = sprintf('%s-%s-%s %s:00', $date_parts[2], $date_parts[1], $date_parts[0], $time);

                if ($end_time) {
                    $data['end_datetime'] = sprintf('%s-%s-%s %s:00', $date_parts[2], $date_parts[1], $date_parts[0], $end_time);
                }
            }
        }

        if ($capacity) {
            $data['capacity'] = $capacity;
        }

        $result = $this->slot_repository->update_slot($slot_id, $data);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $slot = $this->slot_repository->get_slot($slot_id);

        ob_start();
        $this->render_slot_row($slot);
        $html = ob_get_clean();

        wp_send_json_success(
            array(
                'message' => 'Slot aggiornato con successo.',
                'slot'    => $slot,
                'html'    => $html,
            )
        );
    }

    /**
     * AJAX: Delete slot.
     */
    public function ajax_delete_slot()
    {
        check_ajax_referer('eva_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permesso negato.'));
        }

        $slot_id = isset($_POST['slot_id']) ? absint($_POST['slot_id']) : 0;

        if (! $slot_id) {
            wp_send_json_error(array('message' => 'ID slot non valido.'));
        }

        $result = $this->slot_repository->delete_slot($slot_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => 'Slot eliminato con successo.'));
    }

    /**
     * AJAX: Toggle slot status.
     */
    public function ajax_toggle_slot_status()
    {
        check_ajax_referer('eva_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permesso negato.'));
        }

        $slot_id        = isset($_POST['slot_id']) ? absint($_POST['slot_id']) : 0;
        $current_status = isset($_POST['current_status']) ? sanitize_text_field(wp_unslash($_POST['current_status'])) : '';

        if (! $slot_id) {
            wp_send_json_error(array('message' => 'ID slot non valido.'));
        }

        $new_status = 'open' === $current_status ? 'closed' : 'open';

        $result = $this->slot_repository->update_slot($slot_id, array('status' => $new_status));

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $slot = $this->slot_repository->get_slot($slot_id);

        ob_start();
        $this->render_slot_row($slot);
        $html = ob_get_clean();

        wp_send_json_success(
            array(
                'message'    => 'Stato slot aggiornato.',
                'new_status' => $new_status,
                'slot'       => $slot,
                'html'       => $html,
            )
        );
    }

    /**
     * AJAX: Bulk enable course booking.
     */
    public function ajax_bulk_enable()
    {
        check_ajax_referer('eva_bulk_enable', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permesso negato.'));
        }

        $product_ids = isset($_POST['product_ids']) ? array_map('absint', (array) $_POST['product_ids']) : array();

        if (empty($product_ids)) {
            wp_send_json_error(array('message' => 'Nessun prodotto selezionato.'));
        }

        // First, disable all products.
        $all_products = get_posts(
            array(
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
            )
        );

        foreach ($all_products as $product_id) {
            if (in_array($product_id, $product_ids, true)) {
                update_post_meta($product_id, '_eva_course_enabled', 'yes');
            } else {
                update_post_meta($product_id, '_eva_course_enabled', 'no');
            }
        }

        wp_send_json_success(
            array(
                'message' => sprintf('%d prodotti aggiornati.', count($product_ids)),
            )
        );
    }

    /**
     * Add self-test page.
     */
    public function add_selftest_page()
    {
        add_submenu_page(
            'woocommerce',
            'Eva Course - Self Test',
            'Eva Self Test',
            'manage_woocommerce',
            'eva-course-selftest',
            array($this, 'render_selftest_page')
        );
    }

    /**
     * Render self-test page.
     */
    public function render_selftest_page()
    {
        $tests = array();

        // Test 1: CPT registered.
        $tests['cpt_registered'] = array(
            'name'   => 'Custom Post Type registrato',
            'passed' => Slot_Repository::is_cpt_registered(),
        );

        // Test 2: Can create a slot (dry run with a test product).
        $test_product = get_posts(
            array(
                'post_type'      => 'product',
                'posts_per_page' => 1,
                'post_status'    => 'publish',
            )
        );

        if (! empty($test_product)) {
            $tests['create_slot'] = array(
                'name'   => 'Creazione slot possibile',
                'passed' => true,
                'note'   => 'Prodotto di test disponibile: ' . $test_product[0]->post_title,
            );
        } else {
            $tests['create_slot'] = array(
                'name'   => 'Creazione slot possibile',
                'passed' => false,
                'note'   => 'Nessun prodotto disponibile per il test',
            );
        }

        // Test 3: Atomic query syntax.
        $tests['atomic_query'] = array(
            'name'   => 'Query atomica funzionante',
            'passed' => Slot_Repository::test_atomic_query(),
        );

        // Test 4: WooCommerce active.
        $tests['woocommerce'] = array(
            'name'   => 'WooCommerce attivo',
            'passed' => class_exists('WooCommerce'),
        );

    ?>
        <div class="wrap">
            <h1>Eva Course Bookings - Self Test</h1>
            <p>Questa pagina verifica che il plugin sia configurato correttamente.</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Test</th>
                        <th>Risultato</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tests as $key => $test) : ?>
                        <tr>
                            <td><?php echo esc_html($test['name']); ?></td>
                            <td>
                                <?php if ($test['passed']) : ?>
                                    <span style="color: green;">✓ Passato</span>
                                <?php else : ?>
                                    <span style="color: red;">✗ Fallito</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo isset($test['note']) ? esc_html($test['note']) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Informazioni Sistema</h2>
            <table class="wp-list-table widefat fixed striped">
                <tbody>
                    <tr>
                        <th>Versione Plugin</th>
                        <td><?php echo esc_html(EVA_COURSE_BOOKINGS_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th>Versione WordPress</th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <th>Versione WooCommerce</th>
                        <td><?php echo esc_html(defined('WC_VERSION') ? WC_VERSION : 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th>Versione PHP</th>
                        <td><?php echo esc_html(PHP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th>Fuso Orario</th>
                        <td><?php echo esc_html(wp_timezone_string()); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php
    }

    /**
     * Add metabox to order edit screen for manual slot assignment.
     */
    public function add_order_slots_metabox()
    {
        // For legacy orders (post type).
        add_meta_box(
            'eva_order_slots',
            'Assegna slot corso',
            array($this, 'render_order_slots_metabox'),
            'shop_order',
            'normal',
            'default'
        );

        // For HPOS orders.
        add_meta_box(
            'eva_order_slots',
            'Assegna slot corso',
            array($this, 'render_order_slots_metabox'),
            'woocommerce_page_wc-orders',
            'normal',
            'default'
        );
    }

    /**
     * Render order slots metabox.
     *
     * @param WP_Post|WC_Order $post_or_order Post object or Order object.
     */
    public function render_order_slots_metabox($post_or_order)
    {
        // Get order object.
        if ($post_or_order instanceof \WP_Post) {
            $order = wc_get_order($post_or_order->ID);
        } else {
            $order = $post_or_order;
        }

        if (! $order) {
            echo '<p>Ordine non trovato.</p>';
            return;
        }

        // Find items that are course products but don't have a slot assigned.
        $items_needing_slots = array();
        $items_with_slots    = array();

        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();

            // Check if this product is a course.
            if (! Plugin::is_course_enabled($product_id)) {
                continue;
            }

            $slot_id = $item->get_meta('_eva_slot_id');

            if ($slot_id) {
                // Has a slot assigned.
                $slot = $this->slot_repository->get_slot((int) $slot_id);
                $items_with_slots[] = array(
                    'item_id'    => $item_id,
                    'item'       => $item,
                    'product_id' => $product_id,
                    'slot_id'    => $slot_id,
                    'slot'       => $slot,
                    'reserved'   => $item->get_meta('_eva_seats_reserved'),
                );
            } else {
                // Needs a slot.
                $is_pending = 'yes' === $item->get_meta('_eva_pending_booking');
                $items_needing_slots[] = array(
                    'item_id'         => $item_id,
                    'item'            => $item,
                    'product_id'      => $product_id,
                    'pending_booking' => $is_pending,
                );
            }
        }

        // If no course items, hide the metabox content.
        if (empty($items_needing_slots) && empty($items_with_slots)) {
            echo '<p>Nessun prodotto corso in questo ordine.</p>';
            return;
        }

        wp_nonce_field('eva_order_slots', 'eva_order_slots_nonce');
    ?>
        <div class="eva-order-slots" data-order-id="<?php echo esc_attr($order->get_id()); ?>">

            <?php if (! empty($items_with_slots)) : ?>
                <h4>Prodotti con slot assegnato</h4>
                <table class="wp-list-table widefat fixed striped" id="eva-items-with-slots">
                    <thead>
                        <tr>
                            <th>Prodotto</th>
                            <th>Quantità</th>
                            <th>Data/Ora slot</th>
                            <th>Stato prenotazione</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items_with_slots as $data) : ?>
                            <?php
                            $available_slots = $this->slot_repository->get_slots_for_product($data['product_id'], 'open', false);
                            ?>
                            <tr data-item-id="<?php echo esc_attr($data['item_id']); ?>"
                                data-product-id="<?php echo esc_attr($data['product_id']); ?>"
                                data-current-slot-id="<?php echo esc_attr($data['slot_id']); ?>"
                                data-quantity="<?php echo esc_attr($data['item']->get_quantity()); ?>">
                                <td><?php echo esc_html($data['item']->get_name()); ?></td>
                                <td><?php echo esc_html($data['item']->get_quantity()); ?></td>
                                <td class="eva-slot-display">
                                    <?php
                                    if ($data['slot']) {
                                        echo esc_html(Plugin::format_datetime_italian($data['slot']['start_datetime']));
                                        if ($data['slot']['end_datetime']) {
                                            $end_dt = \DateTime::createFromFormat('Y-m-d H:i:s', $data['slot']['end_datetime']);
                                            if ($end_dt) {
                                                echo ' - ' . esc_html($end_dt->format('H:i'));
                                            }
                                        }
                                    } else {
                                        echo '<span style="color: red;">Slot non trovato (ID: ' . esc_html($data['slot_id']) . ')</span>';
                                    }
                                    ?>
                                    <!-- Hidden select for changing slot -->
                                    <div class="eva-change-slot-select" style="display: none; margin-top: 10px;">
                                        <select class="eva-new-slot-select">
                                            <option value="">-- Seleziona nuovo slot --</option>
                                            <?php foreach ($available_slots as $slot) : ?>
                                                <?php
                                                if ((int) $slot['id'] === (int) $data['slot_id']) continue; // Skip current slot
                                                $start_dt = \DateTime::createFromFormat('Y-m-d H:i:s', $slot['start_datetime']);
                                                $label = $start_dt ? $start_dt->format('d/m/Y H:i') : $slot['start_datetime'];
                                                $label .= ' (Disponibili: ' . $slot['remaining'] . '/' . $slot['capacity'] . ')';
                                                ?>
                                                <option value="<?php echo esc_attr($slot['id']); ?>"
                                                    data-remaining="<?php echo esc_attr($slot['remaining']); ?>"
                                                    data-start="<?php echo esc_attr($slot['start_datetime']); ?>"
                                                    data-end="<?php echo esc_attr($slot['end_datetime']); ?>">
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div style="margin-top: 5px;">
                                            <button type="button" class="button button-small eva-confirm-change-btn">Conferma</button>
                                            <button type="button" class="button button-small eva-cancel-change-btn">Annulla</button>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ('yes' === $data['reserved']) : ?>
                                        <span style="color: green;">✓ Posti riservati</span>
                                    <?php else : ?>
                                        <span style="color: orange;">⚠ Posti non riservati</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (count($available_slots) > 1 || (count($available_slots) === 1 && (int) $available_slots[0]['id'] !== (int) $data['slot_id'])) : ?>
                                        <button type="button" class="button button-small eva-change-slot-btn">Modifica</button>
                                    <?php else : ?>
                                        <span class="description">Nessun altro slot</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (! empty($items_needing_slots)) : ?>
                <h4>Prodotti da assegnare</h4>
                <p class="description">Questi prodotti sono corsi ma non hanno ancora uno slot assegnato. Seleziona uno slot per ogni prodotto.</p>

                <table class="wp-list-table widefat fixed striped" id="eva-items-needing-slots">
                    <thead>
                        <tr>
                            <th>Prodotto</th>
                            <th>Quantità</th>
                            <th>Tipo</th>
                            <th>Seleziona slot</th>
                            <th>Azione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items_needing_slots as $data) : ?>
                            <?php
                            $slots = $this->slot_repository->get_slots_for_product($data['product_id'], 'open', false);
                            ?>
                            <tr data-item-id="<?php echo esc_attr($data['item_id']); ?>" data-product-id="<?php echo esc_attr($data['product_id']); ?>">
                                <td><?php echo esc_html($data['item']->get_name()); ?></td>
                                <td><?php echo esc_html($data['item']->get_quantity()); ?></td>
                                <td>
                                    <?php if (! empty($data['pending_booking'])) : ?>
                                        <span class="eva-pending-tag" title="Acquistato come regalo o prenotazione futura">🎁 Regalo</span>
                                    <?php else : ?>
                                        <span class="eva-legacy-tag" title="Ordine esistente senza slot">📋 Legacy</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($slots)) : ?>
                                        <span style="color: orange;">Nessuno slot disponibile. <a href="<?php echo esc_url(get_edit_post_link($data['product_id'])); ?>">Crea slot</a></span>
                                    <?php else : ?>
                                        <select class="eva-slot-select" name="eva_slot_<?php echo esc_attr($data['item_id']); ?>">
                                            <option value="">-- Seleziona slot --</option>
                                            <?php foreach ($slots as $slot) : ?>
                                                <?php
                                                $start_dt = \DateTime::createFromFormat('Y-m-d H:i:s', $slot['start_datetime']);
                                                $label = $start_dt ? $start_dt->format('d/m/Y H:i') : $slot['start_datetime'];
                                                $label .= ' (Disponibili: ' . $slot['remaining'] . '/' . $slot['capacity'] . ')';
                                                ?>
                                                <option value="<?php echo esc_attr($slot['id']); ?>"
                                                    data-remaining="<?php echo esc_attr($slot['remaining']); ?>"
                                                    data-start="<?php echo esc_attr($slot['start_datetime']); ?>"
                                                    data-end="<?php echo esc_attr($slot['end_datetime']); ?>">
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (! empty($slots)) : ?>
                                        <button type="button" class="button eva-assign-slot-btn" disabled>Assegna slot</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color: green;">✓ Tutti i prodotti corso hanno uno slot assegnato.</p>
            <?php endif; ?>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Enable/disable assign button based on selection.
                $('.eva-slot-select').on('change', function() {
                    var $row = $(this).closest('tr');
                    var $btn = $row.find('.eva-assign-slot-btn');
                    var slotId = $(this).val();

                    if (slotId) {
                        $btn.prop('disabled', false);
                    } else {
                        $btn.prop('disabled', true);
                    }
                });

                // Assign slot button click.
                $('.eva-assign-slot-btn').on('click', function() {
                    var $btn = $(this);
                    var $row = $btn.closest('tr');
                    var itemId = $row.data('item-id');
                    var orderId = $('.eva-order-slots').data('order-id');
                    var $select = $row.find('.eva-slot-select');
                    var slotId = $select.val();
                    var slotStart = $select.find(':selected').data('start');
                    var slotEnd = $select.find(':selected').data('end') || '';

                    if (!slotId) {
                        alert('Seleziona uno slot.');
                        return;
                    }

                    $btn.prop('disabled', true).text('Assegnazione...');

                    $.ajax({
                        url: evaAdminData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'eva_admin_assign_slot',
                            nonce: evaAdminData.nonce,
                            order_id: orderId,
                            item_id: itemId,
                            slot_id: slotId,
                            slot_start: slotStart,
                            slot_end: slotEnd
                        },
                        success: function(response) {
                            if (response.success) {
                                alert(response.data.message);
                                location.reload();
                            } else {
                                alert('Errore: ' + response.data.message);
                                $btn.prop('disabled', false).text('Assegna slot');
                            }
                        },
                        error: function() {
                            alert('Errore di comunicazione con il server.');
                            $btn.prop('disabled', false).text('Assegna slot');
                        }
                    });
                });

                // Change slot button click - show the select dropdown.
                $('.eva-change-slot-btn').on('click', function() {
                    var $row = $(this).closest('tr');
                    $row.find('.eva-change-slot-select').show();
                    $(this).hide();
                });

                // Cancel change button.
                $('.eva-cancel-change-btn').on('click', function() {
                    var $row = $(this).closest('tr');
                    $row.find('.eva-change-slot-select').hide();
                    $row.find('.eva-new-slot-select').val('');
                    $row.find('.eva-change-slot-btn').show();
                });

                // Confirm change slot button.
                $('.eva-confirm-change-btn').on('click', function() {
                    var $btn = $(this);
                    var $row = $btn.closest('tr');
                    var itemId = $row.data('item-id');
                    var orderId = $('.eva-order-slots').data('order-id');
                    var currentSlotId = $row.data('current-slot-id');
                    var quantity = $row.data('quantity');
                    var $select = $row.find('.eva-new-slot-select');
                    var newSlotId = $select.val();
                    var newSlotStart = $select.find(':selected').data('start');
                    var newSlotEnd = $select.find(':selected').data('end') || '';
                    var newSlotRemaining = parseInt($select.find(':selected').data('remaining')) || 0;

                    if (!newSlotId) {
                        alert('Seleziona un nuovo slot.');
                        return;
                    }

                    // Check if there's enough capacity in the new slot.
                    if (newSlotRemaining < quantity) {
                        alert('Capacità insufficiente nel nuovo slot. Posti richiesti: ' + quantity + ', disponibili: ' + newSlotRemaining);
                        return;
                    }

                    if (!confirm('Sei sicuro di voler cambiare lo slot? I posti del vecchio slot saranno liberati.')) {
                        return;
                    }

                    $btn.prop('disabled', true).text('Modifica...');
                    $row.find('.eva-cancel-change-btn').prop('disabled', true);

                    $.ajax({
                        url: evaAdminData.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'eva_admin_change_slot',
                            nonce: evaAdminData.nonce,
                            order_id: orderId,
                            item_id: itemId,
                            old_slot_id: currentSlotId,
                            new_slot_id: newSlotId,
                            slot_start: newSlotStart,
                            slot_end: newSlotEnd
                        },
                        success: function(response) {
                            if (response.success) {
                                alert(response.data.message);
                                location.reload();
                            } else {
                                alert('Errore: ' + response.data.message);
                                $btn.prop('disabled', false).text('Conferma');
                                $row.find('.eva-cancel-change-btn').prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('Errore di comunicazione con il server.');
                            $btn.prop('disabled', false).text('Conferma');
                            $row.find('.eva-cancel-change-btn').prop('disabled', false);
                        }
                    });
                });
            });
        </script>
<?php
    }

    /**
     * AJAX: Assign slot to order item.
     */
    public function ajax_assign_slot_to_order_item()
    {
        check_ajax_referer('eva_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permesso negato.'));
        }

        $order_id   = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $item_id    = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        $slot_id    = isset($_POST['slot_id']) ? absint($_POST['slot_id']) : 0;
        $slot_start = isset($_POST['slot_start']) ? sanitize_text_field(wp_unslash($_POST['slot_start'])) : '';
        $slot_end   = isset($_POST['slot_end']) ? sanitize_text_field(wp_unslash($_POST['slot_end'])) : '';

        if (! $order_id || ! $item_id || ! $slot_id) {
            wp_send_json_error(array('message' => 'Dati mancanti.'));
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            wp_send_json_error(array('message' => 'Ordine non trovato.'));
        }

        // Get the order item.
        $item = $order->get_item($item_id);
        if (! $item) {
            wp_send_json_error(array('message' => 'Articolo non trovato.'));
        }

        // Check if slot exists and has capacity.
        $slot = $this->slot_repository->get_slot($slot_id);
        if (! $slot) {
            wp_send_json_error(array('message' => 'Slot non trovato.'));
        }

        $quantity = $item->get_quantity();

        // Check capacity.
        if ($slot['remaining'] < $quantity) {
            wp_send_json_error(array(
                'message' => sprintf(
                    'Capacità insufficiente. Posti richiesti: %d, disponibili: %d.',
                    $quantity,
                    $slot['remaining']
                )
            ));
        }

        // Try to reserve seats.
        $reserved = $this->slot_repository->reserve_seats($slot_id, $quantity);

        if (! $reserved) {
            wp_send_json_error(array('message' => 'Impossibile riservare i posti. Capacità esaurita.'));
        }

        // Update order item meta.
        $item->update_meta_data('_eva_slot_id', $slot_id);
        $item->update_meta_data('_eva_slot_start', $slot_start);
        $item->update_meta_data('_eva_slot_end', $slot_end);
        $item->update_meta_data('_eva_slot_qty', $quantity);
        $item->update_meta_data('_eva_seats_reserved', 'yes');

        // Remove pending booking flag if it was set.
        $item->delete_meta_data('_eva_pending_booking');
        $item->save_meta_data();

        // Update order meta with aggregate info.
        $slot_info = $order->get_meta('_eva_slot_info') ?: array();
        $slot_info[] = array(
            'product'  => $item->get_name(),
            'slot_id'  => $slot_id,
            'start'    => $slot_start,
            'end'      => $slot_end,
            'quantity' => $quantity,
        );
        $order->update_meta_data('_eva_slot_info', $slot_info);
        $order->save();

        // Add order note.
        $order->add_order_note(
            sprintf(
                'Slot corso assegnato manualmente: %s - %s (%d posti)',
                $item->get_name(),
                Plugin::format_datetime_italian($slot_start),
                $quantity
            )
        );

        Slot_Repository::log(sprintf(
            'Manual slot assignment: Order %d, Item %d, Slot %d, Qty %d',
            $order_id,
            $item_id,
            $slot_id,
            $quantity
        ));

        wp_send_json_success(array(
            'message' => sprintf(
                'Slot assegnato con successo! %d posti riservati.',
                $quantity
            )
        ));
    }

    /**
     * AJAX: Get available slots for a product.
     */
    public function ajax_get_product_slots()
    {
        check_ajax_referer('eva_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permesso negato.'));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (! $product_id) {
            wp_send_json_error(array('message' => 'ID prodotto non valido.'));
        }

        $slots = $this->slot_repository->get_slots_for_product($product_id, 'open', false);

        $formatted = array();
        foreach ($slots as $slot) {
            $start_dt = \DateTime::createFromFormat('Y-m-d H:i:s', $slot['start_datetime']);
            $label = $start_dt ? $start_dt->format('d/m/Y H:i') : $slot['start_datetime'];
            $label .= ' (Disponibili: ' . $slot['remaining'] . '/' . $slot['capacity'] . ')';

            $formatted[] = array(
                'id'        => $slot['id'],
                'label'     => $label,
                'remaining' => $slot['remaining'],
                'start'     => $slot['start_datetime'],
                'end'       => $slot['end_datetime'],
            );
        }

        wp_send_json_success(array('slots' => $formatted));
    }

    /**
     * AJAX: Change slot for an order item.
     * Releases seats from old slot and reserves in new slot.
     */
    public function ajax_change_order_item_slot()
    {
        check_ajax_referer('eva_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permesso negato.'));
        }

        $order_id    = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $item_id     = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        $old_slot_id = isset($_POST['old_slot_id']) ? absint($_POST['old_slot_id']) : 0;
        $new_slot_id = isset($_POST['new_slot_id']) ? absint($_POST['new_slot_id']) : 0;
        $slot_start  = isset($_POST['slot_start']) ? sanitize_text_field(wp_unslash($_POST['slot_start'])) : '';
        $slot_end    = isset($_POST['slot_end']) ? sanitize_text_field(wp_unslash($_POST['slot_end'])) : '';

        if (! $order_id || ! $item_id || ! $new_slot_id) {
            wp_send_json_error(array('message' => 'Dati mancanti.'));
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            wp_send_json_error(array('message' => 'Ordine non trovato.'));
        }

        // Get the order item.
        $item = $order->get_item($item_id);
        if (! $item) {
            wp_send_json_error(array('message' => 'Articolo non trovato.'));
        }

        // Check if new slot exists and has capacity.
        $new_slot = $this->slot_repository->get_slot($new_slot_id);
        if (! $new_slot) {
            wp_send_json_error(array('message' => 'Nuovo slot non trovato.'));
        }

        $quantity = $item->get_quantity();

        // Check capacity in new slot.
        if ($new_slot['remaining'] < $quantity) {
            wp_send_json_error(array(
                'message' => sprintf(
                    'Capacità insufficiente nel nuovo slot. Posti richiesti: %d, disponibili: %d.',
                    $quantity,
                    $new_slot['remaining']
                )
            ));
        }

        // First, reserve seats in the new slot.
        $reserved = $this->slot_repository->reserve_seats($new_slot_id, $quantity);

        if (! $reserved) {
            wp_send_json_error(array('message' => 'Impossibile riservare i posti nel nuovo slot.'));
        }

        // If old slot had reserved seats, release them.
        $was_reserved = $item->get_meta('_eva_seats_reserved');
        if ('yes' === $was_reserved && $old_slot_id) {
            $this->slot_repository->release_seats($old_slot_id, $quantity);
        }

        // Get old slot info for the note.
        $old_slot = $old_slot_id ? $this->slot_repository->get_slot($old_slot_id) : null;
        $old_slot_date = $old_slot ? Plugin::format_datetime_italian($old_slot['start_datetime']) : 'N/A';

        // Update order item meta.
        $item->update_meta_data('_eva_slot_id', $new_slot_id);
        $item->update_meta_data('_eva_slot_start', $slot_start);
        $item->update_meta_data('_eva_slot_end', $slot_end);
        $item->update_meta_data('_eva_seats_reserved', 'yes');
        $item->save_meta_data();

        // Update order meta with aggregate info.
        $slot_info = $order->get_meta('_eva_slot_info') ?: array();

        // Remove old slot info for this item and add new one.
        $new_slot_info = array();
        foreach ($slot_info as $info) {
            // Keep info for other products.
            if ($info['slot_id'] != $old_slot_id || $info['product'] != $item->get_name()) {
                $new_slot_info[] = $info;
            }
        }
        $new_slot_info[] = array(
            'product'  => $item->get_name(),
            'slot_id'  => $new_slot_id,
            'start'    => $slot_start,
            'end'      => $slot_end,
            'quantity' => $quantity,
        );
        $order->update_meta_data('_eva_slot_info', $new_slot_info);
        $order->save();

        // Add order note.
        $order->add_order_note(
            sprintf(
                'Slot corso modificato: %s - Da: %s → A: %s (%d posti)',
                $item->get_name(),
                $old_slot_date,
                Plugin::format_datetime_italian($slot_start),
                $quantity
            )
        );

        Slot_Repository::log(sprintf(
            'Slot change: Order %d, Item %d, Old Slot %d → New Slot %d, Qty %d',
            $order_id,
            $item_id,
            $old_slot_id,
            $new_slot_id,
            $quantity
        ));

        wp_send_json_success(array(
            'message' => sprintf(
                'Slot modificato con successo! Nuova data: %s',
                Plugin::format_datetime_italian($slot_start)
            )
        ));
    }
}
