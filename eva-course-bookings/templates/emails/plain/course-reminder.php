<?php
/**
 * Course Reminder Email (Plain Text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/course-reminder.php.
 *
 * @package Eva_Course_Bookings
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html(wp_strip_all_tags($email_heading));
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ($customer_name) {
    /* translators: %s: Customer name */
    echo sprintf(esc_html__('Ciao %s,', 'eva-course-bookings'), esc_html($customer_name)) . "\n\n";
} else {
    echo esc_html__('Ciao,', 'eva-course-bookings') . "\n\n";
}

echo esc_html__('Ti ricordiamo che hai una prenotazione per il seguente corso:', 'eva-course-bookings') . "\n\n";

echo "----------------------------------------\n";
echo strtoupper(esc_html($course_name)) . "\n";
echo "----------------------------------------\n\n";

echo esc_html__('Data:', 'eva-course-bookings') . ' ' . esc_html($course_date) . "\n";

echo esc_html__('Orario:', 'eva-course-bookings') . ' ' . esc_html($course_time);
if ($course_end_time) {
    echo ' - ' . esc_html($course_end_time);
}
echo "\n";

echo esc_html__('Partecipanti:', 'eva-course-bookings') . ' ' . esc_html($participant_count) . "\n";

if ($location) {
    echo esc_html__('Luogo:', 'eva-course-bookings') . ' ' . esc_html($location) . "\n";
}

echo "\n";

echo esc_html__('Come raggiungerci', 'eva-course-bookings') . "\n\n";
echo esc_html__('Ti aspettiamo presso la nostra torrefazione in Via Tucidide 56, 20134 Milano.', 'eva-course-bookings') . "\n";
echo esc_url_raw('https://maps.app.goo.gl/HkFkzm27H6KFMQF16') . "\n";
echo esc_html__('Il numero del nostro interno al citofono è 183.', 'eva-course-bookings') . "\n\n";

echo esc_html__('Se vieni in macchina, ti consigliamo questi parcheggi:', 'eva-course-bookings') . "\n";
echo esc_html__('Parcheggio 1:', 'eva-course-bookings') . ' ' . esc_url_raw('https://maps.app.goo.gl/xaFTgSjihrmzz8Pn6') . "\n";
echo esc_html__('Parcheggio 2:', 'eva-course-bookings') . ' ' . esc_url_raw('https://maps.app.goo.gl/1VZaZSJujXTKxG388') . "\n\n";

echo esc_html__('Se vieni con i mezzi, le fermate della metro più vicine sono:', 'eva-course-bookings') . "\n";
echo 'M4 Repetti: ' . esc_url_raw('https://maps.app.goo.gl/a1CPx48WBnXdT3ix5') . "\n";
echo 'M4 Argonne: ' . esc_url_raw('https://maps.app.goo.gl/38B56CGzsmji1MvP6') . "\n\n";

echo esc_html__('Guarda il video: Come raggiungerci', 'eva-course-bookings') . "\n";
echo esc_url_raw('https://www.instagram.com/s/aGlnaGxpZ2h0OjE3OTA4MDg0MzQ0MTY0NjQ5?story_media_id=3821614722886423053&igsh=MWcwOHpzeHJrb2Q0NQ==') . "\n\n";

if ($notes) {
    echo "----------------------------------------\n";
    echo strtoupper(esc_html__('Note importanti', 'eva-course-bookings')) . "\n";
    echo "----------------------------------------\n\n";
    echo esc_html($notes) . "\n\n";
}

if ($additional_content) {
    echo "----------------------------------------\n\n";
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)));
    echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));

