<?php
defined('ABSPATH') || exit;
echo wp_strip_all_tags($email_heading) . "\n\n";
echo 'Ciao ' . $gift['recipient'] . ",\n\n";
echo $gift['sender'] . ' ti ha regalato ' . $course . ' per ' . $quantity . " partecipanti.\n\n";
if ($gift['note'] !== '') {
    echo $gift['note'] . "\n\n";
}
echo "Il tuo buono regalo personalizzato è allegato a questa email.\n\n";
echo 'Riferimento prenotazione: ' . $reference . "\nEmail destinatario: " . $recipient . "\n\n";
echo $booking_url ? "Scegli la data del corso: " . $booking_url . "\nInserisci il riferimento e la tua email.\n" : "Contattaci per scegliere la data del corso, indicando il riferimento prenotazione.\n";
echo "\n" . wp_strip_all_tags($additional_content) . "\n";
echo wp_strip_all_tags(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'))) . "\n";
