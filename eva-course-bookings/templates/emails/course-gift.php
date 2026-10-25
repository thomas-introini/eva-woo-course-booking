<?php
defined('ABSPATH') || exit;
do_action('woocommerce_email_header', $email_heading, $email);
?>
<p>Ciao <?php echo esc_html($gift['recipient']); ?>,</p>
<p><?php echo esc_html($gift['sender']); ?> ti ha regalato <strong><?php echo esc_html($course); ?></strong> per <?php echo esc_html($quantity); ?> partecipanti.</p>
<?php if ($gift['note'] !== '') : ?>
    <blockquote><?php echo nl2br(esc_html($gift['note'])); ?></blockquote>
<?php endif; ?>
<p>Il tuo buono regalo personalizzato è allegato a questa email.</p>
<p>Riferimento prenotazione: <strong><?php echo esc_html($reference); ?></strong><br>Email destinatario: <?php echo esc_html($recipient); ?></p>
<?php if ($booking_url) : ?>
    <p><a href="<?php echo esc_url($booking_url); ?>">Scegli la data del corso</a> inserendo il riferimento e la tua email.</p>
<?php else : ?>
    <p>Contattaci per scegliere la data del corso, indicando il riferimento prenotazione.</p>
<?php endif; ?>
<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
