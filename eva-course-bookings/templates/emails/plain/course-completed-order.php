<?php
/**
 * Course purchase confirmation (plain text).
 *
 * Override in yourtheme/woocommerce/emails/plain/course-completed-order.php.
 *
 * @package Eva_Course_Bookings
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

echo wp_strip_all_tags($email_heading) . "\n\n";
if ($order->get_billing_first_name()) {
    /* translators: %s: Customer first name. */
    echo sprintf(__('Ciao %s,', 'eva-course-bookings'), wp_strip_all_tags($order->get_billing_first_name())) . "\n\n";
} else {
    echo __('Ciao,', 'eva-course-bookings') . "\n\n";
}
echo __('Grazie per il tuo acquisto. Qui trovi il riepilogo dei tuoi corsi e le informazioni sulla prenotazione.', 'eva-course-bookings') . "\n\n";

if ($has_pending_booking) {
    echo __('Scegli la data dei tuoi corsi', 'eva-course-bookings') . "\n\n";
    if ($booking_url) {
        echo __('Per i corsi con data da scegliere, apri Gestisci prenotazione e inserisci il numero indicato qui sotto e l’email usata per l’acquisto. Per i regali puoi usare anche l’email del destinatario indicata nell’ordine.', 'eva-course-bookings') . "\n\n";
        /* translators: %d: Internal order ID used by the booking portal. */
        echo sprintf(__('Numero per gestire la prenotazione: %d', 'eva-course-bookings'), $order->get_id()) . "\n";
        echo __('Gestisci prenotazione:', 'eva-course-bookings') . ' ' . esc_url_raw($booking_url) . "\n\n";
    } else {
        echo __('Contattaci per scegliere data e orario dei corsi con data ancora da definire.', 'eva-course-bookings') . "\n\n";
    }
}

do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);

echo "\n" . __('Dati di fatturazione', 'eva-course-bookings') . "\n";
echo wp_strip_all_tags(preg_replace('/<br\s*\/?\s*>/i', "\n", $order->get_formatted_billing_address())) . "\n";
echo wp_strip_all_tags($order->get_billing_phone()) . "\n";
echo wp_strip_all_tags($order->get_billing_email()) . "\n\n";

if ($additional_content) {
    echo wp_strip_all_tags(wptexturize($additional_content)) . "\n\n";
}
echo wp_strip_all_tags(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
