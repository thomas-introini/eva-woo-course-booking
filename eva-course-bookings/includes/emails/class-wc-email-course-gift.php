<?php
/** Recipient-only course gift email. */
namespace Eva_Course_Bookings;

defined('ABSPATH') || exit;

class WC_Email_Course_Gift extends \WC_Email
{
    public $gift_item;
    public $gift_data;
    private $voucher_path;

    public function __construct()
    {
        $this->id = 'eva_course_gift';
        $this->customer_email = true;
        $this->title = 'Buono regalo corso';
        $this->description = 'Buono PDF personalizzato inviato al destinatario dopo il pagamento, subito o alla data scelta.';
        $this->template_html = 'emails/course-gift.php';
        $this->template_plain = 'emails/plain/course-gift.php';
        $this->template_base = EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'templates/';
        $this->placeholders = array('{course_name}' => '', '{recipient_name}' => '', '{sender_name}' => '');
        parent::__construct();
    }

    public function get_default_subject()
    {
        return 'Un regalo per te: {course_name} – {site_title}';
    }

    public function get_default_heading()
    {
        return 'Hai ricevuto un corso in regalo!';
    }

    public function trigger($order, $item, $path)
    {
        $this->setup_locale();
        try {
            $this->object = $order;
            $this->gift_item = $item;
            $this->gift_data = $item->get_meta('_eva_gift_data');
            $this->voucher_path = $path;
            $this->recipient = $item->get_meta('_eva_gift_email');
            $this->placeholders['{course_name}'] = $item->get_name();
            $this->placeholders['{recipient_name}'] = $this->gift_data['recipient'];
            $this->placeholders['{sender_name}'] = $this->gift_data['sender'];
            if (! $this->is_enabled() || ! is_email($this->recipient)) {
                return false;
            }
            return $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        } finally {
            $this->restore_locale();
        }
    }

    public function get_attachments()
    {
        return $this->voucher_path ? array($this->voucher_path) : array();
    }

    public function get_content_html()
    {
        return $this->content(false);
    }

    public function get_content_plain()
    {
        return $this->content(true);
    }

    private function content($plain)
    {
        if (! $this->object instanceof \WC_Order || ! $this->gift_item instanceof \WC_Order_Item_Product) {
            return '';
        }
        $emails = WC()->mailer()->get_emails();
        $booking_url = isset($emails['WC_Email_Course_Completed_Order']) ? $emails['WC_Email_Course_Completed_Order']->get_booking_page_url() : '';
        return wc_get_template_html($plain ? $this->template_plain : $this->template_html, array(
            'email' => $this, 'email_heading' => $this->get_heading(), 'gift' => $this->gift_data,
            'course' => $this->gift_item->get_name(), 'quantity' => Gift_Delivery::remaining($this->object, $this->gift_item),
            'reference' => $this->object->get_id(), 'recipient' => $this->recipient, 'booking_url' => $booking_url,
            'additional_content' => $this->get_additional_content(),
        ), '', $this->template_base);
    }
}
