<?php
/**
 * Completed-order confirmation for purchases containing only Eva courses.
 *
 * @package Eva_Course_Bookings
 */

namespace Eva_Course_Bookings;

defined('ABSPATH') || exit;

class WC_Email_Course_Completed_Order extends \WC_Email
{
    public function __construct()
    {
        $this->id = 'eva_course_completed_order';
        $this->customer_email = true;
        $this->title = __('Conferma acquisto corsi', 'eva-course-bookings');
        $this->description = __('Inviata quando un ordine composto solo da corsi Eva viene completato. Sostituisce l’email standard di ordine completato per questi ordini.', 'eva-course-bookings');
        $this->template_html = 'emails/course-completed-order.php';
        $this->template_plain = 'emails/plain/course-completed-order.php';
        $this->template_base = EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'templates/';
        $this->placeholders = array('{order_number}' => '', '{order_date}' => '');

        // Preserve the store's previous completion-email preference on first use.
        $completed_settings = get_option('woocommerce_customer_completed_order_settings', array());
        add_option('woocommerce_' . $this->id . '_settings', array(
            'enabled' => $completed_settings['enabled'] ?? 'yes',
        ));

        parent::__construct();
        add_action('woocommerce_order_status_completed_notification', array($this, 'trigger'), 10, 2);
        add_filter('woocommerce_email_styles', array($this, 'email_styles'), 10, 2);
    }

    public function get_default_subject()
    {
        return __('Conferma acquisto corsi – ordine #{order_number} – {site_title}', 'eva-course-bookings');
    }

    public function get_default_heading()
    {
        return __('Il tuo acquisto di corsi è confermato', 'eva-course-bookings');
    }

    public function get_default_additional_content()
    {
        return __('Per qualsiasi domanda sui corsi o sulla prenotazione, contattaci.', 'eva-course-bookings');
    }

    /**
     * Send only for course-only orders, using WooCommerce's normal mail lifecycle.
     *
     * @param int             $order_id Order ID.
     * @param \WC_Order|false $order    Order object.
     * @return bool
     */
    public function trigger($order_id, $order = false)
    {
        $this->object = false;
        $this->recipient = '';
        if (! $order instanceof \WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (! Plugin::is_course_only_order($order)) {
            return false;
        }

        $this->setup_locale();
        try {
            $this->object = $order;
            $this->recipient = $order->get_billing_email();
            $this->placeholders['{order_number}'] = $order->get_order_number();
            $this->placeholders['{order_date}'] = $order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '';

            if (! $this->is_enabled() || ! $this->get_recipient()) {
                return false;
            }

            return $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        } finally {
            $this->restore_locale();
        }
    }

    public function get_content_html()
    {
        return $this->get_template_content(false);
    }

    public function get_content_plain()
    {
        return $this->get_template_content(true);
    }

    /**
     * Keep course instructions readable on small screens.
     *
     * @param string    $styles Email CSS.
     * @param \WC_Email $email  Email being rendered.
     * @return string
     */
    public function email_styles($styles, $email)
    {
        if ($email && $this->id === $email->id) {
            $styles .= "\n@media screen and (max-width: 600px) { #body_content_inner { font-size: 14px !important; } }";
        }
        return $styles;
    }

    private function get_template_content($plain_text)
    {
        if (! $this->object instanceof \WC_Order) {
            return '';
        }

        $has_pending_booking = false;
        foreach ($this->object->get_items() as $item) {
            if ('yes' === $item->get_meta('_eva_pending_booking') && ! $item->get_meta('_eva_slot_id')) {
                $has_pending_booking = true;
                break;
            }
        }

        return wc_get_template_html(
            $plain_text ? $this->template_plain : $this->template_html,
            array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => $plain_text,
                'email' => $this,
                'has_pending_booking' => $has_pending_booking,
                'booking_url' => $this->get_booking_page_url(),
            ),
            '',
            $this->template_base
        );
    }

    /**
     * Never send customers to a missing, unpublished, or unrelated page.
     *
     * @return string
     */
    public function get_booking_page_url()
    {
        $page_id = absint($this->get_option('booking_page_id', 0));
        $page = $page_id ? get_post($page_id) : null;
        if (! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status || ! has_shortcode($page->post_content, 'eva_course_bookings_manage')) {
            return '';
        }

        return get_permalink($page) ?: '';
    }

    public function init_form_fields()
    {
        parent::init_form_fields();
        $this->form_fields['enabled']['description'] = __('Se disabilitata, gli ordini composti solo da corsi non riceveranno un’email di completamento.', 'eva-course-bookings');
        $placeholder_text = __('Segnaposto disponibili: {site_title}, {order_number}, {order_date}.', 'eva-course-bookings');
        foreach (array('subject', 'heading', 'additional_content') as $field) {
            $this->form_fields[$field]['description'] = $placeholder_text;
        }

        $pages = array('' => __('Seleziona una pagina', 'eva-course-bookings'));
        foreach (get_pages(array('post_status' => 'publish')) as $page) {
            if (has_shortcode($page->post_content, 'eva_course_bookings_manage')) {
                $pages[$page->ID] = $page->post_title;
            }
        }
        $this->form_fields['booking_page_id'] = array(
            'title' => __('Pagina gestione prenotazioni', 'eva-course-bookings'),
            'type' => 'select',
            'default' => '',
            'options' => $pages,
            'description' => __('Pubblica una pagina con lo shortcode [eva_course_bookings_manage], poi selezionala qui. Il link viene mostrato solo per i corsi con data ancora da scegliere. Senza una pagina valida verrà chiesto di contattare il negozio.', 'eva-course-bookings'),
        );
    }
}
