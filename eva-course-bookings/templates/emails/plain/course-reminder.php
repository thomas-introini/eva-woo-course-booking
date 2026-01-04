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

