<?php
/**
 * Course purchase confirmation (HTML).
 *
 * Override in yourtheme/woocommerce/emails/course-completed-order.php.
 *
 * @package Eva_Course_Bookings
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p>
    <?php
    if ($order->get_billing_first_name()) {
        /* translators: %s: Customer first name. */
        printf(esc_html__('Ciao %s,', 'eva-course-bookings'), esc_html($order->get_billing_first_name()));
    } else {
        esc_html_e('Ciao,', 'eva-course-bookings');
    }
    ?>
</p>
<p><?php esc_html_e('Grazie per il tuo acquisto. Qui trovi il riepilogo dei tuoi corsi e le informazioni sulla prenotazione.', 'eva-course-bookings'); ?></p>

<?php if ($has_pending_booking) : ?>
    <h2><?php esc_html_e('Scegli la data dei tuoi corsi', 'eva-course-bookings'); ?></h2>
    <?php if ($booking_url) : ?>
        <p><?php esc_html_e('Per i corsi con data da scegliere, apri Gestisci prenotazione e inserisci il numero indicato qui sotto e l’email usata per l’acquisto. Per i regali puoi usare anche l’email del destinatario indicata nell’ordine.', 'eva-course-bookings'); ?></p>
        <p>
            <?php
            /* translators: %d: Internal order ID used by the booking portal. */
            printf(esc_html__('Numero per gestire la prenotazione: %d', 'eva-course-bookings'), $order->get_id());
            $button_color = sanitize_hex_color(get_option('woocommerce_email_base_color', '#7f54b3')) ?: '#7f54b3';
            $button_text_color = wc_light_or_dark($button_color, '#202020', '#ffffff');
            ?>
        </p>
        <p><a class="button" href="<?php echo esc_url($booking_url); ?>" style="display: inline-block; padding: 12px 18px; background-color: <?php echo esc_attr($button_color); ?>; color: <?php echo esc_attr($button_text_color); ?>; border-radius: 4px; font-weight: bold; text-decoration: none;"><?php esc_html_e('Gestisci prenotazione', 'eva-course-bookings'); ?></a></p>
    <?php else : ?>
        <p><?php esc_html_e('Contattaci per scegliere data e orario dei corsi con data ancora da definire.', 'eva-course-bookings'); ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);
?>

<h2><?php esc_html_e('Dati di fatturazione', 'eva-course-bookings'); ?></h2>
<address class="address" style="font-style: normal; margin-bottom: 24px;">
    <?php echo wp_kses_post($order->get_formatted_billing_address()); ?>
    <?php if ($order->get_billing_phone()) : ?>
        <br><?php echo esc_html($order->get_billing_phone()); ?>
    <?php endif; ?>
    <?php if ($order->get_billing_email()) : ?>
        <br><a href="<?php echo esc_url('mailto:' . $order->get_billing_email()); ?>"><?php echo esc_html($order->get_billing_email()); ?></a>
    <?php endif; ?>
</address>

<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
